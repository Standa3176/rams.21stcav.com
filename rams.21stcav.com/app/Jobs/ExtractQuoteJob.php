<?php

namespace App\Jobs;

use App\Models\ProjectPackage;
use App\Models\Project;
use App\Models\ProjectActivityLog;
use App\Models\User;
use App\Services\QuoteExtractorService;
use App\Services\ProjectQuoteVersionService;
use App\Core\Modules\Projects\ProjectService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ExtractQuoteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 180;

    public function __construct(
        private readonly ProjectPackage $package,
        private readonly User           $user,
        private readonly bool           $createProject,
    ) {}

    public function handle(
        QuoteExtractorService      $extractor,
        ProjectService             $projectService,
        ProjectQuoteVersionService $quoteVersioner,
    ): void {
        $absolutePath = Storage::disk('local')->path($this->package->quote_path);
        $extracted    = $extractor->extractFromPath($absolutePath);

        // Map output → import pipeline shape
        $extracted['equipment_list']  = $extracted['line_items'] ?? [];
        $extracted['cable_hints']     = [];
        $extracted['quote_reference'] = $extracted['qw_number'] ?? '';

        DB::transaction(function () use ($extracted, $projectService, $quoteVersioner) {
            // Auto-match project by client+site
            $project     = null;
            $clientName  = $extracted['client_name']  ?? null;
            $siteAddress = $extracted['site_address']  ?? null;

            if ($clientName && $siteAddress) {
                $project = Project::whereRaw('LOWER(client_name) = ?', [strtolower($clientName)])
                    ->whereRaw('LOWER(site_address) = ?', [strtolower($siteAddress)])
                    ->whereNull('deleted_at')
                    ->first();
            }

            if ($project === null && $this->createProject) {
                $project = $projectService->create($this->user, [
                    'name'              => $extracted['project_name']     ?? 'AV Installation',
                    'ref'               => $extracted['qw_number']        ?? null,
                    'client_name'       => $clientName ?? 'Client',
                    'site_address'      => $siteAddress ?? '',
                    'works_description' => $extracted['works_description'] ?? null,
                ]);
            }

            $this->package->update([
                'project_id'        => $project?->id,
                'extracted_data'    => $extracted,
                'equipment_list'    => $extracted['equipment_list'] ?? [],
                'cable_list'        => $extracted['cable_hints']    ?? [],
                'works_description' => $extracted['works_description'] ?? null,
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
                        'line_item_count' => count($extracted['line_items'] ?? []),
                    ],
                );
            }
        });

        Log::info('ExtractQuoteJob: extraction complete', [
            'package_id' => $this->package->id,
            'user_id'    => $this->user->id,
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
}
