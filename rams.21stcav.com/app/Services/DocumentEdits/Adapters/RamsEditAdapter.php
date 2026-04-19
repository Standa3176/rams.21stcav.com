<?php

namespace App\Services\DocumentEdits\Adapters;

use App\Models\RamsDocument;
use App\Services\DocumentEdits\DocumentEditAdapterInterface;
use Illuminate\Support\Facades\Log;

/**
 * Pass-C RAMS edit adapter — data-only mutations against generated_data /
 * reviewed_data. No direct DOCX edits. Artifact regen is explicitly deferred
 * (returns null) because the RAMS pipeline requires a full build via
 * BuildRamsDocumentJob / RamsDocumentRendererService — we don't want chat
 * edits to silently queue AI work. Persist-only here; regen is manual.
 */
class RamsEditAdapter implements DocumentEditAdapterInterface
{
    public function documentType(): string
    {
        return 'rams';
    }

    public function loadPayload(int $documentId): ?array
    {
        $rams = RamsDocument::query()->find($documentId);
        if ($rams === null) return null;
        return [
            'generated_data' => (array) ($rams->generated_data ?? []),
            'reviewed_data'  => (array) ($rams->reviewed_data ?? []),
        ];
    }

    public function allowedOperations(): array
    {
        return [
            'update_project_field',
            'add_exclusion',
            'remove_exclusion',
            'add_client_responsibility',
            'remove_client_responsibility',
        ];
    }

    /** Project fields writable via chat — allow-list intersects with CLAUDE.md safety scope. */
    private const PROJECT_FIELDS = [
        'name', 'client', 'site_address', 'ref',
        'project_manager', 'lead_engineer', 'programmer',
        'planned_start_date', 'planned_end_date',
        'planned_start_time', 'planned_end_time',
        'site_contact',
    ];

    public function applyOperation(array $payload, array $op): array
    {
        return match (strtolower(trim((string) ($op['op'] ?? '')))) {
            'update_project_field'        => $this->applyUpdateProjectField($payload, $op),
            'add_exclusion'               => $this->applyAddExclusion($payload, $op),
            'remove_exclusion'            => $this->applyRemoveExclusion($payload, $op),
            'add_client_responsibility'   => $this->applyAddClientResp($payload, $op),
            'remove_client_responsibility'=> $this->applyRemoveClientResp($payload, $op),
            default => ['ok' => false, 'code' => 'unknown_operation', 'error' => "Unknown RAMS op '{$op['op']}'"],
        };
    }

    private function applyUpdateProjectField(array $payload, array $op): array
    {
        $field = trim((string) ($op['field'] ?? ''));
        if ($field === '' || ! in_array($field, self::PROJECT_FIELDS, true)) {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => "update_project_field only accepts: " . implode(', ', self::PROJECT_FIELDS)];
        }
        if (! array_key_exists('value', $op) || ! is_string($op['value'])) {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'update_project_field requires a string `value`'];
        }
        $payload['generated_data']['project'] = (array) ($payload['generated_data']['project'] ?? []);
        $payload['generated_data']['project'][$field] = trim($op['value']);
        return ['ok' => true, 'payload' => $payload];
    }

    private function applyAddExclusion(array $payload, array $op): array
    {
        $text = trim((string) ($op['text'] ?? ''));
        if ($text === '') {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'add_exclusion requires `text`'];
        }
        $payload['reviewed_data']['exclusions'] = (array) ($payload['reviewed_data']['exclusions'] ?? []);
        if (! in_array($text, $payload['reviewed_data']['exclusions'], true)) {
            $payload['reviewed_data']['exclusions'][] = $text;
        }
        return ['ok' => true, 'payload' => $payload];
    }

    private function applyRemoveExclusion(array $payload, array $op): array
    {
        $text = trim((string) ($op['text'] ?? ''));
        if ($text === '') {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'remove_exclusion requires `text`'];
        }
        $payload['reviewed_data']['exclusions'] = array_values(array_filter(
            (array) ($payload['reviewed_data']['exclusions'] ?? []),
            fn ($x) => $x !== $text,
        ));
        return ['ok' => true, 'payload' => $payload];
    }

    private function applyAddClientResp(array $payload, array $op): array
    {
        $item = trim((string) ($op['item'] ?? ''));
        if ($item === '') {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'add_client_responsibility requires `item`'];
        }
        $payload['reviewed_data']['client_responsibilities_expanded'] = (array) ($payload['reviewed_data']['client_responsibilities_expanded'] ?? []);
        // Dedupe by item text (each entry is typically {item, notes}).
        foreach ($payload['reviewed_data']['client_responsibilities_expanded'] as $existing) {
            if (is_array($existing) && ($existing['item'] ?? null) === $item) {
                return ['ok' => true, 'payload' => $payload];
            }
        }
        $payload['reviewed_data']['client_responsibilities_expanded'][] = ['item' => $item, 'notes' => (string) ($op['notes'] ?? '')];
        return ['ok' => true, 'payload' => $payload];
    }

    private function applyRemoveClientResp(array $payload, array $op): array
    {
        $item = trim((string) ($op['item'] ?? ''));
        if ($item === '') {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'remove_client_responsibility requires `item`'];
        }
        $payload['reviewed_data']['client_responsibilities_expanded'] = array_values(array_filter(
            (array) ($payload['reviewed_data']['client_responsibilities_expanded'] ?? []),
            fn ($x) => ! (is_array($x) && ($x['item'] ?? null) === $item),
        ));
        return ['ok' => true, 'payload' => $payload];
    }

    public function summariseDiff(array $before, array $after): array
    {
        $beforeExcl = (array) ($before['reviewed_data']['exclusions'] ?? []);
        $afterExcl  = (array) ($after['reviewed_data']['exclusions']  ?? []);
        $beforeResp = (array) ($before['reviewed_data']['client_responsibilities_expanded'] ?? []);
        $afterResp  = (array) ($after['reviewed_data']['client_responsibilities_expanded']  ?? []);
        $beforeProj = (array) ($before['generated_data']['project'] ?? []);
        $afterProj  = (array) ($after['generated_data']['project']  ?? []);

        $projectChanged = [];
        foreach (self::PROJECT_FIELDS as $f) {
            if (($beforeProj[$f] ?? null) !== ($afterProj[$f] ?? null)) {
                $projectChanged[] = $f;
            }
        }

        return [
            'before_summary' => [
                'exclusions_count'             => count($beforeExcl),
                'client_responsibilities_count' => count($beforeResp),
            ],
            'after_summary' => [
                'exclusions_count'             => count($afterExcl),
                'client_responsibilities_count' => count($afterResp),
            ],
            'project_fields_changed' => $projectChanged,
        ];
    }

    public function commitChanges(int $documentId, array $payload): ?string
    {
        $rams = RamsDocument::query()->find($documentId);
        if ($rams === null) {
            Log::warning('RamsEditAdapter::commitChanges rams not found', ['id' => $documentId]);
            return null;
        }
        $rams->update([
            'generated_data' => $payload['generated_data'] ?? $rams->generated_data,
            'reviewed_data'  => $payload['reviewed_data']  ?? $rams->reviewed_data,
        ]);
        // Artifact regen deferred to the regular RAMS pipeline. Chat edits
        // only update the data; operator triggers a rebuild when ready.
        return null;
    }
}
