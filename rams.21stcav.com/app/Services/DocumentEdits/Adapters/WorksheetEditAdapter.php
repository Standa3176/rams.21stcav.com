<?php

namespace App\Services\DocumentEdits\Adapters;

use App\Models\Worksheet;
use App\Services\DocumentEdits\DocumentEditAdapterInterface;
use App\Services\WorksheetDocxService;
use Illuminate\Support\Facades\Log;

/**
 * Full worksheet-edit adapter (pass B).
 *
 * Applies deterministic, data-only edits to Worksheet.generated_data, persists
 * the revised payload back to the model, and re-renders the DOCX artifact
 * through the existing WorksheetDocxService. Never touches DOCX XML directly;
 * never writes outside generated_data + the artifact disk the docx service
 * already owns.
 *
 * Supported ops (validated against allowedOperations() upstream):
 *   add_blocker             → append to generated_data.blockers
 *   remove_blocker          → remove from generated_data.blockers
 *   add_tool                → append to rooms[<name>].tools
 *   remove_tool             → remove from rooms[<name>].tools
 *   append_install_step     → append string to rooms[<name>].install_steps
 *   replace_install_step    → replace rooms[<name>].install_steps[<index>]
 *   update_room_summary     → set rooms[<name>].category_summary and/or
 *                              rooms[<name>].room_works_description
 *
 * Idempotence contracts:
 *   add_blocker / add_tool           → no-op when the same entry already exists
 *   remove_blocker / remove_tool     → no-op when the target is absent
 *   append_install_step              → always appends (caller-managed)
 *   replace_install_step             → ok iff the index exists
 *   update_room_summary              → no-op when no fields change
 */
class WorksheetEditAdapter implements DocumentEditAdapterInterface
{
    public function documentType(): string
    {
        return 'worksheet';
    }

    public function loadPayload(int $documentId): ?array
    {
        /** @var Worksheet|null $worksheet */
        $worksheet = Worksheet::query()->find($documentId);
        if ($worksheet === null) {
            return null;
        }
        return (array) ($worksheet->generated_data ?? []);
    }

    public function allowedOperations(): array
    {
        return [
            'add_blocker',
            'remove_blocker',
            'add_tool',
            'remove_tool',
            'append_install_step',
            'replace_install_step',
            'update_room_summary',
        ];
    }

    public function applyOperation(array $payload, array $op): array
    {
        $opName = strtolower(trim((string) ($op['op'] ?? '')));
        return match ($opName) {
            'add_blocker'          => $this->applyAddBlocker($payload, $op),
            'remove_blocker'       => $this->applyRemoveBlocker($payload, $op),
            'add_tool'             => $this->applyAddTool($payload, $op),
            'remove_tool'          => $this->applyRemoveTool($payload, $op),
            'append_install_step'  => $this->applyAppendInstallStep($payload, $op),
            'replace_install_step' => $this->applyReplaceInstallStep($payload, $op),
            'update_room_summary'  => $this->applyUpdateRoomSummary($payload, $op),
            default => [
                'ok'    => false,
                'code'  => 'unknown_operation',
                'error' => "Unknown worksheet operation '{$opName}'",
            ],
        };
    }

    // ─── Op implementations ──────────────────────────────────────────────────

    private function applyAddBlocker(array $payload, array $op): array
    {
        $type    = trim((string) ($op['type']    ?? ''));
        $message = trim((string) ($op['message'] ?? ''));
        $action  = trim((string) ($op['action']  ?? ''));
        $room    = trim((string) ($op['room']    ?? '(project)'));

        if ($type === '' || $message === '' || $action === '') {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'add_blocker requires type, message and action'];
        }

        $payload['blockers'] = (array) ($payload['blockers'] ?? []);
        foreach ($payload['blockers'] as $b) {
            // Idempotent — same type+message+room → no-op success.
            if (($b['type'] ?? null) === $type
                && ($b['message'] ?? null) === $message
                && ($b['room'] ?? null) === $room) {
                return ['ok' => true, 'payload' => $payload];
            }
        }

