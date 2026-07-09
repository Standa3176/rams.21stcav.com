<?php

namespace App\Http\Controllers;

use App\Core\Modules\OMManual\OmManualGeneratorService;
use App\Exceptions\AIGenerationException;
use App\Jobs\BuildOmManualJob;
use App\Models\Device;
use App\Models\OmManual;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Services\OmManualDocxService;
use App\Services\PdfService;
use App\Services\WorkerMonitorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OmManualController extends Controller
{
    public function __construct(
        private readonly OmManualGeneratorService $generator,
        private readonly OmManualDocxService      $docxService,
        private readonly PdfService               $pdfService,
    ) {}

    // ── index ─────────────────────────────────────────────────────────────────

    public function index(Request $request): View
    {
        $isAdmin     = auth()->user()->isAdmin();
        $showDeleted = $isAdmin && $request->boolean('show_deleted');

        if ($showDeleted) {
            $manuals = OmManual::onlyTrashed()->with(['user', 'project'])->latest('deleted_at')->paginate(15);
            return view('om-manual.index', compact('manuals', 'isAdmin', 'showDeleted'));
        }

        // Shared workspace: every authenticated user sees ALL O&M manuals.
        $manuals = OmManual::with(['user', 'project'])->latest()->paginate(15);

        return view('om-manual.index', compact('manuals', 'isAdmin', 'showDeleted'));
    }

    // ── create ────────────────────────────────────────────────────────────────

    public function create(Request $request): View
    {
        $defaultProvider = config('ai.default');

        // Shared workspace: list ALL projects in the dropdown.
        $projects = Project::query()
            ->orderByDesc('updated_at')
            ->get(['id', 'name', 'ref', 'client_name']);

        $selectedProjectId = $request->query('project_id');
        $selectedProject   = null;
        $latestPackage     = null;

        if ($selectedProjectId) {
            $selectedProject = Project::where('id', $selectedProjectId)
                ->with('latestPackage')
                ->first();
            $latestPackage = $selectedProject?->latestPackage;
        }

        return view('om-manual.create', compact('defaultProvider', 'projects', 'selectedProjectId', 'selectedProject', 'latestPackage'));
    }

    // ── store (Pass 1) ────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'quote_pdf'   => ['required', 'file', 'mimes:pdf', 'max:20480'],
            'project_id'  => ['nullable', 'integer', 'exists:projects,id'],
            'ai_provider' => ['nullable', 'string', 'in:claude,openai'],
        ]);

        $provider  = $request->input('ai_provider', config('ai.default', 'claude'));
        $projectId = $request->input('project_id');

        $project = $projectId
            ? Project::where('id', $projectId)->firstOrFail()
            : null;

        // Move to a temp directory for extraction
        $tmpDir  = storage_path('app/tmp/om-uploads/' . uniqid('om_', true));
        $pdfPath = $tmpDir . '/quote.pdf';

        if (! mkdir($tmpDir, 0775, true) && ! is_dir($tmpDir)) {
            return back()->withInput()->with('error', 'Could not create temporary directory.');
        }

        $request->file('quote_pdf')->move($tmpDir, 'quote.pdf');

        try {
            $manual = $this->generator->extractFromPdf(
                user:         auth()->user(),
                pdfPath:      $pdfPath,
                originalName: $request->file('quote_pdf')->getClientOriginalName(),
                project:      $project,
                provider:     $provider,
            );
        } catch (AIGenerationException $e) {
            $this->cleanupTmp($tmpDir);
            return back()->withInput()->with('error', 'Extraction failed: ' . $e->getMessage());
        } catch (\Throwable $e) {
            $this->cleanupTmp($tmpDir);
            return back()->withInput()->with('error', 'An unexpected error occurred. Please try again.');
        }

        $this->cleanupTmp($tmpDir);

        return redirect()
            ->route('om-manuals.edit', $manual)
            ->with('success', 'Equipment extracted successfully. Please review the list below before generating the manual.');
    }

    // ── storeFromProject (Pass 1, project package) ───────────────────────────

    public function storeFromProject(Request $request, Project $project): RedirectResponse
    {
        abort_unless(auth()->check(), 403); // Shared workspace: any authenticated user has full access.

        $package = $project->latestPackage;

        if (! $package || $package->status !== ProjectPackage::STATUS_REVIEWED) {
            return back()->with('error', 'No reviewed quote data found for this project. Please review the quote import first.');
        }

        try {
            $manual = $this->generator->extractFromProjectPackage(
                user:    auth()->user(),
                package: $package,
            );
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not create O&M manual from project data: ' . $e->getMessage());
        }

        return redirect()
            ->route('om-manuals.edit', $manual)
            ->with('success', 'Project quote data loaded. Please review the equipment list before generating the manual.');
    }

    // ── generateFromProject (create + generate immediately) ─────────────────

    public function generateFromProject(Request $request, Project $project): RedirectResponse
    {
        abort_unless(auth()->check(), 403); // Shared workspace: any authenticated user has full access.

        // Draft mode (?draft=1) — generates the manual even when post-engineering
        // fields aren't ready yet (handover_date, drawings). The generator seeds
        // those gates with `[TBC]` placeholders so the validator passes while
        // still producing a useful early-stage document. Required for projects
        // in `quote_imported` / `survey_pending` status that don't yet have a
        // scheduled handover or attached drawings. Final-issue mode (no flag)
        // continues to enforce the strict Tier-1 NO-TBC policy.
        $isDraft = $request->boolean('draft');

        // Pass 1 replacement (D-07, D-08): Build extracted_data from ProjectDataService.
        // ProjectDataService::resolve() returns reviewed, merged canonical data.
        // No PDF upload, no AI extraction, no user review step required.
        try {
            $context = $this->generator->buildContextFromProjectData($project);
        } catch (\Throwable $e) {
            Log::error('OmManualController: buildContextFromProjectData failed', [
                'project_id' => $project->id,
                'error'      => $e->getMessage(),
            ]);
            return back()->with('error', 'Could not read project data: ' . $e->getMessage());
        }

        // Persist the draft flag inside extracted_data so it survives Retry
        // re-dispatches (no new column needed, picked up by the generator).
        $context['_draft_mode'] = $isDraft;

        // Create the OmManual record with pre-built extracted_data.
        // Status starts as 'generating' — BuildOmManualJob will advance to 'draft' on success.
        $manual = OmManual::create([
            'user_id'        => auth()->id(),
            'project_id'     => $project->id,
            'project_name'   => $context['project_name'],
            'project_ref'    => $context['project_ref'],
            'client_name'    => $context['client_name'],
            'site_address'   => $context['site_address'],
            'extracted_data' => $context,
            'status'         => OmManual::STATUS_GENERATING,
            'error_message'  => null,
        ]);

        Log::info('OmManualController: generateFromProject queued', [
            'project_id'   => $project->id,
            'om_manual_id' => $manual->id,
            'draft_mode'   => $isDraft,
        ]);

        app(WorkerMonitorService::class)->ensureRunning();
        BuildOmManualJob::dispatch($manual->id);

        $message = $isDraft
            ? 'Draft O&M generation queued — the document will use [TBC] placeholders for handover date and drawings until those are finalised.'
            : 'O&M generation queued — the document will be ready to download shortly.';

        return back()->with('success', $message);
    }

    // ── status (JSON polling endpoint for Alpine.js — D-17) ──────────────────

    public function status(OmManual $omManual): JsonResponse
    {
        abort_unless(auth()->check(), 403); // Shared workspace: any authenticated user has full access.

        $downloadUrl = in_array($omManual->status, [OmManual::STATUS_DRAFT, OmManual::STATUS_FINAL])
            ? route('om-manuals.download', $omManual)
            : null;

        return response()->json([
            'status'       => $omManual->status,
            'label'        => $omManual->statusLabel(),
            'download_url' => $downloadUrl,
            'error'        => $omManual->error_message,
        ]);
    }

    /**
     * Retry a failed O&M generation job.
     * Re-dispatches BuildOmManualJob using the existing extracted_data.
     */
    public function retryGeneration(OmManual $omManual): RedirectResponse
    {
        // Re-audit S-04 — route through OmManualPolicy::update so all
        // four retry-generation handlers share one enforcement point.
        $this->authorize('update', $omManual);

        if (empty($omManual->extracted_data)) {
            return back()->with('error', 'Cannot retry — extracted data is missing. Please create a new O&M manual.');
        }

        $omManual->update([
            'status'        => OmManual::STATUS_GENERATING,
            'error_message' => null,
        ]);

        app(WorkerMonitorService::class)->ensureRunning();
        BuildOmManualJob::dispatch($omManual->id);

        return back()->with('success', 'O&M generation re-queued.');
    }

    // ── edit (review Pass 1) ──────────────────────────────────────────────────

    public function edit(OmManual $omManual): View
    {
        $this->authorize('update', $omManual);

        return view('om-manual.edit', ['manual' => $omManual]);
    }

    // ── update (save edited extracted_data) ───────────────────────────────────

    /**
     * Save the O&M edit form. Two paths:
     *
     * 1. **Structured path (default)** — the form posts typed fields
     *    (project_name, project_ref, client_name, site_address, scope_of_works,
     *    notes). The controller takes the existing extracted_data, overlays
     *    those fields onto it, and saves the result. Unknown keys are left
     *    untouched. Rooms are NEVER touched via this path — they come from the
     *    generator based on survey/quote data.
     *
     * 2. **Raw JSON path (opt-in)** — if the user checks "use_raw_json" in the
     *    Advanced disclosure, we fall back to the legacy behaviour: parse the
     *    extracted_json textarea, sanitise rooms, and replace extracted_data
     *    wholesale. Kept as an escape hatch for the rare case where structured
     *    fields don't cover what a user needs.
     */
    public function update(Request $request, OmManual $omManual): RedirectResponse
    {
        $this->authorize('update', $omManual);

        $useRawJson = $request->boolean('use_raw_json');

        if ($useRawJson) {
            $request->validate(['extracted_json' => ['required', 'string']]);

            $decoded = json_decode((string) $request->input('extracted_json'), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return back()
                    ->withInput()
                    ->with('error', 'Invalid JSON — ' . json_last_error_msg() . '. Fix the payload and try again.');
            }

            if (isset($decoded['rooms']) && is_array($decoded['rooms'])) {
                $decoded['rooms'] = $this->generator->sanitiseRooms($decoded['rooms']);
            }

            $omManual->update([
                'project_name'   => $decoded['project']['name']   ?? $decoded['project_name'] ?? $omManual->project_name,
                'project_ref'    => $decoded['project']['ref']    ?? $decoded['project_ref']  ?? $omManual->project_ref,
                'client_name'    => $decoded['project']['client'] ?? $decoded['client_name']  ?? $omManual->client_name,
                'site_address'   => $decoded['project']['site']   ?? $decoded['site_address'] ?? $omManual->site_address,
                'extracted_data' => $decoded,
            ]);

            return back()->with('success', 'O&M saved from raw JSON payload.');
        }

        $payload = $request->validate([
            'project_name'   => ['nullable', 'string', 'max:255'],
            'project_ref'    => ['nullable', 'string', 'max:255'],
            'client_name'    => ['nullable', 'string', 'max:255'],
            'site_address'   => ['nullable', 'string', 'max:500'],
            'handover_date'  => ['nullable', 'string', 'max:255'],
            'scope_of_works' => ['nullable', 'string'],
            'notes'          => ['nullable', 'string'],

            'rooms'                            => ['nullable', 'array'],
            'rooms.*.name'                     => ['nullable', 'string', 'max:255'],
            'rooms.*.floor'                    => ['nullable', 'string', 'max:120'],
            'rooms.*.drawing_ref'              => ['nullable', 'string', 'max:120'],
            'rooms.*.narrative'                => ['nullable', 'string'],
            'rooms.*.equipment'                => ['nullable', 'array'],
            'rooms.*.equipment.*.qty'          => ['nullable', 'integer', 'min:0', 'max:9999'],
            'rooms.*.equipment.*.part_number'  => ['nullable', 'string', 'max:180'],
            'rooms.*.equipment.*.description'  => ['nullable', 'string', 'max:2000'],
            'rooms.*.equipment.*.manufacturer' => ['nullable', 'string', 'max:180'],

            // Front matter — distribution + revision history
            'distribution_list'             => ['nullable', 'array'],
            'distribution_list.*.name'      => ['nullable', 'string', 'max:180'],
            'distribution_list.*.role'      => ['nullable', 'string', 'max:120'],
            'distribution_list.*.email'     => ['nullable', 'string', 'max:180'],

            'revision_history'              => ['nullable', 'array'],
            'revision_history.*.date'       => ['nullable', 'string', 'max:60'],
            'revision_history.*.rev'        => ['nullable', 'string', 'max:20'],
            'revision_history.*.author'     => ['nullable', 'string', 'max:120'],
            'revision_history.*.changes'    => ['nullable', 'string', 'max:1000'],

            // §10 — Manufacturer support overrides
            'manufacturer_support_overrides'                 => ['nullable', 'array'],
            'manufacturer_support_overrides.*.brand'         => ['nullable', 'string', 'max:120'],
            'manufacturer_support_overrides.*.phone'         => ['nullable', 'string', 'max:60'],
            'manufacturer_support_overrides.*.email'         => ['nullable', 'string', 'max:180'],
            'manufacturer_support_overrides.*.portal'        => ['nullable', 'string', 'max:255'],
            'manufacturer_support_overrides.*.warranty'      => ['nullable', 'string', 'max:255'],

            // §11 — Service & escalation
            'service_escalation'                 => ['nullable', 'array'],
            'service_escalation.contact_name'    => ['nullable', 'string', 'max:180'],
            'service_escalation.phone'           => ['nullable', 'string', 'max:60'],
            'service_escalation.email'           => ['nullable', 'string', 'max:180'],
            'service_escalation.hours'           => ['nullable', 'string', 'max:120'],
            'service_escalation.matrix'          => ['nullable', 'string', 'max:2000'],

            // §12 — Training & Handover (attendees + competency)
            'training_handover'                     => ['nullable', 'array'],
            'training_handover.competency'          => ['nullable', 'string', 'max:2000'],
            'training_handover.attendees'           => ['nullable', 'array'],
            'training_handover.attendees.*.name'    => ['nullable', 'string', 'max:180'],
            'training_handover.attendees.*.role'    => ['nullable', 'string', 'max:120'],

            // §15 — Document control
            'document_control'                 => ['nullable', 'array'],
            'document_control.revision'        => ['nullable', 'string', 'max:20'],
            'document_control.status'          => ['nullable', 'string', 'max:60'],
            'document_control.prepared_by'     => ['nullable', 'string', 'max:180'],
        ]);

        // Start from the current extracted_data so we preserve any unknown
        // keys the generator writes that the form doesn't surface (e.g.
        // system_summary, _draft_mode, cached_ai_response, etc.).
        $data = is_array($omManual->extracted_data) ? $omManual->extracted_data : [];

        // Overlay typed top-level fields. Flat form fields (project_name, etc.)
        // are the canonical shape; if the payload also carries a nested
        // `project` key from earlier writes, keep the flat values in sync
        // there too so downstream consumers reading either shape stay consistent.
        $data['project_name']   = $payload['project_name']   ?? ($data['project_name']   ?? null);
        $data['project_ref']    = $payload['project_ref']    ?? ($data['project_ref']    ?? null);
        $data['client_name']    = $payload['client_name']    ?? ($data['client_name']    ?? null);
        $data['site_address']   = $payload['site_address']   ?? ($data['site_address']   ?? null);
        $data['handover_date']  = $payload['handover_date']  ?? ($data['handover_date']  ?? '');
        $data['scope_of_works'] = $payload['scope_of_works'] ?? ($data['scope_of_works'] ?? '');
        $data['notes']          = $payload['notes']          ?? ($data['notes']          ?? '');

        if (isset($data['project']) && is_array($data['project'])) {
            $data['project']['name']   = $data['project_name'];
            $data['project']['ref']    = $data['project_ref'];
            $data['project']['client'] = $data['client_name'];
            $data['project']['site']   = $data['site_address'];
        }

        // Rooms + equipment — normalise into the canonical extracted_data
        // shape while preserving the narrative field that sanitiseRooms()
        // drops (sanitiseRooms is written for AI/JSON-supplied rooms and
        // discards prose; we keep it here because it's what the user just
        // edited). Falls through to the existing rooms if the form did not
        // include the rooms[] array at all.
        if (isset($payload['rooms']) && is_array($payload['rooms'])) {
            $data['rooms'] = self::normaliseFormRooms($payload['rooms']);
        }

        // Front-matter repeaters — distribution list and revision history.
        // The `?? $data[key]` fallback lets a form that doesn't submit the
        // repeater at all leave the existing entries intact. An empty
        // repeater (user cleared all rows) posts `[]` explicitly and we
        // honour that by writing the empty array.
        if (array_key_exists('distribution_list', $payload)) {
            $data['distribution_list'] = self::normaliseNamedRows(
                $payload['distribution_list'] ?? [],
                ['name', 'role', 'email']
            );
        }
        if (array_key_exists('revision_history', $payload)) {
            $data['revision_history'] = self::normaliseNamedRows(
                $payload['revision_history'] ?? [],
                ['date', 'rev', 'author', 'changes']
            );
        }

        // §10 Manufacturer support overrides — per-brand contact + warranty.
        // The AI generator resolves brands automatically at generate time;
        // these overrides win on next regen.
        if (array_key_exists('manufacturer_support_overrides', $payload)) {
            $data['manufacturer_support_overrides'] = self::normaliseNamedRows(
                $payload['manufacturer_support_overrides'] ?? [],
                ['brand', 'phone', 'email', 'portal', 'warranty']
            );
        }

        // §11 Service & escalation — composite (not a repeater).
        if (isset($payload['service_escalation']) && is_array($payload['service_escalation'])) {
            $data['service_escalation'] = [
                'contact_name' => trim((string) ($payload['service_escalation']['contact_name'] ?? '')),
                'phone'        => trim((string) ($payload['service_escalation']['phone']        ?? '')),
                'email'        => trim((string) ($payload['service_escalation']['email']        ?? '')),
                'hours'        => trim((string) ($payload['service_escalation']['hours']        ?? '')),
                'matrix'       => trim((string) ($payload['service_escalation']['matrix']       ?? '')),
            ];
        }

        // §12 Training & handover — composite w/ attendees repeater inside.
        if (isset($payload['training_handover']) && is_array($payload['training_handover'])) {
            $ho = $payload['training_handover'];
            $data['training_handover'] = [
                'date'       => trim((string) ($payload['handover_date'] ?? $ho['date'] ?? '')),
                'competency' => trim((string) ($ho['competency'] ?? '')),
                'attendees'  => self::normaliseNamedRows(
                    (array) ($ho['attendees'] ?? []),
                    ['name', 'role']
                ),
            ];
        }

        // §15 Document control — composite.
        if (isset($payload['document_control']) && is_array($payload['document_control'])) {
            $data['document_control'] = [
                'revision'    => trim((string) ($payload['document_control']['revision']    ?? '')),
                'status'      => trim((string) ($payload['document_control']['status']      ?? '')),
                'prepared_by' => trim((string) ($payload['document_control']['prepared_by'] ?? '')),
            ];
        }

        $omManual->update([
            'project_name'   => $data['project_name'],
            'project_ref'    => $data['project_ref'],
            'client_name'    => $data['client_name'],
            'site_address'   => $data['site_address'],
            'extracted_data' => $data,
        ]);

        return back()->with('success', 'O&M details saved.');
    }

    /**
     * Normalise the rooms[] payload from the structured edit form into the
     * canonical extracted_data['rooms'][*] shape. Preserves the narrative
     * field (which OmManualGeneratorService::sanitiseRooms drops) so that
     * a user editing a room's prose here doesn't lose it on save.
     *
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    /**
     * Normalise a repeater's rows down to a flat array of typed strings.
     * Trims each field, drops any row where every value is empty (so a
     * clicked-but-unfilled "+ Add" row doesn't pollute the payload).
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string>              $keys  Ordered field names to keep.
     * @return array<int,array<string,string>>
     */
    private static function normaliseNamedRows(array $rows, array $keys): array
    {
        $out = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalised = [];
            $hasContent = false;
            foreach ($keys as $k) {
                $v = trim((string) ($row[$k] ?? ''));
                $normalised[$k] = $v;
                if ($v !== '') {
                    $hasContent = true;
                }
            }
            if ($hasContent) {
                $out[] = $normalised;
            }
        }
        return $out;
    }

    private static function normaliseFormRooms(array $rows): array
    {
        return array_values(array_map(function (array $row): array {
            $equipment = is_array($row['equipment'] ?? null) ? $row['equipment'] : [];

            $normalisedEquipment = array_values(array_map(function (array $eq): array {
                return [
                    'qty'          => (int) ($eq['qty'] ?? 1),
                    'name'         => trim((string) ($eq['description'] ?? '')),
                    'description'  => trim((string) ($eq['description'] ?? '')),
                    'part_no'      => trim((string) ($eq['part_number']  ?? '')),
                    'part_number'  => trim((string) ($eq['part_number']  ?? '')),
                    'manufacturer' => trim((string) ($eq['manufacturer'] ?? '')),
                    'model'        => '',
                    'category'     => 'Other',
                ];
            }, $equipment));

            return [
                'name'        => trim((string) ($row['name']        ?? 'Unknown Room')) ?: 'Unknown Room',
                'floor'       => trim((string) ($row['floor']       ?? '')) ?: null,
                'drawing_ref' => trim((string) ($row['drawing_ref'] ?? '')),
                'narrative'   => trim((string) ($row['narrative']   ?? '')),
                'description' => trim((string) ($row['narrative']   ?? '')),
                'equipment'   => $normalisedEquipment,
            ];
        }, $rows));
    }

    // ── editDevices (asset register form) ────────────────────────────────────

    /**
     * Render the per-device editor for the OM's project — one row per
     * `devices` table entry with editable serial / IP / VLAN / port /
     * firmware / asset tag / MAC fields.
     */
    public function editDevices(OmManual $omManual): View
    {
        $this->authorize('update', $omManual);

        $project = $omManual->project;
        $devices = $project
            ? $project->devices()
                ->orderBy('room_name')
                ->orderBy('description')
                ->orderBy('id')
                ->get()
            : collect();

        return view('om-manual.edit-devices', [
            'manual'  => $omManual,
            'devices' => $devices,
        ]);
    }

    // ── updateDevices (bulk-save asset register) ─────────────────────────────

    /**
     * Bulk-update the device rows for the OM's project. Only updates rows
     * that belong to the OM's project (any device id submitted that doesn't
     * match is silently ignored — defends against tampered form input).
     *
     * If the "regenerate" submit button was used, the OM build job is
     * dispatched after the save so the user lands back with a queued doc.
     */
    public function updateDevices(Request $request, OmManual $omManual): RedirectResponse
    {
        $this->authorize('update', $omManual);

        if ($omManual->project_id === null) {
            return back()->with('error', 'This O&M Manual is not linked to a project — devices cannot be edited here.');
        }

        $payload = $request->validate([
            'devices'                       => ['nullable', 'array'],
            'devices.*.serial_number'       => ['nullable', 'string', 'max:120'],
            'devices.*.mac_address'         => ['nullable', 'string', 'max:64'],
            'devices.*.ip_address'          => ['nullable', 'string', 'max:64'],
            'devices.*.vlan'                => ['nullable', 'string', 'max:32'],
            'devices.*.port'                => ['nullable', 'string', 'max:64'],
            'devices.*.firmware_version'    => ['nullable', 'string', 'max:64'],
            'devices.*.asset_tag'           => ['nullable', 'string', 'max:120'],
            'regenerate'                    => ['nullable', 'string'],
        ]);

        $rows    = $payload['devices'] ?? [];
        $updated = 0;

        foreach ($rows as $deviceId => $fields) {
            $device = Device::where('project_id', $omManual->project_id)
                ->whereKey((int) $deviceId)
                ->first();
            if ($device === null) {
                continue;
            }

            $device->fill([
                'serial_number'    => $this->trimOrNull($fields['serial_number']    ?? null),
                'mac_address'      => $this->trimOrNull($fields['mac_address']      ?? null),
                'ip_address'       => $this->trimOrNull($fields['ip_address']       ?? null),
                'vlan'             => $this->trimOrNull($fields['vlan']             ?? null),
                'port'             => $this->trimOrNull($fields['port']             ?? null),
                'firmware_version' => $this->trimOrNull($fields['firmware_version'] ?? null),
                'asset_tag'        => $this->trimOrNull($fields['asset_tag']        ?? null),
            ])->save();
            $updated++;
        }

        if (! empty($payload['regenerate'])) {
            $omManual->update([
                'status'        => OmManual::STATUS_GENERATING,
                'error_message' => null,
            ]);

            app(WorkerMonitorService::class)->ensureRunning();
            BuildOmManualJob::dispatch($omManual->id);

            return redirect()
                ->route('om-manuals.edit-devices', $omManual)
                ->with('success', "Saved {$updated} device(s). O&M generation re-queued — the new PDF will reflect the updated values once the job completes.");
        }

        return redirect()
            ->route('om-manuals.edit-devices', $omManual)
            ->with('success', "Saved {$updated} device(s).");
    }

    private function trimOrNull(mixed $v): ?string
    {
        $s = trim((string) ($v ?? ''));
        return $s === '' ? null : $s;
    }

    // ── Asset register CSV workflow ─────────────────────────────────────────

    /**
     * The fields users are expected to fill in on the CSV template. Ordered
     * for readability in a spreadsheet. Kept as a constant so the download
     * and the import share the exact same column set.
     */
    private const ASSET_CSV_HEADERS = [
        'device_id',            // read-only — matching key on import
        'room_name',            // read-only — helps the user find the row
        'part_no',              // read-only
        'description',          // read-only
        'manufacturer',         // read-only
        'serial_number',        // FILL IN
        'mac_address',          // FILL IN
        'ip_address',           // FILL IN
        'vlan',                 // FILL IN
        'port',                 // FILL IN
        'firmware_version',     // FILL IN
        'asset_tag',            // FILL IN
        'commissioning_date',   // FILL IN — YYYY-MM-DD
        'warranty_expiry',      // FILL IN — YYYY-MM-DD
    ];

    /**
     * Which columns the import actually writes back. Everything else on the
     * incoming CSV is treated as read-only reference and ignored, so a user
     * editing part_no or description in the template doesn't accidentally
     * mutate the source records.
     */
    private const ASSET_CSV_FILLABLE = [
        'serial_number',
        'mac_address',
        'ip_address',
        'vlan',
        'port',
        'firmware_version',
        'asset_tag',
        'commissioning_date',
        'warranty_expiry',
    ];

    /**
     * Download a CSV pre-populated with the project's current devices — one
     * row per device, identifier columns filled, fillable asset columns
     * left blank (unless already populated on the record). Filename encodes
     * the project ref so a support conversation can identify which template
     * was used.
     */
    public function downloadAssetTemplate(OmManual $omManual): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorize('update', $omManual);

        if ($omManual->project_id === null) {
            abort(400, 'This O&M Manual is not linked to a project — asset templates can only be generated for project-linked manuals.');
        }

        $devices = $omManual->project?->devices()
            ->orderBy('room_name')
            ->orderBy('description')
            ->orderBy('id')
            ->get() ?? collect();

        $refSlug = str($omManual->project_ref ?? 'asset-register')->slug()->toString();
        $fname   = "asset-register-{$refSlug}-template.csv";

        return response()->streamDownload(function () use ($devices) {
            $out = fopen('php://output', 'w');

            // UTF-8 BOM so Excel (Windows) picks up encoding correctly.
            fwrite($out, "\xEF\xBB\xBF");

            fputcsv($out, self::ASSET_CSV_HEADERS);

            foreach ($devices as $device) {
                fputcsv($out, [
                    (string) $device->id,
                    (string) ($device->room_name       ?? ''),
                    (string) ($device->part_no         ?? ''),
                    (string) ($device->description     ?? ''),
                    (string) ($device->manufacturer    ?? ''),
                    (string) ($device->serial_number    ?? ''),
                    (string) ($device->mac_address      ?? ''),
                    (string) ($device->ip_address       ?? ''),
                    (string) ($device->vlan             ?? ''),
                    (string) ($device->port             ?? ''),
                    (string) ($device->firmware_version ?? ''),
                    (string) ($device->asset_tag        ?? ''),
                    optional($device->commissioning_date)->format('Y-m-d') ?? '',
                    optional($device->warranty_expiry)->format('Y-m-d')    ?? '',
                ]);
            }

            fclose($out);
        }, $fname, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $fname . '"',
        ]);
    }

    /**
     * Import a CSV that was downloaded from downloadAssetTemplate(), edited
     * by the user, and re-uploaded. Rows are matched by `device_id` —
     * unknown IDs and IDs belonging to other projects are skipped
     * (defence-in-depth against tampered spreadsheets).
     *
     * Only the columns listed in ASSET_CSV_FILLABLE are written back. A row
     * with all fillable cells blank is skipped rather than clearing every
     * asset field on that device — protects an "I only meant to update the
     * Crestron rows" flow from wiping the others.
     */
    public function importAssetsCsv(Request $request, OmManual $omManual): RedirectResponse
    {
        $this->authorize('update', $omManual);

        if ($omManual->project_id === null) {
            return back()->with('error', 'This O&M Manual is not linked to a project — CSV import needs a project link.');
        }

        $request->validate([
            'asset_csv' => ['required', 'file', 'mimes:csv,txt', 'max:2048'],
        ]);

        $path = $request->file('asset_csv')->getRealPath();
        $fh   = fopen($path, 'r');
        if ($fh === false) {
            return back()->with('error', 'Could not read the uploaded CSV — try re-exporting from your spreadsheet.');
        }

        // Strip the optional UTF-8 BOM so Excel-saved files still parse.
        $peek = fread($fh, 3);
        if ($peek !== "\xEF\xBB\xBF") {
            rewind($fh);
        }

        $header = fgetcsv($fh);
        if ($header === false || $header === null) {
            fclose($fh);
            return back()->with('error', 'The uploaded CSV is empty. Download a fresh template and try again.');
        }

        // Normalise header cells to lower-case keys so a "Serial Number" header
        // still matches our expected "serial_number" column.
        $header = array_map(
            fn ($h) => strtolower(trim(str_replace(' ', '_', (string) $h))),
            $header
        );

        $idxOf = fn (string $key): ?int => (($p = array_search($key, $header, true)) === false ? null : $p);

        $idxDeviceId = $idxOf('device_id');
        if ($idxDeviceId === null) {
            fclose($fh);
            return back()->with('error', 'Missing required "device_id" column. Use the "Download template" button to get a fresh CSV with the right columns.');
        }

        $fillableIdx = [];
        foreach (self::ASSET_CSV_FILLABLE as $col) {
            $fillableIdx[$col] = $idxOf($col);
        }

        $updated  = 0;
        $skipped  = 0;
        $rowNum   = 1;   // Header was row 1.

        while (($row = fgetcsv($fh)) !== false) {
            $rowNum++;
            $rawId = trim((string) ($row[$idxDeviceId] ?? ''));
            if ($rawId === '') {
                $skipped++;
                continue;
            }

            $device = Device::where('project_id', $omManual->project_id)
                ->whereKey((int) $rawId)
                ->first();
            if ($device === null) {
                $skipped++;
                continue;
            }

            $attrs = [];
            $anyValue = false;
            foreach (self::ASSET_CSV_FILLABLE as $col) {
                $idx = $fillableIdx[$col];
                if ($idx === null) {
                    continue;
                }
                $value = trim((string) ($row[$idx] ?? ''));
                if ($value === '') {
                    continue;
                }
                $anyValue = true;

                // Date columns tolerate both YYYY-MM-DD and dd/mm/yyyy — Excel
                // frequently reformats on save.
                if (in_array($col, ['commissioning_date', 'warranty_expiry'], true)) {
                    try {
                        $attrs[$col] = \Illuminate\Support\Carbon::parse($value)->toDateString();
                    } catch (\Throwable) {
                        // Skip bad date, keep other cells on the row.
                    }
                    continue;
                }

                $attrs[$col] = $value;
            }

            if (! $anyValue) {
                $skipped++;
                continue;
            }

            $device->fill($attrs)->save();
            $updated++;
        }

        fclose($fh);

        $msg = "Imported {$updated} device row(s).";
        if ($skipped > 0) {
            $msg .= " Skipped {$skipped} row(s) (blank, unknown device_id, or from a different project).";
        }

        return redirect()
            ->route('om-manuals.edit-devices', $omManual)
            ->with('success', $msg);
    }

    // ── generate (Pass 2) ─────────────────────────────────────────────────────

    public function generate(OmManual $omManual): RedirectResponse
    {
        $this->authorize('update', $omManual);

        if (empty($omManual->extracted_data)) {
            return back()->with('error', 'No equipment data found. Please re-upload the quote PDF.');
        }

        // Dispatch as background job to avoid 504 timeout — same pattern as
        // generateFromProject() and retryGeneration().
        $omManual->update([
            'status'        => OmManual::STATUS_GENERATING,
            'error_message' => null,
        ]);

        app(WorkerMonitorService::class)->ensureRunning();
        BuildOmManualJob::dispatch($omManual->id);

        return back()->with('success', 'O&M generation queued — the document will be ready to download shortly.');
    }

    // ── download (.docx) ──────────────────────────────────────────────────────

    public function download(OmManual $omManual): BinaryFileResponse|RedirectResponse
    {
        $this->authorize('view', $omManual);

        if (! $omManual->filename) {
            return back()->with('error', 'No document available yet. Please generate the manual first.');
        }

        // Post-H-07: the artifact store resolves the new `documents` disk first
        // and falls back to legacy storage/app/om-manuals/ for older files.
        $filePath = app(\App\Services\DocumentArtifactStorage::class)
            ->readPath(\App\Services\DocumentArtifactStorage::TYPE_OM, basename($omManual->filename));

        if ($filePath === null) {
            return back()->with('error', 'Document file not found on disk.');
        }

        // Audit M-08 — draft-mode DOCX gets a filename prefix. The DOCX body
        // itself has no watermark (PhpWord shape-in-header would need a rebuild
        // of the templating layer) — the filename prefix + the O&M edit UI's
        // "draft mode" banner are the belt-and-braces surfacing for now.
        $downloadFilename = ! empty($omManual->extracted_data['_draft_mode'])
            ? 'DRAFT-' . $omManual->filename
            : $omManual->filename;

        return response()->download(
            $filePath,
            $downloadFilename,
            ['Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'],
        );
    }

    // ── downloadPdf ───────────────────────────────────────────────────────────

    public function downloadPdf(OmManual $omManual): BinaryFileResponse|RedirectResponse
    {
        $this->authorize('view', $omManual);

        if (empty($omManual->generated_data)) {
            return back()->with('error', 'No generated content yet. Please run Generate first.');
        }

        try {
            $pdfPath = $this->pdfService->buildOmManual($omManual);
        } catch (\Throwable $e) {
            return back()->with('error', 'PDF generation failed: ' . $e->getMessage());
        }

        $filename = pathinfo($omManual->filename ?? 'om-manual', PATHINFO_FILENAME) . '.pdf';

        // Audit M-08 — draft-mode PDFs get a filename prefix so a downloaded
        // copy sat in a client's inbox reads as work-in-progress at a glance,
        // matching the diagonal watermark rendered inside the PDF body.
        if (! empty($omManual->extracted_data['_draft_mode'])) {
            $filename = 'DRAFT-' . $filename;
        }

        return response()->download($pdfPath, $filename, [
            'Content-Type' => 'application/pdf',
        ])->deleteFileAfterSend();
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function destroy($omManual): RedirectResponse
    {
        $record = OmManual::findOrFail($omManual);
        $this->authorize('delete', $record);

        $projectId = $record->project_id;

        // Soft-delete only — keep file on disk so it can be restored
        $record->delete();

        if ($projectId) {
            return redirect()->route('projects.show', $projectId)->with('success', 'O&M Manual deleted.');
        }

        return redirect()->route('om-manuals.index')->with('success', 'O&M Manual deleted.');
    }

    public function restore(int $id): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $record = OmManual::withTrashed()->findOrFail($id);
        $record->restore();

        return back()->with('success', 'O&M Manual restored.');
    }

    public function forceDestroy(int $id): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $record = OmManual::onlyTrashed()->findOrFail($id);

        if ($record->filename) {
            // Previously this targeted Storage::disk('local') which resolves
            // to storage/app/private/om-manuals/ — the wrong disk — so the
            // delete was a silent no-op. The artifact store removes copies
            // from both the new `documents` disk and the legacy path.
            app(\App\Services\DocumentArtifactStorage::class)
                ->delete(\App\Services\DocumentArtifactStorage::TYPE_OM, basename((string) $record->filename));
        }

        $record->forceDelete();

        return back()->with('success', 'O&M Manual permanently deleted.');
    }

    // ── Private ───────────────────────────────────────────────────────────────

    private function cleanupTmp(string $dir): void
    {
        if (is_dir($dir)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($files as $fileinfo) {
                $fileinfo->isDir() ? rmdir($fileinfo->getRealPath()) : unlink($fileinfo->getRealPath());
            }
            rmdir($dir);
        }
    }
}
