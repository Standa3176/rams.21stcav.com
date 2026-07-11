<?php

namespace App\Console\Commands;

use App\Models\CableScheduleItem;
use App\Models\Device;
use App\Services\Cable\CablePortFkResolverService;
use App\Services\Cable\StencilPortResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * cables:backfill-port-fks
 *
 * Phase 22 Plan 03 — one-shot artisan command that populates port FKs on
 * existing cable_schedule_items where the from_location / to_location text
 * resolves deterministically against the project's catalogued devices.
 *
 * Dry-run by DEFAULT (mirrors RamsRefreshComplianceCommand --dry-run, but
 * FLIPS the default per CONTEXT.md D-LOCK): --apply opts in to writes. This
 * is a safer default than rams:refresh-compliance because the writes here
 * are spread across N rows × M projects rather than a single document.
 *
 * Per-row outcome categories (CONTEXT.md D-LOCK):
 *   - matched         : both sides resolved deterministically → all 4 FKs
 *                       written atomically inside a DB::transaction
 *   - ambiguous       : multiple matches possible on at least one side →
 *                       all 4 FKs left NULL (D-LOCK + DRAW-41). NO PARTIAL
 *                       WRITES — even when the resolver reports source
 *                       resolved but dest ambiguous, the row stays wholly
 *                       NULL. See feature test
 *                       test_ambiguous_overall_leaves_all_four_fks_null.
 *   - no-device-match : neither side text resolved to a catalogued device →
 *                       all 4 FKs left NULL
 *   - already-set     : item already has source_device_id or dest_device_id
 *                       populated → skip entirely (resolver not called,
 *                       no overwrite). Re-running the command on already-
 *                       backfilled rows is therefore idempotent.
 *
 * Auto-fire on quote import: NOT implemented in Phase 22 (D-LOCK deferred
 * to v2.1). CableScheduleGeneratorService (app/Services/
 * CableScheduleGeneratorService.php line 88-98) is UNTOUCHED. Engineers
 * trigger this command manually after a quote import, review the dry-run
 * report, then --apply.
 *
 * Stencil attachment: the Device model has no native belongsTo(DeviceStencil)
 * relation — stencils are resolved by NORMALISED part_number, not FK. This
 * command pre-loads stencils for the project and attaches them to each
 * Device via setRelation('stencil', $stencil) so the resolver's
 * `$device->stencil->ports` access works without DB hits inside the loop.
 *
 * T-22-A5 (SQL injection via --project arg, RESEARCH.md §"Security Domain"):
 * the arg is cast to int via `(int) $this->argument('project')`. Eloquent's
 * where('project_id', $projectId) uses PDO parameterised binding. Net: arg
 * "5; DROP TABLE devices;" parses as the integer 5 (PHP cast semantics —
 * junk after the first non-numeric char is silently dropped). devices table
 * cannot be dropped.
 *
 * T-22-A6 (wrong-tenant write): impossible by construction. The matcher
 * loads ONLY devices belonging to each cable item's cable_schedule.project_id
 * inside the iteration loop. Cross-project text matches are impossible —
 * even when Project A has a Crestron device whose name happens to match
 * Project B's cable text, the matcher never sees it because the iteration
 * scopes Device::where('project_id', $scheduleProjectId) per row.
 *
 * Usage:
 *   php artisan cables:backfill-port-fks            # dry-run, all projects
 *   php artisan cables:backfill-port-fks --apply    # write, all projects
 *   php artisan cables:backfill-port-fks 5          # dry-run, project 5
 *   php artisan cables:backfill-port-fks 5 --apply  # write, project 5
 *
 * Admin-only by convention (CLI access = admin per RESEARCH.md
 * §"Security Domain"). Do NOT expose via HTTP.
 *
 * @see app/Services/Cable/CablePortFkResolverService.php
 * @see app/Services/CableScheduleGeneratorService.php (UNTOUCHED — D-LOCK)
 */
class BackfillCablePortFksCommand extends Command
{
    protected $signature = 'cables:backfill-port-fks
                            {project? : Project ID to scope the backfill (default: all projects)}
                            {--apply : Actually write port FKs (default: dry-run reports only)}';

    protected $description = 'Resolve and populate port-level FKs on cable_schedule_items where deterministic. Idempotent and dry-run by default.';

