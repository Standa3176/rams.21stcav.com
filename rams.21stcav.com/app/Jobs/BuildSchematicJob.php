<?php

namespace App\Jobs;

use App\Mail\DocumentGenerationFailedMail;
use App\Mail\DrawingReadyMail;
use App\Models\ProjectDrawing;
use App\Services\Drawings\SchematicGeneratorService;
use App\Services\NotificationRecipientResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Async schematic generation job.
 *
 * Plan 17-02 Task 2 wired this job to the real D2-driven
 * SchematicGeneratorService — the Plan 17-01 placeholder SVG block has
 * been removed. The mail dispatch + failed() hook below stay UNCHANGED
 * from Plan 17-01. Plan 17-03 Task 1 will insert a thumbnail render block
 * AFTER the generator call and BEFORE the completion email send.
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

    public function handle(SchematicGeneratorService $generator): void
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
            // ── Plan 17-02 REAL generator (replaces Plan 17-01 placeholder) ──
            // SchematicGeneratorService::generate() consumes the canonical
            // adjacency from DrawingDataResolverService, builds D2 source
            // via SchematicD2SourceBuilder, shells out to the D2 CLI, and
            // sets generated_svg + filename + status=READY itself. The
            // surrounding mail dispatch and failed() hook below stay
            // UNCHANGED from Plan 17-01.
            //
            // ── Plan 17-03 thumbnail-render insertion point ──────────────
            // Plan 17-03 Task 1 will insert a Browsershot thumbnail render
            // block immediately AFTER $generator->generate() succeeds and
            // BEFORE the completion email dispatch below. Disjoint from the
            // generator call — generator owns generated_svg / filename /
            // status; thumbnail block will own thumbnail_png_path. Leave
            // this comment marker so the Plan 03 executor can grep for the
            // exact insertion point.
            $generator->generate($drawing);
            // ── End Plan 17-02 generator block ───────────────────────────

            Log::info('BuildSchematicJob: completed successfully', [
                'drawing_id' => $this->drawingId,
                'attempt' => $this->attempts(),
                'filename' => $drawing->filename,
                'status' => 'ready',
            ]);

            // ── Plan 17-03 thumbnail render (Warning 6 disjoint region) ─────────────
            // After the generator writes generated_svg + sets STATUS_READY, render a
            // PNG thumbnail via the centralised PdfRenderService::fromBladeAsPng path
            // (Warning 8 — no inline Browsershot). Failure is non-fatal — the SVG is
            // the primary artifact; a missing thumbnail just means the index card
            // won't have a preview image until the next regeneration.
            $drawing->refresh();
            if ($drawing->status === ProjectDrawing::STATUS_READY) {
                try {
                    $renderer = app(\App\Services\Drawings\DrawingExportRendererService::class);
                    $thumbPath = $renderer->renderPng($drawing, 400); // 400px-wide thumbnail
                    $relative = 'drawings/'.basename($thumbPath);
                    $drawing->update(['thumbnail_png_path' => $relative]);
                } catch (\Throwable $thumbErr) {
                    Log::warning('BuildSchematicJob: thumbnail render failed (non-fatal)', [
                        'drawing_id' => $drawing->id,
                        'error' => $thumbErr->getMessage(),
                    ]);
                    // Do NOT mark drawing as failed — the SVG is the primary artifact.
                }
            }
            // ── End Plan 17-03 thumbnail block ──────────────────────────────────────

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
