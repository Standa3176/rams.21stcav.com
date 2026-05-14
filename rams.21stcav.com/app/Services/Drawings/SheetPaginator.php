<?php

namespace App\Services\Drawings;

use App\Models\Project;
use Illuminate\Support\Facades\Log;

/**
 * Phase 23 — Multi-sheet paginator (DRAW-47).
 *
 * Classifies the project into a list of sheets the Plan 23-05 orchestrator
 * wraps in a single `<mxfile>` envelope. Always emits the system_overview
 * sheet first; audio / video / control / network sub-sheets emit only when
 * BOTH D-06 thresholds are met:
 *
 *   - cables-of-signal-type  >= config('drawings.sub_sheet_thresholds.min_cables_per_signal')
 *   - distinct-devices-touching-signal >= config('drawings.sub_sheet_thresholds.min_devices_touching_signal')
 *
 * Engineer tinker override:
 *   Project.metadata.force_sheets = ['audio', 'video', ...]
 * forces sub-sheets regardless of threshold (D-06 deferred-UI escape hatch).
 * Phase 24 ships the proper toggle UI.
 *
 * Validation rules (T-23-04-A2):
 *   - non-array force_sheets → log warning + ignore (treat as empty)
 *   - entries that are not strings → silently dropped
 *   - entries not in {audio, video, control, network} → silently dropped
 *
 * Pure read-only — NO Eloquent writes, NO AI calls, deterministic.
 * Phase 22 D-10 forbids class-level `$with`; uses loadMissing at call site.
 *
 * @see .planning/phases/23-xten-av-style-renderer/23-CONTEXT.md D-06
 * @see .planning/phases/23-xten-av-style-renderer/23-RESEARCH.md Example 4 (mxfile multi-page)
 */
class SheetPaginator
{
    /**
     * Canonical sub-sheet ordering. Output order is: system_overview, then
     * any subset of these in this exact sequence. Force_sheets metadata can
     * include them in any order — output normalises to this canonical list.
     */
    private const VALID_SUB_SHEETS = ['audio', 'video', 'control', 'network'];

    /**
     * Classify the project into per-sheet descriptors.
     *
     * @return array<int, array{key: string, sheet_number: string, title: string, signal_filter: ?string}>
     */
    public function classify(Project $project): array
    {
        $sheets = [];

        // System overview sheet always emits (sheet 1).
        $sheets[] = [
            'key'           => 'system_overview',
            'sheet_number'  => (string) (config('drawings.sheet_number_format.system_overview') ?? 'AV-201'),
            'title'         => 'System Overview',
            'signal_filter' => null,
        ];

        $forced = $this->forcedSheets($project);
        $thresholds = (array) config('drawings.sub_sheet_thresholds', []);
        $minCables  = (int) ($thresholds['min_cables_per_signal'] ?? 5);
        $minDevices = (int) ($thresholds['min_devices_touching_signal'] ?? 3);

        // Call-site eager-load (Phase 22 D-10 — never class-level $with).
        $project->loadMissing([
            'cableSchedules.items.sourcePort',
            'cableSchedules.items.destPort',
        ]);

        // Walk canonical sub-sheet order; emit each that meets the threshold
        // OR is forced via metadata. Force_sheets does NOT change ordering —
        // the canonical sequence (audio → video → control → network) is the
        // contract Plan 23-05 + Plan 23-07 verifier rely on.
        foreach (self::VALID_SUB_SHEETS as $signal) {
            $emit = in_array($signal, $forced, true)
                || $this->meetsThreshold($project, $signal, $minCables, $minDevices);

            if (! $emit) {
                continue;
            }

            $sheets[] = [
                'key'           => $signal,
                'sheet_number'  => (string) (config("drawings.sheet_number_format.{$signal}") ?? 'AV-2XX'),
                'title'         => ucfirst($signal).' Subsystem',
                'signal_filter' => $signal,
            ];
        }

        return $sheets;
    }

    /**
     * Read + validate Project.metadata.force_sheets.
     *
     * Mitigates T-23-04-A2 — non-array / non-string / unknown signal_type
     * entries are silently dropped. Non-array root is logged as warning so
     * tinker-typos get spotted in Phase 24 telemetry.
     *
     * @return array<int, string>
     */
    private function forcedSheets(Project $project): array
    {
        $raw = is_array($project->metadata) ? ($project->metadata['force_sheets'] ?? null) : null;

        if (! is_array($raw)) {
            if ($raw !== null) {
                Log::warning('SheetPaginator: force_sheets is not an array — ignoring', [
                    'project_id' => $project->id,
                    'type'       => gettype($raw),
                    'value'      => is_scalar($raw) ? (string) $raw : null,
                ]);
            }

            return [];
        }

        $valid = [];
        foreach ($raw as $entry) {
            if (! is_string($entry)) {
                continue;
            }
            if (in_array($entry, self::VALID_SUB_SHEETS, true)) {
                $valid[] = $entry;
            }
        }

        return $valid;
    }

    /**
     * BOTH-and-AND threshold check per D-06 — sub-sheet emits only when:
     *   - count(cables where source-or-dest port has signal_type == $signal
     *     AND at least one device FK is set) >= $minCables
     *   - count(distinct device ids touching such ports) >= $minDevices
     */
    private function meetsThreshold(Project $project, string $signal, int $minCables, int $minDevices): bool
    {
        // Flatten all schedule items across all schedules into one iterable.
        $items = $project->cableSchedules->flatMap(fn ($s) => $s->items);

        $cableCount = 0;
        $deviceIds = [];

        foreach ($items as $item) {
            $srcSignal = $item->sourcePort?->signal_type;
            $dstSignal = $item->destPort?->signal_type;
            $touches = $srcSignal === $signal || $dstSignal === $signal;

            if (! $touches) {
                continue;
            }

            // Pure-text legacy rows (both device_ids NULL) don't count for paginator
            // threshold — they render via the v1.3 surface per Phase 22 D-10.
            if ($item->source_device_id === null && $item->dest_device_id === null) {
                continue;
            }

            $cableCount++;

            if ($item->source_device_id !== null) {
                $deviceIds[$item->source_device_id] = true;
            }
            if ($item->dest_device_id !== null) {
                $deviceIds[$item->dest_device_id] = true;
            }
        }

        return $cableCount >= $minCables && count($deviceIds) >= $minDevices;
    }
}
