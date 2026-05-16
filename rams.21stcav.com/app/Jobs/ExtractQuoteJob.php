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

        Log::debug('ExtractQuoteJob: parser result', [
            'package_id'    => $this->package->id,
            'confidence'    => $parsed['confidence'] ?? null,
            'client'        => $parsed['client'] ?? '',
            'site'          => $parsed['site'] ?? '',
            'ref'           => $parsed['ref'] ?? '',
            'equipment_count' => count($parsed['equipment'] ?? []),
            'has_markers'   => str_contains($text, 'PARTSTART'),
            'text_length'   => strlen($text),
            'text_preview'  => mb_substr($text, 0, 300),
        ]);

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

            // Use pre-assigned project (e.g. "Upload New Quote" from project page).
            $project = null;
            if ($this->package->project_id !== null) {
                $project = Project::whereNull('deleted_at')->find($this->package->project_id);
            }

            // Auto-match existing project by client+site when no project pre-assigned.
            if ($project === null && $clientName && $siteAddress) {
                $project = Project::whereRaw('LOWER(client_name) = ?', [strtolower($clientName)])
                    ->whereRaw('LOWER(site_address) = ?', [strtolower($siteAddress)])
                    ->whereNull('deleted_at')
                    ->first();
            }

            // Create project if none matched.
            //
            // Phase 22.1 D-02: Project.works_description is NOT auto-seeded
            // from $extracted['overview']. The raw QuoteWerks overview prose
            // is still preserved at ProjectPackage.extracted_data['overview']
            // (top-level) for the review form to surface as a starting-value
            // suggestion in the scope textarea — but it does not flow into
            // a compliance-document field until a PM explicitly approves it.
            if ($project === null && $this->createProject) {
                $project = $projectService->create($this->user, [
                    'name'              => $extracted['project_name']  ?? ($clientName ?? 'AV Installation'),
                    'ref'               => $extracted['qw_number']     ?? null,
                    'client_name'       => $clientName ?? 'Client',
                    'site_address'      => $siteAddress ?? '',
                ]);
            }

            // Phase 22.1 D-02: ProjectPackage.works_description is also NO
            // LONGER auto-seeded from $extracted['overview']. The raw text
            // stays inside extracted_data['overview']; nothing downstream
            // reads ProjectPackage.works_description as a canonical scope
            // source after this plan ships.
            $this->package->update([
                'project_id'        => $project?->id,
                'extracted_data'    => $extracted,
                'equipment_list'    => $extracted['equipment_list'] ?? [],
                'cable_list'        => $extracted['cable_hints']    ?? [],
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

        $this->generateContentPack($extracted);

        Log::info('ExtractQuoteJob: extraction complete', [
            'package_id' => $this->package->id,
            'user_id'    => $this->user->id,
            'confidence' => $extracted['meta']['parser_confidence'] ?? null,
        ]);
    }

    /**
     * Best-effort content pack generation.
     * Generates room descriptions (summary + description) and scope fields,
     * then merges them into extracted_data.
     * Wrapped in try/catch — AI failure must NOT propagate to the extraction job.
     */
    private function generateContentPack(array $extracted): void
    {
        try {
            $roomOverviews = (array) ($extracted['room_overviews'] ?? []);

            // ── 1. Generate room summaries + descriptions ─────────────────────
            $summaryService = app(\App\Services\RoomOverviewSummaryService::class);
            $roomOverviews  = $summaryService->summarize($roomOverviews);

            // ── 2. Generate scope_of_works + works_overview ───────────────────
            $roomLines = [];
            foreach ($roomOverviews as $ro) {
                $room = trim((string) ($ro['room'] ?? ''));
                $desc = trim((string) ($ro['works_summary'] ?? $ro['overview'] ?? ''));
                if ($room !== '' && $desc !== '') {
                    $roomLines[] = "- {$room}: {$desc}";
                }
            }

            $projectName = $extracted['project']['project_name'] ?? $extracted['project_name'] ?? 'this project';
            $clientName  = $extracted['project']['client_name']  ?? $extracted['client_name']  ?? '';
            $siteAddress = $extracted['project']['site_address'] ?? $extracted['site_address']  ?? '';

            $worksOverview = '';
            $scopeOfWorks  = '';

            if (! empty($roomLines)) {
                $prompt = (new \App\Core\AI\Prompts\ScopeOfWorksPrompt())->withContext([
                    'project_name' => $projectName,
                    'client_name'  => $clientName,
                    'site_address' => $siteAddress,
                    'room_lines'   => implode("\n", $roomLines),
                ]);
                $scopeResult   = AIManager::run($prompt, []);
                $scopeOfWorks  = trim((string) ($scopeResult['scope_of_works'] ?? ''));
                $worksOverview = trim((string) ($scopeResult['works_overview']  ?? ''));
            }

            // ── 3. Merge into extracted_data and persist ──────────────────────
            $fresh = $this->package->fresh()->extracted_data ?? [];
            $fresh['room_overviews'] = $roomOverviews;
            if ($scopeOfWorks !== '') {
                $fresh['scope_of_works'] = $scopeOfWorks;
            }
            if ($worksOverview !== '') {
                $fresh['works_overview'] = $worksOverview;
            }
            $this->package->update(['extracted_data' => $fresh]);

            Log::info('ExtractQuoteJob: content pack generated', [
                'package_id'    => $this->package->id,
                'rooms_updated' => count($roomOverviews),
                'has_scope'     => $scopeOfWorks !== '',
                'has_overview'  => $worksOverview !== '',
            ]);
        } catch (\Throwable $e) {
            Log::warning('ExtractQuoteJob: content pack generation failed (best-effort, extraction still succeeds)', [
                'package_id' => $this->package->id,
                'error'      => $e->getMessage(),
            ]);
        }
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
        // Scaffold minimal room_overviews from rooms list when the tag-based path
        // produced nothing — ensures the review form always has rows to display.
        //
        // Phase 22.1 closure (Plan 07): emit the canonical 4-key shape only.
        // `solution_type_id` is `null` not `''` to match the normaliser's
        // expected type (RamsReviewDataService::normaliseRoomOverviews coerces
        // both to null but `null` is the honest representation). The legacy
        // `summary` and `description` keys are gone — RoomOverviewSummaryService
        // now writes `works_summary` directly per the same plan.
        if (empty($ai['room_overviews']) && ! empty($ai['rooms'])) {
            $ai['room_overviews'] = array_values(array_map(
                static fn (string $roomName): array => [
                    'room'             => $roomName,
                    'overview'         => '',
                    'works_summary'    => '',
                    'solution_type_id' => null,
                ],
                (array) $ai['rooms']
            ));
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

                $partNo   = trim((string) ($row['part_number'] ?? ''));
                $itemType = $this->classifyItemType($partNo, $name);

                // Map item_type → category so the review form (which reads 'category')
                // renders items in the correct section immediately after import.
                $category = match ($itemType) {
                    'consumable'           => 'consumables',
                    'professional_service' => 'services',
                    'service_contract'     => 'service_contracts',
                    'customer_supplied'    => 'customer_supplied',
                    default                => 'hardware',
                };

                return [
                    'quantity'    => max(1, (int) ($row['qty'] ?? 1)),
                    'qty'         => max(1, (int) ($row['qty'] ?? 1)),
                    'part_number' => $partNo,
                    'part_no'     => $partNo,
                    'name'        => $name,
                    'description' => $name,
                    'area'        => trim((string) ($row['area'] ?? '')),
                    'location'    => trim((string) ($row['location'] ?? '')),
                    'item_type'   => $itemType,
                    'category'    => $category,
                ];
            },
            (array) ($parsed['equipment'] ?? [])
        )));

        if (! empty($parserEquipment)) {
            $ai['equipment']      = $parserEquipment;
            $ai['equipment_list'] = $parserEquipment;
            $ai['line_items']     = $parserEquipment;

            // hardware_list — physical items only; used by RAMS and O&M generators.
            // Excludes services, service contracts, consumables, and customer-supplied items.
            $ai['hardware_list'] = array_values(
                array_filter($parserEquipment, fn (array $i) => $i['item_type'] === 'hardware')
            );

            // worksheet_items — hardware only; tracks what a physical crew installs on site.
            // customer_supplied items are excluded (client procures them separately).
            $ai['worksheet_items'] = array_values(
                array_filter($parserEquipment, fn (array $i) => $i['item_type'] === 'hardware')
            );
        }

        $ai['cable_hints'] = [];

        $ai['meta'] = array_merge((array) ($ai['meta'] ?? []), [
            'parser_confidence'        => $parsed['confidence'] ?? null,
            'source'                   => 'extracted',
            'total_items'              => count($parserEquipment),
            'hardware_count'           => count($ai['hardware_list'] ?? []),
            'service_count'            => count(array_filter($parserEquipment, fn ($i) => $i['item_type'] === 'professional_service')),
            'service_contract_count'   => count(array_filter($parserEquipment, fn ($i) => $i['item_type'] === 'service_contract')),
            'consumable_count'         => count(array_filter($parserEquipment, fn ($i) => $i['item_type'] === 'consumable')),
            'customer_supplied_count'  => count(array_filter($parserEquipment, fn ($i) => $i['item_type'] === 'customer_supplied')),
        ]);

        return $ai;
    }

    /**
     * Classify a quote line item by type.
     *
     * Return values (applied in order):
     *   customer_supplied   — CS / C/S prefix: client-procured hardware, no supply cost
     *   service_contract    — Subscription, warranty, SLA, or support-plan line
     *   consumable          — Bulk materials: cables, fixings, sundries, waste, etc.
     *   professional_service — Labour, documentation, travel, surveys, delivery
     *   hardware            — Everything else (physically installed product)
     *
     * Used to split equipment_list into hardware_list (RAMS/O&M) and
     * worksheet_items (everything a physical crew would install/connect).
     */
    private function classifyItemType(string $partNo, string $description): string
    {
        $upper = strtoupper(trim($partNo));
        $desc  = strtolower($description);

        // ── Customer-supplied items ───────────────────────────────────────────
        // Prefixed CS or C/S — client procures these; we install only.
        if ($upper === 'CS' || str_starts_with($upper, 'CS-') || str_starts_with($upper, 'C/S')) {
            return 'customer_supplied';
        }

        // ── Service contracts ─────────────────────────────────────────────────
        // Subscriptions, warranties, SLAs, annual support plans — no physical install.
        $contractPrefixes = ['SVC', 'SVCCON', 'WARRANT', 'WARRANTY', 'SUBSCRIP', 'SLA', 'ANNUALSUP', 'CAREPACK'];
        foreach ($contractPrefixes as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return 'service_contract';
            }
        }

        // Description-based contract detection (any part number, including blank).
        $contractDescKeywords = [
            'warranty', 'extended warranty', 'annual support', 'support contract',
            'service contract', 'maintenance contract', 'subscription', 'sla',
            'care pack', 'carepack', 'support plan', 'licence', 'license',
        ];
        foreach ($contractDescKeywords as $kw) {
            if (str_contains($desc, $kw)) {
                return 'service_contract';
            }
        }

        // ── Consumables ───────────────────────────────────────────────────────
        // Bulk materials — not individually identifiable hardware pieces.
        // NOTE: DELIVERY/CARRIAGE/POSTAGE were previously here but moved to
        // $servicePrefixes — transportation is a service, not a consumable
        // bulk material. QuoteWerks templates list them under "Services"
        // alongside SURVEY/RAMS/INSTALL (see 21CQ30451-01-OPS).
        $consumablePrefixes = [
            'CONSUMABLE', 'CABLES', 'CABLE', 'MISC', 'PACKING', 'WASTE',
            'SUNDRY', 'SUNDRIES', 'MATERIALS', 'TRUNKING', 'HDMI', 'WALLMOUNT',
            'BRACKET', 'FASTENER', 'FIXINGS', 'CONDUIT', 'CATCABLE', 'PATCH',
        ];
        foreach ($consumablePrefixes as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return 'consumable';
            }
        }

        // Description-based consumable detection for blank part numbers.
        if ($upper === '') {
            $consumableDescKeywords = [
                'cable', 'cabling', 'trunking', 'conduit', 'patch', 'hdmi',
                'bracket', 'fixings', 'fixings', 'fastener', 'wall mount',
                'sundry', 'materials',
            ];
            foreach ($consumableDescKeywords as $kw) {
                if (str_contains($desc, $kw)) {
                    return 'consumable';
                }
            }
        }

        // ── Professional services ─────────────────────────────────────────────
        // Labour, documentation, travel, surveys, transportation —
        // not physically installed items.
        $servicePrefixes = [
            'INSTALL', 'FIRSTFIX', 'SECONDFIX', 'THIRDFIX', 'COMMISSION',
            'RAMS', 'SSV', 'PM', 'PROJMAN', 'PROJECTMAN', 'TRAINING',
            'TRAVEL', 'TRAV', 'MILEAGE', 'DELIVERY', 'CARRIAGE', 'POSTAGE',
            'OMANUAL', 'CABLESCH', 'PROSER', 'PROGRAM', 'CONSULT',
            'DRAWING', 'SNAG', 'CALLOUT', 'SUPPORT', 'CONFIG', 'HANDOVER',
            'DERIG', 'DECOMM', 'SURVEY', 'ATTEND', 'VISIT', 'RACK',
            'PROJ', 'MANAGE',
        ];
        foreach ($servicePrefixes as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return 'professional_service';
            }
        }

        // Description-based service detection (blank part number or known-service descriptions).
        $serviceDescKeywords = [
            'site survey', 'install', 'commissioning', 'labour', 'travel',
            'training', 'project management', 'risk assessment', 'method statement',
            'call out', 'callout', 'consultation', 'engineer',
            'delivery', 'carriage', 'postage',
        ];
        foreach ($serviceDescKeywords as $kw) {
            if (str_contains($desc, $kw)) {
                return 'professional_service';
            }
        }

        return 'hardware';
    }
}
