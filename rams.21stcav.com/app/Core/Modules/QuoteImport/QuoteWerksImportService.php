<?php

namespace App\Core\Modules\QuoteImport;

use App\Models\ProjectPackage;
use App\Models\User;
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
        private readonly QuoteImportService $importService,
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
            $qty        = (int) ($item['qty'] ?? 1);
            $unitPrice  = (float) ($item['unit_price'] ?? 0);
            $description = (string) ($item['description'] ?? '');

            return [
                'quantity'    => $qty,
                'qty'         => $qty,
                'part_number' => (string) ($item['part_number'] ?? ''),
                'part_no'     => (string) ($item['part_number'] ?? ''),
                'name'        => $description,
                'description' => $description,
                'area'        => (string) ($item['area'] ?? ''),
                'location'    => (string) ($item['location'] ?? ($item['area'] ?? '')),
                'category'    => $this->classifyDescription($description),
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

        return [
            'qw_number'         => (string) ($parsedShape['ref'] ?? ''),
            'quote_ref'         => (string) ($parsedShape['ref'] ?? ''),
            'client_name'       => (string) ($parsedShape['client'] ?? ''),
            'site_name'         => (string) ($parsedShape['site_name'] ?? ''),
            'site_address'      => (string) ($parsedShape['site'] ?? ''),
            'project_name'      => $projectName,
            'works_description' => $scopeNarrative,
            'prepared_by'       => (string) ($parsedShape['prepared_by'] ?? ''),
            // Fetcher's mapToParsedShape doesn't currently surface a project-level
            // total (SCC gets it from Subtotal on the header row separately).
            // Add in a follow-up if the Review page needs it.
            'total_price'       => 0.0,
            'equipment'         => $equipment,
            'equipment_list'    => $equipment,
            'line_items'        => $equipment,
            'cable_hints'       => [],
            'rooms'             => array_values($parsedShape['rooms'] ?? []),
            'meta'              => [
                // Tier-2 confidence trigger — do NOT change this string.
                'source'            => 'quotewerks_sql',
                'confidence'        => 0.95,
                'parser_confidence' => 0.95,
                'data_source'       => 'quotewerks',
                'item_count'        => count($equipment),
                'room_count'        => count($parsedShape['rooms'] ?? []),
            ],
        ];
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Private helpers
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Classify an equipment description into a category.
     *
     * Mirrors the classification logic from the PDF pipeline to ensure
     * consistent categorisation regardless of import source.
     */
    private function classifyDescription(string $description): string
    {
        $desc = strtolower($description);

        if (preg_match('/display|screen|monitor|tv|panel/i', $desc)) {
            return 'display';
        }
        if (preg_match('/speaker|amplifier|amp|audio|microphone|mic|dsp/i', $desc)) {
            return 'audio';
        }
        if (preg_match('/camera|ptz|webcam/i', $desc)) {
            return 'camera';
        }
        if (preg_match('/cable|hdmi|cat[56]|patch|fibre|fiber|connector/i', $desc)) {
            return 'cable';
        }
        if (preg_match('/mount|bracket|plate|trolley|stand|floor\s*stand/i', $desc)) {
            return 'mounting';
        }
        if (preg_match('/switch|matrix|extender|transmitter|receiver|hdbt/i', $desc)) {
            return 'signal_distribution';
        }
        if (preg_match('/control|touch\s*panel|keypad|button|crestron|extron|amx/i', $desc)) {
            return 'control';
        }
        if (preg_match('/rack|credenza|furniture/i', $desc)) {
            return 'furniture';
        }
        if (preg_match('/install|labour|labor|commission|program/i', $desc)) {
            return 'service';
        }

        return 'other';
    }
}
