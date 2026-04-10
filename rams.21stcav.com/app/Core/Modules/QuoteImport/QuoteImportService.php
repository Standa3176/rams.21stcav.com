<?php

namespace App\Core\Modules\QuoteImport;

use App\Core\AI\AIManager;
use App\Core\AI\Prompts\QuoteExtractionPrompt;
use App\Core\Modules\Projects\ProjectService;
use App\Exceptions\AIGenerationException;
use App\Models\Project;
use App\Models\ProjectActivityLog;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Services\EquipmentLineParserService;
use App\Services\EquipmentNormalizerService;
use App\Services\PdfTextExtractorService;
use App\Services\ProjectQuoteVersionService;
use App\Services\QuoteParserService;
use App\Services\QuoteLineExtractorService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * QuoteImportService — orchestrates the full quote-to-project-package pipeline.
 *
 * Flow:
 *   1. Store the uploaded PDF to persistent storage.
 *   2. Extract plain text locally (PdfTextExtractorService).
 *   3. Filter to equipment lines only (QuoteLineExtractorService).
 *   4. Send filtered lines to AIManager via QuoteExtractionPrompt.
 *   5. Normalise the extracted JSON into a ProjectPackage record.
 *   6. Optionally create (or link) a Project from the extracted data.
 *   7. Log the import event to the project activity log.
 *
 * Usage:
 *   $package = app(QuoteImportService::class)->import($user, $file);
 *   // or link to an existing project:
 *   $package = app(QuoteImportService::class)->import($user, $file, $project);
 *   // or create a new project automatically:
 *   $package = app(QuoteImportService::class)->import($user, $file, null, createProject: true);
 */
class QuoteImportService
{
    public function __construct(
        private readonly ProjectService             $projectService,
        private readonly PdfTextExtractorService    $pdfExtractor,
        private readonly QuoteLineExtractorService  $lineExtractor,
        private readonly EquipmentNormalizerService $normalizer,
        private readonly EquipmentLineParserService $lineParser,
        private readonly QuoteParserService         $quoteParser,
        private readonly ProjectQuoteVersionService $quoteVersioner,
    ) {}

    // ── Primary entry point ───────────────────────────────────────────────────

    /**
     * Import a QuoteWerks PDF quote.
     *
     * @param  User           $user           The authenticated user performing the import.
     * @param  UploadedFile   $file           The uploaded PDF file.
     * @param  Project|null   $project        Existing project to attach to (null = standalone or auto-create).
     * @param  bool           $createProject  When true and $project is null, create a Project from extracted data.
     * @param  string|null    $provider       Override AI provider ('claude'|'openai'|null = default).
     * @return ProjectPackage                 The saved package (with extracted_data, equipment_list, cable_list).
     *
     * @throws AIGenerationException  If AI extraction fails after retries.
     * @throws RuntimeException       On file storage or DB failure.
     */
    public function import(
        User         $user,
        UploadedFile $file,
        ?Project     $project       = null,
        bool         $createProject = false,
        ?string      $provider      = null,
    ): ProjectPackage {
        // 1. Store the PDF
        $storagePath = $this->storePdf($file);

        try {
            // 2. Extract structured data via AI
            $extracted = $this->extract($storagePath, $provider);

            // 3. Persist everything in a transaction
            return DB::transaction(function () use ($user, $file, $storagePath, $extracted, $project, $createProject) {
                // ── Auto-create: find or create project for this client+site (D-02) ──
                if ($project === null) {
                    $clientName  = $extracted['client_name']  ?? null;
                    $siteAddress = $extracted['site_address'] ?? null;

                    if ($clientName && $siteAddress) {
                        $project = Project::whereRaw('LOWER(client_name) = ?', [strtolower($clientName)])
                            ->whereRaw('LOWER(site_address) = ?', [strtolower($siteAddress)])
                            ->whereNull('deleted_at')
                            ->first();
                    }
                }

                // Optionally create a Project from extracted data
                if ($project === null && $createProject) {
                    $project = $this->projectService->create($user, [
                        'name'              => $extracted['project_name'] ?? 'AV Installation',
                        'ref'               => $extracted['qw_number']    ?? null,
                        'client_name'       => $extracted['client_name']  ?? 'Client',
                        'site_address'      => $extracted['site_address'] ?? '',
                        'works_description' => $extracted['works_description'] ?? null,
                    ]);
                }

                // 4. Create the ProjectPackage
                $package = ProjectPackage::create([
                    'project_id'        => $project?->id,
                    'user_id'           => $user->id,
                    'quote_filename'    => $file->getClientOriginalName(),
                    'quote_path'        => $storagePath,
                    'extracted_data'    => $extracted,
                    'equipment_list'    => $extracted['equipment_list'] ?? [],
                    'cable_list'        => $extracted['cable_hints']    ?? [],
                    'works_description' => $extracted['works_description'] ?? null,
                    'revision'          => 1,
                    'status'            => ProjectPackage::STATUS_EXTRACTED,
                ]);

                // 5. Create a ProjectQuote history record so it appears in Quote History
                if ($project !== null) {
                    $this->quoteVersioner->create(
                        project:          $project,
                        uploader:         $user,
                        originalFilename: $file->getClientOriginalName(),
                        storedFilename:   $storagePath,
                        parsed:           [
                            'ref'    => $extracted['qw_number']    ?? $extracted['quote_ref']   ?? '',
                            'client' => $extracted['client_name']  ?? '',
                            'site'   => $extracted['site_address'] ?? '',
                        ],
                        formData: [],
                    );
                }

                // 6. Activity log
                if ($project !== null) {
                    $this->projectService->log(
                        project:     $project,
                        user:        $user,
                        action:      ProjectActivityLog::ACTION_PACKAGE_IMPORTED,
                        description: "{$user->name} imported quote \"{$file->getClientOriginalName()}\".",
                        metadata:    [
                            'package_id'      => $package->id,
                            'qw_number'       => $extracted['qw_number'] ?? null,
                            'line_item_count' => count($extracted['line_items'] ?? []),
                        ],
                    );
                }

                Log::info('Quote import succeeded', [
                    'user_id'    => $user->id,
                    'package_id' => $package->id,
                    'project_id' => $project?->id,
                    'filename'   => $file->getClientOriginalName(),
                ]);

                return $package;
            });
        } catch (\Throwable $e) {
            // Clean up the stored file if DB write fails but AI succeeded,
            // or if AI itself threw — avoid orphaned files.
            $this->deletePdf($storagePath);
            throw $e;
        }
    }

