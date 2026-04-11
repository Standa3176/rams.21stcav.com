<?php

namespace App\Jobs;

use App\Core\AI\AIManager;
use App\Core\AI\Prompts\QuoteExtractionPrompt;
use App\Core\Modules\Projects\ProjectService;
use App\Models\Project;
use App\Models\ProjectActivityLog;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Services\EquipmentLineParserService;
use App\Services\EquipmentNormalizerService;
use App\Services\PdfTextExtractorService;
use App\Services\ProjectQuoteVersionService;
use App\Services\QuoteLineExtractorService;
use App\Services\QuoteParserService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Extracts structured quote data from a stored PDF using the original pipeline:
 *
 *   PDF → PdfTextExtractorService (text)
 *       → QuoteParserService (tag-based / heuristic — local)
 *       → QuoteLineExtractorService → EquipmentNormalizerService → EquipmentLineParserService (local)
 *       → QuoteExtractionPrompt via AIManager (AI standardises equipment names only)
 *       → mergeParsedQuoteData (combine all sources, parser wins on equipment)
 *       → ProjectPackage update + optional Project create
 */
class ExtractQuoteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 120;

    public function __construct(
        private readonly ProjectPackage $package,
        private readonly User           $user,
        private readonly bool           $createProject,
    ) {}

    public function handle(
        PdfTextExtractorService    $pdfExtractor,
        QuoteParserService         $quoteParser,
        QuoteLineExtractorService  $lineExtractor,
        EquipmentNormalizerService $normalizer,
        EquipmentLineParserService $lineParser,
        ProjectService             $projectService,
        ProjectQuoteVersionService $quoteVersioner,
    ): void {
        $absolutePath = Storage::disk('local')->path($this->package->quote_path);

        // ── Stage 1: extract raw text ─────────────────────────────────────────
        $text = $pdfExtractor->extract($absolutePath);

        // ── Stage 2: parallel local parsing ──────────────────────────────────
        $parsed = $quoteParser->parse($text);

        // ── Stages 3-5: filter → normalise → parse equipment lines ───────────
        $lines = $lineExtractor->extractEquipmentLines($text);
        $lines = $normalizer->normalize($lines);
        $items = $lineParser->parse($lines);

        // ── Stage 6: AI standardises equipment names only (minimal tokens) ───
        try {
            $ai = AIManager::run(new QuoteExtractionPrompt($items), []);
        } catch (\Throwable $e) {
            Log::warning('ExtractQuoteJob: AI standardisation failed, using parser only', [
                'package_id' => $this->package->id,
                'error'      => $e->getMessage(),
            ]);
            $ai = [];
        }

        // ── Stage 7: merge (parser wins on equipment + header fields) ─────────
        $extracted = $this->mergeParsedQuoteData($ai, $parsed);

        // ── Persist ───────────────────────────────────────────────────────────
        DB::transaction(function () use ($extracted, $projectService, $quoteVersioner) {
            $clientName  = $extracted['client_name']  ?? null;
            $siteAddress = $extracted['site_address']  ?? null;

            // Auto-match existing project by client+site
            $project = null;
            if ($clientName && $siteAddress) {
                $project = Project::whereRaw('LOWER(client_name) = ?', [strtolower($clientName)])
                    ->whereRaw('LOWER(site_address) = ?', [strtolower($siteAddress)])
                    ->whereNull('deleted_at')
                    ->first();
            }

            // Create project if none matched
            if ($project === null && $this->createProject) {
                $project = $projectService->create($this->user, [
                    'name'              => $extracted['project_name']  ?? ($clientName ?? 'AV Installation'),
                    'ref'               => $extracted['qw_number']     ?? null,
                    'client_name'       => $clientName ?? 'Client',
                    'site_address'      => $siteAddress ?? '',
                    'works_description' => $extracted['overview']      ?? null,
                ]);
            }

            $this->package->update([
                'project_id'        => $project?->id,
                'extracted_data'    => $extracted,
                'equipment_list'    => $extracted['equipment_list'] ?? [],
                'cable_list'        => $extracted['cable_hints']    ?? [],
                'works_description' => $extracted['overview']       ?? null,
                'status'            => ProjectPackage::STATUS_EXTRACTED,
            ]);

            if ($project !== null) {
                $quoteVersioner->create(
                    project:          $project,
                    uploader:         $this->user,
                    originalFilename: $this->package->quote_filename,
                    storedFilename:   $this->package->quote_path,
                    parsed:           [
                        'ref'    => $extracted['qw_number']    ?? '',
                        'client' => $extracted['client_name']  ?? '',
                        'site'   => $extracted['site_address'] ?? '',
                    ],
                    formData: [],
                );

                $projectService->log(
                    project:     $project,
                    user:        $this->user,
                    action:      ProjectActivityLog::ACTION_PACKAGE_IMPORTED,
                    description: "{$this->user->name} imported quote \"{$this->package->quote_filename}\".",
                    metadata:    [
                        'package_id'      => $this->package->id,
                        'qw_number'       => $extracted['qw_number'] ?? null,
                        'line_item_count' => count($extracted['equipment_list'] ?? []),
                    ],
                );
            }
        });

        Log::info('ExtractQuoteJob: extraction complete', [
            'package_id' => $this->package->id,
            'user_id'    => $this->user->id,
            'confidence' => $extracted['meta']['parser_confidence'] ?? null,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $this->package->update(['status' => ProjectPackage::STATUS_FAILED]);

        Log::error('ExtractQuoteJob: extraction failed', [
            'package_id' => $this->package->id,
            'error'      => $e->getMessage(),
        ]);
    }

    // ── Merge helpers ─────────────────────────────────────────────────────────

    /**
     * Merge AI standardised output with QuoteParserService structured output.
     * Parser wins on equipment and all header fields when AI is empty or wrong.
     */
    private function mergeParsedQuoteData(array $ai, array $parsed): array
    {
        $ref = (string) ($parsed['ref'] ?? '');
        if (($ai['qw_number'] ?? '') === '' || strtoupper((string) ($ai['qw_number'] ?? '')) === 'RAMS-001') {
            if ($ref !== '' && strtoupper($ref) !== 'RAMS-001') {
                $ai['qw_number'] = $ref;
            }
        }

        if (($ai['client_name'] ?? '') === '' && ($parsed['client'] ?? '') !== '') {
            $ai['client_name'] = (string) $parsed['client'];
        }
        if (($ai['site_name'] ?? '') === '' && ($parsed['site_name'] ?? '') !== '') {
            $ai['site_name'] = (string) $parsed['site_name'];
        }
        if (($ai['site_address'] ?? '') === '' && ($parsed['site'] ?? '') !== '') {
            $ai['site_address'] = (string) $parsed['site'];
        }
        if (($ai['prepared_by'] ?? '') === '' && ($parsed['prepared_by'] ?? '') !== '') {
            $ai['prepared_by'] = (string) $parsed['prepared_by'];
        }
        if (($ai['overview'] ?? '') === '' && ($parsed['overview'] ?? '') !== '') {
            $ai['overview'] = (string) $parsed['overview'];
        }
        if (empty($ai['rooms']) && ! empty($parsed['rooms'])) {
            $ai['rooms'] = array_values((array) $parsed['rooms']);
        }
        // Per-room overview texts from OVERVIEWTITLE/TXT tags — AI never produces these.
        if (empty($ai['room_overviews']) && ! empty($parsed['room_overviews'])) {
            $ai['room_overviews'] = array_values((array) $parsed['room_overviews']);
        }

        // Auto-generate project_name from available fields
        if (($ai['project_name'] ?? '') === '') {
            $client = trim((string) ($ai['client_name'] ?? ''));
            $qref   = trim((string) ($ai['qw_number']   ?? ''));
            $site   = trim((string) ($ai['site_name']   ?? ''));

            if ($qref !== '' && strtoupper($qref) !== 'RAMS-001' && $client !== '') {
                $ai['project_name'] = "{$qref} - {$client}";
            } elseif ($site !== '') {
                $ai['project_name'] = $site;
            } elseif ($client !== '') {
                $ai['project_name'] = $client;
            }
        }

        // Build canonical equipment from parser (more reliable than AI for this PDF format)
        $parserEquipment = array_values(array_filter(array_map(
            function (array $row): ?array {
                $name = trim((string) ($row['description'] ?? ''));
                if ($name === '') {
                    return null;
                }

                return [
                    'quantity'    => max(1, (int) ($row['qty'] ?? 1)),
                    'qty'         => max(1, (int) ($row['qty'] ?? 1)),
                    'part_number' => trim((string) ($row['part_number'] ?? '')),
                    'part_no'     => trim((string) ($row['part_number'] ?? '')),
                    'name'        => $name,
                    'description' => $name,
                    'area'        => trim((string) ($row['area'] ?? '')),
                    'location'    => trim((string) ($row['location'] ?? '')),
                ];
            },
            (array) ($parsed['equipment'] ?? [])
        )));

        if (! empty($parserEquipment)) {
            $ai['equipment']      = $parserEquipment;
            $ai['equipment_list'] = $parserEquipment;
            $ai['line_items']     = $parserEquipment;
        }

        $ai['cable_hints'] = [];

        $ai['meta'] = array_merge((array) ($ai['meta'] ?? []), [
            'parser_confidence' => $parsed['confidence'] ?? null,
            'source'            => 'extracted',
        ]);

        return $ai;
    }
}
