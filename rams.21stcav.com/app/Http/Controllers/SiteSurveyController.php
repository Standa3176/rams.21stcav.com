<?php

namespace App\Http\Controllers;

use App\Core\Modules\Survey\SurveyService;
use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyPhoto;
use App\Models\SiteSurveyRoom;
use App\Services\SurveyPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class SiteSurveyController extends Controller
{
    public function __construct(
        private readonly SurveyService    $service,
        private readonly SurveyPdfService $pdfService,
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

        $siteSurvey->load('rooms.photos', 'project:id,name');

        return view('site-survey.show', ['survey' => $siteSurvey]);
    }

    // ─── Create ──────────────────────────────────────────────────────────────

    public function create(Request $request): View
    {
        $projects = Project::where('user_id', auth()->id())
            ->orderBy('name')
            ->get(['id', 'name']);

        $selectedProjectId = $request->query('project_id');

        return view('site-survey.create', compact('projects', 'selectedProjectId'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateSurvey($request);

        /** @var \App\Models\User $user */
        $user   = auth()->user();
        $survey = $this->service->create($user, $data);

        return redirect()->route('site-surveys.show', $survey)
            ->with('success', 'Site survey created. Add photos to each room below.');
    }

    /**
     * GET /site-surveys/from-project/{project}
     *
     * Pre-create a survey seeded from the project's reviewed package data,
     * then redirect the authenticated user straight to the edit page so they
     * can review the pre-filled rooms before sharing the engineer link.
     */
    public function createFromProject(Project $project): RedirectResponse
    {
        // Only the project owner or an admin may create a survey for this project.
        abort_if(
            $project->user_id !== auth()->id() && ! auth()->user()?->isAdmin(),
            403
        );

        /** @var \App\Models\User $user */
        $user   = auth()->user();
        $survey = $this->service->createFromProject($project, $user);

        return redirect()->route('site-surveys.edit', $survey)
            ->with('success', 'Survey created and pre-filled from project data. Review the rooms below, then share the engineer link.');
    }

    // ─── Edit ────────────────────────────────────────────────────────────────

    public function edit(SiteSurvey $siteSurvey): View
    {
        $this->authorizeSurvey($siteSurvey);

        $siteSurvey->load('rooms.photos');

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

    // ─── Destroy ─────────────────────────────────────────────────────────────

    public function destroy($siteSurvey): RedirectResponse
    {
        $record = SiteSurvey::findOrFail($siteSurvey);
        $this->authorizeSurvey($record);

        $record->delete();

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
        abort_unless($survey->user_id === auth()->id() || auth()->user()?->is_admin, 403);
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
            'general_notes'                         => ['nullable', 'string', 'max:3000'],
            'survey_type'                           => ['nullable', 'string', 'in:general,pa_system,infrastructure,signage,upgrade,mixed'],
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
        ]);
    }
}
