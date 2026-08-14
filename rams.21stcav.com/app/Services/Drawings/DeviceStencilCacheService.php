<?php

namespace App\Services\Drawings;

use App\Models\DeviceStencil;
use Illuminate\Support\Facades\Log;

/**
 * Phase 21 Plan 01 — cross-project stencil cache (per CONTEXT.md D-03).
 *
 * The renderer ALWAYS calls through this service; never instantiates an
 * auto-generic stencil directly. That guarantees:
 *
 *   1. First reference to a fresh part_number on ANY project persists a
 *      Tier 1 placeholder. Subsequent calls (same project OR any other
 *      project) hit the cached row — no duplicate insert.
 *
 *   2. When Phase 24 promotes a stencil to engineer-curated, every project
 *      that referenced the same part_number automatically picks up the
 *      upgrade on the next render. Cross-project propagation is the
 *      desired contract (T-21.01-02 acceptance: part_numbers + manufacturer
 *      + model are not customer secrets — they ship on every quote PDF).
 *
 * SIDE EFFECT: this service MUTATES the database on cache miss (inserts a
 * row). Subsequent calls for the same part_number are pure SELECTs.
 *
 * Mirrors DeviceCatalogService's case-insensitive trimmed lookup semantics —
 * but writes through firstOrCreate instead of read-only access to the JSON
 * pack.
 *
 * @see app/Models/DeviceStencil.php
 * @see app/Services/Drawings/AutoGenericStencilGenerator.php
 * @see app/Services/Drawings/DeviceCatalogService.php — sibling read-only service
 * @see .planning/phases/21-device-port-catalog-stencil-cache/21-CONTEXT.md (D-03)
 */
class DeviceStencilCacheService
{
    public function __construct(private AutoGenericStencilGenerator $generator) {}

    /**
     * Resolve (or create-on-miss) the DeviceStencil for a part_number.
     *
     * Hints are passed to AutoGenericStencilGenerator::build() ONLY on cache
     * miss — they're metadata for the Tier 1 placeholder, not lookup keys.
     * The cache is keyed solely on the normalised part_number.
     *
     * NOTE: NOT wrapped in DB::transaction. Race-safety is provided by the
     * unique index on device_stencils.part_number — concurrent first-calls
     * on a fresh part_number race the INSERT; the loser hits a UNIQUE-
     * violation QueryException which Eloquent's firstOrCreate catches and
     * retries as a SELECT (Laravel core behaviour). Net: exactly one row,
     * no data loss.
     *
     * Stencil rows are read-only after creation from this service's
     * perspective; Phase 24 curation upgrades update an existing row (no
     * new insert race). Wrapping this call in a transaction would block on
     * the unique index without benefit and would actually HURT throughput
     * under concurrent renderer hits (T-21.01-03 mitigation).
     *
     * @param  array{manufacturer?:?string, model?:?string, name?:?string, part_number?:?string}  $hints
     */
    public function resolveForPartNumber(string $partNumber, array $hints = []): DeviceStencil
    {
        $normalised = DeviceStencil::normalisePartNumber($partNumber);

        // Build the auto-generic payload eagerly. This is the value used by
        // firstOrCreate IFF the row doesn't exist; a lambda would skip the
        // build on cache hit but firstOrCreate's signature takes a flat
        // array, so we accept the single build-call cost on hit. Phase 24's
        // future curation makes hits the common case anyway.
        //
        // The mock-asserted "generator NOT invoked on cache hit" test expects
        // a different code path — we therefore short-circuit BEFORE building
        // when the row already exists.
        $existing = DeviceStencil::query()->where('part_number', $normalised)->first();
        if ($existing !== null) {
            return $existing;
        }

        // Cache miss: build the placeholder + persist via firstOrCreate (race-
        // safe per the docblock above).
        $payload = $this->generator->build(array_merge($hints, ['part_number' => $partNumber]));

        return DeviceStencil::firstOrCreate(
            ['part_number' => $normalised],
            [
                'manufacturer'   => $this->stringify($hints['manufacturer'] ?? null) ?: null,
                'model'          => $this->stringify($hints['model'] ?? null) ?: null,
                'display_name'   => $payload['display_name'],
                'mxgraph_xml'    => $payload['mxgraph_xml'],
                'default_width'  => $payload['default_width'],
                'default_height' => $payload['default_height'],
                'source'         => DeviceStencil::SOURCE_AUTO_GENERATED,
                // Phase 24 D-10 — single write-through for the review-queue
                // flag. Covers BOTH this phase's import-time stubbing
                // (QuoteImportStencilStubber, Plan 24-02) and the pre-existing
                // Phase 21 lazy-create path (Project::devicesWithStencils(),
                // left untouched) uniformly — no auto-generated stub, no
                // matter which caller first references an uncatalogued
                // part_number, silently skips the review queue.
                'needs_review'   => true,
            ]
        );
    }

    /**
     * Bulk variant — resolves an array of equipment lines and returns each
     * line enriched with a `stencil` key (DeviceStencil instance OR null
     * when the line has no part_number).
     *
     * Empty / missing part_number lines are kept in the output (returned
     * with stencil = null) so the caller can still surface them — the
     * renderer might display a "no part_number" warning rather than dropping
     * the line silently.
     *
     * @param  array<int, array{part_number:string, manufacturer?:?string, model?:?string, name?:?string, quantity?:int, area?:?string}>  $lines
     * @return array<int, array{part_number:string, manufacturer?:?string, model?:?string, name?:?string, quantity?:int, area?:?string, stencil:?DeviceStencil}>
     */
    public function resolveMany(array $lines): array
    {
        $out = [];
        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }
            $partNumber = $this->stringify($line['part_number'] ?? null);
            if ($partNumber === '') {
                Log::info('DeviceStencilCacheService: skipping line with empty part_number', [
                    'line_keys' => array_keys($line),
                ]);
                $out[] = array_merge($line, ['stencil' => null]);

                continue;
            }

            $stencil = $this->resolveForPartNumber($partNumber, [
                'manufacturer' => $line['manufacturer'] ?? null,
                'model'        => $line['model'] ?? null,
                'name'         => $line['name'] ?? null,
                'part_number'  => $partNumber,
            ]);
            $out[] = array_merge($line, ['stencil' => $stencil]);
        }

        return $out;
    }

    private function stringify(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        return trim((string) $value);
    }
}