    // ── Re-extract (revision bump) ────────────────────────────────────────────

    /**
     * Re-run AI extraction on an existing package's stored PDF.
     *
     * Creates a NEW ProjectPackage record (preserving history) with revision + 1.
     *
     * @throws AIGenerationException
     */
    public function reimport(User $user, ProjectPackage $existing, ?string $provider = null): ProjectPackage
    {
        if (! Storage::exists($existing->quote_path)) {
            throw new RuntimeException(
                "Cannot re-extract package #{$existing->id}: stored PDF not found at '{$existing->quote_path}'."
            );
        }

        $extracted = $this->extract($existing->quote_path, $provider);

        return DB::transaction(function () use ($user, $existing, $extracted) {
            $package = ProjectPackage::create([
                'project_id'        => $existing->project_id,
                'user_id'           => $user->id,
                'quote_filename'    => $existing->quote_filename,
                'quote_path'        => $existing->quote_path,
                'extracted_data'    => $extracted,
                'equipment_list'    => $extracted['equipment_list'] ?? [],
                'cable_list'        => $extracted['cable_hints']    ?? [],
                'works_description' => $extracted['works_description'] ?? null,
                'revision'          => $existing->revision + 1,
                'status'            => ProjectPackage::STATUS_EXTRACTED,
            ]);

            if ($existing->project_id) {
                $project = $existing->project;
                $this->projectService->log(
                    project:     $project,
                    user:        $user,
                    action:      ProjectActivityLog::ACTION_PACKAGE_IMPORTED,
                    description: "{$user->name} re-extracted quote (revision {$package->revision}).",
                    metadata:    ['package_id' => $package->id, 'previous_id' => $existing->id],
                );
            }

            return $package;
        });
    }

    // ── Confirm / review ──────────────────────────────────────────────────────

