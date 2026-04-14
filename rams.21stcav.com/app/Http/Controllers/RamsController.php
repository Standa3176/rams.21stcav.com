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

        // ── Project pre-fill (optional) ──────────────────────────────
        $project = null;
        if ($projectId = request()->query('project_id')) {
            $candidate = \App\Models\Project::find((int) $projectId);
            if ($candidate) {
                if ($candidate->user_id !== auth()->id() && ! auth()->user()->isAdmin()) {
                    abort(403, 'You do not own this project.');
                }
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

        // Link to originating project if supplied
        if (! empty($validated['project_id'])) {
            $ramsDocument->project_id = (int) $validated['project_id'];
            $ramsDocument->save();
        }

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

        // Patch generated_data['project'] with live values so the review form
        // always shows current data, not stale strings baked in at generation time.
        // normalise() casts every field to '' (never null), so ?? won't fall back —
        // we must actively overwrite empties here. Nothing is persisted to DB.
        $gd = $rams->generated_data ?? [];
        $p  = $gd['project'] ?? [];

        // 1. Fill from the linked Project record when available.
        if ($rams->project_id) {
            $liveProject = Project::find($rams->project_id);
            if ($liveProject) {
                $p = array_merge($p, array_filter([
                    'name'         => $liveProject->name,
                    'client'       => $liveProject->client_name,
                    'site_address' => $liveProject->site_address,
                    'ref'          => $liveProject->ref,
                ], fn ($v) => $v !== null && $v !== ''));
            }
        }

        // 2. Fall back to model columns for any field still empty.
        //    This covers form-based RAMS with no project_id.
        $p['name']         = ($p['name']         ?? '') ?: ($rams->project_name ?? '');
        $p['client']       = ($p['client']        ?? '') ?: ($rams->client_name  ?? '');
        $p['site_address'] = ($p['site_address']  ?? '') ?: ($rams->site_address ?? '');
        $p['ref']          = ($p['ref']           ?? '') ?: ($rams->project_ref  ?? '');

        // 3. Fill personnel from reviewed_data['programme'] when generated_data has none.
        //    Pipeline RAMS store PM/Lead/etc. in reviewed_data, not form_data.
        $rd   = $rams->reviewed_data ?? [];
        $prog = $rd['programme']     ?? [];

        if (empty($p['project_manager'])) {
            $p['project_manager'] = ($prog['project_manager_name'] ?? '')
                ?: ($rd['project']['project_manager']   ?? '')
                ?: ($rams->form_data['project_manager'] ?? '');
        }
        if (empty($p['lead_engineer'])) {
            $p['lead_engineer'] = ($prog['lead_engineer_name'] ?? '')
                ?: ($rd['project']['lead_engineer']   ?? '')
                ?: ($rams->form_data['lead_engineer'] ?? '');
        }
        if (empty($p['additional_engineers'])) {
            $addEngs = $prog['additional_engineers'] ?? [];
            if (is_array($addEngs) && count($addEngs) > 0) {
                $p['additional_engineers'] = implode(', ', array_filter(array_map('trim', $addEngs)));
            } else {
                $p['additional_engineers'] = ($rams->form_data['additional_engineers'] ?? '');
            }
        }
        if (empty($p['project_manager_phone'])) {
            $p['project_manager_phone'] = $prog['project_manager_phone'] ?? '';
        }
        if (empty($p['programmer'])) {
            $p['programmer'] = ($prog['programmer_name'] ?? '')
                ?: ($rd['project']['programmer'] ?? '')
                ?: ($rams->form_data['programmer'] ?? '');
        }

        // 4. Pull client contact from reviewed_data['site_logistics'] when not in generated data.
        $siteLogistics = $rd['site_logistics'] ?? [];
        if (empty($p['client_contact_name'])) {
            $p['client_contact_name'] = ($siteLogistics['site_contact_name'] ?? '')
                ?: ($siteLogistics['client_contact_name'] ?? '');
        }
        if (empty($p['client_contact_email'])) {
            $p['client_contact_email'] = ($siteLogistics['site_contact_email'] ?? '')
                ?: ($siteLogistics['client_contact_email'] ?? '');
        }
        if (empty($p['client_contact_phone'])) {
            $p['client_contact_phone'] = $siteLogistics['site_contact_phone'] ?? '';
        }

        // 5. Pull planned dates from reviewed_data['programme'].
        if (empty($p['planned_start_date'])) {
            $p['planned_start_date'] = $prog['planned_start_date'] ?? '';
        }
        if (empty($p['planned_end_date'])) {
            $p['planned_end_date'] = $prog['planned_end_date'] ?? '';
        }

        // 6. Pull planned times from reviewed_data['programme'].
        if (empty($p['planned_start_time'])) {
            $p['planned_start_time'] = $prog['planned_start_time'] ?? '';
        }
        if (empty($p['planned_end_time'])) {
            $p['planned_end_time'] = $prog['planned_end_time'] ?? '';
        }

        $gd['project']        = $p;
        $rams->generated_data = $gd; // transient — not saved

        // ── Pre-fill new reviewed_data sub-keys (transient — not saved) ──────────
        // Pre-fill scope_traceability from quote line_items when not yet reviewed
        if (empty($rd['scope_traceability'])) {
            $lineItems = $gd['quote']['line_items'] ?? [];
            if (is_array($lineItems) && count($lineItems) > 0) {
                $rd['scope_traceability'] = array_values(array_map(fn ($li) => [
                    'quote_item'    => ($li['description'] ?? ''),
                    'rams_activity' => '',
                    'room'          => ($li['room'] ?? ''),
                    'notes'         => '',
                ], $lineItems));
            }
        }

        // Default exclusions when not yet reviewed
        if (! isset($rd['exclusions'])) {
            $rd['exclusions'] = [
                'No structural works',
                'No core drilling unless explicitly scoped',
                'No containment beyond surface trunking',
                'No decorative making good after cable routes',
                'No IT network provision unless scoped',
            ];
        }

        // Ensure other sub-keys exist with empty defaults so blade never gets null
        $rd['client_responsibilities_expanded'] = $rd['client_responsibilities_expanded'] ?? [];
        $rd['decommissioning']                  = $rd['decommissioning']                  ?? [];
        $rd['commissioning_criteria']           = $rd['commissioning_criteria']           ?? [];

        $rams->reviewed_data = $rd; // transient — not saved

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

        $rams->update([
            'project_name'   => $validated['project_name'],
            'project_ref'    => $validated['project_ref'] ?? $rams->project_ref,
            'client_name'    => $validated['client_name'],
            'site_address'   => $validated['site_address'],
            'generated_data' => $generatedData,
            'reviewed_data'  => $reviewedData,
        ]);

        // Write the core project fields back to the linked Project record so
        // that future RAMS views pick up these edits without another generate.
        if ($rams->project_id) {
            $linkedProject = Project::find($rams->project_id);
            if ($linkedProject) {
                $updates = array_filter([
                    'name'         => $validated['project_name'],
                    'ref'          => $validated['project_ref'] ?? null,
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
        $rebuildError = null;
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
     * Resolve the absolute path to the RAMS DOCX, tolerating legacy filenames.
     *
     * DocxBuilderService writes to storage_path('app/rams/'), NOT to the
     * local Storage disk root (which is storage/app/private/ in Laravel 11+).
     * Always resolve via storage_path() to match where files are actually written.
     */
    private function resolveRamsDocxPath(RamsDocument $rams): string
    {
        $filename = (string) ($rams->filename ?? '');
        if ($filename === '') {
            return '';
        }

        $filename = ltrim($filename, '/');

        // Legacy filenames may include a subpath (e.g. "rams/file.docx")
        if (str_starts_with($filename, 'rams/')) {
            return storage_path('app/' . $filename);
        }

        return storage_path('app/rams/' . $filename);
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
