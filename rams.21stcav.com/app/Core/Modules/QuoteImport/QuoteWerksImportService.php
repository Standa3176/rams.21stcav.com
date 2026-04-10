<?php

namespace App\Core\Modules\QuoteImport;

use App\Models\ProjectPackage;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;

/**
 * QuoteWerksImportService — orchestrates DB fetch → extracted_data → importFromData().
 *
 * Produces an extracted_data array structurally identical to the PDF import path,
 * with meta.source set to exactly 'quotewerks_sql' (this is the ProjectDataService
 * tier-2 confidence trigger — do NOT change this string).
 *
 * All SQL queries are delegated to QuoteWerksRepository. This service handles
 * data transformation and orchestration only.
 *
 * @see QuoteWerksRepository  For raw SQL queries and column mapping
 * @see QuoteImportService     For the downstream importFromData() entry point
 */
class QuoteWerksImportService
{
    public function __construct(
        private readonly QuoteWerksRepository $repository,
        private readonly QuoteImportService   $importService,
    ) {}

    // ══════════════════════════════════════════════════════════════════════════
    // Public methods
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Import a quote by its QuoteWerks reference number.
     *
     * Synchronous — no queue dispatch. Returns immediately with the created package.
     *
     * @throws ModelNotFoundException If the reference is not found in QuoteWerks.
     */
    public function importByReference(User $user, string $reference): ProjectPackage
    {
        $header = $this->repository->findByReference($reference);

        if ($header === null) {
            throw (new ModelNotFoundException())
                ->setModel('QuoteWerks Document', $reference);
        }

        $items = $this->repository->getItemsByDocNo($reference);

        $extractedData = $this->buildExtractedData($header, $items);

        Log::info('QuoteWerksImportService: imported by reference', [
            'reference'  => $reference,
            'user_id'    => $user->id,
            'item_count' => count($items),
        ]);

        return $this->importService->importFromData($user, [
            'client_name'     => $header['client_name'],
            'site_address'    => $header['site_address'],
            'ref'             => $header['doc_no'],
            'name'            => $header['subject'] ?: ($header['doc_no'] . ' — ' . $header['client_name']),
            'works_description' => $header['subject'],
            'equipment_list'  => $extractedData['equipment_list'],
            'cable_list'      => [],
            'extracted_data'  => $extractedData,
        ]);
    }

    /**
     * Search QuoteWerks headers by client name (for the lookup UI).
     *
     * @return array[] Array of header summaries (max 20 results).
     */
    public function searchByClient(string $clientName, ?string $dateFrom = null): array
    {
        return $this->repository->searchByClient($clientName, $dateFrom);
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Data transformation
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Build the canonical extracted_data array from QuoteWerks header + items.
     *
     * The output shape matches what QuoteImportService::importFromData() expects,
     * and is structurally identical to the PDF extraction pipeline output.
     *
     * @param  array   $header  Mapped header from QuoteWerksRepository::findByReference()
     * @param  array[] $items   Mapped items from QuoteWerksRepository::getItemsByDocNo()
     * @return array   Canonical extracted_data array
     */
    public function buildExtractedData(array $header, array $items): array
    {
        // ── Filter to product lines only ──
        $productItems = array_filter($items, function (array $item) {
            $type = strtoupper($item['item_type'] ?? '');
            return in_array($type, ['P', 'G'], true); // Product or Group header
        });

        // ── Build equipment rows ──
        $equipment = [];
        foreach ($productItems as $item) {
            $equipment[] = [
                'quantity'    => $item['quantity'],
                'qty'         => $item['quantity'],
                'part_number' => $item['part_number'],
                'part_no'     => $item['part_number'],
                'name'        => $item['description'],
                'description' => $item['description'],
                'area'        => $item['group_name'],
                'location'    => $item['group_name'],
                'category'    => $this->classifyDescription($item['description']),
                'unit_price'  => $item['unit_price'],
                'total_price' => $item['total_price'],
                'data_source' => 'quotewerks',
                'confidence'  => 0.95,
            ];
        }

        // ── Build rooms from unique group names ──
        $rooms = array_values(array_unique(array_filter(
            array_column($items, 'group_name')
        )));

        return [
            'qw_number'         => $header['doc_no'],
            'quote_ref'         => $header['doc_no'],
            'client_name'       => $header['client_name'],
            'site_address'      => $header['site_address'],
            'project_name'      => $header['subject'] ?: $header['doc_no'],
            'works_description' => $header['subject'],
            'doc_date'          => $header['doc_date'],
            'total_price'       => $header['total_price'],
            'equipment'         => $equipment,
            'equipment_list'    => $equipment,
            'line_items'        => $equipment,
            'cable_hints'       => [],
            'rooms'             => $rooms,
            'meta'              => [
                'source'            => 'quotewerks_sql',
                'confidence'        => 0.95,
                'parser_confidence' => 0.95,
                'data_source'       => 'quotewerks',
                'item_count'        => count($equipment),
                'room_count'        => count($rooms),
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
