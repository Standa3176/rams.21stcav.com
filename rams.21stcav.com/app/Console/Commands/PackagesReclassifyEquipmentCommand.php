<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\ProjectPackage;
use App\Services\Imports\EquipmentCategoryClassifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Retroactively re-classify equipment rows on already-imported packages
 * so they use the canonical 7-value vocabulary from 260725-qw3.
 *
 * Runs the shared EquipmentCategoryClassifier over every row in
 * `extracted_data.equipment[]` (and its aliases `equipment_list` / `line_items`
 * where present, plus the soft-delete graveyard `equipment_deleted[]` from
 * 260723-eq1) and applies the same non-room section-header re-routing
 * QuoteWerksImportService now runs at import time.
 *
 * Symptoms of the pre-fix pollution (see .planning/quick/260725-qw3):
 *   - QW-imported categories are fabricated values (`display`, `audio`,
 *     `cable`, `mounting`, `signal_distribution`, `control`, `furniture`,
 *     `service`, `other`) — the review UI treats them all as `hardware`.
 *   - Non-room section headers ("Professional Services", "Room Booking
 *     Panels", "Delivery", "Summary") leak into the `area` field as
 *     fake rooms.
 *
 * Safety:
 *   - Default is DRY-RUN. --commit actually persists.
 *   - Idempotent: re-running with --commit yields zero further diffs.
 *   - Untouched packages (already-correct classifications, or non-QW
 *     imports where categories were already canonical) are silently
 *     skipped.
 *   - Only mutates the equipment-row keys `category`, `area`, `location`.
 *     Never touches quantities, prices, descriptions, part numbers.
 */
class PackagesReclassifyEquipmentCommand extends Command
{
    protected $signature = 'packages:reclassify-equipment
                            {package? : Package ID (optional; without = all packages)}
                            {--commit : Actually persist changes (default is dry-run)}';

    protected $description = 'Retroactively re-classify equipment rows using the canonical EquipmentCategoryClassifier (260725-qw3 alignment)';

    /**
     * QW section-header patterns re-routed by QuoteWerksImportService.
     * Kept in sync with QuoteWerksImportService::NON_ROOM_SECTION_PATTERNS.
     *
     * @var array<string,string|null>
     */
    private const NON_ROOM_SECTION_PATTERNS = [
        '/professional\s+services?/i' => 'services',
        '/^\s*services?\s*$/i'        => 'services',
        '/^\s*labour\s*$/i'           => 'services',
        '/^\s*delivery\s*$/i'         => 'services',
        '/^\s*consumables?\s*$/i'     => 'consumables',
        '/^\s*summary\s*$/i'          => null,
        '/room\s+booking\s+panels?/i' => null,
    ];

    /** Equipment array keys that carry equipment rows on ProjectPackage.extracted_data. */
    private const EQUIPMENT_KEYS = ['equipment', 'equipment_list', 'line_items', 'equipment_deleted'];

    public function handle(EquipmentCategoryClassifier $classifier): int
    {
        $commit    = (bool) $this->option('commit');
        $packageId = $this->argument('package');

        $this->info($commit ? '── COMMIT MODE — changes will be persisted ──' : '── DRY-RUN MODE (default) — no writes ──');
        $this->newLine();

        $query = ProjectPackage::query()->with('project');
        if ($packageId) {
            $query->where('id', $packageId);
        }

        $packages = $query->get();

        if ($packages->isEmpty()) {
            $this->warn($packageId
                ? "No package #{$packageId} found."
                : 'No packages in the database. Nothing to do.');
            return self::SUCCESS;
        }

        $this->line("Scanning {$packages->count()} package(s)...");
        $this->newLine();

        $totalRowsScanned   = 0;
        $totalRowsChanged   = 0;
        $totalAreasCleared  = 0;
        $packagesChanged    = 0;
        $reportRows         = [];

        foreach ($packages as $package) {
            $result = $this->reclassifyPackage($package, $classifier);

            $totalRowsScanned  += $result['rows_scanned'];
            $totalRowsChanged  += $result['rows_recategorised'];
            $totalAreasCleared += $result['areas_cleared'];

            if ($result['rows_recategorised'] === 0 && $result['areas_cleared'] === 0) {
                continue; // clean — no report row
            }

            $packagesChanged++;
            $reportRows[] = [
                'pkg_id'       => $package->id,
                'project'      => mb_substr((string) ($package->project?->name ?? '—'), 0, 30),
                'scanned'      => $result['rows_scanned'],
                'recategorised' => $result['rows_recategorised'],
                'areas_cleared' => $result['areas_cleared'],
                'category_diff' => $this->summariseCategoryDiff($result['category_diff']),
            ];

            if ($commit) {
                $package->update([
                    'extracted_data' => $result['new_extracted_data'],
                ]);

                Log::info('PackagesReclassifyEquipmentCommand: package recategorised', [
                    'package_id'         => $package->id,
                    'rows_recategorised' => $result['rows_recategorised'],
                    'areas_cleared'      => $result['areas_cleared'],
                    'category_diff'      => $result['category_diff'],
                ]);
            }
        }

        if (empty($reportRows)) {
            $this->info('Every package already carries canonical classifications. Nothing to change.');
            return self::SUCCESS;
        }

        $this->table(
            ['Pkg', 'Project', 'Scanned', 'Recategorised', 'Areas cleared', 'Category diff'],
            $reportRows,
        );

        $this->newLine();
        $this->line(sprintf(
            '── Totals: %d package(s) affected · %d row(s) recategorised · %d area(s) cleared · %d row(s) scanned',
            $packagesChanged,
            $totalRowsChanged,
            $totalAreasCleared,
            $totalRowsScanned,
        ));

        if (! $commit) {
            $this->newLine();
            $this->warn('DRY-RUN — no packages were changed.');
            $this->line('Re-run with --commit to persist. Command is idempotent — running twice with --commit produces no additional diffs.');
        } else {
            $this->newLine();
            $this->info('Changes persisted. Re-run with --commit to verify idempotence (should show zero further diffs).');
        }

        return self::SUCCESS;
    }

    /**
     * Reclassify one package. Returns per-package stats + the new extracted_data
     * shape (only mutated where diffs were found).
     *
     * @return array{
     *   rows_scanned: int,
     *   rows_recategorised: int,
     *   areas_cleared: int,
     *   category_diff: array<string,int>,
     *   new_extracted_data: array<string,mixed>
     * }
     */
    private function reclassifyPackage(ProjectPackage $package, EquipmentCategoryClassifier $classifier): array
    {
        $extracted    = (array) ($package->extracted_data ?? []);
        $rowsScanned  = 0;
        $rowsChanged  = 0;
        $areasCleared = 0;
        $catDiff      = []; // "old→new" => count

        foreach (self::EQUIPMENT_KEYS as $key) {
            if (empty($extracted[$key]) || ! is_array($extracted[$key])) {
                continue;
            }

            $newRows = [];
            foreach ($extracted[$key] as $row) {
                $rowsScanned++;
                if (! is_array($row)) {
                    $newRows[] = $row;
                    continue;
                }

                $oldCategory = (string) ($row['category'] ?? '');
                $oldArea     = (string) ($row['area']     ?? '');
                $oldLocation = (string) ($row['location'] ?? '');

                // 1. Classify from name/description/part_number.
                //    We DON'T pass $row['category'] to the classifier because
                //    fabricated values ('display', 'audio', 'cable', …) would
                //    short-circuit the canonical fallback. Only re-honour
                //    canonical values below via a second guard.
                $classifiable = $row;
                unset($classifiable['category']);
                $newCategory = $classifier->classify($classifiable);

                // 2. Respect already-canonical categories (someone manually
                //    picked service_contracts / customer_supplied etc. via
                //    the dropdown — do NOT clobber those with a keyword guess).
                if (in_array(strtolower(trim($oldCategory)), EquipmentCategoryClassifier::CATEGORIES, true)) {
                    $newCategory = strtolower(trim($oldCategory));
                }

                // 3. Apply non-room section-header re-routing (mirrors
                //    QuoteWerksImportService::applySectionHeaderReroute).
                $newArea     = $oldArea;
                $newLocation = $oldLocation;
                foreach (self::NON_ROOM_SECTION_PATTERNS as $pattern => $forcedCategory) {
                    if ($newArea !== '' && preg_match($pattern, $newArea) === 1) {
                        $locationWasDefaultedFromArea = ($newLocation === $newArea);
                        $newArea     = '';
                        $newLocation = $locationWasDefaultedFromArea ? '' : $newLocation;
                        if ($forcedCategory !== null) {
                            $newCategory = $forcedCategory;
                        }
                        break;
                    }
                }

                if ($newCategory !== $oldCategory) {
                    $rowsChanged++;
                    $diffKey = ($oldCategory === '' ? '(empty)' : $oldCategory).'→'.$newCategory;
                    $catDiff[$diffKey] = ($catDiff[$diffKey] ?? 0) + 1;
                }

                if ($newArea !== $oldArea && $oldArea !== '') {
                    $areasCleared++;
                }

                $row['category'] = $newCategory;
                $row['area']     = $newArea;
                $row['location'] = $newLocation;
                $newRows[]       = $row;
            }

            $extracted[$key] = $newRows;
        }

        return [
            'rows_scanned'       => $rowsScanned,
            'rows_recategorised' => $rowsChanged,
            'areas_cleared'      => $areasCleared,
            'category_diff'      => $catDiff,
            'new_extracted_data' => $extracted,
        ];
    }

    /**
     * Compress a diff map into a single string for the report table.
     */
    private function summariseCategoryDiff(array $catDiff): string
    {
        if (empty($catDiff)) {
            return '—';
        }

        $bits = [];
        foreach ($catDiff as $transition => $count) {
            $bits[] = "{$transition}×{$count}";
        }

        return mb_substr(implode(', ', $bits), 0, 60);
    }
}