    /**
     * Mark a package as reviewed and optionally update the parent project fields.
     */
    public function confirm(
        User           $user,
        ProjectPackage $package,
        array          $overrides = [],
    ): ProjectPackage {
        $confirmed = DB::transaction(function () use ($user, $package, $overrides) {
            $package->update(['status' => ProjectPackage::STATUS_REVIEWED]);

            // Propagate any user-corrected fields back to the project
            if ($package->project && ! empty($overrides)) {
                $this->projectService->update($package->project, $user, $overrides);
            }

            if ($package->project) {
                $this->projectService->log(
                    project:     $package->project,
                    user:        $user,
                    action:      ProjectActivityLog::ACTION_PACKAGE_REVIEWED,
                    description: "{$user->name} confirmed the imported quote data.",
                    metadata:    ['package_id' => $package->id],
                );
            }

            return $package->fresh();
        });

        // ── Auto-advance: quote confirmed → survey_pending (D-18, Hook 1) ──
        $linkedProject = $confirmed->project;
        if ($linkedProject?->canTransitionTo(Project::STATUS_SURVEY_PENDING)) {
            try {
                $this->projectService->transition(
                    $linkedProject,
                    Project::STATUS_SURVEY_PENDING,
                    $user
                );
            } catch (\InvalidArgumentException) {
                Log::warning('QuoteImportService: auto-advance to survey_pending skipped', [
                    'project_id'  => $linkedProject->id,
                    'from_status' => $linkedProject->status,
                ]);
            }
        }

        // ── Hook 3 (all docs → handover) deferred to Phase 4 ──

        return $confirmed;
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Store the uploaded PDF under `quote-imports/` and return the storage path.
     */
    private function storePdf(UploadedFile $file): string
    {
        $path = $file->store('quote-imports', 'local');

        if ($path === false) {
            throw new RuntimeException('Failed to store uploaded quote PDF.');
        }

        return $path;
    }

    /**
     * Delete a stored PDF (best-effort — logs on failure, does not re-throw).
     */
    private function deletePdf(string $path): void
    {
        try {
            Storage::disk('local')->delete($path);
        } catch (\Throwable $e) {
            Log::warning('QuoteImportService: could not delete PDF after failed import', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Local extraction pipeline, then AI structured extraction.
     *
     * Pipeline:
     *   PDF file
     *     → PdfTextExtractorService    (local text extraction)      [Stage 1]
     *     → QuoteLineExtractorService  (filter to quantity-prefixed lines)
     *     → EquipmentNormalizerService (canonical brand + title-case)
     *     → EquipmentLineParserService (split qty/name into structs)
     *     → QuoteExtractionPrompt      (structured JSON prompt)     [Stage 2]
     *     → AIManager                  (AI model — standardize only)
     *
     * No raw PDF binary or full document text is ever sent to the AI model.
     *
     * @throws AIGenerationException
     */
    private function extract(string $storagePath, ?string $provider): array
    {
        $absolutePath = Storage::disk('local')->path($storagePath);

        $text  = $this->pdfExtractor->extract($absolutePath);
        $parsed = $this->quoteParser->parse($text);
        $lines = $this->lineExtractor->extractEquipmentLines($text);
        $lines = $this->normalizer->normalize($lines);
        $items = $this->lineParser->parse($lines);

        $ai = AIManager::run(
            new QuoteExtractionPrompt($items),
            [],
            $provider,
        );

        return $this->mergeParsedQuoteData($ai, $parsed);
    }

    /**
     * Merge parser-derived data into AI output to harden mapping reliability.
     *
     * QuoteWerks OCR exports often carry richer structured tags than the
     * quantity-line extractor can preserve. When parser equipment exists, it
     * becomes the canonical source for project mapping and equipment rows.
     */
    private function mergeParsedQuoteData(array $ai, array $parsed): array
    {
        $ref = (string) ($parsed['ref'] ?? '');
        if (($ai['qw_number'] ?? '') === '' || strtoupper((string) $ai['qw_number']) === 'RAMS-001') {
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
        // Carry forward per-room overview texts extracted from OVERVIEWTITLE/TXT tags.
        // The AI does not produce these; only the structured parser does.
        if (empty($ai['room_overviews']) && ! empty($parsed['room_overviews'])) {
            $ai['room_overviews'] = array_values((array) $parsed['room_overviews']);
        }

        if (($ai['project_name'] ?? '') === '') {
            $client = trim((string) ($ai['client_name'] ?? ''));
            $qref   = trim((string) ($ai['qw_number'] ?? ''));
            $site   = trim((string) ($ai['site_name'] ?? ''));

            if ($qref !== '' && strtoupper($qref) !== 'RAMS-001' && $client !== '') {
                $ai['project_name'] = "{$qref} - {$client}";
            } elseif ($site !== '') {
                $ai['project_name'] = $site;
            } elseif ($client !== '') {
                $ai['project_name'] = $client;
            }
        }

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
                    'category'    => self::classifyDescription($name),
                ];
            },
            (array) ($parsed['equipment'] ?? [])
        )));

        if (! empty($parserEquipment)) {
            $ai['equipment']      = $parserEquipment;
            $ai['equipment_list'] = $parserEquipment;
            $ai['line_items']     = $parserEquipment;
        }

        $ai['meta'] = array_merge((array) ($ai['meta'] ?? []), [
            'parser_confidence' => $parsed['confidence'] ?? null,
            'source'            => 'extracted',
        ]);

        return $ai;
    }

    private static function classifyDescription(string $desc): string
    {
        $d = strtolower($desc);

        $serviceKw = [
            'install', 'installation', 'commission', 'programming', 'configuration', 'setup',
            'survey', 'site survey', 'project management', 'engineering', 'labour', 'training',
            'handover', 'design', 'draw', 'tech check', 'testing', 'travel', 'accommodation',
            'logistics', 'delivery cost', 'delivery', 'pallet delivery', 'rams', 'risk assessment',
            'method statement', 'first fix', 'snagging', 'commissioning',
        ];
        $cableKw = [
            'cat5', 'cat5e', 'cat6', 'cat6a', 'cat7', 'cat8', 'cable', 'patch lead',
            'hdmi', 'displayport', 'usb', 'ethernet', 'network cable', 'coupler', 'plug',
            'connector', 'socket',
        ];
        $consumableKw = [
            'consumable', 'consumables', 'sundry', 'sundries', 'fixing', 'fixings',
            'screws', 'anchors', 'bolt', 'cable tie', 'velcro', 'tape', 'label', 'grommet',
        ];

        foreach ($serviceKw   as $kw) { if (str_contains($d, $kw)) return 'services'; }
        foreach ($cableKw     as $kw) { if (str_contains($d, $kw)) return 'cables'; }
        foreach ($consumableKw as $kw) { if (str_contains($d, $kw)) return 'consumables'; }

        return 'hardware';
    }
}
