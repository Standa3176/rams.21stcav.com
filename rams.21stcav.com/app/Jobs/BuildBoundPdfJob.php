<?php

namespace App\Jobs;

use App\Mail\BoundPdfReadyMail;
use App\Mail\DocumentGenerationFailedMail;
use App\Models\Project;
use App\Services\Drawings\BoundPdfBuilderService;
use App\Services\NotificationRecipientResolver;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Phase 20 Plan 01 — async assembly of a project's bound multi-page PDF
 * (DRAW-21).
 *
 * MIRRORS BuildSchematicJob's shape (tries=2, timeout=300, idempotency-aware
 * mail, failed() admin alert) BUT diverges in one critical way:
 *
 *   ⚠ Constructor takes `int $projectId`, NOT `int $drawingId`. Bound PDFs are
 *     PROJECT-LEVEL artifacts that aggregate every drawing in the project —
 *     not drawing-level like a schematic or rack render. Every reference
 *     inside this job uses `$this->projectId`.
 *
 * Queue: 'drawings' — Plan 20-02 wires the dedicated worker. Setting the
 * queue name now is forward-compatible: Laravel falls back to 'default' when
 * the queue isn't configured separately, which matches the current state.
 *
 * Concurrency: WithoutOverlapping middleware keyed by 'bound-pdf-{projectId}'
 * with a 60s release window — prevents a double-click on "Download Bound PDF"
 * from stacking two concurrent assemblies for the same project.
 *
 * Failure semantics:
 *   - Per-drawing render failures are isolated INSIDE BoundPdfBuilderService::build
 *     (logged + skipped, register row gets "[render failed]" prefix).
 *   - Whole-job failures (FPDI exception, disk full, etc) flow to handle()'s
 *     try/catch → status NOT applicable (project-level), error email to admins
 *     via failed() hook.
 *
 * @see BoundPdfBuilderService — actual concat logic
 * @see BoundPdfReadyMail — completion notification
 */
class BuildBoundPdfJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public string $queue = 'drawings';

    /**
     * NOTE: project-level, NOT drawing-level. Distinct from
     * BuildSchematicJob::$drawingId.
     */
    public function __construct(public readonly int $projectId) {}

    /**
     * WithoutOverlapping per project — a double-click never assembles the
     * bound PDF twice in parallel. 60s release window matches the typical
     * end-to-end build time (~5-15s for typical projects + safety margin).
     */
    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('bound-pdf-'.$this->projectId))
                ->releaseAfter(60),
        ];
    }

    // =========================================================================
    // HANDLE
    // =========================================================================

    public function handle(BoundPdfBuilderService $builder): void
    {
        $project = Project::find($this->projectId);

        if (! $project) {
            Log::warning('BuildBoundPdfJob: project not found, discarding', [
                'project_id' => $this->projectId,
            ]);

            return;
        }

        Log::info('BuildBoundPdfJob: starting', [
            'project_id' => $this->projectId,
            'attempt'    => $this->attempts(),
        ]);

        try {
            $result = $builder->build($project);

            Log::info('BuildBoundPdfJob: completed successfully', [
                'project_id'     => $this->projectId,
                'attempt'        => $this->attempts(),
                'version'        => $result['version'] ?? null,
                'register_count' => count($result['register'] ?? []),
                'failed_count'   => count($result['failed_drawings'] ?? []),
            ]);

            // ── Completion email (best-effort; no DB idempotency column) ─────
            // The WithoutOverlapping middleware + user-initiated dispatch are
            // sufficient dedupe — adding a dedicated send_at column for a
            // download-trigger notification adds DB noise without protecting
            // against a real duplicate-send class of bug.
            try {
                $resolver  = app(NotificationRecipientResolver::class);
                $recipient = $resolver->resolveProjectRecipient($project);
                if ($recipient?->email) {
                    $pending = Mail::to($recipient->email);
                    $bcc     = config('rams.notifications.bcc');
                    if (is_string($bcc) && trim($bcc) !== '') {
                        $pending->bcc(trim($bcc));
                    }
                    $pending->send(new BoundPdfReadyMail($project, $result['path']));
                    Log::info('BuildBoundPdfJob: completion email dispatched', [
                        'project_id' => $this->projectId,
                        'recipient'  => $recipient->email,
                    ]);
                }
            } catch (\Throwable $mailErr) {
                Log::warning('BuildBoundPdfJob: completion email send failed', [
                    'project_id' => $this->projectId,
                    'error'      => $mailErr->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::error('BuildBoundPdfJob: failed', [
                'project_id'      => $this->projectId,
                'attempt'         => $this->attempts(),
                'exception_class' => get_class($e),
                'error'           => $e->getMessage(),
                'file'            => $e->getFile(),
                'line'            => $e->getLine(),
            ]);

            throw $e;
        }
    }

    // =========================================================================
    // FAILURE HOOK — called by the queue after all retries are exhausted
    // =========================================================================

    public function failed(\Throwable $e): void
    {
        Log::error('BuildBoundPdfJob: all retries exhausted', [
            'project_id'      => $this->projectId,
            'exception_class' => get_class($e),
            'error'           => $e->getMessage(),
        ]);

        // NOTF-04 — admin failure alert. No idempotency column on the project
        // for this notification (bound PDFs are user-initiated; double-fire is
        // bounded by tries=2). Mirrors BuildSchematicJob::failed shape.
        $project = Project::find($this->projectId);
        if (! $project) {
            return;
        }

        try {
            $resolver     = app(NotificationRecipientResolver::class);
            $admins       = $resolver->resolveAdminRecipients();
            $rawError     = (string) $e->getMessage();
            $errorMessage = $rawError !== '' ? substr($rawError, 0, 500) : null;
            $bcc          = config('rams.notifications.bcc');

            foreach ($admins as $admin) {
                if (! $admin->email) {
                    continue;
                }
                $pending = Mail::to($admin->email);
                if (is_string($bcc) && trim($bcc) !== '') {
                    $pending->bcc(trim($bcc));
                }
                $pending->send(new DocumentGenerationFailedMail(
                    documentType: 'Bound project drawings PDF',
                    projectRef:   (string) ($project->ref ?? ''),
                    projectName:  (string) ($project->name ?? ''),
                    errorMessage: $errorMessage,
                    detailUrl:    route('projects.drawings.index', ['project' => $project->id]),
                ));
            }
        } catch (\Throwable $mailErr) {
            Log::warning(
                'BuildBoundPdfJob: failure-alert email send failed',
                ['project_id' => $this->projectId, 'error' => $mailErr->getMessage()]
            );
        }
    }
}