    public function __construct(
        private readonly CablePortFkResolverService $resolver,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        // T-22-A5: int cast neutralises SQL injection on the project arg.
        $projectArg = $this->argument('project');
        $projectId  = $projectArg !== null ? (int) $projectArg : null;

        $apply = (bool) $this->option('apply');

        if (! $apply) {
            $this->info('[DRY RUN] cables:backfill-port-fks — pass --apply to persist.');
        } else {
            $this->info('cables:backfill-port-fks — APPLYING writes.');
        }

        $itemsQuery = CableScheduleItem::query()
            ->with(['schedule:id,project_id'])
            ->orderBy('cable_schedule_id')
            ->orderBy('sort_order');

        if ($projectId !== null) {
            $itemsQuery->whereHas('schedule', fn ($q) => $q->where('project_id', $projectId));
        }

        $items = $itemsQuery->get();

        if ($items->isEmpty()) {
            $this->info('No cable_schedule_items found.');
            return self::SUCCESS;
        }

        $summary = [
            'matched'         => 0,
            'ambiguous'       => 0,
            'no-device-match' => 0,
            'already-set'     => 0,
            'wrote'           => 0,
        ];

        // Per-project device cache (avoid N+1 across rows). Devices are pre-
        // enriched with their matched DeviceStencil so the resolver can
        // access $device->stencil->ports without DB hits.
        $devicesByProject = [];

        foreach ($items as $item) {
            $scheduleProjectId = $item->schedule->project_id ?? null;
            if ($scheduleProjectId === null) {
                $summary['no-device-match']++;
                $this->line("  #{$item->id} (no project) — skipped");
                continue;
            }

            // Already-set guard (D-LOCK 'already-set' category — skip without
            // calling the resolver, idempotency invariant).
            if ($item->source_device_id !== null || $item->dest_device_id !== null) {
                $summary['already-set']++;
                $this->line("  #{$item->id} — already-set, skipped");
                continue;
            }

            // T-22-A6 mitigation: load ONLY devices for this project; attach
            // stencils via setRelation so the resolver reads $device->stencil
            // ->ports without further DB hits.
            if (! isset($devicesByProject[$scheduleProjectId])) {
                $devicesByProject[$scheduleProjectId] = $this->loadProjectDevicesWithStencils($scheduleProjectId);
            }

            $decision = $this->resolver->resolve($item, $devicesByProject[$scheduleProjectId]);

            $tag = $decision['match'];
            $summary[$tag] = ($summary[$tag] ?? 0) + 1;

            $this->line(sprintf(
                '  #%d — %s: %s',
                $item->id,
                $tag,
                $decision['reason'] ?? ''
            ));

            // CRITICAL W5 / D-LOCK / DRAW-41: writes happen ONLY when overall
            // match is 'matched'. Ambiguous and no-device-match leave ALL FOUR
            // FKs NULL — NO partial writes even when the resolver's return
            // shape carries diagnostic data for one side. See the feature
            // test test_ambiguous_overall_leaves_all_four_fks_null.
            if ($apply && $tag === 'matched') {
                DB::transaction(function () use ($item, $decision) {
                    $item->update([
                        'source_device_id' => $decision['source_device_id'],
                        'source_port_id'   => $decision['source_port_id'],
                        'dest_device_id'   => $decision['dest_device_id'],
                        'dest_port_id'     => $decision['dest_port_id'],
                    ]);
                });
                $summary['wrote']++;
            }
        }

        $this->newLine();
        $this->info('Summary:');
        $this->line(sprintf(
            '  matched: %d  |  ambiguous: %d  |  no-device-match: %d  |  already-set: %d  |  wrote: %d',
            $summary['matched'],
            $summary['ambiguous'],
            $summary['no-device-match'],
            $summary['already-set'],
            $summary['wrote'],
        ));

        Log::info('cables:backfill-port-fks completed', [
            'project_id' => $projectId,
            'apply'      => $apply,
            'summary'    => $summary,
        ]);

        return self::SUCCESS;
    }

    /**
     * Load the project's devices with their DeviceStencil (with ports) attached
     * via the shared StencilPortResolver. Thin wrapper — the resolver owns
     * the canonical normalised-part_number bulk-lookup shape.
     *
     * @return \Illuminate\Support\Collection<int, Device>
     */
    private function loadProjectDevicesWithStencils(int $projectId): \Illuminate\Support\Collection
    {
        $devices = Device::query()
            ->where('project_id', $projectId)
            ->get();

        return app(StencilPortResolver::class)->attachToDevices($devices);
    }
}
