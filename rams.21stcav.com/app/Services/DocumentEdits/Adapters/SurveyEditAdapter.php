<?php

namespace App\Services\DocumentEdits\Adapters;

use App\Models\SiteSurvey;
use App\Models\SiteSurveyRoom;
use App\Services\DocumentEdits\DocumentEditAdapterInterface;
use Illuminate\Support\Facades\Log;

/**
 * Pass-C Site Survey edit adapter. Writes room-level scalars via
 * SiteSurveyRoom eloquent models. Survey doesn't have a pre-committed DOCX
 * artifact analogous to RAMS/Worksheet/O&M/Cable; commitChanges persists
 * fields and returns null.
 */
class SurveyEditAdapter implements DocumentEditAdapterInterface
{
    public function documentType(): string
    {
        return 'survey';
    }

    public function loadPayload(int $documentId): ?array
    {
        $survey = SiteSurvey::query()->with('rooms')->find($documentId);
        if ($survey === null) return null;
        return [
            'survey' => [
                'id'           => $survey->id,
                'project_name' => $survey->project_name,
                'client_name'  => $survey->client_name,
                'site_address' => $survey->site_address,
                'status'       => $survey->status,
            ],
            'rooms' => $survey->rooms->map(fn ($r) => $r->only([
                'id', 'room_name',
                'room_width_m', 'room_depth_m', 'room_height_m',
                'av_requirements', 'av_equipment_list',
                'has_power', 'power_outlet_count',
                'has_network', 'network_port_count',
                'is_completed',
            ]))->values()->all(),
        ];
    }

    public function allowedOperations(): array
    {
        return [
            'update_room_dimensions',
            'set_room_power',
            'set_room_network',
            'update_av_requirements',
            'mark_room_complete',
        ];
    }

    public function applyOperation(array $payload, array $op): array
    {
        return match (strtolower(trim((string) ($op['op'] ?? '')))) {
            'update_room_dimensions' => $this->applyUpdateDimensions($payload, $op),
            'set_room_power'         => $this->applySetPower($payload, $op),
            'set_room_network'       => $this->applySetNetwork($payload, $op),
            'update_av_requirements' => $this->applyUpdateAvRequirements($payload, $op),
            'mark_room_complete'     => $this->applyMarkRoomComplete($payload, $op),
            default => ['ok' => false, 'code' => 'unknown_operation', 'error' => "Unknown Survey op '{$op['op']}'"],
        };
    }

    private function findRoomIndex(array $payload, int $roomId): ?int
    {
        foreach ((array) ($payload['rooms'] ?? []) as $idx => $r) {
            if ((int) ($r['id'] ?? 0) === $roomId) return (int) $idx;
        }
        return null;
    }

    private function applyUpdateDimensions(array $payload, array $op): array
    {
        $idx = $this->findRoomIndex($payload, (int) ($op['room_id'] ?? 0));
        if ($idx === null) {
            return ['ok' => false, 'code' => 'room_not_found', 'error' => "Room id {$op['room_id']} not found"];
        }
        foreach (['room_width_m', 'room_depth_m', 'room_height_m'] as $f) {
            if (array_key_exists($f, $op)) {
                $v = (float) $op[$f];
                if ($v <= 0 || $v > 100) {
                    return ['ok' => false, 'code' => 'invalid_op', 'error' => "{$f} must be > 0 and ≤ 100"];
                }
                $payload['rooms'][$idx][$f] = $v;
            }
        }
        return ['ok' => true, 'payload' => $payload];
    }

