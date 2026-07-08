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
use App\Services\RamsReviewDataService;
use App\Services\RamsReviewValidatorService;
use App\Services\WorkerMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
        private readonly RamsDocumentRendererService $ramsRenderer,
        private readonly PdfService          $pdfService,
        private readonly ProjectPackageRamsReviewService $packageToReview,
        private readonly RamsReviewValidatorService      $reviewValidator,
        private readonly RamsReviewDataService           $reviewDataService,
        private readonly AiSettingsService               $aiSettings,
    ) {}

    // ─────────────────────────────────────────────────────────────────────────
    // index
    // ─────────────────────────────────────────────────────────────────────────

    public function index(): View
    {
        $isAdmin = auth()->user()->isAdmin();

        $showTrashed = $isAdmin && request()->boolean('show_deleted');

        // Shared workspace: every authenticated user sees ALL RAMS.
        // Viewing trashed/soft-deleted rows stays admin-only.
        $query = RamsDocument::query()->with(['user', 'omManual']);

        if ($showTrashed) {
            $query = RamsDocument::onlyTrashed()->with(['user', 'omManual']);
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

        // ── Project pre-fill (optional) ──────────────────────────────
        $project = null;
        if ($projectId = request()->query('project_id')) {
            $candidate = \App\Models\Project::find((int) $projectId);
            if ($candidate) {
                // Shared workspace: any authenticated user has full access.
                $project = $candidate;
            }
        }

        // ── Personnel pre-fill ────────────────────────────────────────
        // Default to the logged-in user as project manager, then override
        // with values from the most recent RAMS for this project (if any).
        $prefill = ['project_manager' => auth()->user()->name ?? ''];
        if ($project) {
            $previousRams = \App\Models\RamsDocument::where('project_id', $project->id)
                ->whereNotNull('form_data')
                ->latest()
                ->first();
            if ($previousRams) {
                $fd = $previousRams->form_data ?? [];
                foreach (['project_manager', 'lead_engineer', 'additional_engineers', 'programmer'] as $field) {
                    if (! empty($fd[$field])) {
                        $prefill[$field] = $fd[$field];
                    }
                }
            }
        }

        return view('rams.create', compact(
            'hazardLibrary',
            'ppeOptions',
            'personsOptions',
            'providers',
            'defaultProvider',
            'hazardTemplates',
            'project',
            'prefill',
        ));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // store
    // ─────────────────────────────────────────────────────────────────────────

    public function store(RamsFormRequest $request): RedirectResponse
    {
        $validated = $request->validated();
        $formData = array_merge($validated, ['source' => 'manual_form']);

        // 1. Persist a placeholder record so the builder has an ID for the filename
        $ramsDocument = RamsDocument::create([
            'user_id'      => auth()->id(),
            'project_ref'  => $validated['project_ref']  ?? null,
            'project_name' => $validated['project_name'] ?? '',
            'client_name'  => $validated['client_name']  ?? '',
            'site_address' => $validated['site_address'] ?? '',
            'ai_provider'  => $this->aiSettings->defaultProvider(),
            'ai_model'     => $this->aiSettings->defaultModel(),
            'form_data'    => $formData,
            'filename'     => 'pending-' . now()->format('YmdHis') . '.docx',
            'status'       => RamsDocument::STATUS_FOR_REVIEW,
        ]);

        // Link to originating project if supplied
        if (! empty($validated['project_id'])) {
            $ramsDocument->project_id = (int) $validated['project_id'];
            $ramsDocument->save();
        }

        // 2. Queue generation to avoid long-running HTTP requests / 504 timeouts.
        try {
            app(WorkerMonitorService::class)->ensureRunning();
            BuildRamsDocumentJob::dispatch($ramsDocument->id);
        } catch (\Throwable $e) {
            Log::error('RamsController: failed to queue manual RAMS generation', [
                'record_id' => $ramsDocument->id,
                'error'     => $e->getMessage(),
            ]);
            $ramsDocument->update([
                'status'        => RamsDocument::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            return back()->with('error', 'The document could not be queued for generation. Please try again.');
        }

        if (! empty($validated['project_id'])) {
            return redirect()->route('projects.show', (int) $validated['project_id'])
                ->with('success', 'RAMS generation queued. The document will be ready shortly.');
        }

        return redirect()->route('rams.review', $ramsDocument)
            ->with('success', 'RAMS generation queued. The document will be ready shortly.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // generateFromProject — create + generate from reviewed project data
    // ─────────────────────────────────────────────────────────────────────────

    public function generateFromProject(Project $project): RedirectResponse
    {
        // Shared workspace: any authenticated user has full access.
        abort_unless(auth()->check(), 403);

        $package = $project->latestPackage;

        if (! $package) {
            return back()->with('error', 'No quote data found for this project. Please upload a quote PDF first.');
        }

        // If the package hasn't been reviewed yet, send the user directly to the review page.
        if ($package->status !== ProjectPackage::STATUS_REVIEWED) {
            return redirect()
                ->route('project-packages.review.show', $package)
                ->with('error', 'Please review and save the project data before generating a RAMS.');
        }

        // Use the data that was already saved and validated by the review/approve
        // process — do NOT rebuild via ProjectPackageRamsReviewService::build(),
        // which re-runs equipment filtering and classification and can produce a
        // different (incomplete) payload from what the user actually reviewed.
        $reviewPayload = $this->reviewDataService->normalise(
            $package->extracted_data ?? []
        );

        try {
            $this->reviewValidator->validate($reviewPayload);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Send the user back to the review page with the specific field errors
            // so they know exactly what needs to be fixed.
            $messages = collect($e->errors())->flatten()->implode(' ');
            return redirect()
                ->route('project-packages.review.show', $package)
                ->with('error', 'Some required fields are missing — please fix and click Save & Return. ' . $messages);
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

        // Apply transient display patches (live project data, personnel, contacts, dates).
        // Nothing is persisted to DB — see patchRamsForDisplay() for full logic.
        $this->patchRamsForDisplay($rams);

        // Diff: extracted vs reviewed for change highlighting (only when reviewed_data exists)
        $hasReviewed = ! empty($rams->reviewed_data);

        $diff = $hasReviewed
            ? \App\Services\Rams\RamsDiffService::diff(
                $rams->extracted_data ?? [],
                $rams->reviewed_data ?? [],
            )
            : ['changes' => [], 'summary' => ['total' => 0, 'added' => 0, 'modified' => 0, 'removed' => 0]];

        return view('rams.review', compact('rams', 'diff'));
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
            // Programme dates & times
            'planned_start_date'  => ['nullable', 'string', 'max:20'],
            'planned_start_time'  => ['nullable', 'string', 'max:10'],
            'planned_end_date'    => ['nullable', 'string', 'max:20'],
            'planned_end_time'    => ['nullable', 'string', 'max:10'],
            // Waste removal
            'waste_removal_party' => ['nullable', 'string', 'in:client,21cav,other'],
            'waste_removal_notes' => ['nullable', 'string', 'max:1000'],
            // Welfare
            'welfare_notes'       => ['nullable', 'string', 'max:1000'],
            // Permits
            'permits_required'            => ['nullable', 'array'],
            'permits_required.*.type'     => ['nullable', 'string', 'max:100'],
            'permits_required.*.required' => ['nullable', 'boolean'],
            'permits_required.*.notes'    => ['nullable', 'string', 'max:500'],
            // Material handling
            'material_handling_has_large_items'         => ['nullable', 'boolean'],
            'material_handling_handling_notes'          => ['nullable', 'string', 'max:1000'],
            'material_handling_items'                   => ['nullable', 'array'],
            'material_handling_items.*.item'            => ['nullable', 'string', 'max:200'],
            'material_handling_items.*.weight_kg'       => ['nullable', 'string', 'max:20'],
            'material_handling_items.*.handling_method' => ['nullable', 'string', 'max:500'],
            // CDM duty holders
            'cdm'               => ['nullable', 'array'],
            'cdm.*.role'        => ['nullable', 'string', 'max:100'],
            'cdm.*.organisation' => ['nullable', 'string', 'max:200'],
            'cdm.*.name'        => ['nullable', 'string', 'max:200'],
            'cdm.*.contact'     => ['nullable', 'string', 'max:200'],
            // Scope traceability
            'scope_traceability'                 => ['nullable', 'array'],
            'scope_traceability.*.quote_item'    => ['nullable', 'string', 'max:500'],
            'scope_traceability.*.rams_activity' => ['nullable', 'string', 'max:500'],
            'scope_traceability.*.room'          => ['nullable', 'string', 'max:200'],
            'scope_traceability.*.notes'         => ['nullable', 'string', 'max:500'],
            // Client responsibilities expanded
            'client_resp_network_readiness_required'  => ['nullable', 'boolean'],
            'client_resp_network_readiness_notes'     => ['nullable', 'string', 'max:500'],
            'client_resp_licences_required'           => ['nullable', 'boolean'],
            'client_resp_licences_notes'              => ['nullable', 'string', 'max:500'],
            'client_resp_access_required'             => ['nullable', 'boolean'],
            'client_resp_access_notes'                => ['nullable', 'string', 'max:500'],
            'client_resp_power_validation_required'   => ['nullable', 'boolean'],
            'client_resp_power_validation_notes'      => ['nullable', 'string', 'max:500'],
            'client_resp_additional'                  => ['nullable', 'array'],
            'client_resp_additional.*.item'           => ['nullable', 'string', 'max:300'],
            'client_resp_additional.*.notes'          => ['nullable', 'string', 'max:500'],
            // Exclusions
            'exclusions'   => ['nullable', 'array'],
            'exclusions.*' => ['nullable', 'string', 'max:500'],
            // Decommissioning
            'decommissioning_enabled'               => ['nullable', 'boolean'],
            'decommissioning_labelling_procedure'   => ['nullable', 'string', 'max:1000'],
            'decommissioning_storage_location'      => ['nullable', 'string', 'max:500'],
            'decommissioning_client_sign_off'       => ['nullable', 'boolean'],
            'decommissioning_disposal_method'       => ['nullable', 'string', 'max:500'],
            'decommissioning_steps'                 => ['nullable', 'array'],
            'decommissioning_steps.*'               => ['nullable', 'string', 'max:500'],
            // Commissioning criteria
            'commissioning_criteria'                         => ['nullable', 'array'],
            'commissioning_criteria.*.system'                => ['nullable', 'string', 'max:200'],
            'commissioning_criteria.*.criterion'             => ['nullable', 'string', 'max:500'],
            'commissioning_criteria.*.verification_method'   => ['nullable', 'string', 'max:300'],
            'commissioning_criteria.*.pass_condition'        => ['nullable', 'string', 'max:300'],
        ]);

        // Treat an empty-string `project_ref` submission as "not provided" so
        // fallback chains below reach the existing generated_data / model value
        // instead of clobbering it with "". (H-03)
        $projectRefInput = $request->filled('project_ref') ? $validated['project_ref'] : null;

        // Merge edits into generated_data['project']
        $generatedData = $rams->generated_data ?? [];
        $generatedData['project'] = array_merge($generatedData['project'] ?? [], [
            'name'            => $validated['project_name'],
            'ref'             => $projectRefInput ?? ($generatedData['project']['ref'] ?? null),
            'client'          => $validated['client_name'],
            'site_address'    => $validated['site_address'],
            'site_contact'    => $validated['site_contact'] ?? '',
            'document_status' => $validated['document_status'] ?? 'For Construction',
            'subtitle'        => $validated['subtitle'] ?? '',
            'project_manager'      => $validated['project_manager']      ?? '',
            'lead_engineer'        => $validated['lead_engineer']        ?? '',
            'additional_engineers' => $validated['additional_engineers'] ?? '',
            'programmer'           => $validated['programmer']           ?? '',
            // Site vehicles — split textarea into a clean array, persisted
            // into generated_data so the PDF / DOCX render layer can pick it up
            // immediately (without round-tripping through RamsBuilderService).
            'site_vehicles'        => array_values(array_filter(
                array_map('trim', preg_split('/\r?\n/', (string) ($request->input('site_vehicles') ?? '')) ?: []),
                fn (string $s) => $s !== '',
            )),
            // Programme dates & times — pass through to PDF cover table
            'planned_start_date' => $validated['planned_start_date'] ?? ($generatedData['project']['planned_start_date'] ?? ''),
            'planned_start_time' => $validated['planned_start_time'] ?? '',
            'planned_end_date'   => $validated['planned_end_date']   ?? ($generatedData['project']['planned_end_date']   ?? ''),
            'planned_end_time'   => $validated['planned_end_time']   ?? '',
        ]);

        // Persist new fields into reviewed_data JSON sub-keys (saved before download attempt)
        $reviewedData = $rams->reviewed_data ?? [];
        $reviewedData['programme'] = array_merge($reviewedData['programme'] ?? [], [
            'planned_start_date'  => $validated['planned_start_date']  ?? '',
            'planned_start_time'  => $validated['planned_start_time']  ?? '',
            'planned_end_date'    => $validated['planned_end_date']    ?? '',
            'planned_end_time'    => $validated['planned_end_time']    ?? '',
            'waste_removal_party' => $validated['waste_removal_party'] ?? '',
            'waste_removal_notes' => $validated['waste_removal_notes'] ?? '',
            'welfare_notes'       => $validated['welfare_notes']       ?? '',
        ]);

        // Mirror personnel + vehicle fields into reviewed_data['project'] so a
        // later regenerate (which rebuilds via RamsBuilderService::buildFromReview)
        // sees the same values the user just entered. Without this mirror, the
        // regenerated PDF renders blanks for engineers, programmer and vehicles.
        $vehiclesArr = array_values(array_filter(
            array_map('trim', preg_split('/\r?\n/', (string) ($request->input('site_vehicles') ?? '')) ?: []),
            fn (string $s) => $s !== '',
        ));
        $reviewedData['project'] = array_merge($reviewedData['project'] ?? [], [
            'project_manager'      => $validated['project_manager']      ?? '',
            'lead_engineer'        => $validated['lead_engineer']        ?? '',
            'additional_engineers' => $validated['additional_engineers'] ?? '',
            'programmer'           => $validated['programmer']           ?? '',
            'site_vehicles'        => $vehiclesArr,
        ]);

        // Build permits array — preserve all types with their state
        $permitsInput = $request->input('permits_required', []);
        $reviewedData['permits_required'] = array_values(array_filter(
            is_array($permitsInput) ? $permitsInput : [],
            fn ($p) => ! empty($p['type'])
        ));

        // Material handling
        $mhItems = $request->input('material_handling_items', []);
        $reviewedData['material_handling'] = [
            'has_large_items' => $request->boolean('material_handling_has_large_items'),
            'large_items'     => array_values(array_filter(
                is_array($mhItems) ? $mhItems : [],
                fn ($i) => ! empty($i['item'])
            )),
            'handling_notes'  => $validated['material_handling_handling_notes'] ?? '',
        ];

        // CDM duty holders
        $cdmInput = $request->input('cdm', []);
        $reviewedData['cdm'] = array_values(array_filter(
            is_array($cdmInput) ? $cdmInput : [],
            fn ($row) => ! empty($row['role'])
        ));

        // ── New reviewed_data sub-keys ────────────────────────────────────────

        // Scope traceability
        $stInput = $request->input('scope_traceability', []);
        $reviewedData['scope_traceability'] = array_values(array_filter(
            is_array($stInput) ? $stInput : [],
            fn ($row) => ! empty($row['quote_item']) || ! empty($row['rams_activity'])
        ));

        // Client responsibilities expanded
        $crAdditional = $request->input('client_resp_additional', []);
        $reviewedData['client_responsibilities_expanded'] = [
            'network_readiness' => ['required' => $request->boolean('client_resp_network_readiness_required'), 'notes' => $validated['client_resp_network_readiness_notes'] ?? ''],
            'licences'          => ['required' => $request->boolean('client_resp_licences_required'),          'notes' => $validated['client_resp_licences_notes']          ?? ''],
            'access'            => ['required' => $request->boolean('client_resp_access_required'),            'notes' => $validated['client_resp_access_notes']            ?? ''],
            'power_validation'  => ['required' => $request->boolean('client_resp_power_validation_required'),  'notes' => $validated['client_resp_power_validation_notes']  ?? ''],
            'additional'        => array_values(array_filter(
                is_array($crAdditional) ? $crAdditional : [],
                fn ($r) => ! empty($r['item'])
            )),
        ];

        // Exclusions — filter empty strings
        $exclusionsInput = $request->input('exclusions', []);
        $reviewedData['exclusions'] = array_values(array_filter(
            is_array($exclusionsInput) ? $exclusionsInput : [],
            fn ($e) => trim((string) $e) !== ''
        ));

        // Decommissioning
        $decomSteps = $request->input('decommissioning_steps', []);
        $reviewedData['decommissioning'] = [
            'enabled'                  => $request->boolean('decommissioning_enabled'),
            'labelling_procedure'      => $validated['decommissioning_labelling_procedure'] ?? '',
            'storage_location'         => $validated['decommissioning_storage_location']    ?? '',
            'client_sign_off_required' => $request->boolean('decommissioning_client_sign_off'),
            'disposal_method'          => $validated['decommissioning_disposal_method']     ?? '',
            'steps'                    => array_values(array_filter(
                is_array($decomSteps) ? $decomSteps : [],
                fn ($s) => trim((string) $s) !== ''
            )),
        ];

        // Commissioning criteria
        $ccInput = $request->input('commissioning_criteria', []);
        $reviewedData['commissioning_criteria'] = array_values(array_filter(
            is_array($ccInput) ? $ccInput : [],
            fn ($row) => ! empty($row['system']) || ! empty($row['criterion'])
        ));

        // ── Apply Tier 1 compliance upgrade before persist + render ────────
        $generatedData = \App\Services\Rams\RamsComplianceUpgradeService::upgrade($generatedData);

        // Mirror personnel + vehicle + programme date fields into form_data so
        // a later regenerate (BuildRamsDocumentJob → RamsDataBuilderService::buildFromForm)
        // sees the same values the user entered here. The display patch service
        // also uses form_data as a last-resort fallback.
        $formData = $rams->form_data ?? [];
        $formData = array_merge($formData, [
            'project_manager'      => $validated['project_manager']      ?? ($formData['project_manager']      ?? ''),
            'lead_engineer'        => $validated['lead_engineer']        ?? ($formData['lead_engineer']        ?? ''),
            'additional_engineers' => $validated['additional_engineers'] ?? ($formData['additional_engineers'] ?? ''),
            'programmer'           => $validated['programmer']           ?? ($formData['programmer']           ?? ''),
            'site_vehicles'        => $vehiclesArr,
        ]);

        try {
            $filePath = DB::transaction(function () use ($rams, $validated, $projectRefInput, $generatedData, $reviewedData, $formData): string {
                $rams->update([
                    'project_name'   => $validated['project_name'],
                    'project_ref'    => $projectRefInput ?? $rams->project_ref,
                    'client_name'    => $validated['client_name'],
                    'site_address'   => $validated['site_address'],
                    'form_data'      => $formData,
                    'generated_data' => $generatedData,
                    'reviewed_data'  => $reviewedData,
                ]);

                // Write core fields back to the linked Project record in the
                // same transaction so render failures roll back both documents.
                if ($rams->project_id) {
                    $linkedProject = Project::find($rams->project_id);
                    if ($linkedProject) {
                        $updates = array_filter([
                            'name'         => $validated['project_name'],
                            'ref'          => $projectRefInput,
                            'client_name'  => $validated['client_name'],
                            'site_address' => $validated['site_address'],
                        ], fn ($v) => $v !== null && $v !== '');

                        if ($updates) {
                            $linkedProject->update($updates);
                            Log::info('RamsController: project fields synced from RAMS updateAndDownload', [
                                'rams_id'    => $rams->id,
                                'project_id' => $rams->project_id,
                                'fields'     => array_keys($updates),
                            ]);
                        }
                    }
                }

                // Render inside the transaction so DB writes are rolled back
                // if DOCX generation fails.
                return $this->ramsRenderer->render($generatedData, $rams);
            });

            // Ensure download uses the latest filename after renderer update.
            $rams->refresh();
        } catch (\Throwable $e) {
            Log::error('RamsController: updateAndDownload failed (transaction rolled back)', [
                'record_id' => $rams->id,
                'error'     => $e->getMessage(),
            ]);
            return back()->with('error', 'The document could not be regenerated. Please try again.');
        }

        // Save-and-prompt UX: instead of streaming a DOCX download, redirect
        // back to the review page with a flash flag so the front-end can prompt
        // the user to kick off a full regenerate. The current DOCX/PDF links on
        // the page already serve the freshly-saved file via $rams->filename.
        unset($filePath); // not used in the save-and-prompt response
        return redirect()
            ->route('rams.review', $rams)
            ->with('success', 'Changes saved.')
            ->with('rams_regen_prompt', $rams->id);
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
        $rebuildError = null;
        if (! $hasDocxExt || ! $rams->filename || ! file_exists($filePath) || ! $this->isValidDocx($filePath)) {
            if (! empty($rams->generated_data)) {
                try {
                    // Apply Tier 1 compliance upgrade + use pipeline renderer
                    $upgradeData = \App\Services\Rams\RamsComplianceUpgradeService::upgrade($rams->generated_data);
                    $this->ramsRenderer->render($upgradeData, $rams);
                    $filePath = $this->resolveRamsDocxPath($rams->fresh());
                } catch (\Throwable $e) {
                    Log::error('RamsController: DOCX rebuild failed during download', [
                        'record_id' => $rams->id,
                        'error'     => $e->getMessage(),
                        'file'      => $e->getFile(),
                        'line'      => $e->getLine(),
                    ]);
                    $rebuildError = $e->getMessage();
                }
            }
        }

        if (! $rams->filename || ! file_exists($filePath) || ! $this->isValidDocx($filePath)) {
            if (empty($rams->generated_data)) {
                $msg = 'DOCX download failed: document data is missing. Please click Regenerate and try again.';
            } elseif ($rebuildError) {
                $msg = 'DOCX download failed: ' . $rebuildError . ' — please contact support or try Regenerate.';
            } else {
                $msg = 'DOCX download failed: the file is missing or invalid. Please click Regenerate and try again.';
            }

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

        $projectId = $record->project_id;
        $record->delete();

        Log::info('RamsController: document soft-deleted', [
            'record_id' => $record->id,
            'user_id'   => auth()->id(),
        ]);

        if ($projectId) {
            return redirect()->route('projects.show', $projectId)
                ->with('success', 'RAMS document deleted.');
        }

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

        // Remove the generated DOCX from disk if it exists. Uses the artifact
        // store so both the new `documents` disk and any legacy storage/app/rams/
        // copy are cleaned up. (Previously this called Storage::disk('local')
        // which resolved to storage/app/private/$filename — the wrong disk —
        // so the delete was a no-op.)
        if ($rams->filename) {
            app(\App\Services\DocumentArtifactStorage::class)
                ->delete(\App\Services\DocumentArtifactStorage::TYPE_RAMS, basename((string) $rams->filename));
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

        // Apply the same live-data patches used in the review form so the PDF
        // always reflects current project data, personnel, and contacts.
        // This is transient — nothing is persisted to DB.
        $this->patchRamsForDisplay($rams);

        // Apply Tier 1 compliance upgrade to generated_data (transient — not persisted)
        $rams->generated_data = \App\Services\Rams\RamsComplianceUpgradeService::upgrade(
            $rams->generated_data
        );

        try {
            $pdfPath = $this->pdfService->buildRams($rams);
        } catch (\Throwable $e) {
            return back()->with('error', 'PDF generation failed: ' . $e->getMessage());
        }

        // Build a meaningful filename: "{ref} - {client}.pdf", falling back gracefully.
        $ref    = trim($rams->project_ref  ?? ($rams->generated_data['project']['ref']    ?? ''));
        $client = trim($rams->client_name  ?? ($rams->generated_data['project']['client'] ?? ''));
        if ($ref && $client) {
            $base = $ref . ' - ' . $client;
        } elseif ($ref) {
            $base = $ref;
        } elseif ($client) {
            $base = $client;
        } else {
            $base = pathinfo($rams->filename ?? 'rams', PATHINFO_FILENAME);
        }
        // Strip characters that are invalid in filenames.
        $pdfName = preg_replace('/[\\\\\/:\*\?"<>|]/', '_', $base) . '.pdf';

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

        // Audit WR-02 (2026-07-08) — sender_note is user-controllable free
        // text that flows into a Mailable. Route is throttled at 5 per hour
        // (see routes/web.php), but a compromised staff account is still an
        // arbitrary-recipient spam surface; strip HTML so an XSS payload
        // can't reach the client's inbox rendered from our domain.
        $senderNote = strip_tags((string) $request->input('sender_note', ''));

        Mail::to($request->input('recipient_email'), $request->input('recipient_name'))
            ->send(new RamsDocumentMail(
                rams:          $rams,
                recipientName: $request->input('recipient_name'),
                senderNote:    $senderNote,
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

        $formData     = $rams->form_data     ?? [];
        $reviewedData = $rams->reviewed_data ?? [];
        $generatedData = $rams->generated_data ?? [];

        // Create the new record, copying all data from the original so the
        // rebuilt document is based on real project content, not a blank form.
        $newRams = RamsDocument::create([
            'user_id'        => auth()->id(),
            'project_id'     => $rams->project_id,
            'project_ref'    => $rams->project_ref,
            'project_name'   => $rams->project_name,
            'client_name'    => $rams->client_name,
            'site_address'   => $rams->site_address,
            'ai_provider'    => $this->aiSettings->defaultProvider(),
            'ai_model'       => $this->aiSettings->defaultModel(),
            'form_data'      => $formData,
            'reviewed_data'  => $reviewedData,
            'generated_data' => $generatedData,
            'approved_by'    => $rams->approved_by ?? auth()->id(),
            'approved_at'    => $rams->approved_at ?? now(),
            'filename'       => 'pending-' . now()->format('YmdHis') . '.docx',
            'status'         => RamsDocument::STATUS_GENERATING,
        ]);

        // Dispatch as background job to avoid 504 timeout.
        app(WorkerMonitorService::class)->ensureRunning();
        BuildRamsDocumentJob::dispatch($newRams->id);

        // Mark the original as superseded, linking it to the new one
        $rams->update([
            'status'           => RamsDocument::STATUS_SUPERSEDED,
            'superseded_by_id' => $newRams->id,
        ]);

        if ($newRams->project_id) {
            return redirect()->route('projects.show', $newRams->project_id)
                ->with('success', 'RAMS regeneration queued. The document will be ready to download shortly.');
        }

        return redirect()->route('rams.index')
            ->with('success', 'RAMS regeneration queued. The document will be ready to download shortly.');
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

        if (in_array($rams->status, [
            RamsDocument::STATUS_GENERATING,
            RamsDocument::STATUS_APPROVED_FOR_GENERATION,
        ], true)) {
            return redirect()->route('projects.show', $rams->project_id)
                ->with('error', 'This document is already queued for generation. Please wait.');
        }

        // Always refresh reviewed_data from the latest reviewed package so that
        // any changes made in the review UI (e.g. recategorising equipment) are
        // picked up on every Regen, not just the first time.
        if ($rams->project_id) {
            $package = ProjectPackage::where('project_id', $rams->project_id)
                ->where('status', ProjectPackage::STATUS_REVIEWED)
                ->latest()
                ->first();

            if (! $package) {
                return redirect()->route('projects.show', $rams->project_id)
                    ->with('error', 'No reviewed project data found. Please review the project data first.');
            }

            $reviewedData = $this->reviewDataService->normalise($package->extracted_data ?? []);
            $rams->update(['reviewed_data' => $reviewedData]);
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

        return redirect()->route('projects.show', $rams->project_id)
            ->with('success', 'Regeneration queued. The document will be ready to download shortly.');
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
     * Resolve the absolute path to the RAMS DOCX, tolerating legacy filenames
     * and legacy on-disk locations.
     *
     * Post-H-07, writes land in the `documents` disk (storage/app/documents/rams/).
     * Legacy files may still exist in storage/app/rams/ — DocumentArtifactStorage
     * transparently falls back to that location. Returns '' if the file cannot
     * be found anywhere, matching the previous empty-string sentinel.
     */
    private function resolveRamsDocxPath(RamsDocument $rams): string
    {
        $filename = ltrim((string) ($rams->filename ?? ''), '/');
        if ($filename === '') {
            return '';
        }
        // Tolerate legacy filenames that include the `rams/` subpath prefix.
        if (str_starts_with($filename, 'rams/')) {
            $filename = substr($filename, strlen('rams/'));
        }

        return app(\App\Services\DocumentArtifactStorage::class)
            ->readPath(\App\Services\DocumentArtifactStorage::TYPE_RAMS, $filename)
            ?? '';
    }

    /**
     * Apply transient display patches to a RamsDocument. Thin delegate to
     * RamsDisplayPatchService — the extraction is covered by
     * PatchRamsForDisplayTest (reflection-invoked on this controller method).
     *
     * Called by review() and downloadPdf(). Mutates $rams in-place; nothing
     * is persisted — callers must not save() afterwards.
     */
    private function patchRamsForDisplay(RamsDocument $rams): void
    {
        app(\App\Services\Rams\RamsDisplayPatchService::class)->patch($rams);
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
