<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DeviceStencil;
use App\Models\ProjectPackage;
use App\Services\Imports\EquipmentCategoryClassifier;
use Illuminate\Console\Command;

/**
 * Phase 24 Plan 08 — stencils:coverage-report.
 *
 * Read-only. Ranks part_numbers by REAL quote-import occurrence (hardware
 * lines only) and reports each top-N entry's current Tier 1 (auto-generated
 * or missing entirely) vs Tier 2 (engineer-curated) status. This is the
 * audit-trail input for Plan 24-09's bounded top-10 curation fill and for
 * ROADMAP criterion 5.
 *
 * Provenance (Phase 21 D-15 independence rule): the ranking is derived from
 * a LIVE DB query over `ProjectPackage.extracted_data['equipment']` —
 * exactly like `SeedPackCoverageTest`'s preferred source (D-15 priority 1).
 * It NEVER reads the on-disk stencil seed pack under `resources/data/` —
 * deriving the "what needs curating" ranking from that same seed pack would
 * make the coverage assertion circular (you'd be asserting the seed pack
 * covers itself).
 *
 * Hardware-only filtering mirrors QuoteImportStencilStubber::stubFromEquipmentLines
 * exactly — always re-classifies through the shared EquipmentCategoryClassifier
 * rather than trusting any one import shape's `category` key directly, so a
 * part_number that only ever appears on cable/service/consumable lines never
 * pollutes the ranking.
 *
 * @see app/Services/QuoteImport/QuoteImportStencilStubber.php
 * @see app/Services/Imports/EquipmentCategoryClassifier.php
 * @see .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md (D-15)
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-08-PLAN.md
 */
class StencilsCoverageReportCommand extends Command
{
    protected $signature = 'stencils:coverage-report
                            {--limit=10 : How many top part_numbers to report}';

    protected $description = 'Rank part_numbers by live quote-import occurrence (hardware only) and report Tier 1/2 curation status — feeds Plan 24-09.';

    public function handle(EquipmentCategoryClassifier $classifier): int
    {
        $limit = max(1, (int) $this->option('limit'));

        // Live DB query over real quote-import data — independent of the
        // seed pack by construction (D-15). No file under the on-disk
        // stencil seed pack directory is ever read by this command.
        $packages = ProjectPackage::query()->whereNotNull('extracted_data')->get();

        if ($packages->isEmpty()) {
            $this->info('No packages with extracted_data found. Nothing to report.');

            return self::SUCCESS;
        }

        $occurrences = [];

        foreach ($packages as $package) {
            $lines = $package->extracted_data['equipment'] ?? $package->equipment_list ?? [];
            if (! is_array($lines)) {
                continue;
            }

            foreach ($lines as $line) {
                if (! is_array($line)) {
                    continue;
                }

                $partNumber = trim((string) ($line['part_number'] ?? $line['sku'] ?? ''));
                if ($partNumber === '') {
                    continue;
                }

                $name = trim((string) ($line['name'] ?? $line['description'] ?? ''));

                // Always re-classify through the shared classifier — never
                // trust an upstream category key directly (mirrors
                // QuoteImportStencilStubber's own rationale). The
                // classifier's own explicit-value short-circuit still
                // respects a genuinely canonical category when present.
                $category = $classifier->classify([
                    'category'    => $line['category'] ?? null,
                    'name'        => $name,
                    'description' => (string) ($line['description'] ?? $name),
                    'part_number' => $partNumber,
                ]);

                if ($category !== 'hardware') {
                    continue;
                }

                $key = DeviceStencil::normalisePartNumber($partNumber);
                $occurrences[$key] = ($occurrences[$key] ?? 0) + 1;
            }
        }

        if (empty($occurrences)) {
            $this->info('No hardware-category equipment lines found across any package. Nothing to report.');

            return self::SUCCESS;
        }

        arsort($occurrences);
        $top = array_slice($occurrences, 0, $limit, true);

        $stencilsByPartNumber = DeviceStencil::query()
            ->whereIn('part_number', array_keys($top))
            ->withCount('ports')
            ->get()
            ->keyBy('part_number');

        $reportRows = [];
        $tier1Count = 0;

        foreach ($top as $partNumber => $count) {
            $stencil = $stencilsByPartNumber->get($partNumber);

            // Tier 2 = promoted to engineer-curated. Tier 1 = still the
            // auto-generated placeholder, OR no stencil exists at all yet.
            $isTier2 = $stencil !== null && $stencil->source === DeviceStencil::SOURCE_ENGINEER_CURATED;
            if (! $isTier2) {
                $tier1Count++;
            }

            $reportRows[] = [
                $partNumber,
                $count,
                $isTier2 ? 'Tier 2' : 'Tier 1',
                $stencil?->ports_count ?? 0,
            ];
        }

        $this->table(
            ['Part Number', 'Quote Occurrences', 'Tier', 'Ports'],
            $reportRows,
        );

        $this->newLine();
        $this->line(sprintf(
            '── %d of the top %d part_number(s) are still Tier 1 (auto-generated or uncatalogued) ──',
            $tier1Count,
            count($top),
        ));

        return self::SUCCESS;
    }
}
