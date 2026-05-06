<?php

namespace App\Http\Controllers;

use App\Core\Modules\Survey\SurveyService;
use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyPhoto;
use App\Models\SiteSurveyRoom;
use App\Models\SiteSurveyRoomQuestion;
use App\Services\Survey\SiteSurveyTierOneReadinessService;
use App\Services\SurveyPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SiteSurveyController extends Controller
{
    public function __construct(
        private readonly SurveyService                     $service,
        private readonly SurveyPdfService                  $pdfService,
        private readonly SiteSurveyTierOneReadinessService $tierOne,
    ) {}

    // ─── Listing / show ──────────────────────────────────────────────────────

    public function index(Request $request): View
    {
        $isAdmin     = auth()->user()->isAdmin();
        $showDeleted = $isAdmin && $request->boolean('show_deleted');

        if ($showDeleted) {
            $surveys = SiteSurvey::onlyTrashed()->with(['user', 'project:id,name'])->withCount('rooms')->latest('deleted_at')->paginate(15);
            return view('site-survey.index', compact('surveys', 'isAdmin', 'showDeleted'));
        }

        $surveys = SiteSurvey::where('user_id', auth()->id())
            ->with('project:id,name')
            ->withCount('rooms')
            ->latest()
            ->paginate(15);

        return view('site-survey.index', compact('surveys', 'isAdmin', 'showDeleted'));
    }

    public function show(SiteSurvey $siteSurvey): View
    {
        $this->authorizeSurvey($siteSurvey);

        // `rooms.questions` eager-loaded so SiteSurveyTierOneReadinessService
        // doesn't N+1 when counting answered pre-install checks.
        $siteSurvey->load('rooms.photos', 'rooms.questions', 'project:id,name');

        $tierOne = $this->tierOne->assessSurvey($siteSurvey);

        return view('site-survey.show', [
            'survey'  => $siteSurvey,
            'tierOne' => $tierOne,
        ]);
    }

    // ─── Create ──────────────────────────────────────────────────────────────

    public function create(Request $request): View
    {
        $projects = Project::where('user_id', auth()->id())
            ->orderBy('name')
            ->get(['id', 'name']);

        $selectedProjectId = $request->query('project_id');

        $existingSurvey = null;
        if ($selectedProjectId) {
            $existingSurvey = SiteSurvey::where('project_id', $selectedProjectId)
                ->whereNull('superseded_at')
                ->whereIn('status', ['draft', 'completed'])
                ->first();
        }

        return view('site-survey.create', compact('projects', 'selectedProjectId', 'existingSurvey'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateSurvey($request);

        // ── Ownership check when a project_id is present ────────────────────
        if (! empty($data['project_id'])) {
            $project = Project::find($data['project_id']);
            abort_if(
                $project && $project->user_id !== auth()->id() && ! auth()->user()?->isAdmin(),
                403
            );
        }

        // Extract supersede flag from the form submission.
        $data['supersede'] = $request->boolean('supersede');

        /** @var \App\Models\User $user */
        $user   = auth()->user();
        $survey = $this->service->create($user, $data);

        $successMessage = $data['supersede']
            ? 'Previous survey archived. New survey created.'
            : 'Site survey created. Add photos to each room below.';

        return redirect()->route('site-surveys.show', $survey)
            ->with('success', $successMessage);
    }

    /**
     * GET /site-surveys/from-project/{project}
     *
     * Pre-create a survey seeded from the project's reviewed package data.
     * If the project already has an active survey, returns the create view with
     * $existingSurvey set so the supersede confirmation block is rendered.
     * If no existing survey: creates directly and redirects to edit page.
     */
    public function createFromProject(Project $project): \Illuminate\Http\Response|RedirectResponse
    {
        // Only the project owner or an admin may create a survey for this project.
        abort_if(
            $project->user_id !== auth()->id() && ! auth()->user()?->isAdmin(),
            403
        );

        // Check for an existing active survey.
        $existingSurvey = SiteSurvey::where('project_id', $project->id)
            ->whereNull('superseded_at')
            ->whereIn('status', ['draft', 'completed'])
            ->first();

        if ($existingSurvey) {
            // Render the create form with the supersede confirmation block.
            $projects = Project::where('user_id', auth()->id())
                ->orderBy('name')
                ->get(['id', 'name']);

            return response(view('site-survey.create', [
                'projects'          => $projects,
                'selectedProjectId' => $project->id,
                'existingSurvey'    => $existingSurvey,
                'fromProject'       => $project,
            ]));
        }

        /** @var \App\Models\User $user */
        $user   = auth()->user();
        $survey = $this->service->createFromProject($project, $user);

        return redirect()->route('site-surveys.confirm-rooms', $survey)
            ->with('success', 'Survey created and pre-filled from project data. Confirm the rooms below.');
    }

    /**
     * POST /site-surveys/supersede-from-project/{project}
     *
     * Directly archives any existing active survey and creates a fresh one
     * pre-filled from the project's latest package data — no confirmation page.
     * Used by the ↻ Regen button on the project overview.
     */
    public function supersedeFromProject(Project $project): RedirectResponse
    {
        abort_if(
            $project->user_id !== auth()->id() && ! auth()->user()?->isAdmin(),
            403
        );

        /** @var \App\Models\User $user */
        $user   = auth()->user();
        $survey = $this->service->createFromProject($project, $user, supersede: true);

        return redirect()->route('site-surveys.confirm-rooms', $survey)
            ->with('success', 'Previous survey archived. Confirm the rooms below.');
    }

    // ─── Confirm Rooms (review step) ─────────────────────────────────────────

    /**
     * GET /site-surveys/{siteSurvey}/confirm-rooms
     *
     * Lightweight verify-rooms screen shown after createFromProject. Lets the
     * user untick rooms that aren't physical spaces, bump qty for repeated
     * room types, edit room names, and edit the survey-level works
     * description before the survey enters the heavy edit form.
     *
     * Quick task 260506-fh0.
     */
    public function confirmRooms(SiteSurvey $siteSurvey): View
    {
        $this->authorizeSurvey($siteSurvey);
        $siteSurvey->load('rooms');

        return view('site-survey.confirm-rooms', [
            'survey' => $siteSurvey,
        ]);
    }

    /**
     * POST /site-surveys/{siteSurvey}/confirm-rooms
     *
     * Applies the user's confirm-rooms choices: deletes excluded rooms,
     * updates names + scope text, and expands qty>1 entries into N numbered
     * copies via the existing SurveyService::update() qty-expansion logic.
     *
     * Quick task 260506-fh0.
     */
    public function applyConfirmedRooms(Request $request, SiteSurvey $siteSurvey): RedirectResponse
    {
        $this->authorizeSurvey($siteSurvey);

        $validated = $request->validate([
            'general_notes'              => ['nullable', 'string', 'max:3000'],
            'rooms'                      => ['nullable', 'array'],
            'rooms.*.id'                 => ['nullable', 'integer'],
            'rooms.*.include'            => ['nullable', 'boolean'],
            'rooms.*.room_name'          => ['required_with:rooms.*.include', 'string', 'max:150'],
            'rooms.*.qty'                => ['nullable', 'integer', 'min:1', 'max:99'],
            'rooms.*.av_requirements'    => ['nullable', 'string', 'max:5000'],
        ]);

        // Build the rooms[] payload SurveyService::update() already understands.
        // Excluded rooms are simply omitted — update() then prunes any stored room
        // whose id is not in the incoming list.
        $roomsPayload = [];
        foreach ($validated['rooms'] ?? [] as $row) {
            if (! ($row['include'] ?? false)) {
                continue;
            }
            $roomsPayload[] = [
                'id'              => $row['id'] ?? null,
                'room_name'       => trim((string) $row['room_name']),
                'qty'             => (int) ($row['qty'] ?? 1),
                'av_requirements' => $row['av_requirements'] ?? null,
            ];
        }

        /** @var \App\Models\User $user */
        $user = auth()->user();
        $this->service->update($siteSurvey, $user, [
            'project_name'  => $siteSurvey->project_name,
            'general_notes' => $validated['general_notes'] ?? $siteSurvey->general_notes,
            'rooms'         => $roomsPayload,
        ]);

        return redirect()->route('site-surveys.edit', $siteSurvey)
            ->with('success', 'Rooms confirmed. Add details and photos as needed.');
    }

    // ─── Edit ────────────────────────────────────────────────────────────────

    public function edit(SiteSurvey $siteSurvey): View
    {
        $this->authorizeSurvey($siteSurvey);

        $siteSurvey->load('rooms.photos', 'rooms.questions');

        $projects = Project::where('user_id', auth()->id())
            ->orderBy('name')
            ->get(['id', 'name']);

        // Build kit-by-area lookup from the linked project's latest package so
        // each room card can display a collapsible quote kit list.
        $kitByArea = [];
        if ($siteSurvey->project_id) {
            $proj = Project::with('latestPackage')->find($siteSurvey->project_id);
            foreach ((array) ($proj?->latestPackage?->extracted_data['equipment'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
                // Only include hardware items in the survey kit list
                $category = strtolower(trim((string) ($item['category'] ?? 'hardware')));
                if ($category !== 'hardware') {
                    continue;
                }
                $area = trim((string) ($item['area'] ?? $item['location'] ?? ''));
                if ($area === '') {
                    continue;
                }
                $kitByArea[$area][] = $item;
            }
        }

        return view('site-survey.edit', [
            'survey'    => $siteSurvey,
            'projects'  => $projects,
            'kitByArea' => $kitByArea,
        ]);
    }

    /**
     * GET /site-surveys/project-data/{project}
     *
     * Returns the project's name / ref / client / site address as JSON so the
     * create form can auto-fill those fields when a project is selected.
     */
    public function projectData(Project $project): JsonResponse
    {
        abort_unless(
            $project->user_id === auth()->id() || auth()->user()?->isAdmin(),
            403
        );

        return response()->json([
            'name'         => $project->name,
            'ref'          => $project->ref,
            'client_name'  => $project->client_name,
            'site_address' => $project->site_address,
        ]);
    }

    public function update(Request $request, SiteSurvey $siteSurvey): RedirectResponse
    {
        $this->authorizeSurvey($siteSurvey);

        $data = $this->validateSurvey($request);

        /** @var \App\Models\User $user */
        $user = auth()->user();
        $this->service->update($siteSurvey, $user, $data);

        return redirect()->route('site-surveys.show', $siteSurvey)
            ->with('success', 'Survey updated.');
    }

    // ─── Complete ────────────────────────────────────────────────────────────

    public function complete(Request $request, SiteSurvey $siteSurvey): RedirectResponse
    {
        $this->authorizeSurvey($siteSurvey);

        $request->validate([
            'project_id' => ['nullable', 'exists:projects,id'],
        ]);

        /** @var \App\Models\User $user */
        $user = auth()->user();
        $this->service->complete($siteSurvey, $user, $request->input('project_id'));

        return redirect()->route('site-surveys.show', $siteSurvey)
            ->with('success', 'Survey marked as completed.');
    }

    // ─── Question answer persistence (internal form) ──────────────────────────

    /**
     * POST /site-surveys/{siteSurvey}/rooms/{room}/questions/{question}
     *
     * Save or update the answer for a pre-install check question from the
     * internal admin survey form. Auth-gated (session auth, not token).
     *
     * Identical business logic to PublicSurveyController::answerQuestion() —
     * same validation, same security scope, same JSON response shape.
     */
    public function answerQuestion(Request $request, SiteSurvey $siteSurvey, SiteSurveyRoom $room, int $question): JsonResponse
    {
        abort_unless($room->site_survey_id === $siteSurvey->id, 403);

        // Scope question to room — prevents guessing other rooms' question IDs.
        // Returns 403 (not 404) so the ID existence is not leaked.
        $questionRecord = SiteSurveyRoomQuestion::where('id', $question)
            ->where('site_survey_room_id', $room->id)
            ->first();

        abort_if($questionRecord === null, 403);

        $validated = $request->validate([
            'answer'     => ['nullable', 'in:yes,no,other'],
            'other_text' => ['nullable', 'string', 'max:2000'],
        ]);

        $update = [];
        if (array_key_exists('answer', $validated) && $validated['answer'] !== null) {
            $update['answer'] = $validated['answer'];
            if ($validated['answer'] !== 'other') {
                $update['other_text'] = null;
            }
        }
        if (array_key_exists('other_text', $validated)) {
            $update['other_text'] = $validated['other_text'];
        }

        if (! empty($update)) {
            $questionRecord->update($update);
        }

        $fresh = $questionRecord->fresh();

        return response()->json([
            'answered'   => $fresh->answer !== null,
            'answer'     => $fresh->answer,
            'other_text' => $fresh->other_text,
        ]);
    }

    // ─── Destroy ─────────────────────────────────────────────────────────────

    public function destroy($siteSurvey): RedirectResponse
    {
        $record = SiteSurvey::findOrFail($siteSurvey);
        $this->authorizeSurvey($record);

        $projectId = $record->project_id;

        $record->delete();

        if ($projectId) {
            return redirect()->route('projects.show', $projectId)->with('success', 'Survey deleted.');
        }

        return redirect()->route('site-surveys.index')->with('success', 'Survey deleted.');
    }

    public function restore(int $id): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $record = SiteSurvey::withTrashed()->findOrFail($id);
        $record->restore();

        return back()->with('success', 'Survey restored.');
    }

    public function forceDestroy(int $id): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        $record = SiteSurvey::onlyTrashed()->findOrFail($id);
        $record->forceDelete();

        return back()->with('success', 'Survey permanently deleted.');
    }

    // ─── Photo upload ────────────────────────────────────────────────────────

    /**
     * POST /site-surveys/{siteSurvey}/rooms/{room}/photos
     */
    public function uploadPhoto(Request $request, SiteSurvey $siteSurvey, SiteSurveyRoom $room): JsonResponse
    {
        $this->authorizeSurvey($siteSurvey);
        abort_unless($room->site_survey_id === $siteSurvey->id, 403);

        $request->validate([
            'photo'   => ['required', 'file', 'image', 'max:10240'],  // 10 MB
            'caption' => ['nullable', 'string', 'max:200'],
        ]);

        $photo = $this->service->addPhoto(
            $room,
            $request->file('photo'),
            $request->input('caption'),
        );

        return response()->json([
            'id'            => $photo->id,
            'filename'      => $photo->filename,
            'original_name' => $photo->original_name,
            'caption'       => $photo->caption,
            'url'           => route('site-surveys.photos.serve', ['photo' => $photo->id]),
        ]);
    }

    /**
     * DELETE /site-surveys/photos/{photo}
     */
    public function deletePhoto(SiteSurveyPhoto $photo): JsonResponse
    {
        // Verify ownership through room → survey chain
        $survey = $photo->room->survey;
        $this->authorizeSurvey($survey);

        $this->service->deletePhoto($photo);

        return response()->json(['deleted' => true]);
    }

    /**
     * GET /site-surveys/photos/{photo} — serve photo through local storage
     *
     * Uses storagePath() to support both legacy flat paths and the new
     * project-scoped path format (projects/{id}/surveys/{id}/file.jpg).
     */
    public function servePhoto(SiteSurveyPhoto $photo): \Symfony\Component\HttpFoundation\Response
    {
        $survey = $photo->room->survey;
        $this->authorizeSurvey($survey);

        $path = \Illuminate\Support\Facades\Storage::disk('local')->path($photo->storagePath());
        abort_unless(file_exists($path), 404);

        return response()->file($path, [
            'Content-Type'        => $photo->mime_type,
            'Content-Disposition' => 'inline; filename="' . $photo->original_name . '"',
        ]);
    }

    // ─── PDF downloads ───────────────────────────────────────────────────────

    /**
     * GET /site-surveys/{siteSurvey}/pdf
     */
    public function downloadPdf(SiteSurvey $siteSurvey): BinaryFileResponse
    {
        $this->authorizeSurvey($siteSurvey);

        $path = $this->pdfService->buildSummary($siteSurvey);

        return response()->download($path, 'site-survey-' . $siteSurvey->id . '.pdf');
    }

    /**
     * GET /site-surveys/blank-form
     */
    public function downloadBlankForm(): BinaryFileResponse
    {
        $path = $this->pdfService->buildBlank();

        return response()->download($path, 'site-survey-blank-form.pdf');
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function authorizeSurvey(SiteSurvey $survey): void
    {
        abort_unless($survey->user_id === auth()->id() || auth()->user()?->isAdmin(), 403);
    }

    private function validateSurvey(Request $request): array
    {
        return $request->validate([
            'project_id'                            => ['nullable', 'exists:projects,id'],
            'project_name'                          => ['required', 'string', 'max:200'],
            'project_ref'                           => ['nullable', 'string', 'max:50'],
            'client_name'                           => ['nullable', 'string', 'max:150'],
            'site_address'                          => ['nullable', 'string', 'max:500'],
            'survey_date'                           => ['nullable', 'date'],
            'surveyor_name'                         => ['nullable', 'string', 'max:100'],
            'site_contact_name'                     => ['nullable', 'string', 'max:150'],
            'site_contact_phone'                    => ['nullable', 'string', 'max:50'],
            'visit_time'                            => ['nullable', 'string', 'max:100'],
            'pm_name'                               => ['nullable', 'string', 'max:150'],
            'pm_phone'                              => ['nullable', 'string', 'max:50'],
            'pm_email'                              => ['nullable', 'email', 'max:150'],
            'general_notes'                         => ['nullable', 'string', 'max:3000'],
            'survey_type'                           => ['nullable', 'string', 'in:general,pa_system,infrastructure,signage,upgrade,mixed'],
            // Engineer-feedback site logistics (quick task 260503-rgg)
            'comms_room_access_status'              => ['nullable', 'string', 'in:yes,no,outsourced,unknown'],
            'comms_room_access_notes'               => ['nullable', 'string', 'max:2000'],
            'parking_restraints'                    => ['nullable', 'string', 'max:2000'],
            'distance_from_base_miles'              => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'distance_from_base_notes'              => ['nullable', 'string', 'max:2000'],
            'site_access_notes'                     => ['nullable', 'string', 'max:3000'],
            'delivery_routes'                       => ['nullable', 'string', 'max:3000'],
            // Rooms
            'rooms'                                 => ['nullable', 'array'],
            'rooms.*.id'                            => ['nullable', 'integer'],
            'rooms.*.qty'                           => ['nullable', 'integer', 'min:1', 'max:99'],
            'rooms.*.room_name'                     => ['required', 'string', 'max:150'],
            'rooms.*.room_ref'                      => ['nullable', 'string', 'max:50'],
            'rooms.*.floor'                         => ['nullable', 'string', 'max:50'],
            'rooms.*.area_type'                     => ['nullable', 'string', 'max:50'],
            'rooms.*.space_type'                    => ['nullable', 'string', 'in:general,pa_system,infrastructure,signage,upgrade,mixed'],
            // Dimensions / structure
            'rooms.*.room_width_m'                  => ['nullable', 'numeric', 'min:0', 'max:999'],
            'rooms.*.room_depth_m'                  => ['nullable', 'numeric', 'min:0', 'max:999'],
            'rooms.*.room_height_m'                 => ['nullable', 'numeric', 'min:0', 'max:99'],
            'rooms.*.ceiling_type'                  => ['nullable', 'string', 'max:50'],
            'rooms.*.ceiling_height_m'              => ['nullable', 'numeric', 'min:0', 'max:99'],
            'rooms.*.wall_material'                 => ['nullable', 'string', 'max:50'],
            'rooms.*.floor_type'                    => ['nullable', 'string', 'max:50'],
            // AV
            'rooms.*.av_requirements'               => ['nullable', 'string', 'max:5000'],
            'rooms.*.av_equipment_list'             => ['nullable', 'string', 'max:5000'],
            // Services
            'rooms.*.has_power'                     => ['nullable', 'boolean'],
            'rooms.*.has_network'                   => ['nullable', 'boolean'],
            'rooms.*.power_outlet_count'            => ['nullable', 'integer', 'min:0', 'max:999'],
            'rooms.*.network_port_count'            => ['nullable', 'integer', 'min:0', 'max:999'],
            'rooms.*.existing_cabling'              => ['nullable', 'string', 'max:500'],
            'rooms.*.requires_additional_power'     => ['nullable', 'boolean'],
            'rooms.*.access_notes'                  => ['nullable', 'string', 'max:500'],
            'rooms.*.notes'                         => ['nullable', 'string', 'max:500'],
            // PA system
            'rooms.*.speaker_count'                 => ['nullable', 'integer', 'min:0', 'max:999'],
            'rooms.*.speaker_type'                  => ['nullable', 'string', 'max:50'],
            'rooms.*.speaker_mounting'              => ['nullable', 'string', 'max:50'],
            'rooms.*.bg_noise_db'                   => ['nullable', 'integer', 'min:0', 'max:200'],
            // Digital signage
            'rooms.*.display_size_in'               => ['nullable', 'numeric', 'min:0', 'max:999'],
            'rooms.*.display_orient'                => ['nullable', 'string', 'in:landscape,portrait'],
            'rooms.*.display_mounting'              => ['nullable', 'string', 'max:50'],
            // Infrastructure
            'rooms.*.rack_unit_space'               => ['nullable', 'integer', 'min:0', 'max:999'],
            'rooms.*.cable_route_desc'              => ['nullable', 'string', 'max:3000'],
            // Upgrade / strip-out
            'rooms.*.existing_condition'            => ['nullable', 'string', 'max:3000'],
            'rooms.*.items_to_remove'               => ['nullable', 'string', 'max:3000'],
            'rooms.*.items_to_retain'               => ['nullable', 'string', 'max:3000'],

            // ── Engineer-feedback room-level additions (quick task 260503-rgg) ──
            'rooms.*.mounting_heights'                  => ['nullable', 'array'],
            'rooms.*.mounting_heights.screen_h_m'       => ['nullable', 'numeric', 'min:0', 'max:99'],
            'rooms.*.mounting_heights.camera_h_m'       => ['nullable', 'numeric', 'min:0', 'max:99'],
            'rooms.*.mounting_heights.booking_panel_h_m'=> ['nullable', 'numeric', 'min:0', 'max:99'],
            'rooms.*.mounting_heights.speaker_h_m'      => ['nullable', 'numeric', 'min:0', 'max:99'],
            'rooms.*.mounting_heights.other'            => ['nullable', 'array'],
            'rooms.*.mounting_heights.other.*.label'    => ['nullable', 'string', 'max:150'],
            'rooms.*.mounting_heights.other.*.height_m' => ['nullable', 'numeric', 'min:0', 'max:99'],

            'rooms.*.work_at_height_methods'            => ['nullable', 'array'],
            'rooms.*.work_at_height_methods.*'          => ['string', 'in:ladder,podium,tower,mewp,scaffold,na'],

            'rooms.*.cable_routes'                      => ['nullable', 'array'],
            'rooms.*.cable_routes.*.category'           => ['nullable', 'string', 'in:ceiling_speakers,desk_cables,mic_cables,booking_panel_cables,screen_cables,rack_to_room,other'],
            'rooms.*.cable_routes.*.from'               => ['nullable', 'string', 'max:255'],
            'rooms.*.cable_routes.*.to'                 => ['nullable', 'string', 'max:255'],
            'rooms.*.cable_routes.*.length_m'           => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'rooms.*.cable_routes.*.notes'              => ['nullable', 'string', 'max:500'],

            'rooms.*.wall_construction'                 => ['nullable', 'array'],
            'rooms.*.wall_construction.*'               => ['string', 'in:ply_lined,solid,plasterboard,masonry,metal_stud,concrete'],
            'rooms.*.wall_needs_reinforcement'          => ['nullable', 'boolean'],
            'rooms.*.wall_needs_chase_out'              => ['nullable', 'boolean'],
            'rooms.*.wall_needs_conduit'                => ['nullable', 'boolean'],

            'rooms.*.table_info'                        => ['nullable', 'array'],
            'rooms.*.table_info.has_grommets'           => ['nullable', 'boolean'],
            'rooms.*.table_info.grommet_count'          => ['nullable', 'integer', 'min:0', 'max:99'],
            'rooms.*.table_info.grommet_size'           => ['nullable', 'string', 'in:small,standard,large'],
            'rooms.*.table_info.notes'                  => ['nullable', 'string', 'max:500'],

            'rooms.*.floor_box_info'                    => ['nullable', 'array'],
            'rooms.*.floor_box_info.has_floor_box'      => ['nullable', 'boolean'],
            'rooms.*.floor_box_info.power_outlets'      => ['nullable', 'integer', 'min:0', 'max:99'],
            'rooms.*.floor_box_info.data_outlets'       => ['nullable', 'integer', 'min:0', 'max:99'],
            'rooms.*.floor_box_info.cable_space'        => ['nullable', 'string', 'in:tight,adequate,spacious'],
            'rooms.*.floor_box_info.notes'              => ['nullable', 'string', 'max:500'],

            'rooms.*.brackets_required'                 => ['nullable', 'array'],
            'rooms.*.brackets_required.*.equipment'     => ['nullable', 'string', 'max:255'],
            'rooms.*.brackets_required.*.model'         => ['nullable', 'string', 'max:255'],
            'rooms.*.brackets_required.*.pull_out'      => ['nullable', 'boolean'],
            'rooms.*.brackets_required.*.notes'         => ['nullable', 'string', 'max:500'],
        ]);
    }
}
