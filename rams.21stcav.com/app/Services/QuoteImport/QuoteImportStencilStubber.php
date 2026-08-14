<?php

namespace App\Services\QuoteImport;

use App\Models\DevicePort;
use App\Models\DeviceStencil;
use App\Services\Drawings\CategoryPortTemplateResolver;
use App\Services\Drawings\DeviceStencilCacheService;
use App\Services\Imports\EquipmentCategoryClassifier;

/**
 * Phase 24 Plan 02 — single shared auto-stub orchestration service (D-09).
 *
 * Moves device_stencils/device_ports creation from Phase 21's lazy
 * render-time fallback (Project::devicesWithStencils()) to import time, and
 * makes it richer — a resolved port template instead of a bare zero-port
 * shell — so Plan 24-03's admin review queue is already populated before
 * anyone opens a drawing.
 *
 * Called from all THREE quote-import call sites (D-09):
 *   - App\Jobs\ExtractQuoteJob (PDF upload path)
 *   - App\Core\Modules\QuoteImport\QuoteWerksImportService::buildExtractedData
 *     (QuoteWerks-direct / default import route since 260725-qw4)
 *   - App\Jobs\ReimportQuoteJob (re-import path)
 *
 * Each call site's equipment-line shape differs (see 24-02-PLAN.md
 * <interfaces>) — this service normalises all of them via
 * `$line['part_number'] ?? $line['sku'] ?? ''` and
 * `$line['name'] ?? $line['description'] ?? ''`, and ALWAYS re-classifies
 * through the shared EquipmentCategoryClassifier rather than trusting any
 * one shape's `category` key directly (the classifier's own explicit-value
 * short-circuit still respects an already-canonical category when present).
 *
 * Determinism (D-06/D-07): port-template resolution is delegated entirely
 * to CategoryPortTemplateResolver — this service never guesses. An
 * ambiguous/unrecognised device type resolves to a zero-port stub, still
 * flagged needs_review by DeviceStencilCacheService.
 *
 * Idempotency (Phase 21 D-03): stencil resolution always goes through
 * DeviceStencilCacheService::resolveForPartNumber's firstOrCreate cache
 * contract — never a direct insert. Port-row insertion only happens on
 * genuine cache-miss (`$stencil->wasRecentlyCreated`), so re-importing the
 * same quote never duplicates stencils or ports.
 *
 * Never wrapped in DB::transaction — mirrors
 * DeviceStencilCacheService::resolveForPartNumber's documented race-safety
 * contract (21 D-03, RESEARCH.md Pitfall 5): the unique index on
 * device_stencils.part_number is the race-safety mechanism, not an
 * application-level lock.
 *
 * @see app/Services/Drawings/DeviceStencilCacheService.php
 * @see app/Services/Drawings/CategoryPortTemplateResolver.php
 * @see app/Services/Imports/EquipmentCategoryClassifier.php
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-CONTEXT.md (D-06, D-07, D-09)
 */
class QuoteImportStencilStubber
{
    public function __construct(
        private readonly CategoryPortTemplateResolver $templateResolver,
        private readonly DeviceStencilCacheService $cache,
        private readonly EquipmentCategoryClassifier $classifier,
    ) {}

    /**
     * Stub device_stencils/device_ports rows for every hardware-category
     * line in `$lines`. Cables/services/consumables/etc. never produce a
     * stencil row (mirrors Project::devicesWithStencils()'s own D-07 filter
     * contract — Phase 21 CONTEXT.md D-07).
     *
     * @param  array<int, array<string, mixed>>  $lines
     * @return array{created: int, stencils: array<int, DeviceStencil>}
     */
    public function stubFromEquipmentLines(array $lines): array
    {
        $created = 0;
        $stencils = [];

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $partNumber = trim((string) ($line['part_number'] ?? $line['sku'] ?? ''));
            if ($partNumber === '') {
                continue;
            }

            $name = trim((string) ($line['name'] ?? $line['description'] ?? ''));

            $category = $this->classifier->classify([
                'category'    => $line['category'] ?? null,
                'name'        => $name,
                'description' => (string) ($line['description'] ?? $name),
                'part_number' => $partNumber,
            ]);

            if ($category !== 'hardware') {
                continue;
            }

            $portTemplate = $this->templateResolver->resolve($name, $partNumber);

            $stencil = $this->cache->resolveForPartNumber($partNumber, [
                'manufacturer' => $line['manufacturer'] ?? null,
                'model'        => $line['model'] ?? null,
                'name'         => $name,
                'part_number'  => $partNumber,
                'ports'        => $portTemplate ?? [],
            ]);

            if ($stencil->wasRecentlyCreated && ! empty($portTemplate)) {
                // Bulk insert — a raw insert(), not create(), for one
                // statement per stubbed device. insert() bypasses Eloquent's
                // auto-timestamps, so created_at/updated_at are set manually.
                DevicePort::insert(array_map(
                    static fn (array $row): array => array_merge($row, [
                        'device_stencil_id' => $stencil->id,
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ]),
                    $portTemplate
                ));
            }

            if ($stencil->wasRecentlyCreated) {
                $stencils[] = $stencil;
                $created++;
            }
        }

        return ['created' => $created, 'stencils' => $stencils];
    }
}