        $payload['blockers'][] = [
            'type'    => $type,
            'message' => $message,
            'action'  => $action,
            'room'    => $room,
            'source'  => 'manual_' . substr(md5($type . '|' . $message . '|' . $room), 0, 12),
        ];
        return ['ok' => true, 'payload' => $payload];
    }

    private function applyRemoveBlocker(array $payload, array $op): array
    {
        $payload['blockers'] = (array) ($payload['blockers'] ?? []);

        // Match precedence: source, then (type, message[, room]).
        $source = trim((string) ($op['source'] ?? ''));
        $type    = trim((string) ($op['type']    ?? ''));
        $message = trim((string) ($op['message'] ?? ''));
        $room    = trim((string) ($op['room']    ?? ''));

        if ($source === '' && ($type === '' || $message === '')) {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'remove_blocker requires either `source` or (`type` and `message`)'];
        }

        $before = count($payload['blockers']);
        $payload['blockers'] = array_values(array_filter(
            $payload['blockers'],
            function ($b) use ($source, $type, $message, $room) {
                if ($source !== '' && ($b['source'] ?? null) === $source) {
                    return false;
                }
                if ($source === ''
                    && ($b['type'] ?? null) === $type
                    && ($b['message'] ?? null) === $message
                    && ($room === '' || ($b['room'] ?? null) === $room)) {
                    return false;
                }
                return true;
            },
        ));

        // Idempotent: missing target is ok (payload unchanged).
        return ['ok' => true, 'payload' => $payload];
    }

    private function applyAddTool(array $payload, array $op): array
    {
        $roomName = trim((string) ($op['room'] ?? ''));
        $tool     = trim((string) ($op['tool'] ?? ''));
        if ($roomName === '' || $tool === '') {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'add_tool requires room and tool'];
        }

        $idx = $this->findRoomIndex($payload, $roomName);
        if ($idx === null) {
            return ['ok' => false, 'code' => 'room_not_found', 'error' => "Room '{$roomName}' not found"];
        }

        $tools = (array) ($payload['rooms'][$idx]['tools'] ?? []);
        if (! in_array($tool, $tools, true)) {
            $tools[] = $tool;
            $payload['rooms'][$idx]['tools'] = array_values($tools);
        }
        return ['ok' => true, 'payload' => $payload];
    }

    private function applyRemoveTool(array $payload, array $op): array
    {
        $roomName = trim((string) ($op['room'] ?? ''));
        $tool     = trim((string) ($op['tool'] ?? ''));
        if ($roomName === '' || $tool === '') {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'remove_tool requires room and tool'];
        }

        $idx = $this->findRoomIndex($payload, $roomName);
        if ($idx === null) {
            return ['ok' => false, 'code' => 'room_not_found', 'error' => "Room '{$roomName}' not found"];
        }

        $tools = (array) ($payload['rooms'][$idx]['tools'] ?? []);
        $payload['rooms'][$idx]['tools'] = array_values(array_filter($tools, fn ($t) => $t !== $tool));
        return ['ok' => true, 'payload' => $payload];
    }

    private function applyAppendInstallStep(array $payload, array $op): array
    {
        $roomName = trim((string) ($op['room'] ?? ''));
        $step     = trim((string) ($op['step'] ?? ''));
        if ($roomName === '' || $step === '') {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'append_install_step requires room and step'];
        }

        $idx = $this->findRoomIndex($payload, $roomName);
        if ($idx === null) {
            return ['ok' => false, 'code' => 'room_not_found', 'error' => "Room '{$roomName}' not found"];
        }

        // install_steps may be a string (legacy) or array (post-Pass B worksheets).
        $steps = $payload['rooms'][$idx]['install_steps'] ?? [];
        if (is_string($steps)) {
            $steps = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $steps))));
        }
        if (! is_array($steps)) $steps = [];
        $steps[] = $step;
        $payload['rooms'][$idx]['install_steps'] = array_values($steps);
        return ['ok' => true, 'payload' => $payload];
    }

    private function applyReplaceInstallStep(array $payload, array $op): array
    {
        $roomName = trim((string) ($op['room'] ?? ''));
        $step     = trim((string) ($op['step'] ?? ''));
        if (! array_key_exists('index', $op) || ! is_int($op['index']) || $op['index'] < 0) {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'replace_install_step requires a non-negative integer index'];
        }
        if ($roomName === '' || $step === '') {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'replace_install_step requires room and step'];
        }

        $idx = $this->findRoomIndex($payload, $roomName);
        if ($idx === null) {
            return ['ok' => false, 'code' => 'room_not_found', 'error' => "Room '{$roomName}' not found"];
        }

        $steps = $payload['rooms'][$idx]['install_steps'] ?? [];
        if (is_string($steps)) {
            $steps = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $steps))));
        }
        if (! is_array($steps)) $steps = [];
        if (! array_key_exists($op['index'], $steps)) {
            return ['ok' => false, 'code' => 'step_index_out_of_range', 'error' => "install_steps[{$op['index']}] does not exist in room '{$roomName}'"];
        }
        $steps[$op['index']] = $step;
        $payload['rooms'][$idx]['install_steps'] = array_values($steps);
        return ['ok' => true, 'payload' => $payload];
    }

    private function applyUpdateRoomSummary(array $payload, array $op): array
    {
        $roomName = trim((string) ($op['room'] ?? ''));
        if ($roomName === '') {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'update_room_summary requires room'];
        }

        $idx = $this->findRoomIndex($payload, $roomName);
        if ($idx === null) {
            return ['ok' => false, 'code' => 'room_not_found', 'error' => "Room '{$roomName}' not found"];
        }

        $changed = false;
        if (array_key_exists('category_summary', $op)) {
            $new = trim((string) $op['category_summary']);
            if (($payload['rooms'][$idx]['category_summary'] ?? '') !== $new) {
                $payload['rooms'][$idx]['category_summary'] = $new;
                $changed = true;
            }
        }
        if (array_key_exists('room_works_description', $op)) {
            $new = trim((string) $op['room_works_description']);
            if (($payload['rooms'][$idx]['room_works_description'] ?? '') !== $new) {
                $payload['rooms'][$idx]['room_works_description'] = $new;
                $changed = true;
            }
        }
        if (! $changed && ! array_key_exists('category_summary', $op) && ! array_key_exists('room_works_description', $op)) {
            return ['ok' => false, 'code' => 'invalid_op', 'error' => 'update_room_summary requires at least one of `category_summary` or `room_works_description`'];
        }
        return ['ok' => true, 'payload' => $payload];
    }

    // ─── Diff + commit ───────────────────────────────────────────────────────

    public function summariseDiff(array $before, array $after): array
    {
        $roomsBefore = $this->indexRoomsByName($before);
        $roomsAfter  = $this->indexRoomsByName($after);

        $changedRooms = [];
        foreach ($roomsAfter as $name => $a) {
            $b = $roomsBefore[$name] ?? null;
            $delta = $this->roomDelta($b, $a);
            if ($delta !== null) {
                $changedRooms[] = ['room' => $name] + $delta;
            }
        }

        $blockersBefore = count((array) ($before['blockers'] ?? []));
        $blockersAfter  = count((array) ($after['blockers']  ?? []));

        return [
            'before_summary' => [
                'rooms_count'       => count($roomsBefore),
                'blockers_count'    => $blockersBefore,
                'tools_total'       => $this->totalToolsCount($roomsBefore),
                'install_steps_total' => $this->totalInstallStepsCount($roomsBefore),
            ],
            'after_summary' => [
                'rooms_count'       => count($roomsAfter),
                'blockers_count'    => $blockersAfter,
                'tools_total'       => $this->totalToolsCount($roomsAfter),
                'install_steps_total' => $this->totalInstallStepsCount($roomsAfter),
            ],
            'changed_rooms'           => $changedRooms,
            'changed_blockers_count'  => abs($blockersAfter - $blockersBefore),
        ];
    }

    public function commitChanges(int $documentId, array $payload): ?string
    {
        $worksheet = Worksheet::query()->find($documentId);
        if ($worksheet === null) {
            Log::warning('WorksheetEditAdapter::commitChanges worksheet not found', ['id' => $documentId]);
            return null;
        }

        // Persist the revised payload.
        $worksheet->update(['generated_data' => $payload]);

        // Regenerate the DOCX artifact via the existing service. build() writes
        // the file AND updates $worksheet->filename under the hood.
        try {
            app(WorksheetDocxService::class)->build($payload, $worksheet);
        } catch (\Throwable $e) {
            Log::error('WorksheetEditAdapter::commitChanges docx build failed', [
                'id'    => $documentId,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        return $worksheet->fresh()->filename;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function findRoomIndex(array $payload, string $roomName): ?int
    {
        $needle = strtolower(trim($roomName));
        foreach ((array) ($payload['rooms'] ?? []) as $idx => $room) {
            $name = strtolower(trim((string) ($room['name'] ?? '')));
            if ($name === $needle) {
                return (int) $idx;
            }
        }
        return null;
    }

    /** @return array<string, array> */
    private function indexRoomsByName(array $payload): array
    {
        $out = [];
        foreach ((array) ($payload['rooms'] ?? []) as $room) {
            $name = trim((string) ($room['name'] ?? ''));
            if ($name !== '') $out[$name] = $room;
        }
        return $out;
    }

    /** Return per-room delta dict or null when nothing changed. */
    private function roomDelta(?array $before, ?array $after): ?array
    {
        $delta = [];

        $beforeTools = (array) ($before['tools'] ?? []);
        $afterTools  = (array) ($after['tools']  ?? []);
        $addedTools  = array_values(array_diff($afterTools, $beforeTools));
        $removedTools = array_values(array_diff($beforeTools, $afterTools));
        if ($addedTools)   $delta['tools_added']   = $addedTools;
        if ($removedTools) $delta['tools_removed'] = $removedTools;

        $beforeSteps = $this->stepsAsArray($before['install_steps'] ?? []);
        $afterSteps  = $this->stepsAsArray($after['install_steps'] ?? []);
        if (count($afterSteps) !== count($beforeSteps)) {
            $delta['install_steps_count_change'] = count($afterSteps) - count($beforeSteps);
        }
        $replaced = [];
        $commonLen = min(count($beforeSteps), count($afterSteps));
        for ($i = 0; $i < $commonLen; $i++) {
            if ($beforeSteps[$i] !== $afterSteps[$i]) {
                $replaced[] = $i;
            }
        }
        if ($replaced) $delta['install_steps_replaced_indices'] = $replaced;

        if (($before['category_summary'] ?? null) !== ($after['category_summary'] ?? null)) {
            $delta['category_summary_changed'] = true;
        }
        if (($before['room_works_description'] ?? null) !== ($after['room_works_description'] ?? null)) {
            $delta['room_works_description_changed'] = true;
        }

        return empty($delta) ? null : $delta;
    }

    private function stepsAsArray(mixed $steps): array
    {
        if (is_string($steps)) {
            return array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $steps))));
        }
        if (is_array($steps)) return array_values($steps);
        return [];
    }

    private function totalToolsCount(array $roomsByName): int
    {
        $n = 0;
        foreach ($roomsByName as $r) $n += count((array) ($r['tools'] ?? []));
        return $n;
    }

    private function totalInstallStepsCount(array $roomsByName): int
    {
        $n = 0;
        foreach ($roomsByName as $r) $n += count($this->stepsAsArray($r['install_steps'] ?? []));
        return $n;
    }
}
