<?php

namespace App\Services\DocumentEdits\Adapters;

use App\Models\OmManual;
use App\Services\DocumentEdits\DocumentEditAdapterInterface;
use App\Services\OmManualDocxService;
use Illuminate\Support\Facades\Log;

/**
 * Pass-C O&M manual edit adapter.
 *
 * Writes to OmManual.generated_data. Regenerates the DOCX via OmManualDocxService
 * (existing pipeline) on commit.
 */
class OmEditAdapter implements DocumentEditAdapterInterface
{
    public function documentType(): string
    {
        return 'om';
    }

    public function loadPayload(int $documentId): ?array
    {
        $om = OmManual::query()->find($documentId);
        if ($om === null) return null;
        return [
            'generated_data' => (array) ($om->generated_data ?? []),
            'extracted_data' => (array) ($om->extracted_data ?? []),
        ];
    }

    public function allowedOperations(): array
    {
        return [
            'update_project_field',
            'add_contact',
            'remove_contact',
            'add_maintenance_item',
            'remove_maintenance_item',
        ];
    }

    private const PROJECT_FIELDS = ['name', 'client', 'ref', 'site'];

    public function applyOperation(array $payload, array $op): array
    {
        return match (strtolower(trim((string) ($op['op'] ?? '')))) {
            'update_project_field'     => $this->applyUpdateProjectField($payload, $op),
            'add_contact'              => $this->applyAddContact($payload, $op),
            'remove_contact'           => $this->applyRemoveContact($payload, $op),
            'add_maintenance_item'     => $this->applyAddMaintenanceItem($payload, $op),
            'remove_maintenance_item'  => $this->applyRemoveMaintenanceItem($payload, $op),
            default => ['ok' => false, 'code' => 'unknown_operation', 'error' => "Unknown O&M op '{$op['op']}'"],
        };
    }

    private function applyUpdateProjectField(array $payload, array $op): array
    {
        $field = trim((string) ($op['field'] ?? ''));
        if (! in_array($field, self::PROJECT_FIELDS, true)) {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'update_project_field only accepts: ' . implode(', ', self::PROJECT_FIELDS)];
        }
        if (! array_key_exists('value', $op) || ! is_string($op['value'])) {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'update_project_field requires string `value`'];
        }
        $payload['generated_data']['project'] = (array) ($payload['generated_data']['project'] ?? []);
        $payload['generated_data']['project'][$field] = trim($op['value']);
        return ['ok' => true, 'payload' => $payload];
    }

    private function applyAddContact(array $payload, array $op): array
    {
        $name  = trim((string) ($op['name']  ?? ''));
        $role  = trim((string) ($op['role']  ?? ''));
        $email = trim((string) ($op['email'] ?? ''));
        $phone = trim((string) ($op['phone'] ?? ''));
        if ($name === '' || $role === '') {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'add_contact requires name and role'];
        }
        $contacts = (array) ($payload['generated_data']['contacts'] ?? []);
        // Dedupe by (name, role).
        foreach ($contacts as $c) {
            if (is_array($c) && ($c['name'] ?? null) === $name && ($c['role'] ?? null) === $role) {
                return ['ok' => true, 'payload' => $payload];
            }
        }
        $contacts[] = ['name' => $name, 'role' => $role, 'email' => $email, 'phone' => $phone];
        $payload['generated_data']['contacts'] = array_values($contacts);
        return ['ok' => true, 'payload' => $payload];
    }

    private function applyRemoveContact(array $payload, array $op): array
    {
        $name = trim((string) ($op['name'] ?? ''));
        $role = trim((string) ($op['role'] ?? ''));
        if ($name === '' || $role === '') {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'remove_contact requires name and role'];
        }
        $payload['generated_data']['contacts'] = array_values(array_filter(
            (array) ($payload['generated_data']['contacts'] ?? []),
            fn ($c) => ! (is_array($c) && ($c['name'] ?? null) === $name && ($c['role'] ?? null) === $role),
        ));
        return ['ok' => true, 'payload' => $payload];
    }

    private function applyAddMaintenanceItem(array $payload, array $op): array
    {
        $task      = trim((string) ($op['task']      ?? ''));
        $frequency = trim((string) ($op['frequency'] ?? ''));
        $notes     = trim((string) ($op['notes']     ?? ''));
        if ($task === '' || $frequency === '') {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'add_maintenance_item requires task and frequency'];
        }
        $items = (array) ($payload['generated_data']['maintenance_schedule'] ?? []);
        foreach ($items as $i) {
            if (is_array($i) && ($i['task'] ?? null) === $task && ($i['frequency'] ?? null) === $frequency) {
                return ['ok' => true, 'payload' => $payload];
            }
        }
        $items[] = ['task' => $task, 'frequency' => $frequency, 'notes' => $notes];
        $payload['generated_data']['maintenance_schedule'] = array_values($items);
        return ['ok' => true, 'payload' => $payload];
    }

    private function applyRemoveMaintenanceItem(array $payload, array $op): array
    {
        $task      = trim((string) ($op['task']      ?? ''));
        $frequency = trim((string) ($op['frequency'] ?? ''));
        if ($task === '' || $frequency === '') {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'remove_maintenance_item requires task and frequency'];
        }
        $payload['generated_data']['maintenance_schedule'] = array_values(array_filter(
            (array) ($payload['generated_data']['maintenance_schedule'] ?? []),
            fn ($i) => ! (is_array($i) && ($i['task'] ?? null) === $task && ($i['frequency'] ?? null) === $frequency),
        ));
        return ['ok' => true, 'payload' => $payload];
    }

    public function summariseDiff(array $before, array $after): array
    {
        $beforeContacts = (array) ($before['generated_data']['contacts'] ?? []);
        $afterContacts  = (array) ($after['generated_data']['contacts']  ?? []);
        $beforeMaint    = (array) ($before['generated_data']['maintenance_schedule'] ?? []);
        $afterMaint     = (array) ($after['generated_data']['maintenance_schedule']  ?? []);
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
                'contacts_count'           => count($beforeContacts),
                'maintenance_items_count'  => count($beforeMaint),
            ],
            'after_summary' => [
                'contacts_count'           => count($afterContacts),
                'maintenance_items_count'  => count($afterMaint),
            ],
            'project_fields_changed' => $projectChanged,
        ];
    }

    public function commitChanges(int $documentId, array $payload): ?string
    {
        $om = OmManual::query()->find($documentId);
        if ($om === null) {
            Log::warning('OmEditAdapter::commitChanges om_manual not found', ['id' => $documentId]);
            return null;
        }
        $om->update([
            'generated_data' => $payload['generated_data'] ?? $om->generated_data,
        ]);

        // Regenerate DOCX via existing pipeline.
        try {
            app(OmManualDocxService::class)->build((array) $om->generated_data, $om);
        } catch (\Throwable $e) {
            Log::error('OmEditAdapter::commitChanges docx build failed', [
                'id'    => $documentId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
        return $om->fresh()->filename;
    }
}
