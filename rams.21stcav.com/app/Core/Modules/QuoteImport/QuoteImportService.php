<?php

namespace App\Core\Modules\QuoteImport;

use App\Core\Modules\Projects\ProjectService;
use App\Models\Project;
use App\Models\ProjectActivityLog;
use App\Models\ProjectPackage;
use App\Models\User;
use App\Services\PdfTextExtractorService;
use App\Services\ProjectQuoteVersionService;
use App\Services\QuoteParserService;
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
 *   3. Parse structured data from text via QuoteParserService (deterministic, no AI).
 *   4. Persist everything in a transaction: ProjectPackage + optional Project.
 *   5. Log the import event to the project activity log.
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
     * @param  string|null    $provider       Unused — retained for call-site compatibility.
     * @return ProjectPackage                 The saved package (with extracted_data, equipment_list, cable_list).
     *
     * @throws RuntimeException  On file storage or DB failure.
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
            // 2. Extract structured data (deterministic parser — no AI)
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
                if ($project !== null) {
                    $this->projectService->log(
                        project:     $project,
                        user:        $user,
                        action:      ProjectActivityLog::ACTION_PACKAGE_IMPORTED,
                        description: "{$user->name} re-extracted quote (revision {$package->revision}).",
                        metadata:    ['package_id' => $package->id, 'previous_id' => $existing->id],
                    );
                }
            }

            return $package;
        });
    }

    // ── Array-based import (test / SQL-import helper) ─────────────────────────

    /**
     * Import a quote from a pre-extracted data array (no PDF required).
     *
     * Intended for testing and for SQL-based import paths where text extraction
     * is already done upstream. Accepts the same extracted data shape that
     * import() would produce after AI extraction, and applies the same
     * project auto-create and package linking logic.
     *
     * @param  User   $user  The authenticated user performing the import.
     * @param  array  $data  Pre-extracted data: must include client_name, site_address.
     *                       Optional keys: ref, name, works_description, equipment_list, cable_list, extracted_data.
     * @return ProjectPackage The saved package linked to an auto-created or matched project.
     */
    public function importFromData(User $user, array $data): ProjectPackage
    {
        return DB::transaction(function () use ($user, $data) {
            // ── Resolve or auto-create project by client+site (D-02) ──
            $clientName  = $data['client_name'] ?? '';
            $siteAddress = $data['site_address'] ?? '';

            $project = Project::whereRaw('LOWER(client_name) = ?', [strtolower($clientName)])
                ->whereRaw('LOWER(site_address) = ?', [strtolower($siteAddress)])
                ->whereNull('deleted_at')
                ->first();

            if ($project === null) {
                $project = $this->projectService->create($user, [
                    'name'              => $data['name'] ?? ($clientName . ' — ' . $siteAddress),
                    'ref'               => $data['ref'] ?? null,
                    'client_name'       => $clientName,
                    'site_address'      => $siteAddress,
                    'works_description' => $data['works_description'] ?? null,
                ]);
            }

            // ── Create a minimal ProjectPackage from the supplied data ──
            $nextRevision = ProjectPackage::where('project_id', $project->id)->max('revision') ?? 0;

            $package = ProjectPackage::create([
                'project_id'     => $project->id,
                'user_id'        => $user->id,
                'quote_filename' => 'array-import.json',
                'quote_path'     => 'quote-imports/array-import.json',
                'extracted_data' => $data['extracted_data'] ?? [],
                'equipment_list' => $data['equipment_list'] ?? [],
                'cable_list'     => $data['cable_list'] ?? [],
                'revision'       => $nextRevision + 1,
                'status'         => ProjectPackage::STATUS_EXTRACTED,
            ]);

            Log::info('QuoteImportService: importFromData completed', [
                'project_id' => $project->id,
                'package_id' => $package->id,
                'user_id'    => $user->id,
            ]);

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
        // Guard: only fire when project is in quote_imported — canTransitionTo() also
        // returns true for backward transitions (engineering → survey_pending), which
        // must NOT be triggered automatically on quote confirm.
        $linkedProject = $confirmed->project;
        if (
            $linkedProject?->status === Project::STATUS_QUOTE_IMPORTED &&
            $linkedProject->canTransitionTo(Project::STATUS_SURVEY_PENDING)
        ) {
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
     * Extract structured quote data from a stored PDF using the deterministic parser.
     *
     * Pipeline:
     *   PDF file → PdfTextExtractorService (local text extraction)
     *            → QuoteParserService::parse() (fully local PHP — no AI)
     *
     * Returns the structured array produced by QuoteParserService::parse().
     */
    private function extract(string $storagePath, ?string $provider): array
    {
        $absolutePath = Storage::disk('local')->path($storagePath);
        $text         = $this->pdfExtractor->extract($absolutePath);

        return $this->quoteParser->parse($text);
    }
}
