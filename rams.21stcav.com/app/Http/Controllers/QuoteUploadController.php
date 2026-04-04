<?php

namespace App\Http\Controllers;

use App\Jobs\ExtractRamsDraftJob;
use App\Models\RamsDocument;
use App\Services\ProjectQuoteVersionService;
use App\Services\ProjectResolverService;
use App\Services\ProjectSyncFromQuoteService;
use App\Services\QuoteParserService;
use App\Services\QuoteTextExtractorService;
use App\Services\WorkerMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Handles PDF quote uploads with full project-layer awareness.
 *
 * Upload flow:
 *   1. Validate uploaded PDF + optional field overrides.
 *   2. Store the PDF permanently — relative path → rams_documents.filename.
 *   3. Extract raw text locally (QuoteTextExtractorService).
 *   4. Parse quote/project fields locally (QuoteParserService).
 *   5. Resolve or create a Project (ProjectResolverService) — user-scoped.
 *   6. Backfill any empty project fields (ProjectSyncFromQuoteService).
 *   7. Create a quote version record (ProjectQuoteVersionService) — deduped.
 *   8. Create a RamsDocument linked to the resolved project.
 *      Guards: project_id MUST be present — throws if missing.
 *   9. Dispatch ExtractRamsDraftJob (Phase A only — no AI, no generation).
 *  10. Redirect to the processing page, which polls for up to 10 seconds and
 *      auto-redirects to the review page when extraction completes.
 *
 * No AI calls occur here. No RAMS document is generated at this stage.
 * File path source of truth: rams_documents.filename (relative path — use Storage::path() for absolute).
 * ExtractRamsDraftJob reads ONLY from $rams->filename.
 */
