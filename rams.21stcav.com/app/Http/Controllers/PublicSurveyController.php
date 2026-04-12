<?php

namespace App\Http\Controllers;

use App\Core\Modules\Survey\SurveyService;
use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyPhoto;
use App\Models\SiteSurveyRoom;
use App\Models\SiteSurveyRoomQuestion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * PublicSurveyController — no authentication required.
 *
 * All access is gated by a UUID access token embedded in the URL.
 * The token is generated automatically when a SiteSurvey is created.
 *
 * Routes (defined outside the auth middleware group):
 *   GET  /survey/{token}                       — show survey form
 *   POST /survey/{token}/save                  — save draft
 *   POST /survey/{token}/submit                — submit final survey
 *   POST /survey/{token}/rooms/{room}/photos   — upload room photo
 *   GET  /survey/{token}/photos/{photo}        — serve room photo
 */
class PublicSurveyController extends Controller
{
    public function __construct(
        private readonly SurveyService $service,
    ) {}

    // ─── Show survey form ────────────────────────────────────────────────────

    /**
     * GET /survey/{token}
     *
     * Display the survey form for an engineer to complete on-site.
     * Responds with a 404 if the token is unknown and a 410 (Gone) if expired.
     * Already-submitted surveys are shown in read-only mode.
     */
    public function show(string $token): View
    {
        $survey = $this->resolveSurvey($token);
        $survey->load('rooms.photos');

        // Build kit-by-area lookup from the linked project's latest package so
        // each room card on the public form can show the quote kit list.
        $kitByArea            = [];
        $solutionTypesByRoom  = []; // room_name → SolutionType
        if ($survey->project_id) {
            $project = Project::with('latestPackage')->find($survey->project_id);
            $extractedData = $project?->latestPackage?->extracted_data ?? [];

            foreach ((array) ($extractedData['equipment'] ?? []) as $item) {
                if (! is_array($item)) {
                    continue;
                }
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

            // Build solution type lookup: room_name → SolutionType model
            $stIds = [];
            foreach ((array) ($extractedData['room_overviews'] ?? []) as $ro) {
                $stId = (int) ($ro['solution_type_id'] ?? 0);
                $name = trim((string) ($ro['room'] ?? ''));
                if ($stId && $name !== '') {
                    $stIds[$name] = $stId;
                }
            }
            if (! empty($stIds)) {
                $stModels = \App\Models\SolutionType::whereIn('id', array_unique(array_values($stIds)))->get()->keyBy('id');
                foreach ($stIds as $roomName => $stId) {
                    if ($stModels->has($stId)) {
                        $solutionTypesByRoom[$roomName] = $stModels->get($stId);
                    }
                }
            }
        }

        return view('public-survey.show', [
            'survey'               => $survey,
            'token'                => $token,
            'readonly'             => $survey->isSubmitted(),
            'kitByArea'            => $kitByArea,
            'solutionTypesByRoom'  => $solutionTypesByRoom,
        ]);
    }

    // ─── Save draft ──────────────────────────────────────────────────────────

    /**
     * POST /survey/{token}/save
     *
     * Persists all room data without marking the survey as submitted.
     * Engineers can save mid-way through and return to the same URL.
     */
    public function save(Request $request, string $token): RedirectResponse
    {
        $survey = $this->resolveSurvey($token);

        abort_if($survey->isSubmitted(), 403, 'This survey has already been submitted.');

        $data = $this->validatePublicSurvey($request);

        $this->service->saveDraftPublic($survey, $data);

        return redirect()
            ->route('survey.show', ['token' => $token])
            ->with('success', 'Progress saved. You can return to this link at any time to continue.');
    }

    // ─── Submit survey ───────────────────────────────────────────────────────

    /**
     * POST /survey/{token}/submit
     *
     * Saves all data and marks the survey as submitted (status = completed,
     * submitted_at = now). Once submitted the form becomes read-only.
     */
    public function submit(Request $request, string $token): RedirectResponse
    {
        $survey = $this->resolveSurvey($token);

        abort_if($survey->isSubmitted(), 403, 'This survey has already been submitted.');

        $data = $this->validatePublicSurvey($request, true);

        $this->service->submitPublic($survey, $data);

        return redirect()->route('survey.confirmation', ['token' => $token]);
    }

    // ─── Confirmation page ───────────────────────────────────────────────────

    /**
     * GET /survey/{token}/confirmation
     *
     * Static thank-you page shown after successful survey submission.
     * Verifies the token is valid but does not require the survey to be completed
     * (in case engineer navigates back via browser history).
     */
    public function confirmation(string $token): View
    {
        $survey = $this->resolveSurvey($token);

        return view('public-survey.confirmation', ['survey' => $survey, 'token' => $token]);
    }

    // ─── Room completion ─────────────────────────────────────────────────────

    /**
     * POST /survey/{token}/rooms/{room}/complete
     *
     * Saves the room data from the request body and marks the room as completed.
     * Returns JSON so the client can update the UI without a page reload.
     */
    public function completeRoom(Request $request, string $token, SiteSurveyRoom $room): JsonResponse
    {
        $survey = $this->resolveSurvey($token);

        abort_unless($room->site_survey_id === $survey->id, 403);
        abort_if($survey->isSubmitted(), 403, 'This survey has already been submitted.');

        // Validate and save the room data first (same rules as the save endpoint).
        $data = $this->validatePublicSurvey($request);

        foreach ($data['rooms'] ?? [] as $roomData) {
            if ((int) ($roomData['id'] ?? 0) !== $room->id) {
                continue;
            }
            $room->update(array_merge(
                $this->roomAttributesFromData($roomData, $room->sort_order),
                ['is_completed' => true, 'completed_at' => now()]
            ));
            break;
        }

        // If no rooms array was sent, just mark complete without overwriting data.
        if (empty($data['rooms'])) {
            $room->update(['is_completed' => true, 'completed_at' => now()]);
        }

        return response()->json([
            'completed'    => true,
            'completed_at' => $room->fresh()->completed_at?->format('d M Y H:i'),
        ]);
    }

    /**
     * POST /survey/{token}/rooms/{room}/uncomplete
     *
     * Clears the completion flag on a room so the engineer can re-open it.
     */
    public function uncompleteRoom(string $token, SiteSurveyRoom $room): JsonResponse
    {
        $survey = $this->resolveSurvey($token);

        abort_unless($room->site_survey_id === $survey->id, 403);
        abort_if($survey->isSubmitted(), 403, 'This survey has already been submitted.');

        $room->update(['is_completed' => false, 'completed_at' => null]);

        return response()->json(['completed' => false]);
    }

    // ─── Question answer persistence ─────────────────────────────────────────

    /**
     * POST /survey/{token}/rooms/{room}/questions/{question}
     *
     * Save or update the answer for a single pre-install check question.
     * Called via AJAX from the Pre-Install Checks panel on the public survey form.
     *
     * Accepts:
     *   answer     — one of: yes, no, other (required unless saving only other_text)
     *   other_text — free-text explanation (required when answer=other is being saved; optional on blur)
     *
     * Security:
     *   - Token gates the survey (resolveSurvey)
     *   - Room must belong to the survey (abort_unless)
     *   - Question must belong to the room (scoped query with firstOrFail)
     */
    public function answerQuestion(Request $request, string $token, SiteSurveyRoom $room, int $question): JsonResponse
    {
        $survey = $this->resolveSurvey($token);

        abort_unless($room->site_survey_id === $survey->id, 403);
        abort_if($survey->isSubmitted(), 403, 'This survey has already been submitted.');

        // Scope question to room — prevents engineers guessing other rooms' question IDs.
        // Returns 403 (not 404) so the ID existence is not leaked.
        $questionRecord = SiteSurveyRoomQuestion::where('id', $question)
            ->where('site_survey_room_id', $room->id)
            ->first();

        abort_if($questionRecord === null, 403);

        $validated = $request->validate([
            'answer'     => ['nullable', 'in:yes,no,other'],
            'other_text' => ['nullable', 'string', 'max:2000'],
        ]);

        // Build update payload: only update fields that are present in the request.
        $update = [];
        if (array_key_exists('answer', $validated) && $validated['answer'] !== null) {
            $update['answer'] = $validated['answer'];
            // Clear other_text if switching away from 'other'.
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

    // ─── Photo upload ────────────────────────────────────────────────────────

    /**
     * POST /survey/{token}/rooms/{room}/photos
     *
     * Upload a photo for a specific room. The room must belong to the survey
     * identified by the token. Returns JSON for AJAX consumption.
     */
    public function uploadPhoto(Request $request, string $token, SiteSurveyRoom $room): JsonResponse
    {
        $survey = $this->resolveSurvey($token);

        abort_unless($room->site_survey_id === $survey->id, 403);
        abort_if($survey->isSubmitted(), 403, 'This survey has already been submitted.');

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
            'url'           => route('survey.photos.serve', [
                'token' => $token,
                'photo' => $photo->id,
            ]),
        ]);
    }

    // ─── Photo serve ─────────────────────────────────────────────────────────

    /**
     * GET /survey/{token}/photos/{photo}
     *
     * Serve a survey photo. Verifies the photo belongs to the survey
     * identified by the token before streaming the file.
     */
    public function servePhoto(string $token, SiteSurveyPhoto $photo): \Symfony\Component\HttpFoundation\Response
    {
        $survey = $this->resolveSurvey($token);

        // Ensure this photo belongs to a room in this survey.
        abort_unless(
            $photo->room->site_survey_id === $survey->id,
            403,
        );

        $path = Storage::disk('local')->path($photo->storagePath());
        abort_unless(file_exists($path), 404);

        return response()->file($path, [
            'Content-Type'        => $photo->mime_type ?? 'image/jpeg',
            'Content-Disposition' => 'inline; filename="' . $photo->original_name . '"',
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /**
     * Resolve and gate a SiteSurvey by its access token.
     *
     * - Unknown token  → 404
     * - Expired token  → 410 Gone
     */
    private function resolveSurvey(string $token): SiteSurvey
    {
        $survey = SiteSurvey::where('access_token', $token)->first();

        abort_if($survey === null, 404, 'Survey not found. Please check your link.');

        abort_if(
            $survey->isTokenExpired(),
            410,
            'This survey link has expired. Please contact the project manager for a new link.'
        );

        return $survey;
    }

    /**
     * Map a validated room data array to the columns accepted by SiteSurveyRoom.
     * Mirrors SurveyService::roomAttributes() but without creating a dependency.
     */
    private function roomAttributesFromData(array $data, int $sortOrder): array
    {
        return [
            'room_name'                 => $data['room_name'],
            'room_ref'                  => $data['room_ref']                ?? null,
            'floor'                     => $data['floor']                    ?? null,
            'area_type'                 => $data['area_type']                ?? null,
            'space_type'                => $data['space_type']               ?? 'general',
            'room_width_m'              => $data['room_width_m']             ?? null,
            'room_depth_m'              => $data['room_depth_m']             ?? null,
            'room_height_m'             => $data['room_height_m']            ?? null,
            'ceiling_type'              => $data['ceiling_type']             ?? null,
            'ceiling_height_m'          => $data['ceiling_height_m']         ?? null,
            'wall_material'             => $data['wall_material']            ?? null,
            'floor_type'                => $data['floor_type']               ?? null,
            'av_requirements'           => $data['av_requirements']          ?? null,
            'av_equipment_list'         => $data['av_equipment_list']        ?? null,
            'has_power'                 => ! empty($data['has_power']),
            'has_network'               => ! empty($data['has_network']),
            'power_outlet_count'        => (int) ($data['power_outlet_count']  ?? 0),
            'network_port_count'        => (int) ($data['network_port_count']  ?? 0),
            'existing_cabling'          => $data['existing_cabling']         ?? null,
            'requires_additional_power' => ! empty($data['requires_additional_power']),
            'access_notes'              => $data['access_notes']             ?? null,
            'notes'                     => $data['notes']                    ?? null,
            'sort_order'                => $sortOrder,
            'speaker_count'             => isset($data['speaker_count'])   ? (int) $data['speaker_count']   : null,
            'speaker_type'              => $data['speaker_type']             ?? null,
            'speaker_mounting'          => $data['speaker_mounting']         ?? null,
            'bg_noise_db'               => isset($data['bg_noise_db'])     ? (int) $data['bg_noise_db']     : null,
            'display_size_in'           => $data['display_size_in']          ?? null,
            'display_orient'            => $data['display_orient']           ?? null,
            'display_mounting'          => $data['display_mounting']         ?? null,
            'rack_unit_space'           => isset($data['rack_unit_space'])  ? (int) $data['rack_unit_space'] : null,
            'cable_route_desc'          => $data['cable_route_desc']         ?? null,
            'existing_condition'        => $data['existing_condition']       ?? null,
            'items_to_remove'           => $data['items_to_remove']          ?? null,
            'items_to_retain'           => $data['items_to_retain']          ?? null,
        ];
    }

    /**
     * Validate the public survey submission payload.
     *
     * On final submit ($requireSurveyor = true) the surveyor_name is required
     * so the submitted record is always attributable.
     */
    private function validatePublicSurvey(Request $request, bool $requireSurveyor = false): array
    {
        return $request->validate([
            'survey_date'                           => ['nullable', 'date'],
            'surveyor_name'                         => $requireSurveyor
                                                        ? ['required', 'string', 'max:100']
                                                        : ['nullable', 'string', 'max:100'],
            'general_notes'                         => ['nullable', 'string', 'max:3000'],
            'site_risks'                            => ['nullable', 'string', 'max:3000'],
            'access_constraints'                    => ['nullable', 'string', 'max:3000'],
            'h_and_s_notes'                         => ['nullable', 'string', 'max:3000'],
            // Rooms
            'rooms'                                 => ['nullable', 'array'],
            'rooms.*.id'                            => ['required', 'integer', 'exists:site_survey_rooms,id'],
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
