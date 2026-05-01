<?php

namespace App\Jobs;

use App\Mail\DocumentGenerationFailedMail;
use App\Mail\DrawingReadyMail;
use App\Models\ProjectDrawing;
use App\Services\NotificationRecipientResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Async schematic generation job.
 *
 * Plan 17-01 placeholder: writes a stub SVG so Plans 02/03 can be planned
 * and tested end-to-end. Plan 17-02 Task 2 REPLACES the placeholder body
 * with the real D2-driven SchematicGeneratorService call. The mail
 * dispatch + failed() hook below stay UNCHANGED across that handover.
 *
 * Status transitions:
 *   draft|generating → ready    (success)
 *   draft|generating → failed   (any exception, timeout, or retry exhaustion)
 *
 * Idempotency:
 *   completion_email_sent_at — set BEFORE send (NOTF-01 / D-14 pattern from
 *                              BuildOmManualJob)
 *   failed_email_sent_at     — set BEFORE send (NOTF-04)
 */
class BuildSchematicJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public function __construct(public readonly int $drawingId) {}

    // =========================================================================
    // HANDLE
    // =========================================================================

    public function handle(): void
    {
        $drawing = ProjectDrawing::find($this->drawingId);

        if (! $drawing) {
            Log::warning('BuildSchematicJob: record not found, discarding', [
                'drawing_id' => $this->drawingId,
            ]);

            return;
        }

        // ── Ensure status is generating ──────────────────────────────────────
        if ($drawing->status !== ProjectDrawing::STATUS_GENERATING) {
            $drawing->update(['status' => ProjectDrawing::STATUS_GENERATING]);
        }

        Log::info('BuildSchematicJob: starting', [
            'drawing_id' => $this->drawingId,
            'attempt' => $this->attempts(),
            'kind' => $drawing->kind,
        ]);

        try {
            // ── Plan 17-01 placeholder body — Plan 17-02 REPLACES this block ──
            // Plan 02 Task 2 will inject SchematicGeneratorService into handle()
            // and call $generator->generate($drawing); the mail dispatch +
            // failed() hook below stay untouched across that handover.
            $placeholderSvg = '<?xml version="1.0" encoding="UTF-8"?>'
                .'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 400 200">'
                .'<text x="20" y="100" font-family="sans-serif" font-size="14">'
                .'Phase 17 Plan 02 will implement schematic generation'
                .'</text></svg>';

            $filename = sprintf(
                'schematic-%d-v%d-%s.svg',
                $drawing->id,
                $drawing->version,
                strtolower((string) Str::ulid()),
            );

            $drawing->update([
                'generated_svg' => $placeholderSvg,
                'filename' => $filename,
                'status' => ProjectDrawing::STATUS_READY,
                'error_message' => null,
            ]);
            // ── End Plan 17-01 placeholder body ──────────────────────────────

            Log::info('BuildSchematicJob: completed successfully', [
                'drawing_id' => $this->drawingId,
                'attempt' => $this->attempts(),
                'filename' => $drawing->filename,
                'status' => 'ready',
            ]);

            // ── Idempotent completion email (NOTF-01) ────────────────────────
            // Copies BuildOmManualJob verbatim — D-14 timestamp-first idempotency.
            $drawing->refresh();
            if ($drawing->status === ProjectDrawing::STATUS_READY
                && $drawing->completion_email_sent_at === null) {

                // Set timestamp FIRST so a retry sees it set and skips.
                $drawing->update(['completion_email_sent_at' => now()]);

                try {
                    $resolver = app(NotificationRecipientResolver::class);
                    $recipient = $resolver->resolveProjectRecipient($drawing->project);
                    if ($recipient?->email) {
                        $pending = Mail::to($recipient->email);
                        $bcc = config('rams.notifications.bcc');
                        if (is_string($bcc) && trim($bcc) !== '') {
                            $pending->bcc(trim($bcc));
                        }
                        $pending->send(new DrawingReadyMail($drawing));
                        Log::info(
                            'BuildSchematicJob: completion email dispatched',
                            ['drawing_id' => $drawing->id, 'recipient' => $recipient->email]
                        );
                    }
                } catch (\Throwable $mailErr) {
                    Log::warning(
                        'BuildSchematicJob: completion email send failed',
                        ['drawing_id' => $drawing->id, 'error' => $mailErr->getMessage()]
                    );
                    // Do NOT clear completion_email_sent_at — D-14.
                }
            }
        } catch (\Throwable $e) {
            Log::error('BuildSchematicJob: failed', [
                'drawing_id' => $this->drawingId,
                'attempt' => $this->attempts(),
                'exception_class' => get_class($e),
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            // Guarantee status leaves "generating".
            try {
                $drawing->update([
                    'status' => ProjectDrawing::STATUS_FAILED,
                    'error_message' => substr($e->getMessage(), 0, 500),
                ]);
            } catch (\Throwable $dbErr) {
                Log::critical('BuildSchematicJob: could not set failed status', [
                    'drawing_id' => $this->drawingId,
                    'db_error' => $dbErr->getMessage(),
                ]);
            }

            throw $e;
        }
    }

    // =========================================================================
    // FAILURE HOOK — called by the queue after all retries are exhausted
    // =========================================================================

    public function failed(\Throwable $e): void
    {
        Log::error('BuildSchematicJob: all retries exhausted', [
            'drawing_id' => $this->drawingId,
            'exception_class' => get_class($e),
            'error' => $e->getMessage(),
        ]);

        try {
            ProjectDrawing::find($this->drawingId)
                ?->update([
                    'status' => ProjectDrawing::STATUS_FAILED,
                    'error_message' => 'All retries exhausted: '.substr($e->getMessage(), 0, 400),
                ]);
        } catch (\Throwable $dbErr) {
            Log::critical('BuildSchematicJob::failed: could not set failed status', [
                'drawing_id' => $this->drawingId,
                'db_error' => $dbErr->getMessage(),
            ]);
        }

        // NOTF-04 — admin failure alert (idempotent via failed_email_sent_at).
        $record = ProjectDrawing::find($this->drawingId);
        if ($record && $record->failed_email_sent_at === null) {
            $record->update(['failed_email_sent_at' => now()]);

            try {
                $resolver = app(NotificationRecipientResolver::class);
                $admins = $resolver->resolveAdminRecipients();
                $rawError = (string) ($record->error_message ?? $e->getMessage() ?? '');
                $errorMessage = $rawError !== '' ? substr($rawError, 0, 500) : null;
                $bcc = config('rams.notifications.bcc');

                foreach ($admins as $admin) {
                    if (! $admin->email) {
                        continue;
                    }
                    $pending = Mail::to($admin->email);
                    if (is_string($bcc) && trim($bcc) !== '') {
                        $pending->bcc(trim($bcc));
                    }
                    $pending->send(new DocumentGenerationFailedMail(
                        documentType: ucfirst((string) $record->kind).' drawing',
                        projectRef: (string) ($record->project->ref ?? ''),
                        projectName: (string) ($record->project->name ?? ''),
                        errorMessage: $errorMessage,
                        detailUrl: route('projects.drawings.show', [
                            'project' => $record->project_id,
                            'drawing' => $record->id,
                        ]),
                    ));
                }
            } catch (\Throwable $mailErr) {
                Log::warning(
                    'BuildSchematicJob: failure-alert email send failed',
                    ['drawing_id' => $this->drawingId, 'error' => $mailErr->getMessage()]
                );
            }
        }
    }
}