    private function applySetPower(array $payload, array $op): array
    {
        $idx = $this->findRoomIndex($payload, (int) ($op['room_id'] ?? 0));
        if ($idx === null) {
            return ['ok' => false, 'code' => 'room_not_found', 'error' => "Room id {$op['room_id']} not found"];
        }
        if (! array_key_exists('has_power', $op) || ! is_bool($op['has_power'])) {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'set_room_power requires boolean `has_power`'];
        }
        $payload['rooms'][$idx]['has_power'] = $op['has_power'];
        if ($op['has_power'] === true) {
            $outlets = (int) ($op['power_outlet_count'] ?? 0);
            if ($outlets < 0 || $outlets > 999) {
                return ['ok' => false, 'code' => 'invalid_op', 'error' => 'power_outlet_count out of range'];
            }
            $payload['rooms'][$idx]['power_outlet_count'] = $outlets;
        }
        return ['ok' => true, 'payload' => $payload];
    }

    private function applySetNetwork(array $payload, array $op): array
    {
        $idx = $this->findRoomIndex($payload, (int) ($op['room_id'] ?? 0));
        if ($idx === null) {
            return ['ok' => false, 'code' => 'room_not_found', 'error' => "Room id {$op['room_id']} not found"];
        }
        if (! array_key_exists('has_network', $op) || ! is_bool($op['has_network'])) {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'set_room_network requires boolean `has_network`'];
        }
        $payload['rooms'][$idx]['has_network'] = $op['has_network'];
        if ($op['has_network'] === true) {
            $ports = (int) ($op['network_port_count'] ?? 0);
            if ($ports < 0 || $ports > 999) {
                return ['ok' => false, 'code' => 'invalid_op', 'error' => 'network_port_count out of range'];
            }
            $payload['rooms'][$idx]['network_port_count'] = $ports;
        }
        return ['ok' => true, 'payload' => $payload];
    }

    private function applyUpdateAvRequirements(array $payload, array $op): array
    {
        $idx = $this->findRoomIndex($payload, (int) ($op['room_id'] ?? 0));
        if ($idx === null) {
            return ['ok' => false, 'code' => 'room_not_found', 'error' => "Room id {$op['room_id']} not found"];
        }
        $text = trim((string) ($op['av_requirements'] ?? ''));
        if ($text === '') {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'update_av_requirements requires `av_requirements`'];
        }
        $payload['rooms'][$idx]['av_requirements'] = $text;
        return ['ok' => true, 'payload' => $payload];
    }

    private function applyMarkRoomComplete(array $payload, array $op): array
    {
        $idx = $this->findRoomIndex($payload, (int) ($op['room_id'] ?? 0));
        if ($idx === null) {
            return ['ok' => false, 'code' => 'room_not_found', 'error' => "Room id {$op['room_id']} not found"];
        }
        $payload['rooms'][$idx]['is_completed'] = (bool) ($op['is_completed'] ?? true);
        return ['ok' => true, 'payload' => $payload];
    }

    public function summariseDiff(array $before, array $after): array
    {
        $beforeById = [];
        foreach ((array) ($before['rooms'] ?? []) as $r) $beforeById[(int) ($r['id'] ?? 0)] = $r;
        $afterById  = [];
        foreach ((array) ($after['rooms']  ?? []) as $r) $afterById[(int) ($r['id']  ?? 0)] = $r;

        $changedRooms = [];
        foreach ($afterById as $id => $a) {
            $b = $beforeById[$id] ?? [];
            $fieldChanges = [];
            foreach (['room_width_m', 'room_depth_m', 'room_height_m', 'av_requirements',
                      'has_power', 'power_outlet_count', 'has_network', 'network_port_count',
                      'is_completed'] as $f) {
                if (($b[$f] ?? null) != ($a[$f] ?? null)) { // loose equality so 5 vs 5.0 don't flag
                    $fieldChanges[] = $f;
                }
            }
            if ($fieldChanges) {
                $changedRooms[] = [
                    'room_id'    => $id,
                    'room_name'  => $a['room_name'] ?? ($b['room_name'] ?? ''),
                    'fields'     => $fieldChanges,
                ];
            }
        }

        return [
            'before_summary' => ['rooms_count' => count($beforeById)],
            'after_summary'  => ['rooms_count' => count($afterById)],
            'changed_rooms'  => $changedRooms,
        ];
    }

    public function commitChanges(int $documentId, array $payload): ?string
    {
        $survey = SiteSurvey::query()->with('rooms')->find($documentId);
        if ($survey === null) {
            Log::warning('SurveyEditAdapter::commitChanges survey not found', ['id' => $documentId]);
            return null;
        }

        foreach ((array) ($payload['rooms'] ?? []) as $roomData) {
            $roomId = (int) ($roomData['id'] ?? 0);
            if ($roomId <= 0) continue;
            /** @var SiteSurveyRoom|null $room */
            $room = $survey->rooms->firstWhere('id', $roomId);
            if ($room === null) continue;
            $room->fill(array_filter(
                array_intersect_key($roomData, array_flip([
                    'room_width_m', 'room_depth_m', 'room_height_m',
                    'av_requirements', 'av_equipment_list',
                    'has_power', 'power_outlet_count',
                    'has_network', 'network_port_count',
                    'is_completed',
                ])),
                fn ($v) => $v !== null,
            ))->save();
        }

        // No artifact regen — survey is a live DB record, not a derived file.
        return null;
    }
}
