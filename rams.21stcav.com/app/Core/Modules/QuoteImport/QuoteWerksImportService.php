<?php

namespace App\Core\Modules\QuoteImport;

use App\Models\ProjectPackage;
use App\Models\User;
use App\Services\Imports\EquipmentCategoryClassifier;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * QuoteWerksImportService — orchestrates parsed-shape → extracted_data →
 * importFromData() for QuoteWerks direct-import (260723-qw1).
 *
 * The upstream SQL is owned by App\Services\Imports\Quote\QuoteWerksDbFetcher
 * (Task 2). This service is a pure transformation + orchestration layer that
 * consumes the SCC-shape parsed array and produces RAMS's canonical
 * extracted_data payload before delegating persistence to QuoteImportService.
 *
 * meta.source is set to exactly 'quotewerks_sql' — this is the ProjectDataService
 * tier-2 confidence trigger. DO NOT change this string.
 *
 * @see App\Services\Imports\Quote\QuoteWerksDbFetcher  Upstream fetcher
 * @see QuoteImportService                              Downstream persister
 */
class QuoteWerksImportService
{
    public function __construct(
        private readonly QuoteImportService          $importService,
        private readonly EquipmentCategoryClassifier $categoryClassifier,
    ) {}

    // ══════════════════════════════════════════════════════════════════════════
    // Public methods
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Import a quote from a fetcher parsed-shape array.
     *
     * Called by QuoteWerksImportController after the fetcher has returned a
     * live header + items. Delegates persistence to QuoteImportService which
     * handles project auto-match/create + ProjectPackage row creation.
     *
     * @param  User   $user         Authenticated user performing the import.
     * @param  array  $parsedShape  Output of QuoteWerksDbFetcher::mapToParsedShape.
     * @return ProjectPackage       The persisted package.
     */
    public function importFromParsedShape(User $user, array $parsedShape): ProjectPackage
    {
        $extractedData = $this->buildExtractedData($parsedShape);

        Log::info('QuoteWerksImportService: imported from parsed shape', [
            'reference'  => $parsedShape['ref'] ?? '',
            'user_id'    => $user->id,
            'item_count' => count($parsedShape['equipment'] ?? []),
            'room_count' => count($parsedShape['rooms'] ?? []),
        ]);

        return $this->importService->importFromData($user, [
            'client_name'       => $extractedData['client_name'],
            'site_address'      => $extractedData['site_address'],
            'ref'               => $extractedData['quote_ref'],
            'name'              => $extractedData['project_name'],
            'works_description' => $extractedData['works_description'],
            'equipment_list'    => $extractedData['equipment_list'],
            'cable_list'        => [],
            'extracted_data'    => $extractedData,
        ]);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Data transformation
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Build the canonical extracted_data array from a fetcher parsed-shape.
     *
     * The output shape matches what QuoteImportService::importFromData()
     * expects and is structurally identical to the PDF extraction pipeline
     * output. Downstream ProjectPackageReviewController reads these keys
     * directly to hydrate the review UI.
     *
     * Input (from QuoteWerksDbFetcher::mapToParsedShape):
     *   client, site, site_name, ref, prepared_by, scope_narrative,
     *   contact_name, contact_phone, contact_email, equipment[], rooms[]
     *
     * @param  array  $parsedShape  Fetcher output.
     * @return array                Canonical extracted_data array.
     */
    public function buildExtractedData(array $parsedShape): array
    {
        $equipment = array_map(function (array $item) {
            $qty         = (int) ($item['qty'] ?? 1);
            $unitPrice   = (float) ($item['unit_price'] ?? 0);
            $description = (string) ($item['description'] ?? '');
            $partNumber  = (string) ($item['part_number'] ?? '');

            return [
                'quantity'    => $qty,
                'qty'         => $qty,
                'part_number' => $partNumber,
                'part_no'     => $partNumber,
                'name'        => $description,
                'description' => $description,
                'area'        => (string) ($item['area'] ?? ''),
                'location'    => (string) ($item['location'] ?? ($item['area'] ?? '')),
                // Canonical 7-value vocabulary via the shared classifier so
                // the review UI dropdown + on-save reclassification never
                // disagree. Pre-260725-qw3 this returned fabricated values
                // (`display`, `audio`, `cable`, …) that were silently
                // reverted to `hardware` on save.
                'category'    => $this->categoryClassifier->classify([
                    'name'        => $description,
                    'description' => $description,
                    'part_number' => $partNumber,
                ]),
                'unit_price'  => $unitPrice,
                'total_price' => $unitPrice * $qty,
                'manufacturer' => $item['manufacturer'] ?? null,
                'data_source' => 'quotewerks',
                'confidence'  => 0.95,
            ];
        }, $parsedShape['equipment'] ?? []);

        $scopeNarrative = (string) ($parsedShape['scope_narrative'] ?? '');
        $projectName    = $scopeNarrative !== ''
            ? Str::limit($scopeNarrative, 80, '')
            : (string) ($parsedShape['ref'] ?? '');

        // Zip room names with their per-room CustomMemo01 narratives into the
        // shape the RAMS review page consumes (see ProjectPackageReviewController
        // render loop for room_overviews[]). Rooms without a populated memo get
        // an empty overview string so the review UI still renders a card the PM
        // can fill in manually. Added 2026-07-25 (260725-qw2).
        $rooms         = array_values($parsedShape['rooms'] ?? []);
        $roomDescs     = (array) ($parsedShape['room_descriptions'] ?? []);
        $roomOverviews = array_map(
            static fn (string $name): array => [
                'room'     => $name,
                'overview' => (string) ($roomDescs[$name] ?? ''),
            ],
            $rooms,
        );

        return [
            'qw_number'          => (string) ($parsedShape['ref'] ?? ''),
            'quote_ref'          => (string) ($parsedShape['ref'] ?? ''),
            'client_name'        => (string) ($parsedShape['client'] ?? ''),
            'site_name'          => (string) ($parsedShape['site_name'] ?? ''),
            'site_address'       => (string) ($parsedShape['site'] ?? ''),
            'project_name'       => $projectName,
            'works_description'  => $scopeNarrative,
            'prepared_by'        => (string) ($parsedShape['prepared_by'] ?? ''),
            // Quote-wide flavour text from DocumentHeaders. Nullable — most
            // quotes populate them, some don't. Downstream RAMS templates
            // (project brief / cover letter) can use for section-1 flavour
            // when present. Kept nullable to preserve "unset" semantics.
            'introduction_notes' => $parsedShape['intro_notes']   ?? null,
            'closing_notes'      => $parsedShape['closing_notes'] ?? null,
            // Fetcher's mapToParsedShape doesn't currently surface a project-level
            // total (SCC gets it from Subtotal on the header row separately).
            // Add in a follow-up if the Review page needs it.
            'total_price'        => 0.0,
            'equipment'          => $equipment,
            'equipment_list'     => $equipment,
            'line_items'         => $equipment,
            'cable_hints'        => [],
            'rooms'              => $rooms,
            // Zipped {room, overview} shape the review page renders — see the
            // narrative-textarea loop in resources/views/project-packages/review.blade.php.
            'room_overviews'     => $roomOverviews,
            'meta'               => [
                // Tier-2 confidence trigger — do NOT change this string.
                'source'            => 'quotewerks_sql',
                'confidence'        => 0.95,
                'parser_confidence' => 0.95,
                'data_source'       => 'quotewerks',
                'item_count'        => count($equipment),
                'room_count'        => count($rooms),
            ],
        ];
    }

}
