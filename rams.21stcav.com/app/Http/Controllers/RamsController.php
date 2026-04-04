<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

use App\Http\Requests\RamsFormRequest;
use App\Jobs\BuildRamsDocumentJob;
use App\Jobs\ExtractRamsDraftJob;
use App\Mail\RamsDocumentMail;
use App\Models\HazardTemplate;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\RamsDocument;
use App\Services\AiSettingsService;
use App\Services\PdfService;
use App\Services\ProjectPackageRamsReviewService;
use App\Services\RamsBuilderService;
use App\Services\RamsDocumentRendererService;
use App\Services\RamsReviewValidatorService;
use App\Services\WordDocumentService;
use App\Services\WorkerMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class RamsController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        private readonly RamsBuilderService  $ramsBuilder,
        private readonly WordDocumentService $wordDoc,
        private readonly RamsDocumentRendererService $ramsRenderer,
        private readonly PdfService          $pdfService,
        private readonly ProjectPackageRamsReviewService $packageToReview,
        private readonly RamsReviewValidatorService      $reviewValidator,
        private readonly AiSettingsService               $aiSettings,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // index
    // ─────────────────────────────────────────────────────────────────────────

    public function index(): View
    {
        $isAdmin = auth()->user()->isAdmin();

        $showTrashed = $isAdmin && request()->boolean('show_deleted');

        $query = $isAdmin
            ? RamsDocument::query()->with(['user', 'omManual'])
            : auth()->user()->ramsDocuments()->with('omManual');

        if ($showTrashed) {
            $query = $isAdmin
                ? RamsDocument::onlyTrashed()->with(['user', 'omManual'])
                : auth()->user()->ramsDocuments()->onlyTrashed()->with('omManual');
        }

        $rams = $query->latest()->paginate(15);

        return view('rams.index', compact('rams', 'isAdmin', 'showTrashed'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // create
    // ─────────────────────────────────────────────────────────────────────────

    public function create(): View
    {
        $ppeOptions = [
            'Safety Boots',
            'Hi-Vis Vest',
            'Safety Glasses',
            'Hard Hat',
            'Dust Mask',
            'Gloves',
            'Hearing Protection',
            'Overalls',
            'Harness',
            'Face Shield',
        ];

        $personsOptions = [
            '21CAV Staff',
            'Client Staff',
            'Other Contractors',
            'Members of Public',
            'Others',
        ];

        $providers       = ['claude', 'openai', 'custom'];
        $defaultProvider = $this->aiSettings->defaultProvider();

        // Hazard templates visible to this user (global + their own)
        $hazardTemplates = HazardTemplate::visibleTo(auth()->id())
            ->orderByRaw('is_global DESC')
            ->orderBy('name')
            ->get(['id', 'name', 'description']);

        // Checkbox hazard list: pull from templates when available, else fallback
        $hazardLibrary = $hazardTemplates->isNotEmpty()
            ? $hazardTemplates->pluck('name')->values()->all()
            : [
                'Electrocution',
                'Step Ladders',
                'Slips Trips Falls',
                'Hand Tools',
                'Power Tools',
                'Asbestos',
                'Manual Handling',
                'Occupied Premises',
                'Under-Floor Working',
                'Fire on Site',
                'Onsite Traffic',
                'Dust Generation',
                'Lone Working',
                'Excessive Noise',
                'COSHH',
            ];

        return view('rams.create', compact(
            'hazardLibrary',
            'ppeOptions',
            'personsOptions',
            'providers',
            'defaultProvider',
            'hazardTemplates',
        ));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // store
    // ─────────────────────────────────────────────────────────────────────────

    public function store(RamsFormRequest $request): RedirectResponse
    {
        $validated = $request->validated();

        // 1. Persist a placeholder record so the builder has an ID for the filename
        $ramsDocument = RamsDocument::create([
            'user_id'      => auth()->id(),
            'project_ref'  => $validated['project_ref']  ?? null,
            'project_name' => $validated['project_name'] ?? '',
            'client_name'  => $validated['client_name']  ?? '',
            'site_address' => $validated['site_address'] ?? '',
            'ai_provider'  => $this->aiSettings->defaultProvider(),
            'ai_model'     => $this->aiSettings->defaultModel(),
            'form_data'    => $validated,
            'filename'     => 'pending-' . now()->format('YmdHis') . '.docx',
            'status'       => RamsDocument::STATUS_FOR_REVIEW,
        ]);

        // 2. Run the full local pipeline (classify → hazards → AI method stmt → DOCX)
        try {
            $this->ramsBuilder->buildFromForm($validated, $ramsDocument);
        } catch (\Throwable $e) {
            Log::error('RamsController: RAMS build failed', [
                'record_id' => $ramsDocument->id,
                'error'     => $e->getMessage(),
            ]);
            $ramsDocument->update(['status' => RamsDocument::STATUS_DRAFT]);

            return back()->with('error', 'The document could not be generated. Please try again.');
        }

        return redirect()->route('rams.review', $ramsDocument)
            ->with('success', 'RAMS generated — review and download below.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // generateFromProject — create + generate from reviewed project data
    // ─────────────────────────────────────────────────────────────────────────

    public function generateFromProject(Project $project): RedirectResponse
    {
        // Only owner/admin may create
        abort_if($project->user_id !== auth()->id() && auth()->user()?->role !== 'admin', 403);

        $package = $project->latestPackage;

        if (! $package || $package->status !== ProjectPackage::STATUS_REVIEWED) {
            return back()->with('error', 'No reviewed quote data found for this project. Please review the quote first.');
        }

        $reviewPayload = $this->packageToReview->build($package);

        try {
            $this->reviewValidator->validate($reviewPayload);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->with('error', 'Cannot generate RAMS — reviewed data is incomplete. Please review and save the quote data first.');
        }

        $ramsDocument = RamsDocument::create([
            'user_id'       => auth()->id(),
            'project_id'    => $project->id,
            'project_ref'   => $project->ref,
            'project_name'  => $project->name,
            'client_name'   => $project->client_name,
            'site_address'  => $project->site_address,
            'ai_provider'   => $this->aiSettings->defaultProvider(),
            'ai_model'      => $this->aiSettings->defaultModel(),
            'form_data'     => [
                'project_ref'       => $project->ref,
                'project_name'      => $project->name,
                'client_name'       => $project->client_name,
                'site_address'      => $project->site_address,
                'works_description' => $reviewPayload['method_statement_notes'] ?? $project->works_description,
            ],
            'reviewed_data' => $reviewPayload,
            'approved_by'   => auth()->id(),
            'approved_at'   => now(),
            'filename'      => 'pending-' . now()->format('YmdHis') . '.docx',
            'status'        => RamsDocument::STATUS_GENERATING,
        ]);

        app(WorkerMonitorService::class)->ensureRunning();
        BuildRamsDocumentJob::dispatch($ramsDocument->id);

        return back()->with('success', 'RAMS generation queued. The document will be ready to download shortly.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // review
    // ─────────────────────────────────────────────────────────────────────────

    public function review(RamsDocument $rams): View
    {
        $this->authorize('view', $rams);

        return view('rams.review', compact('rams'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // updateAndDownload
    // ─────────────────────────────────────────────────────────────────────────

    public function updateAndDownload(Request $request, RamsDocument $rams): BinaryFileResponse|RedirectResponse
    {
        $this->authorize('update', $rams);

        $validated = $request->validate([
            'project_name'    => ['required', 'string', 'max:255'],
            'project_ref'     => ['nullable', 'string', 'max:100'],
            'client_name'     => ['required', 'string', 'max:255'],
            'site_address'    => ['required', 'string', 'max:500'],
            'site_contact'    => ['nullable', 'string', 'max:200'],
            'document_status' => ['nullable', 'string', 'max:100'],
            'subtitle'        => ['nullable', 'string', 'max:500'],
            'project_manager'      => ['nullable', 'string', 'max:200'],
            'lead_engineer'        => ['nullable', 'string', 'max:200'],
            'additional_engineers' => ['nullable', 'string', 'max:500'],
            'programmer'           => ['nullable', 'string', 'max:200'],
        ]);

        // Merge edits into generated_data['project']
        $generatedData = $rams->generated_data ?? [];
        $generatedData['project'] = array_merge($generatedData['project'] ?? [], [
            'name'            => $validated['project_name'],
            'ref'             => $validated['project_ref'] ?? ($generatedData['project']['ref'] ?? null),
            'client'          => $validated['client_name'],
            'site_address'    => $validated['site_address'],
            'site_contact'    => $validated['site_contact'] ?? '',
            'document_status' => $validated['document_status'] ?? 'For Construction',
            'subtitle'        => $validated['subtitle'] ?? '',
            'project_manager'      => $validated['project_manager']      ?? '',
            'lead_engineer'        => $validated['lead_engineer']        ?? '',
            'additional_engineers' => $validated['additional_engineers'] ?? '',
            'programmer'           => $validated['programmer']           ?? '',
        ]);

        $rams->update([
            'project_name'   => $validated['project_name'],
            'project_ref'    => $validated['project_ref'] ?? $rams->project_ref,
            'client_name'    => $validated['client_name'],
            'site_address'   => $validated['site_address'],
            'generated_data' => $generatedData,
        ]);

        try {
            $filePath = $this->wordDoc->build($generatedData, $rams);
        } catch (\Throwable $e) {
            return back()->with('error', 'The document could not be regenerated. Please try again.');
        }

        return response()->download(
            $filePath,
            $rams->filename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        );
    }

    // ─────────────────────────────────────────────────────────────────────────
    // download
    // ─────────────────────────────────────────────────────────────────────────

    public function download(RamsDocument $rams): BinaryFileResponse|RedirectResponse
    {
        // Policy: owner OR admin (RamsDocumentPolicy::view)
        $this->authorize('view', $rams);

        $filePath = $this->resolveRamsDocxPath($rams);

        $hasDocxExt = is_string($rams->filename) && str_ends_with(strtolower($rams->filename), '.docx');

        // If missing/invalid/or not .docx, try a safe rebuild from generated_data.
        if (! $hasDocxExt || ! $rams->filename || ! file_exists($filePath) || ! $this->isValidDocx($filePath)) {
            if (! empty($rams->generated_data)) {
                try {
                    // Use the same renderer used by the pipeline to avoid OOXML mismatch
                    $this->ramsRenderer->render($rams->generated_data, $rams);
                    $filePath = $this->resolveRamsDocxPath($rams->fresh());
                } catch (\Throwable $e) {
                    Log::error('RamsController: DOCX rebuild failed during download', [
                        'record_id' => $rams->id,
                        'error'     => $e->getMessage(),
                    ]);
                }
            }
        }

        if (! $rams->filename || ! file_exists($filePath) || ! $this->isValidDocx($filePath)) {
            $msg = empty($rams->generated_data)
                ? 'DOCX download failed: document data is missing. Please click Regenerate and try again.'
                : 'DOCX download failed: the file is missing or invalid. Please click Regenerate and try again.';

            return back()->with('error', $msg);
        }

        return response()->download($filePath, $rams->filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // destroy — soft delete (file kept on disk for potential restore)
    // ─────────────────────────────────────────────────────────────────────────

    public function destroy(int $rams): RedirectResponse
    {
        $record = RamsDocument::findOrFail($rams);

        $this->authorize('delete', $record);

        $record->delete();

        Log::info('RamsController: document soft-deleted', [
            'record_id' => $record->id,
            'user_id'   => auth()->id(),
        ]);

        return redirect()->route('rams.index')
            ->with('success', 'RAMS document deleted.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // restore — admin only; un-deletes a soft-deleted record
    // ─────────────────────────────────────────────────────────────────────────

    public function restore(int $id): RedirectResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $rams = RamsDocument::withTrashed()->findOrFail($id);
        $rams->restore();

        Log::info('RamsController: document restored', [
            'record_id' => $rams->id,
            'admin_id'  => auth()->id(),
        ]);

        return back()->with('success', 'RAMS document restored successfully.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // forceDestroy — admin only; permanently deletes a soft-deleted record
    // ─────────────────────────────────────────────────────────────────────────

    public function forceDestroy(int $id): RedirectResponse
    {
        abort_unless(auth()->user()?->isAdmin(), 403);

        $rams = RamsDocument::onlyTrashed()->findOrFail($id);

        // Remove the uploaded PDF from disk if it exists
        if ($rams->filename && Storage::disk('local')->exists($rams->filename)) {
            Storage::disk('local')->delete($rams->filename);
        }

        $rams->forceDelete();

        Log::info('RamsController: document permanently deleted', [
            'record_id' => $id,
            'admin_id'  => auth()->id(),
        ]);

        return back()->with('success', 'RAMS document permanently deleted.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // updateStatus
    // ─────────────────────────────────────────────────────────────────────────

    public function updateStatus(Request $request, RamsDocument $rams): RedirectResponse
    {
        $this->authorize('update', $rams);

        $request->validate([
            'status' => [
                'required',
                'in:' . implode(',', [
                    RamsDocument::STATUS_DRAFT,
                    RamsDocument::STATUS_FOR_REVIEW,
                    RamsDocument::STATUS_APPROVED,
                    RamsDocument::STATUS_SUPERSEDED,
                ]),
            ],
        ]);

        $rams->update(['status' => $request->input('status')]);

        return back()->with('success', 'Status updated.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // downloadPdf — stream .docx converted to PDF via DomPDF
    // ─────────────────────────────────────────────────────────────────────────

    public function downloadPdf(RamsDocument $rams): BinaryFileResponse|RedirectResponse
    {
        $this->authorize('view', $rams);

        if (empty($rams->generated_data)) {
            return back()->with('error', 'No generated data found for this document.');
        }

        try {
            $pdfPath = $this->pdfService->buildRams($rams);
        } catch (\Throwable $e) {
            return back()->with('error', 'PDF generation failed: ' . $e->getMessage());
        }

        $pdfName = pathinfo($rams->filename ?? 'rams', PATHINFO_FILENAME) . '.pdf';

        return response()->download($pdfPath, $pdfName, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend();
    }

    // ─────────────────────────────────────────────────────────────────────────
    // email — send the RAMS document to a recipient
    // ─────────────────────────────────────────────────────────────────────────

    public function email(Request $request, RamsDocument $rams): RedirectResponse
    {
        $this->authorize('view', $rams);

        $request->validate([
            'recipient_email' => ['required', 'email', 'max:254'],
            'recipient_name'  => ['required', 'string', 'max:100'],
            'sender_note'     => ['nullable', 'string', 'max:1000'],
        ]);

        Mail::to($request->input('recipient_email'), $request->input('recipient_name'))
            ->send(new RamsDocumentMail(
                rams:          $rams,
                recipientName: $request->input('recipient_name'),
                senderNote:    $request->input('sender_note', ''),
            ));

        $rams->update(['email_sent_at' => now()]);

        return back()->with('success', 'Document emailed to ' . $request->input('recipient_email') . '.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // regenerate — create a fresh document, marking the old one as superseded
    // ─────────────────────────────────────────────────────────────────────────

    public function regenerate(RamsDocument $rams): RedirectResponse
    {
        $this->authorize('update', $rams);

        $formData = $rams->form_data ?? [];

        // Create a new placeholder record for the regenerated document
        $newRams = RamsDocument::create([
            'user_id'      => auth()->id(),
            'project_id'   => $rams->project_id,
            'project_ref'  => $rams->project_ref,
            'project_name' => $rams->project_name,
            'client_name'  => $rams->client_name,
            'site_address' => $rams->site_address,
            'ai_provider'  => $this->aiSettings->defaultProvider(),
            'ai_model'     => $this->aiSettings->defaultModel(),
            'form_data'    => $formData,
            'filename'     => 'pending-' . now()->format('YmdHis') . '.docx',
            'status'       => RamsDocument::STATUS_FOR_REVIEW,
        ]);

        try {
            $this->ramsBuilder->buildFromForm($formData, $newRams);
        } catch (\Throwable $e) {
            Log::error('RamsController: regenerate failed', [
                'original_id' => $rams->id,
                'new_id'      => $newRams->id,
                'error'       => $e->getMessage(),
            ]);
            $newRams->update(['status' => RamsDocument::STATUS_DRAFT]);

            return back()->with('error', 'Document rebuild failed. Please try again.');
        }

        // Mark the original as superseded, linking it to the new one
        $rams->update([
            'status'           => RamsDocument::STATUS_SUPERSEDED,
            'superseded_by_id' => $newRams->id,
        ]);

        return redirect()->route('rams.index')
            ->with('success', 'Document regenerated. The previous version has been marked as superseded.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // retryExtraction — re-queue extraction for a failed document
    // ─────────────────────────────────────────────────────────────────────────

    public function retryExtraction(RamsDocument $rams): RedirectResponse
    {
        $this->authorize('update', $rams);

        // Restore the original PDF path if the filename was overwritten by DOCX generation.
        $formData = $rams->form_data ?? [];
        $pdfPath  = $formData['stored_filename'] ?? null;

        if (! $pdfPath && ! empty($formData['original_filename']) && $rams->project_id) {
            $pq = \App\Models\ProjectQuote::where('project_id', $rams->project_id)
                ->where('original_filename', $formData['original_filename'])
                ->latest('version_number')
                ->first();
            $pdfPath = $pq?->stored_filename;
        }

        if (! $pdfPath) {
            return back()->with('error', 'Cannot re-extract: original PDF path is missing for this RAMS document.');
        }

        $rams->update([
            'status'        => RamsDocument::STATUS_UPLOADED,
            'error_message' => null,
            'filename'      => $pdfPath,
        ]);

        app(WorkerMonitorService::class)->ensureRunning();
        ExtractRamsDraftJob::dispatch($rams->id);

        Log::info('RamsController: extraction retry queued', [
            'record_id' => $rams->id,
            'user_id'   => auth()->id(),
        ]);

        return back()->with('success', 'Extraction re-queued. The document will be ready for review shortly.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // retryGeneration — dispatch generation for an approved or failed document
    // ─────────────────────────────────────────────────────────────────────────

    public function retryGeneration(RamsDocument $rams): RedirectResponse
    {
        $this->authorize('update', $rams);

        if (empty($rams->reviewed_data)) {
            return back()->with('error', 'Cannot generate RAMS — the document has not been reviewed and approved yet. Please review it first.');
        }

        if (in_array($rams->status, [
            RamsDocument::STATUS_GENERATING,
            RamsDocument::STATUS_APPROVED_FOR_GENERATION,
        ], true)) {
            return back()->with('error', 'This document is already queued for generation. Please wait.');
        }

        $rams->update([
            'status'        => RamsDocument::STATUS_GENERATING,
            'error_message' => null,
        ]);

        app(WorkerMonitorService::class)->ensureRunning();
        BuildRamsDocumentJob::dispatch($rams->id);

        Log::info('RamsController: generation queued', [
            'record_id' => $rams->id,
            'user_id'   => auth()->id(),
        ]);

        return back()->with('success', 'Generation queued. The document will be ready to download shortly.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // settings
    // ─────────────────────────────────────────────────────────────────────────

    public function settings(): View
    {
        return view('rams.settings', [
            'currentProvider' => config('ai.default'),
            'claudeModel'     => config('ai.providers.claude.model'),
            'claudeKeySet'    => ! empty(config('ai.providers.claude.api_key')),
            'openaiModel'     => config('ai.providers.openai.model'),
            'openaiEndpoint'  => config('ai.providers.openai.endpoint'),
            'openaiKeySet'    => ! empty(config('ai.providers.openai.api_key')),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // testConnection — lightweight live ping of the selected provider
    // ─────────────────────────────────────────────────────────────────────────

    public function testConnection(Request $request): JsonResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $provider = $request->input('provider', config('ai.default', 'claude'));

        try {
            $this->aiSettings->testConnection($provider);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()]);
        }

        return response()->json(['ok' => true, 'message' => 'Connected successfully.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // saveSettings
    // ─────────────────────────────────────────────────────────────────────────

    public function saveSettings(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'default_provider' => ['required', 'in:claude,openai,custom'],
            'claude_model'     => ['nullable', 'string', 'max:100'],
            'openai_model'     => ['nullable', 'string', 'max:100'],
            'openai_endpoint'  => ['nullable', 'string', 'url', 'max:500'],
            'claude_api_key'   => ['nullable', 'string'],
            'openai_api_key'   => ['nullable', 'string'],
        ]);

        try {
            $this->aiSettings->save($validated);
        } catch (\Throwable $e) {
            return back()
                ->withInput()
                ->with('error', 'Settings could not be saved: ' . $e->getMessage());
        }

        return back()->with('success', 'AI provider settings saved successfully.');
    }

    /**
     * Resolve the absolute path to the RAMS DOCX, tolerating legacy filenames.
     */
    private function resolveRamsDocxPath(RamsDocument $rams): string
    {
        $filename = (string) ($rams->filename ?? '');
        if ($filename === '') {
            return '';
        }

        $filename = ltrim($filename, '/');
        if (str_contains($filename, '/')) {
            return Storage::disk('local')->path($filename);
        }

        return Storage::disk('local')->path('rams/' . $filename);
    }

    /**
     * Basic DOCX validity check (docx is a ZIP archive).
     */
    private function isValidDocx(string $path): bool
    {
        if (! is_file($path) || filesize($path) < 1024) {
            return false;
        }

        $zip = new \ZipArchive();
        $ok  = $zip->open($path) === true;
        if ($ok) {
            $zip->close();
        }

        return $ok;
    }
}
