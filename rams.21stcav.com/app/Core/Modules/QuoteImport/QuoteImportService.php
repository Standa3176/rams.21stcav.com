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
use App\Services\ProjectQuoteVersionService;
use App\Services\EquipmentLineParserService;
use App\Services\EquipmentNormalizerService;
use App\Services\PdfTextExtractorService;
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
        private readonly ProjectQuoteVersionService $quoteVersioner,
        private readonly QuoteParserService         $quoteParser,
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

                // 4b. Create a ProjectQuote version so Quote History shows the upload
                if ($project !== null) {
                    $parsedSnapshot = [
                        'ref'         => $extracted['qw_number']    ?? $extracted['project_ref'] ?? $project->ref ?? '',
                        'client'      => $extracted['client_name']  ?? $project->client_name ?? '',
                        'site'        => $extracted['site_address'] ?? $project->site_address ?? '',
                        'line_items'  => $extracted['line_items']   ?? [],
                        'equipment'   => $extracted['equipment_list'] ?? [],
                        'cables'      => $extracted['cable_hints']  ?? [],
                        'source'      => 'quote-import',
                        'package_id'  => $package->id,
                    ];

                    $this->quoteVersioner->create(
                        project:          $project,
                        uploader:         $user,
                        originalFilename: $file->getClientOriginalName(),
                        storedFilename:   $storagePath,
                        parsed:           $parsedSnapshot,
                        formData:         [],
                    );
                }

                // 5. Activity log
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
        return DB::transaction(function () use ($user, $package, $overrides) {
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
        $lines = $this->lineExtractor->extractEquipmentLines($text);
        $lines = $this->normalizer->normalize($lines);
        $items = $this->lineParser->parse($lines);

        $ai = AIManager::run(
            new QuoteExtractionPrompt($items),
            [],
            $provider,
        );

        // Ensure equipment_list is populated (prompt returns "equipment")
        if (empty($ai['equipment_list']) && ! empty($ai['equipment'])) {
            $ai['equipment_list'] = $ai['equipment'];
        }

        // Parse project info + overview from full text (local parser)
        $parsed = $this->quoteParser->parse($text);
        $project = [
            'project_name' => (string) ($parsed['project_name'] ?? ''),
            'quote_ref'    => (string) ($parsed['ref'] ?? ''),
            'client_name'  => (string) ($parsed['client'] ?? ''),
            'site_name'    => (string) ($parsed['site_name'] ?? ''),
            'site_address' => (string) ($parsed['site'] ?? ''),
            'site_contact' => (string) ($parsed['site_contact'] ?? ''),
            'prepared_by'  => (string) ($parsed['prepared_by'] ?? ''),
            'overview'     => (string) ($parsed['overview'] ?? ''),
        ];

        $ai['project']        = $project;
        $ai['room_overviews'] = $this->buildRoomOverviews($parsed);
        $ai['meta'] = array_merge(
            $ai['meta'] ?? [],
            [
                'parser_confidence' => $parsed['confidence'] ?? null,
                'source'            => 'quote-import',
            ],
        );

        return $ai;
    }

    /**
     * Build room overview entries from parsed overview sections and equipment areas.
     */
    private function buildRoomOverviews(array $parsed): array
    {
        $sections = (array) ($parsed['overview_sections'] ?? []);
        $map = [];

        foreach ($sections as $section) {
            $title = trim((string) ($section['title'] ?? ''));
            $text  = trim((string) ($section['text']  ?? ''));
            if ($text !== '' && $title !== '') {
                $text = $this->stripLeadingTitle($title, $text);
            }
            if ($title === '') {
                continue;
            }
            $map[mb_strtolower($title)] = [
                'room'     => $title,
                'overview' => $text,
                'summary'  => '',
            ];
        }

        foreach ((array) ($parsed['equipment'] ?? []) as $item) {
            $room = trim((string) ($item['area'] ?? ''));
            if ($room === '') {
                continue;
            }
            $key = mb_strtolower($room);
            if (! isset($map[$key])) {
                $map[$key] = [
                    'room'     => $room,
                    'overview' => '',
                    'summary'  => '',
                ];
            }
        }

        return array_values($map);
    }

    private function stripLeadingTitle(string $title, string $text): string
    {
        $lines = preg_split('/\r?\n/', $text);
        if (! $lines) {
            return $text;
        }

        $first = trim((string) $lines[0]);
        if ($first !== '' && strcasecmp($first, $title) === 0) {
            array_shift($lines);
        }

        return trim(implode("\n", $lines));
    }
}
