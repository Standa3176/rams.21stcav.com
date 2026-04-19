<?php

namespace App\Services\DocumentEdits\Adapters;

use App\Models\CableSchedule;
use App\Models\CableScheduleItem;
use App\Services\CableScheduleXlsxService;
use App\Services\DocumentEdits\DocumentEditAdapterInterface;
use Illuminate\Support\Facades\Log;

/**
 * Pass-C Cable Schedule edit adapter.
 *
 * Writes to CableScheduleItem rows keyed by cable_id. Regenerates the XLSX
 * via CableScheduleXlsxService on commit.
 */
class CableEditAdapter implements DocumentEditAdapterInterface
{
    public function documentType(): string
    {
        return 'cable';
    }

    public function loadPayload(int $documentId): ?array
    {
        $cable = CableSchedule::query()->with('items')->find($documentId);
        if ($cable === null) return null;
        return [
            'id'          => $cable->id,
            'project_ref' => $cable->project_ref,
            'items'       => $cable->items->map(fn ($i) => $i->only([
                'id', 'cable_id', 'from_location', 'to_location',
                'cable_type', 'cores', 'approx_length_m', 'notes', 'sort_order',
            ]))->values()->all(),
        ];
    }

    public function allowedOperations(): array
    {
        return [
            'add_cable_item',
            'remove_cable_item',
            'update_cable_item_field',
        ];
    }

    private const WRITABLE_FIELDS = [
        'from_location', 'to_location', 'cable_type',
        'cores', 'approx_length_m', 'notes',
    ];

    public function applyOperation(array $payload, array $op): array
    {
        return match (strtolower(trim((string) ($op['op'] ?? '')))) {
            'add_cable_item'          => $this->applyAddCableItem($payload, $op),
            'remove_cable_item'       => $this->applyRemoveCableItem($payload, $op),
            'update_cable_item_field' => $this->applyUpdateCableItemField($payload, $op),
            default => ['ok' => false, 'code' => 'unknown_operation', 'error' => "Unknown Cable op '{$op['op']}'"],
        };
    }

    private function applyAddCableItem(array $payload, array $op): array
    {
        $cableId = trim((string) ($op['cable_id'] ?? ''));
        if ($cableId === '') {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'add_cable_item requires `cable_id`'];
        }
        // Dedupe by cable_id within the same schedule — no-op on re-add.
        foreach ((array) ($payload['items'] ?? []) as $existing) {
            if (($existing['cable_id'] ?? null) === $cableId) {
                return ['ok' => true, 'payload' => $payload];
            }
        }
        $payload['items'][] = [
            'cable_id'        => $cableId,
            'from_location'   => trim((string) ($op['from_location']  ?? '')),
            'to_location'     => trim((string) ($op['to_location']    ?? '')),
            'cable_type'      => trim((string) ($op['cable_type']     ?? '')),
            'cores'           => trim((string) ($op['cores']          ?? '')),
            'approx_length_m' => (float)       ($op['approx_length_m'] ?? 0),
            'notes'           => trim((string) ($op['notes']          ?? '')),
            'sort_order'      => count((array) ($payload['items'] ?? [])),
        ];
        return ['ok' => true, 'payload' => $payload];
    }

    private function applyRemoveCableItem(array $payload, array $op): array
    {
        $cableId = trim((string) ($op['cable_id'] ?? ''));
        if ($cableId === '') {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'remove_cable_item requires `cable_id`'];
        }
        $payload['items'] = array_values(array_filter(
            (array) ($payload['items'] ?? []),
            fn ($i) => ($i['cable_id'] ?? null) !== $cableId,
        ));
        return ['ok' => true, 'payload' => $payload];
    }

    private function applyUpdateCableItemField(array $payload, array $op): array
    {
        $cableId = trim((string) ($op['cable_id'] ?? ''));
        $field   = trim((string) ($op['field']    ?? ''));
        if ($cableId === '' || ! in_array($field, self::WRITABLE_FIELDS, true)) {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'update_cable_item_field requires cable_id + field in ' . implode(',', self::WRITABLE_FIELDS)];
        }
        if (! array_key_exists('value', $op)) {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'update_cable_item_field requires `value`'];
        }

        $items = (array) ($payload['items'] ?? []);
        $found = false;
        foreach ($items as &$item) {
            if (($item['cable_id'] ?? null) === $cableId) {
                if ($field === 'approx_length_m') {
                    $item[$field] = (float) $op['value'];
                } else {
                    $item[$field] = trim((string) $op['value']);
                }
                $found = true;
                break;
            }
        }
        unset($item);
        if (! $found) {
            return ['ok' => false, 'code' => 'cable_item_not_found', 'error' => "Cable item '{$cableId}' not found"];
        }
        $payload['items'] = $items;
        return ['ok' => true, 'payload' => $payload];
    }

    public function summariseDiff(array $before, array $after): array
    {
        $beforeItems = (array) ($before['items'] ?? []);
        $afterItems  = (array) ($after['items']  ?? []);
        $beforeIds = array_column($beforeItems, 'cable_id');
        $afterIds  = array_column($afterItems,  'cable_id');

        return [
            'before_summary' => ['items_count' => count($beforeItems)],
            'after_summary'  => ['items_count' => count($afterItems)],
            'items_added'    => array_values(array_diff($afterIds, $beforeIds)),
            'items_removed'  => array_values(array_diff($beforeIds, $afterIds)),
        ];
    }

    public function commitChanges(int $documentId, array $payload): ?string
    {
        $schedule = CableSchedule::query()->with('items')->find($documentId);
        if ($schedule === null) {
            Log::warning('CableEditAdapter::commitChanges cable_schedule not found', ['id' => $documentId]);
            return null;
        }

        // Reconcile items: items in payload that exist in DB → update;
        // items in payload not in DB → create; items in DB not in payload → delete.
        $existingById  = $schedule->items->keyBy('cable_id');
        $payloadIds    = [];
        foreach ((array) ($payload['items'] ?? []) as $row) {
            $cableId = (string) ($row['cable_id'] ?? '');
            if ($cableId === '') continue;
            $payloadIds[] = $cableId;
            $attrs = array_intersect_key($row, array_flip([
                'cable_id', 'from_location', 'to_location',
                'cable_type', 'cores', 'approx_length_m', 'notes', 'sort_order',
            ]));
            if ($existingById->has($cableId)) {
                $existingById[$cableId]->fill($attrs)->save();
            } else {
                CableScheduleItem::create(['cable_schedule_id' => $schedule->id] + $attrs);
            }
        }
        // Delete anything no longer in the payload.
        foreach ($existingById as $cableId => $item) {
            if (! in_array($cableId, $payloadIds, true)) {
                $item->delete();
            }
        }

        // Regenerate XLSX.
        try {
            app(CableScheduleXlsxService::class)->build($schedule->fresh('items'));
        } catch (\Throwable $e) {
            Log::error('CableEditAdapter::commitChanges xlsx build failed', [
                'id'    => $documentId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }
        return $schedule->fresh()->source_filename;
    }
}