class QuoteUploadController extends Controller
{
    public function __construct(
        private readonly QuoteTextExtractorService   $textExtractor,
        private readonly QuoteParserService          $parser,
        private readonly ProjectResolverService      $resolver,
        private readonly ProjectSyncFromQuoteService $syncer,
        private readonly ProjectQuoteVersionService  $versioner,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // create — upload form
    // ─────────────────────────────────────────────────────────────────────────

    public function create(): View
    {
        return view('rams.upload');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // store — validate, persist, dispatch
    // ─────────────────────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        // ── Validate ──────────────────────────────────────────────────────────
        $request->validate([
            'quote_pdf'         => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'client_name'       => ['nullable', 'string', 'max:255'],
            'site_address'      => ['nullable', 'string', 'max:500'],
            'project_ref'       => ['nullable', 'string', 'max:100'],
            'project_name'      => ['nullable', 'string', 'max:255'],
            'works_description' => ['nullable', 'string', 'max:2000'],
            'doc_author'        => ['nullable', 'string', 'max:100'],
        ]);

        $pdf              = $request->file('quote_pdf');
        $originalFilename = $pdf->getClientOriginalName();

        // ── 1. Store PDF — always use 'local' disk; relative path stored ─────────
        $path         = $pdf->store('rams/uploads', 'local');
        $absolutePath = Storage::disk('local')->path($path);

        // ── 2. Build form data for downstream services ────────────────────────
        $providerKey = config('ai.default', 'claude');

        $formData = array_filter([
            'client_name'       => $request->input('client_name'),
            'site_address'      => $request->input('site_address'),
            'project_ref'       => $request->input('project_ref'),
            'project_name'      => $request->input('project_name'),
            'works_description' => $request->input('works_description'),
            'doc_author'        => $request->input('doc_author'),
            'source'            => 'quote_upload',
            'original_filename' => $originalFilename,
            'stored_filename'   => $path,
        ], fn ($v) => $v !== null);

        // ── 3. Extraction deferred to queue ───────────────────────────────────
        // Text extraction is performed exclusively inside ExtractRamsDraftJob.
        // Doing it here caused silent failures when the storage directory did
        // not yet exist, and produced low-quality parsed data from binary reads.
        $parsed = [];
        Log::info('QuoteUploadController: upload stored, extraction deferred to queue', [
            'file' => $path,
        ]);

        // ── 4. Resolve or create Project (user-scoped, no AI) ─────────────────
        $resolution = $this->resolver->resolve($parsed, $formData, $request->user());
        $project    = $resolution['project'];

        Log::info('QuoteUploadController: project resolved', [
            'project_id' => $project->id,
            'action'     => $resolution['action'],
            'reason'     => $resolution['reason'],
        ]);

        // ── 5. Backfill empty project fields from this quote (matched only) ───
        if ($resolution['action'] === 'matched') {
            $this->syncer->sync($project, $parsed, $formData);
        }

        // ── 6. Create quote version record (deduped) ──────────────────────────
        $this->versioner->create(
            $project,
            $request->user(),
            $originalFilename,
            $path,                 // relative path stored in stored_filename
            $parsed,
            $formData,
        );

        // ── 7. Guard: project_id must be present before creating RamsDocument ─
        if (empty($project->id)) {
            throw new \RuntimeException(
                'QuoteUploadController: cannot create RamsDocument — project_id is missing.'
            );
        }

        // ── 8. Create the RAMS placeholder record ─────────────────────────────
        //
        // filename  = relative path to the uploaded PDF (e.g. rams/uploads/uuid.pdf).
        //             Use Storage::path($rams->filename) to get the absolute path.
        //             ExtractRamsDraftJob reads from $rams->filename exclusively.
        //             BuildRamsDocumentJob will overwrite filename with the DOCX path.
        //
        // status    = STATUS_UPLOADED — never rely on the DB column default.
        //
        // project_id is ALWAYS set — the resolver guarantees a project exists.
        //
        $ramsDocument = RamsDocument::create([
            'user_id'        => $request->user()->id,
            'project_id'     => $project->id,
            'project_ref'    => $request->input('project_ref') ?? ($parsed['ref'] ?? null),
            'project_name'   => $request->input('project_name', $project->name ?? ''),
            'client_name'    => $request->input('client_name', $project->client_name ?? ''),
            'site_address'   => $request->input('site_address', $project->site_address ?? ''),
            'ai_provider'    => $providerKey,
            'ai_model'       => config("ai.providers.{$providerKey}.model", ''),
            'form_data'      => $formData,
            'extracted_data' => null,
            'reviewed_data'  => null,
            'generated_data' => null,
            'filename'       => $path,                // relative path — use Storage::path() for absolute
            'status'         => RamsDocument::STATUS_UPLOADED,
        ]);

        // ── 9. Ensure worker is running, then dispatch Phase A extraction job ──
        app(WorkerMonitorService::class)->ensureRunning();
        ExtractRamsDraftJob::dispatch($ramsDocument->id);

        // ── 10. Redirect to the processing page ───────────────────────────────
        // The processing page polls for up to 10 seconds. If extraction finishes
        // in time, it auto-redirects to the review page. Otherwise it shows a
        // "still processing" state with a link back to the project page.
        return redirect()->route('rams.processing', $ramsDocument);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // processing — lightweight waiting page shown immediately after upload
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Show the processing/waiting page for a RAMS document.
     *
     * If the document is already in awaiting_review by the time the user hits
     * this page (fast worker), redirect straight to the review page — no need
     * to show a spinner at all.
     *
     * If the document has failed, redirect to the project page with an error.
     */
    public function processing(Request $request, RamsDocument $rams): View|RedirectResponse
    {
        $this->authoriseRamsAccess($request, $rams);

        // Already ready — skip the waiting screen entirely.
        if ($rams->status === RamsDocument::STATUS_AWAITING_REVIEW) {
            return redirect()->route('rams.quote-review.show', $rams);
        }

        // Already failed — go to project page with error banner.
        if ($rams->status === RamsDocument::STATUS_FAILED) {
            $destination = $rams->project_id
                ? route('projects.show', $rams->project_id)
                : route('rams.index');

            return redirect($destination)
                ->with('error', 'Quote extraction failed. Please try uploading the PDF again.');
        }

        return view('rams.processing', [
            'rams'        => $rams,
            'projectUrl'  => $rams->project_id
                ? route('projects.show', $rams->project_id)
                : route('rams.index'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // checkReady — JSON status endpoint polled by the processing view
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Return the current extraction status of a RAMS document as JSON.
     *
     * Called by the processing view's client-side polling loop every 2 seconds.
     * Returns a small JSON object so the view can decide whether to redirect,
     * show an error, or keep waiting.
     *
     * Response shape:
     * {
     *   "status":      "uploaded" | "awaiting_review" | "failed" | …,
     *   "ready":       true | false,
     *   "failed":      true | false,
     *   "review_url":  string | null,   // set when ready === true
     *   "project_url": string           // always set; used for fallback navigation
     * }
     */
    public function checkReady(Request $request, RamsDocument $rams): JsonResponse
    {
        $this->authoriseRamsAccess($request, $rams);

        // Always re-read from the database so we get the latest status.
        $rams->refresh();

        $ready  = $rams->status === RamsDocument::STATUS_AWAITING_REVIEW;
        $failed = $rams->status === RamsDocument::STATUS_FAILED;

        return response()->json([
            'status'      => $rams->status,
            'ready'       => $ready,
            'failed'      => $failed,
            'review_url'  => $ready ? route('rams.quote-review.show', $rams) : null,
            'project_url' => $rams->project_id
                ? route('projects.show', $rams->project_id)
                : route('rams.index'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Abort with 403 unless the current user owns this RAMS document or is admin.
     */
    private function authoriseRamsAccess(Request $request, RamsDocument $rams): void
    {
        $user = $request->user();

        if ($user->id !== $rams->user_id && ! $user->isAdmin()) {
            abort(403);
        }
    }
}
