<?php

namespace App\Http\Controllers;

use App\Core\Modules\Survey\SurveyService;
use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyPhoto;
use App\Models\SiteSurveyRoom;
use App\Models\SiteSurveyRoomQuestion;
use App\Services\Survey\SiteSurveyTierOneReadinessService;
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
        private readonly SurveyService                     $service,
        private readonly SiteSurveyTierOneReadinessService $tierOne,
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
        $survey->load('rooms.photos', 'rooms.questions');

        // Build kit-by-area lookup from the linked project's latest package so
        // each room card on the public form can show the quote kit list.
        $kitByArea            = [];
        $solutionTypesByRoom  = []; // room_name → SolutionType
        $plannedWorksByRoom   = []; // room_name → array<int,string> of bullet lines
        if ($survey->project_id) {
            $project = Project::with('latestPackage')->find($survey->project_id);
            $extractedData = $project?->latestPackage?->extracted_data ?? [];
            $reviewedData  = $project?->latestPackage?->reviewed_data  ?? [];

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

            // Build planned-works-as-bullets lookup. We pick the cleanest
            // available source per room, with fallback to project-level scope:
            //   1. room_overviews[N]['description']    — AI-cleaned per-room prose
            //   2. room_overviews[N]['works_summary']  — PM-curated short summary
            //   3. room_overviews[N]['overview']       — raw extracted per-room
            //   4. project-level works_overview        — formal project-wide scope
            //   5. project-level scope_of_works        — fallback formal scope
            // The room-level fallthrough handles projects where the per-room
            // overview is fragmentary (e.g. tight-wrap PDF extraction artifacts);
            // the project-level scope is always cleaner AI-generated text.
            $projectScope = trim((string) (
                $reviewedData['works_overview']  ??
                $extractedData['works_overview'] ??
                $reviewedData['scope_of_works']  ??
                $extractedData['scope_of_works'] ??
                ''
            ));

            $roSource = ! empty($reviewedData['room_overviews'])
                ? (array) $reviewedData['room_overviews']
                : (array) ($extractedData['room_overviews'] ?? []);

            foreach ($roSource as $ro) {
                if (! is_array($ro)) continue;
                $name = trim((string) ($ro['room'] ?? $ro['room_name'] ?? $ro['name'] ?? ''));
                if ($name === '') continue;

                // Try room-level sources, in cleanest-first order
                $text = '';
                foreach (['description', 'works_summary', 'overview', 'scope'] as $field) {
                    $candidate = trim((string) ($ro[$field] ?? ''));
                    if ($candidate !== '') {
                        $text = $candidate;
                        break;
                    }
                }

                // If nothing room-level — or what we have is suspiciously short
                // / fragmentary (single sentence-fragment under 60 chars) — fall
                // back to the project-wide scope statement.
                if (strlen($text) < 60 && $projectScope !== '') {
                    $text = $projectScope;
                }

                if (trim($text) === '') continue;
                $bullets = $this->textToBullets($name, $text);
                if (! empty($bullets)) {
                    $plannedWorksByRoom[$name] = $bullets;
                }
            }

            // If the loop produced nothing (no room_overviews entries) but a
            // project-level scope exists, seed each survey room with it so the
            // engineer sees something useful in the WORKS panel.
            if (empty($plannedWorksByRoom) && $projectScope !== '') {
                foreach ($survey->rooms as $room) {
                    $bullets = $this->textToBullets($room->room_name, $projectScope);
                    if (! empty($bullets)) {
                        $plannedWorksByRoom[$room->room_name] = $bullets;
                    }
                }
            }
        }

        $tierOne = $this->tierOne->assessSurvey($survey);

        return view('public-survey.show', [
            'survey'               => $survey,
            'token'                => $token,
            'readonly'             => $survey->isSubmitted(),
            'kitByArea'            => $kitByArea,
            'solutionTypesByRoom'  => $solutionTypesByRoom,
            'plannedWorksByRoom'   => $plannedWorksByRoom,
            'tierOne'              => $tierOne,
        ]);
    }

    /**
     * Convert a PM-authored prose overview into a clean bullet list for the
     * public survey page. Applies the same first-line-dedup logic the RAMS
     * PDF uses so the room name isn't echoed back as the first bullet, then
     * splits on line breaks first (PMs usually write line-per-point) and
     * falls back to sentence splitting for unstructured paragraphs.
     *
     * @return array<int, string>  trimmed, non-empty bullet lines (max 12)
     */
    private function textToBullets(string $roomName, string $text): array
    {
        // Strip markdown bold markers — source often has **headers**
        $text = (string) preg_replace('/\*\*([^*]+)\*\*/', '$1', $text);
        $text = ltrim(trim($text), ': ');

        // Drop a leading line that just echoes the room name (same logic as
        // the RAMS PDF renderer uses to avoid duplicate headings).
        $lines = preg_split('/\r?\n/', trim($text)) ?: [];
        if (count($lines) >= 2) {
            $canonFirst = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', (string) $lines[0]));
            $canonName  = strtolower((string) preg_replace('/[^a-z0-9]+/i', '', $roomName));
            if ($canonFirst !== '' && $canonFirst === $canonName) {
                array_shift($lines);
            }
        }

        // If the text is only one line, treat each sentence as a bullet.
        if (count($lines) < 2) {
            $lines = preg_split('/(?<=[.!?])\s+(?=[A-Z])/', (string) ($lines[0] ?? '')) ?: [];
        }

        $bullets = [];
        foreach ($lines as $line) {
            $line = trim((string) $line);
            // Strip any leading bullet marker the PM may have typed in
            $line = (string) preg_replace('/^[\-\*•·\d]+[\.\)]?\s*/u', '', $line);
            if ($line === '' || strlen($line) < 3) continue;
            $bullets[] = $line;
            if (count($bullets) >= 12) break;
        }
        return $bullets;
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

        // Engineer-feedback site-level fields (quick task 260503-u2x — defensive
        // double-write; primary write is via SurveyController::stepSave step=0
        // mid-session, but SurveyService::saveDraftPublic does NOT touch these
        // site-level columns so we explicitly mirror them here).
        $siteUpdate = $this->extractSiteEngineerFeedback($data);
        if (! empty($siteUpdate)) {
            $survey->update($siteUpdate);
        }

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

        // Engineer-feedback site-level fields (quick task 260503-u2x — defensive
        // double-write at final-submit time so any field that didn't make it
        // through stepSave step=0 still lands in the SiteSurvey columns).
        $siteUpdate = $this->extractSiteEngineerFeedback($data);
        if (! empty($siteUpdate)) {
            $survey->update($siteUpdate);
        }

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

        // ── Pre-install check gate (D-05) ─────────────────────────────────────
        // Block completion if any generated questions are unanswered.
        // Rooms with no questions are unaffected (D-06: count = 0, guard skipped).
        $unanswered = $room->questions()->whereNull('answer')->count();
        if ($unanswered > 0) {
            $noun = $unanswered === 1
                ? 'pre-install check question'
                : 'pre-install check questions';
            return response()->json([
                'completed' => false,
                'blocked'   => true,
                'message'   => "Please answer all {$unanswered} {$noun} before marking this room complete.",
            ], 422);
        }

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

        // If no rooms array was sent (the typical wizard mark-complete path),
        // pull the engineer-feedback canonical block from survey_data and
        // mirror it onto the SiteSurveyRoom DB columns BEFORE flipping the
        // completion flags. This is the second mirror (the first runs at
        // every stepSave step=4 in SurveyController) — both are idempotent
        // and safe; ensures downstream RamsBuilderService (260503-tfb) sees
        // the engineer's data even if a stepSave race lost a row. (quick task
        // 260503-u2x)
        if (empty($data['rooms'])) {
            $payload    = $survey->survey_data ?? [];
            $roomsArray = is_array($payload['rooms'] ?? null) ? $payload['rooms'] : [];

            // Match the canonical entry to this DB room by sort_order — the
            // canonical 'rooms' array is built in sort_order in
            // SurveyController::initialPayload + buildAlpineRooms.
            $dbRoomIds    = $survey->rooms()->orderBy('sort_order')->pluck('id')->toArray();
            $canonicalIdx = array_search($room->id, $dbRoomIds, true);
            $ef           = ($canonicalIdx !== false && isset($roomsArray[$canonicalIdx]['engineer_feedback']))
                ? (array) $roomsArray[$canonicalIdx]['engineer_feedback']
                : [];

            if (! empty($ef)) {
                // Resolve via app() so PublicSurveyController doesn't need a
                // constructor-injected SurveyController dependency. The writer
                // calls $room->update() internally — two writes (this one + the
                // completion flag below) is fine because they touch disjoint columns.
                app(\App\Http\Controllers\SurveyController::class)
                    ->writeEngineerFeedbackToColumns($room, $ef);
            }

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
            'photo'    => ['required', 'file', 'image', 'max:10240'],  // 10 MB
            'category' => ['nullable', 'string', 'max:50'],
            'caption'  => ['nullable', 'string', 'max:200'],
        ]);

        $photo = $this->service->addPhoto(
            $room,
            $request->file('photo'),
            $request->input('category'),
            $request->input('caption'),
        );

        return response()->json([
            'id'            => $photo->id,
            'filename'      => $photo->filename,
            'original_name' => $photo->original_name,
            'category'      => $photo->category,
            'caption'       => $photo->caption,
            'url'           => route('survey.photos.serve', [
                'token' => $token,
                'photo' => $photo->id,
            ]),
        ]);
    }

    /**
     * PATCH /survey/{token}/photos/{photo}
     *
     * Update the engineer-supplied caption on an existing photo. Used by the
     * survey wizard so engineers can annotate individual photos after upload.
     * Only the caption is mutable — category, filename, and storage path are
     * fixed at upload time.
     */
    public function updatePhoto(Request $request, string $token, SiteSurveyPhoto $photo): JsonResponse
    {
        $survey = $this->resolveSurvey($token);

        abort_unless($photo->room->site_survey_id === $survey->id, 403);
        abort_if($survey->isSubmitted(), 403, 'This survey has already been submitted.');

        $data = $request->validate([
            'caption' => ['nullable', 'string', 'max:200'],
        ]);

        $photo->update(['caption' => $data['caption'] ?? null]);

        return response()->json([
            'id'      => $photo->id,
            'caption' => $photo->caption,
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
            'network_ssid'              => $data['network_ssid']              ?? null,
            'network_vlan'              => $data['network_vlan']              ?? null,
            'network_switch_port'       => $data['network_switch_port']       ?? null,
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
            'cable_route_from'          => $data['cable_route_from']         ?? null,
            'cable_route_to'            => $data['cable_route_to']           ?? null,
            'is_rack_room'              => isset($data['is_rack_room']) ? (bool) $data['is_rack_room'] : null,
            'projection_throw_m'        => $data['projection_throw_m']       ?? null,
            'viewing_distance_m'        => $data['viewing_distance_m']       ?? null,
            'existing_condition'        => $data['existing_condition']       ?? null,
            'items_to_remove'           => $data['items_to_remove']          ?? null,
            'items_to_retain'           => $data['items_to_retain']          ?? null,
            'engineer_confirmed'        => isset($data['engineer_confirmed']) ? (bool) $data['engineer_confirmed'] : null,
            'engineer_signature_name'   => $data['engineer_signature_name']  ?? null,
            // Engineer-feedback additions (quick task 260503-u2x — mirrors
            // SurveyService::roomAttributes from 260503-rgg). Eloquent's array
            // cast on the JSON columns handles encode/decode automatically;
            // boolean wall_needs_* coerce explicitly so the column stores a
            // canonical boolean rather than truthy strings from a flat-form post.
            'mounting_heights'          => $data['mounting_heights']         ?? null,
            'work_at_height_methods'    => $data['work_at_height_methods']   ?? null,
            'cable_routes'              => $this->stripEmptyCableRoutes($data['cable_routes'] ?? null),
            'wall_construction'         => $data['wall_construction']        ?? null,
            'wall_needs_reinforcement'  => isset($data['wall_needs_reinforcement']) ? (bool) $data['wall_needs_reinforcement'] : null,
            'wall_needs_chase_out'      => isset($data['wall_needs_chase_out'])     ? (bool) $data['wall_needs_chase_out']     : null,
            'wall_needs_conduit'        => isset($data['wall_needs_conduit'])       ? (bool) $data['wall_needs_conduit']       : null,
            'table_info'                => $data['table_info']               ?? null,
            'floor_box_info'            => $data['floor_box_info']           ?? null,
            'brackets_required'         => $this->stripEmptyBracketRows($data['brackets_required'] ?? null),
        ];
    }

    /**
     * Drop fully-empty rows from cable_routes submitted via the legacy flat-form
     * save endpoint. Matches SurveyService::normalizeCableRoutes from 260503-rgg.
     * (quick task 260503-u2x)
     */
    private function stripEmptyCableRoutes(?array $rows): ?array
    {
        if ($rows === null) return null;
        $clean = array_values(array_filter($rows, function ($r) {
            if (! is_array($r)) return false;
            return trim((string) ($r['category'] ?? '')) !== ''
                || trim((string) ($r['from']     ?? '')) !== ''
                || trim((string) ($r['to']       ?? '')) !== ''
                || trim((string) ($r['notes']    ?? '')) !== ''
                || (($r['length_m'] ?? null) !== null && $r['length_m'] !== '');
        }));
        return $clean === [] ? null : $clean;
    }

    /**
     * Drop fully-empty rows from brackets_required submitted via the legacy
     * flat-form save endpoint. Matches SurveyService::normalizeBracketRows.
     * (quick task 260503-u2x)
     */
    private function stripEmptyBracketRows(?array $rows): ?array
    {
        if ($rows === null) return null;
        $clean = array_values(array_filter($rows, function ($r) {
            if (! is_array($r)) return false;
            return trim((string) ($r['equipment'] ?? '')) !== ''
                || trim((string) ($r['model']     ?? '')) !== ''
                || trim((string) ($r['notes']     ?? '')) !== '';
        }));
        return $clean === [] ? null : $clean;
    }

    /**
     * Extract the 7 site-level engineer-feedback fields from a validated payload
     * into a $survey->update()-shaped array. Returns only the keys present in
     * $data so an empty payload doesn't null out previously-saved values.
     * (quick task 260503-u2x — defensive double-write because
     * SurveyService::saveDraftPublic + submitPublic do NOT touch site-level
     * engineer-feedback columns; they handle the legacy header fields only.)
     */
    private function extractSiteEngineerFeedback(array $data): array
    {
        $update = [];
        foreach (['comms_room_access_status', 'comms_room_access_notes', 'parking_restraints',
                  'distance_from_base_miles', 'distance_from_base_notes', 'site_access_notes',
                  'delivery_routes'] as $field) {
            if (array_key_exists($field, $data)) {
                $update[$field] = $data[$field] ?? null;
            }
        }
        return $update;
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
            // Engineer-feedback site-level rules (quick task 260503-u2x —
            // mirrors SiteSurveyController::validateSurvey additions from
            // 260503-rgg). Defensive: the wizard normally writes these via
            // stepSave step=0 → DB columns, but a flat-form save endpoint
            // POST should also accept them so external tools can hit /save.
            'comms_room_access_status'              => ['nullable', 'string', 'in:yes,no,outsourced,unknown'],
            'comms_room_access_notes'               => ['nullable', 'string', 'max:2000'],
            'parking_restraints'                    => ['nullable', 'string', 'max:2000'],
            'distance_from_base_miles'              => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'distance_from_base_notes'              => ['nullable', 'string', 'max:2000'],
            'site_access_notes'                     => ['nullable', 'string', 'max:3000'],
            'delivery_routes'                       => ['nullable', 'string', 'max:3000'],
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
            'rooms.*.cable_route_from'              => ['nullable', 'string', 'max:500'],
            'rooms.*.cable_route_to'                => ['nullable', 'string', 'max:500'],
            'rooms.*.is_rack_room'                  => ['nullable', 'boolean'],
            'rooms.*.projection_throw_m'            => ['nullable', 'numeric', 'min:0', 'max:999'],
            'rooms.*.viewing_distance_m'            => ['nullable', 'numeric', 'min:0', 'max:999'],
            'rooms.*.network_ssid'                  => ['nullable', 'string', 'max:255'],
            'rooms.*.network_vlan'                  => ['nullable', 'string', 'max:100'],
            'rooms.*.network_switch_port'           => ['nullable', 'string', 'max:100'],
            'rooms.*.engineer_confirmed'            => ['nullable', 'boolean'],
            'rooms.*.engineer_signature_name'       => ['nullable', 'string', 'max:255'],
            // Upgrade / strip-out
            'rooms.*.existing_condition'            => ['nullable', 'string', 'max:3000'],
            'rooms.*.items_to_remove'               => ['nullable', 'string', 'max:3000'],
            'rooms.*.items_to_retain'               => ['nullable', 'string', 'max:3000'],
            // Engineer-feedback room-level rules (quick task 260503-u2x —
            // mirrors SiteSurveyController::validateSurvey additions from
            // 260503-rgg). Defensive: the wizard normally writes these via
            // stepSave step=4 → SiteSurveyRoom DB columns, but flat-form
            // save / completeRoom should also accept them.
            'rooms.*.mounting_heights'                    => ['nullable', 'array'],
            'rooms.*.mounting_heights.screen_h_m'         => ['nullable', 'numeric', 'min:0', 'max:99'],
            'rooms.*.mounting_heights.camera_h_m'         => ['nullable', 'numeric', 'min:0', 'max:99'],
            'rooms.*.mounting_heights.booking_panel_h_m'  => ['nullable', 'numeric', 'min:0', 'max:99'],
            'rooms.*.mounting_heights.speaker_h_m'        => ['nullable', 'numeric', 'min:0', 'max:99'],
            'rooms.*.mounting_heights.other'              => ['nullable', 'array'],
            'rooms.*.mounting_heights.other.*.label'      => ['nullable', 'string', 'max:150'],
            'rooms.*.mounting_heights.other.*.height_m'   => ['nullable', 'numeric', 'min:0', 'max:99'],

            'rooms.*.work_at_height_methods'              => ['nullable', 'array'],
            'rooms.*.work_at_height_methods.*'            => ['string', 'in:ladder,podium,tower,mewp,scaffold,na'],

            'rooms.*.cable_routes'                        => ['nullable', 'array'],
            'rooms.*.cable_routes.*.category'             => ['nullable', 'string', 'in:ceiling_speakers,desk_cables,mic_cables,booking_panel_cables,screen_cables,rack_to_room,other'],
            'rooms.*.cable_routes.*.from'                 => ['nullable', 'string', 'max:255'],
            'rooms.*.cable_routes.*.to'                   => ['nullable', 'string', 'max:255'],
            'rooms.*.cable_routes.*.length_m'             => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'rooms.*.cable_routes.*.notes'                => ['nullable', 'string', 'max:500'],

            'rooms.*.wall_construction'                   => ['nullable', 'array'],
            'rooms.*.wall_construction.*'                 => ['string', 'in:ply_lined,solid,plasterboard,masonry,metal_stud,concrete'],
            'rooms.*.wall_needs_reinforcement'            => ['nullable', 'boolean'],
            'rooms.*.wall_needs_chase_out'                => ['nullable', 'boolean'],
            'rooms.*.wall_needs_conduit'                  => ['nullable', 'boolean'],

            'rooms.*.table_info'                          => ['nullable', 'array'],
            'rooms.*.table_info.has_grommets'             => ['nullable', 'boolean'],
            'rooms.*.table_info.grommet_count'            => ['nullable', 'integer', 'min:0', 'max:99'],
            'rooms.*.table_info.grommet_size'             => ['nullable', 'string', 'in:small,standard,large'],
            'rooms.*.table_info.notes'                    => ['nullable', 'string', 'max:500'],

            'rooms.*.floor_box_info'                      => ['nullable', 'array'],
            'rooms.*.floor_box_info.has_floor_box'        => ['nullable', 'boolean'],
            'rooms.*.floor_box_info.power_outlets'        => ['nullable', 'integer', 'min:0', 'max:99'],
            'rooms.*.floor_box_info.data_outlets'         => ['nullable', 'integer', 'min:0', 'max:99'],
            'rooms.*.floor_box_info.cable_space'          => ['nullable', 'string', 'in:tight,adequate,spacious'],
            'rooms.*.floor_box_info.notes'                => ['nullable', 'string', 'max:500'],

            'rooms.*.brackets_required'                   => ['nullable', 'array'],
            'rooms.*.brackets_required.*.equipment'       => ['nullable', 'string', 'max:255'],
            'rooms.*.brackets_required.*.model'           => ['nullable', 'string', 'max:255'],
            'rooms.*.brackets_required.*.pull_out'        => ['nullable', 'boolean'],
            'rooms.*.brackets_required.*.notes'           => ['nullable', 'string', 'max:500'],
        ]);
    }
}
