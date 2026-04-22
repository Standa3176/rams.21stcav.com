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

        // Also pull the linked project package's extracted_data so chat edits
        // to method-statement notes survive Regen (which refreshes reviewed_data
        // from this source). Not all RAMS have a project — pkg stays null then.
        $pkgData = null;
        if ($rams->project_id) {
            $pkg = \App\Models\ProjectPackage::where('project_id', $rams->project_id)
                ->where('status', \App\Models\ProjectPackage::STATUS_REVIEWED)
                ->latest()
                ->first();
            if ($pkg) {
                $pkgData = [
                    'package_id'     => $pkg->id,
                    'extracted_data' => (array) ($pkg->extracted_data ?? []),
                ];
            }
        }

        return [
            'generated_data'  => (array) ($rams->generated_data ?? []),
            'reviewed_data'   => (array) ($rams->reviewed_data  ?? []),
            'form_data'       => (array) ($rams->form_data      ?? []),
            'project_package' => $pkgData,
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
            'add_method_statement_note',
            'set_method_statement_notes',
        ];
    }

    /**
     * Per-op argument schemas. Surfaced by the parser prompt factory so the AI
     * knows what `args` shapes are accepted for each op — prevents the AI from
     * inventing fields like 'working_hours' or 'comments' that the adapter
     * would otherwise reject.
     *
     * Not part of DocumentEditAdapterInterface — surfaced via method_exists()
     * in DocumentEditParsingPromptFactory so other adapters need not implement it.
     *
     * @return array<string, array{args:array<string,string>, notes?:string}>
     */
    public function operationSchemas(): array
    {
        return [
            'update_project_field' => [
                'args' => [
                    'field' => 'One of: ' . implode(', ', self::PROJECT_FIELDS),
                    'value' => 'string. For working_hours, emit a complete human-readable line like "Monday–Friday, 07:30–17:00" or "Saturday only, 08:00–16:00" — this is rendered verbatim on the RAMS cover and Section 4. For planned_start_time / planned_end_time, use 4-digit HHMM ("0730").',
                ],
                'notes' => 'Do NOT emit field="comments", field="notes", field="method_statement_notes" here — use add_method_statement_note / set_method_statement_notes instead.',
            ],
            'add_exclusion' => [
                'args' => ['text' => 'string — one exclusion line, e.g. "No structural works"'],
            ],
            'remove_exclusion' => [
                'args' => ['text' => 'string — exact text of the exclusion to remove'],
            ],
            'add_client_responsibility' => [
                'args' => [
                    'item'  => 'string — short responsibility title',
                    'notes' => 'string (optional) — extra detail',
                ],
            ],
            'remove_client_responsibility' => [
                'args' => ['item' => 'string — exact title of the responsibility to remove'],
            ],
            'add_method_statement_note' => [
                'args' => ['text' => 'string — a comment / note to append to the method statement notes field. Use this for PM guidance such as "no podium required — ground-level install".'],
                'notes' => 'Appends to reviewed_data.method_statement_notes on a new line; preserves any existing notes.',
            ],
            'set_method_statement_notes' => [
                'args' => ['text' => 'string — full replacement value for method_statement_notes (overwrites existing).'],
                'notes' => 'Prefer add_method_statement_note unless the user explicitly asks to replace the whole block.',
            ],
        ];
    }

    /** Project fields writable via chat — allow-list intersects with CLAUDE.md safety scope. */
    private const PROJECT_FIELDS = [
        'name', 'client', 'site_address', 'ref',
        'project_manager', 'lead_engineer', 'programmer',
        'planned_start_date', 'planned_end_date',
        'planned_start_time', 'planned_end_time',
        'site_contact',
        // Free-text string rendered verbatim on the RAMS cover + Section 4.
        // e.g. "Monday–Friday, 09:00–17:30" or "Saturday only, 08:00–16:00".
        'working_hours',
    ];

    public function applyOperation(array $payload, array $op): array
    {
        return match (strtolower(trim((string) ($op['op'] ?? '')))) {
            'update_project_field'        => $this->applyUpdateProjectField($payload, $op),
            'add_exclusion'               => $this->applyAddExclusion($payload, $op),
            'remove_exclusion'            => $this->applyRemoveExclusion($payload, $op),
            'add_client_responsibility'   => $this->applyAddClientResp($payload, $op),
            'remove_client_responsibility'=> $this->applyRemoveClientResp($payload, $op),
            'add_method_statement_note'   => $this->applyAddMethodStatementNote($payload, $op),
            'set_method_statement_notes'  => $this->applySetMethodStatementNotes($payload, $op),
            default => ['ok' => false, 'code' => 'unknown_operation', 'error' => "Unknown RAMS op '{$op['op']}'"],
        };
    }

    private function applyUpdateProjectField(array $payload, array $op): array
    {
        $field = trim((string) ($op['field'] ?? ''));
        if ($field === '') {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'update_project_field requires a `field` name'];
        }
        if (! array_key_exists('value', $op) || ! is_string($op['value'])) {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'update_project_field requires a string `value`'];
        }
        $value = trim($op['value']);

        if (! in_array($field, self::PROJECT_FIELDS, true)) {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => "update_project_field only accepts: " . implode(', ', self::PROJECT_FIELDS)];
        }

        $payload['generated_data']['project'] = (array) ($payload['generated_data']['project'] ?? []);
        $payload['generated_data']['project'][$field] = $value;

        // Durable mirror for fields the Regen pipeline rebuilds from form_data —
        // without this, the chat edit is overwritten on next regenerate because
        // RamsBuilderService::buildFromReview() re-reads form_data, not generated_data.
        // Kept narrow to fields known to be blown away by regen.
        if (in_array($field, ['working_hours'], true)) {
            $payload['form_data'] = (array) ($payload['form_data'] ?? []);
            $payload['form_data'][$field] = $value;
        }

        return ['ok' => true, 'payload' => $payload];
    }

    private function applyAddMethodStatementNote(array $payload, array $op): array
    {
        $text = trim((string) ($op['text'] ?? ''));
        if ($text === '') {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'add_method_statement_note requires a non-empty `text`'];
        }
        $existing = trim((string) ($payload['reviewed_data']['method_statement_notes'] ?? ''));
        $next = $existing === '' ? $text : $existing . "\n" . $text;
        $payload['reviewed_data']['method_statement_notes'] = $next;

        // Durable mirror: also write the note onto the source-of-truth project
        // package. Regen refreshes reviewed_data FROM the package — without this
        // mirror the chat-added note is silently blown away on next regenerate.
        // (No-op when the RAMS is not linked to a project package.)
        $this->mirrorNotesToPackage($payload, $next);

        return ['ok' => true, 'payload' => $payload];
    }

    private function applySetMethodStatementNotes(array $payload, array $op): array
    {
        if (! array_key_exists('text', $op) || ! is_string($op['text'])) {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'set_method_statement_notes requires a string `text`'];
        }
        $value = trim($op['text']);
        $payload['reviewed_data']['method_statement_notes'] = $value;
        $this->mirrorNotesToPackage($payload, $value);
        return ['ok' => true, 'payload' => $payload];
    }

    /**
     * Write method_statement_notes into the linked ProjectPackage's extracted_data
     * in-payload. Persisted by commitChanges(). Ensures regen reads this value as
     * the source-of-truth instead of overwriting the chat edit.
     */
    private function mirrorNotesToPackage(array &$payload, string $value): void
    {
        if (empty($payload['project_package']['package_id'])) {
            return;
        }
        $payload['project_package']['extracted_data'] = (array) ($payload['project_package']['extracted_data'] ?? []);
        $payload['project_package']['extracted_data']['method_statement_notes'] = $value;
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
        $updates = [
            'generated_data' => $payload['generated_data'] ?? $rams->generated_data,
            'reviewed_data'  => $payload['reviewed_data']  ?? $rams->reviewed_data,
        ];
        // form_data mirror — preserves chat-edited working_hours across Regen.
        if (array_key_exists('form_data', $payload)) {
            $updates['form_data'] = (array) $payload['form_data'];
        }
        $rams->update($updates);

        // Source-of-truth mirror for method_statement_notes. Regen refreshes
        // reviewed_data FROM this package; without the write here, the note
        // round-trips on a single regen and disappears.
        if (! empty($payload['project_package']['package_id'])
            && array_key_exists('extracted_data', (array) $payload['project_package'])) {
            $pkg = \App\Models\ProjectPackage::find($payload['project_package']['package_id']);
            if ($pkg) {
                $pkg->update(['extracted_data' => $payload['project_package']['extracted_data']]);
            } else {
                Log::warning('RamsEditAdapter::commitChanges package not found', [
                    'rams_id'    => $documentId,
                    'package_id' => $payload['project_package']['package_id'],
                ]);
            }
        }

        // Artifact regen deferred to the regular RAMS pipeline. Chat edits
        // only update the data; operator triggers a rebuild when ready.
        return null;
    }
}
