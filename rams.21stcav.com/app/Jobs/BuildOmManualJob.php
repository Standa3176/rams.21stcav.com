<?php

namespace App\Jobs;

use App\Core\Modules\OMManual\OmManualGeneratorService;
use App\Models\OmManual;
use App\Models\User;
use App\Services\OmManualDocxService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Async O&M generation job.
 *
 * Status transitions:
 *   generating → draft   (success)
 *   generating → failed  (any exception, timeout, or retry exhaustion)
 *
 * The OmManual record is already in status 'generating' when this job starts.
 */
class BuildOmManualJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries   = 2;
    public int $timeout = 300;

    public function __construct(
        public readonly int $omManualId,
    ) {}

    // =========================================================================
    // HANDLE
    // =========================================================================

    public function handle(
        OmManualGeneratorService $generator,
        OmManualDocxService      $docxService,
    ): void {
        $manual = OmManual::find($this->omManualId);

        if (! $manual) {
            Log::warning('BuildOmManualJob: record not found, discarding', [
                'om_manual_id' => $this->omManualId,
            ]);
            return;
        }

        // ── Ensure status is generating ──────────────────────────────────────
        if ($manual->status !== OmManual::STATUS_GENERATING) {
            $manual->update(['status' => OmManual::STATUS_GENERATING]);
        }

        Log::info('BuildOmManualJob: starting', [
            'om_manual_id' => $this->omManualId,
            'attempt'      => $this->attempts(),
            'status'       => 'generating',
        ]);

        try {
            // Pass 2: AI generates the full O&M content.
            $provider      = config('ai.default', 'claude');
            $user          = User::find($manual->user_id) ?? User::first();
            $generatedData = $generator->generateContent(
                manual:   $manual,
                user:     $user,
                provider: $provider,
            );

            Log::info('BuildOmManualJob: content generated, starting DOCX build', [
                'om_manual_id' => $this->omManualId,
                'attempt'      => $this->attempts(),
            ]);

            $manual->update([
                'generated_data' => $generatedData,
                'status'         => OmManual::STATUS_DRAFT,
                'error_message'  => null,
            ]);

            // Build the .docx file.
            $docxService->build($generatedData, $manual);

            Log::info('BuildOmManualJob: completed successfully', [
                'om_manual_id' => $this->omManualId,
                'attempt'      => $this->attempts(),
                'filename'     => $manual->filename,
                'status'       => 'draft',
            ]);

            // Phase 09 / NOTF-01 — completion notification (idempotent via completion_email_sent_at).
            $manual->refresh();
            if ($manual->status === OmManual::STATUS_DRAFT
                && $manual->completion_email_sent_at === null) {

                // Set timestamp FIRST (RESEARCH 'Idempotency Pattern') so a retry sees it set.
                $manual->update(['completion_email_sent_at' => now()]);

                try {
                    $resolver  = app(\App\Services\NotificationRecipientResolver::class);
                    $recipient = $resolver->resolveProjectRecipient($manual->project);
                    if ($recipient?->email) {
                        $pending = \Illuminate\Support\Facades\Mail::to($recipient->email);
                        $bcc = config('rams.notifications.bcc');
                        if (is_string($bcc) && trim($bcc) !== '') {
                            $pending->bcc(trim($bcc));
                        }
                        $pending->send(new \App\Mail\OmManualReadyMail($manual));
                        Log::info(
                            'BuildOmManualJob: completion email dispatched',
                            ['om_manual_id' => $manual->id, 'recipient' => $recipient->email]
                        );
                    }
                } catch (\Throwable $mailErr) {
                    Log::warning(
                        'BuildOmManualJob: completion email send failed',
                        ['om_manual_id' => $manual->id, 'error' => $mailErr->getMessage()]
                    );
                    // Do NOT clear completion_email_sent_at — D-14.
                }
            }
        } catch (\Throwable $e) {
            Log::error('BuildOmManualJob: failed', [
                'om_manual_id'    => $this->omManualId,
                'attempt'         => $this->attempts(),
                'exception_class' => get_class($e),
                'error'           => $e->getMessage(),
                'file'            => $e->getFile(),
                'line'            => $e->getLine(),
            ]);

            // Guarantee status leaves "generating"
            try {
                $manual->update([
                    'status'        => OmManual::STATUS_FAILED,
                    'error_message' => substr($e->getMessage(), 0, 500),
                ]);
            } catch (\Throwable $dbErr) {
                Log::critical('BuildOmManualJob: could not set failed status', [
                    'om_manual_id' => $this->omManualId,
                    'db_error'     => $dbErr->getMessage(),
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
        Log::error('BuildOmManualJob: all retries exhausted', [
            'om_manual_id'    => $this->omManualId,
            'exception_class' => get_class($e),
            'error'           => $e->getMessage(),
        ]);

        try {
            OmManual::find($this->omManualId)
                ?->update([
                    'status'        => OmManual::STATUS_FAILED,
                    'error_message' => 'All retries exhausted: ' . substr($e->getMessage(), 0, 400),
                ]);
        } catch (\Throwable $dbErr) {
            Log::critical('BuildOmManualJob::failed: could not set failed status', [
                'om_manual_id' => $this->omManualId,
                'db_error'     => $dbErr->getMessage(),
            ]);
        }

        // Phase 09 / NOTF-04 — admin failure alert (idempotent via failed_email_sent_at).
        $record = OmManual::find($this->omManualId);
        if ($record && $record->failed_email_sent_at === null) {
            $record->update(['failed_email_sent_at' => now()]);

            try {
                $resolver = app(\App\Services\NotificationRecipientResolver::class);
                $admins   = $resolver->resolveAdminRecipients();
                // Truncate error_message to 500 chars (NOTF-04c).
                $rawError     = (string) ($record->error_message ?? $e?->getMessage() ?? '');
                // substr of error_message, 0, 500 — caps the body to NOTF-04c budget.
                $errorMessage = $rawError !== '' ? substr($rawError, 0, 500) : null;

                $bcc = config('rams.notifications.bcc');

                foreach ($admins as $admin) {
                    if (! $admin->email) {
                        continue;
                    }
                    $pending = \Illuminate\Support\Facades\Mail::to($admin->email);
                    if (is_string($bcc) && trim($bcc) !== '') {
                        $pending->bcc(trim($bcc));
                    }
                    $pending->send(new \App\Mail\DocumentGenerationFailedMail(
                        documentType: 'O&M Manual',
                        projectRef:   $record->project_ref,
                        projectName:  (string) $record->project_name,
                        errorMessage: $errorMessage,
                        detailUrl:    route('om-manuals.edit', $record),
                    ));
                }
            } catch (\Throwable $mailErr) {
                Log::warning(
                    'BuildOmManualJob: failure-alert email send failed',
                    ['om_manual_id' => $this->omManualId, 'error' => $mailErr->getMessage()]
                );
            }
        }
    }
}
