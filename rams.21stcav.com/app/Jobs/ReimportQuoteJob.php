<?php

namespace App\Jobs;

use App\Core\Modules\QuoteImport\QuoteImportService;
use App\Models\ProjectPackage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Asynchronously re-extracts an existing project package's stored PDF via the
 * Claude PDF-vision pipeline.
 *
 * Unlike ExtractQuoteJob (which runs the full parser + AI standardisation
 * pipeline for fresh imports), this job runs the Claude-only path used by
 * QuoteImportService::reimport — same code path the synchronous re-extract
 * used to call inline.
 *
 * Why queued: the Claude call can take 60-90s on dense multi-room quotes
 * (Tilda 21CQ29531-05-OPS was the canonical defect — 9 rooms, full Crestron
 * stack). PHP default max_execution_time = 30s killed the process mid-call;
 * an nginx proxy timeout returned 504 to the browser even when PHP did
 * complete server-side. Lifting timeouts (commit ac85722) was a band-aid;
 * this is the proper fix.
 *
 * Flow:
 *   1. QuoteImportController::reextract calls QuoteImportService::reimportPending
 *      to atomically create a new ProjectPackage with status=EXTRACTING.
 *   2. Controller dispatches this job, redirects to quote-import.extracting
 *      for the new package — same status-polling page initial imports use.
 *   3. handle() invokes QuoteImportService::completePendingReimport which
 *      calls Claude, writes extracted_data, flips status to EXTRACTED.
 *   4. extractStatus endpoint polls the package's status field; redirects
 *      the user to the review page once status flips terminal.
 *   5. On failure (Claude API error, malformed JSON not caught by
 *      QuoteExtractorService::sanitiseRawJson), failed() flips status to
 *      FAILED so the extracting page surfaces an error message.
 *
 * @see App\Core\Modules\QuoteImport\QuoteImportService::reimportPending
 * @see App\Core\Modules\QuoteImport\QuoteImportService::completePendingReimport
 * @see App\Http\Controllers\QuoteImportController::reextract
 * @see App\Http\Controllers\QuoteImportController::extractStatus
 */
class ReimportQuoteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Up to two attempts. Claude transient failures should self-recover on
     * retry; persistent failures (malformed PDF, rate limit) surface as
     * STATUS_FAILED on the package after the second attempt.
     */
    public int $tries = 2;

    /**
     * 180s timeout. Generous ceiling for slow Claude responses on dense
     * multi-room quotes; the previous synchronous path was crashing at
     * 30s (PHP default) but Claude itself rarely takes > 120s. 180s
     * gives headroom without parking jobs forever.
     */
    public int $timeout = 180;

    public function __construct(
        private readonly ProjectPackage  $pending,
        private readonly User            $user,
        private readonly ?ProjectPackage $previousRevision = null,
        private readonly ?string         $provider = null,
    ) {}

    public function handle(QuoteImportService $service): void
    {
        Log::info('ReimportQuoteJob: starting', [
            'package_id'      => $this->pending->id,
            'previous_id'     => $this->previousRevision?->id,
            'revision'        => $this->pending->revision,
            'project_id'      => $this->pending->project_id,
            'provider'        => $this->provider,
        ]);

        $service->completePendingReimport(
            pending:          $this->pending,
            user:             $this->user,
            previousRevision: $this->previousRevision,
            provider:         $this->provider,
        );

        Log::info('ReimportQuoteJob: completed', [
            'package_id' => $this->pending->id,
        ]);
    }

    public function failed(\Throwable $e): void
    {
        $this->pending->update(['status' => ProjectPackage::STATUS_FAILED]);

        Log::error('ReimportQuoteJob: re-extraction failed', [
            'package_id'  => $this->pending->id,
            'previous_id' => $this->previousRevision?->id,
            'revision'    => $this->pending->revision,
            'error'       => $e->getMessage(),
        ]);
    }
}
