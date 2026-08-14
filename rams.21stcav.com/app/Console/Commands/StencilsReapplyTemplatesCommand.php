<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DevicePort;
use App\Models\DeviceStencil;
use App\Services\Drawings\AutoGenericStencilGenerator;
use App\Services\Drawings\CategoryPortTemplateResolver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 24 Plan 08 (D-08) — the explicit, opt-in escape hatch for retroactively
 * re-applying `config('drawings.port_templates')` vocabulary fixes to
 * existing auto-generated stencils.
 *
 * Rationale: DeviceStencilCacheService::resolveForPartNumber's `firstOrCreate`
 * contract (Phase 21 D-03) never overwrites an existing row on cache hit —
 * which also freezes every stub on whatever port-template vocabulary was
 * current when it was first created. If `config/drawings.php`'s
 * `port_templates` / `port_template_precedence` later gains a new device
 * type or fixes a wrong keyword, already-stubbed devices don't pick it up
 * automatically. This command is that opt-in re-application.
 *
 * D-11: the 92 pre-existing zero-port `metadata.needs_phase_24_curation`
 * stubs from before Phase 24 are all `source = auto-generated` with zero
 * `device_stencil_audits` rows, so they already qualify under this command's
 * eligibility rule — no separate one-shot backfill command is needed.
 *
 * SAFETY (D-08, the heart of this command): eligibility is
 * `source = auto-generated` AND `whereDoesntHave('audits')`. ANY audit row
 * (promote / edit / discard-regenerate — Plan 24-01/24-07) permanently
 * removes a stencil from this command's reach, regardless of its current
 * `source` value. This guarantees the command can never touch anything an
 * engineer has edited or promoted.
 *
 * Dry-run by DEFAULT — mirrors PackagesReclassifyEquipmentCommand
 * (260725-qw3) and BackfillCablePortFksCommand (Phase 22). `--commit` opts
 * in to writes.
 *
 * Determinism / idempotence: CategoryPortTemplateResolver's `port_id` is
 * derived from `{connector_type}-{n}` (never UUID/time-derived — Plan 24-01
 * decision), and AutoGenericStencilGenerator::build() is a pure function of
 * its hints array. Re-running `--commit` against unchanged config therefore
 * produces byte-identical `mxgraph_xml` on the second pass — zero further
 * diffs.
 *
 * @see app/Models/DeviceStencil.php ::audits()
 * @see app/Services/Drawings/CategoryPortTemplateResolver.php
 * @see app/Services/Drawings/AutoGenericStencilGenerator.php
 * @see app/Services/QuoteImport/QuoteImportStencilStubber.php — bulk-insert shape this command reuses
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-CONTEXT.md (D-08, D-11)
 */
class StencilsReapplyTemplatesCommand extends Command
{
    protected $signature = 'stencils:reapply-templates
                            {--commit : Actually persist changes (default is dry-run)}';

    protected $description = 'Re-apply the current port_templates vocabulary to untouched auto-generated stencils (D-08). Dry-run by default.';

    public function handle(CategoryPortTemplateResolver $resolver, AutoGenericStencilGenerator $generator): int
    {
        $commit = (bool) $this->option('commit');

        $this->info($commit ? '── COMMIT MODE — changes will be persisted ──' : '── DRY-RUN MODE (default) — no writes ──');
        $this->newLine();

        // D-08 eligibility: still source=auto-generated AND zero audit rows.
        // See class docblock — this conjunction is the entire safety
        // guarantee of this command.
        $eligible = DeviceStencil::query()
            ->where('source', DeviceStencil::SOURCE_AUTO_GENERATED)
            ->whereDoesntHave('audits')
            ->withCount('ports')
            ->get();

        if ($eligible->isEmpty()) {
            $this->info('No eligible stencils (source=auto-generated with zero device_stencil_audits rows). Nothing to do.');

            return self::SUCCESS;
        }

        $this->line("Scanning {$eligible->count()} eligible stencil(s)...");
        $this->newLine();

        $reportRows = [];
        $changed = 0;

        foreach ($eligible as $stencil) {
            $portTemplate = $resolver->resolve(
                (string) ($stencil->display_name ?? ''),
                (string) $stencil->part_number,
            );

            $payload = $generator->build([
                'manufacturer' => $stencil->manufacturer,
                'model'        => $stencil->model,
                'name'         => $stencil->display_name,
                'part_number'  => $stencil->part_number,
                'ports'        => $portTemplate ?? [],
            ]);

            if ($payload['mxgraph_xml'] === $stencil->mxgraph_xml) {
                continue; // no drift for this stencil — nothing to report
            }

            $changed++;
            $oldPortCount = $stencil->ports_count;
            $newPortCount = count($portTemplate ?? []);

            $reportRows[] = [
                $stencil->id,
                $stencil->part_number,
                $oldPortCount,
                $newPortCount,
            ];

            if ($commit) {
                DB::transaction(function () use ($stencil, $payload, $portTemplate): void {
                    $stencil->update(['mxgraph_xml' => $payload['mxgraph_xml']]);

                    // Replace device_ports wholesale — delete existing rows,
                    // then bulk-insert the freshly-resolved template (same
                    // insert shape as QuoteImportStencilStubber::stubFromEquipmentLines,
                    // not duplicated differently).
                    DevicePort::where('device_stencil_id', $stencil->id)->delete();

                    if (! empty($portTemplate)) {
                        DevicePort::insert(array_map(
                            static fn (array $row): array => array_merge($row, [
                                'device_stencil_id' => $stencil->id,
                                'created_at'         => now(),
                                'updated_at'         => now(),
                            ]),
                            $portTemplate,
                        ));
                    }
                });

                Log::info('StencilsReapplyTemplatesCommand: stencil re-templated', [
                    'stencil_id'  => $stencil->id,
                    'part_number' => $stencil->part_number,
                    'old_ports'   => $oldPortCount,
                    'new_ports'   => $newPortCount,
                ]);
            }
        }

        if (empty($reportRows)) {
            $this->info('Every eligible stencil already matches the current template vocabulary. Nothing to change.');

            return self::SUCCESS;
        }

        $this->table(
            ['Stencil', 'Part Number', 'Old Ports', 'New Ports'],
            $reportRows,
        );

        $this->newLine();
        $this->line(sprintf('── Totals: %d stencil(s) affected', $changed));

        if (! $commit) {
            $this->newLine();
            $this->warn('DRY-RUN — no stencils were changed.');
            $this->line('Re-run with --commit to persist. Command is idempotent — running twice with --commit produces no additional diffs.');
        } else {
            $this->newLine();
            $this->info('Changes persisted. Re-run with --commit to verify idempotence (should show zero further diffs).');
        }

        return self::SUCCESS;
    }
}
