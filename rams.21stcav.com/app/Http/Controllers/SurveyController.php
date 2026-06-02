<?php

namespace App\Http\Controllers;

use App\Models\SiteSurvey;
use App\Services\SurveyPdfService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * SurveyController — step-based mobile survey wizard (public, token-gated).
 *
 * Routes:
 *   GET  /survey/{token}           → show()     : renders step wizard
 *   POST /survey/{token}/step-save → stepSave() : AJAX per-step canonical save
 *
 * Architecture
 * ────────────
 * The persisted payload lives in SiteSurvey::survey_data (JSON column).
 * Only the canonical shape is ever written to that column:
 *
 *   { "project_id": int, "rooms": [
 *       { "name", "type", "photos", "infrastructure": { "power", "network", "cable_routes" },
 *         "equipment", "risks", "notes" }
 *   ]}
 *
 * UI-only fields (work_type, voice_note, quick_notes, access_issues, working_at_height,
 * client_present, constraints, signoff, is_completed, room_id) live exclusively in the
 * Alpine.js component under a nested `_ui` key on each room object. They are never
 * written into the canonical survey_data payload.
 *
 * Normalization
 * ─────────────
 * Each stepSave() call maps only the canonical contribution of that step into
 * the room, then enforces the canonical shape before persisting. No merge
 * strategy that leaks non-canonical keys is used.
 *
 * Conditional logic is enforced server-side:
 *   - If working_at_height != true  → access_equipment cleared from risks.
 *   - Infrastructure is written only when the client sends it (work_type=new_install
 *     enforced client-side; server normalizes whatever is received).
 */
class SurveyController extends Controller
{
    public function __construct(private readonly SurveyPdfService $pdfService) {}

    // ── Show ──────────────────────────────────────────────────────────────────

    /**
     * GET /survey/{token}
     *
     * Reads or initialises the canonical payload, then builds the extended
     * Alpine room objects (canonical + UI-only fields) for the view.
     */
    public function show(string $token): View
    {
        $survey = $this->resolveSurvey($token);
        // rooms.questions exposes the checklist guidance context per room
        // in buildAlpineRooms(); rooms.photos is authoritative for uploaded photos.
        // 260602-mlt — project.latestPackage powers the header "Site contact"
        // line (sourced from extracted_data['ship_contact'/'ship_phone']) so
        // the @php block in show.blade.php doesn't trigger an N+1.
        $survey->load(['rooms.photos', 'rooms.questions', 'project.referenceFiles', 'project.latestPackage']);

        $payload = $survey->survey_data;

        if (empty($payload['rooms'])) {
            $payload = $this->initialPayload($survey);
            $survey->update(['survey_data' => $payload]);
        }

        $rooms = $this->buildAlpineRooms($survey, $payload, $token);

        // Seed Alpine state for the site-level engineer-feedback block (quick task
        // 260503-u2x). DB column wins over canonical JSON when both are populated —
        // the column is the post-completion source of truth (set on submit + on
        // each stepSave step=0 mid-session). Coalesce to canonical JSON for
        // in-flight wizard sessions before submit, then to defaults.
        $engineerFeedbackSite = [
            'comms_room_access_status' => $survey->comms_room_access_status ?? ($payload['engineer_feedback_site']['comms_room_access_status'] ?? ''),
            'comms_room_access_notes'  => $survey->comms_room_access_notes  ?? ($payload['engineer_feedback_site']['comms_room_access_notes']  ?? ''),
            'parking_restraints'       => $survey->parking_restraints       ?? ($payload['engineer_feedback_site']['parking_restraints']       ?? ''),
            'distance_from_base_miles' => $survey->distance_from_base_miles ?? ($payload['engineer_feedback_site']['distance_from_base_miles'] ?? null),
            'distance_from_base_notes' => $survey->distance_from_base_notes ?? ($payload['engineer_feedback_site']['distance_from_base_notes'] ?? ''),
            'site_access_notes'        => $survey->site_access_notes        ?? ($payload['engineer_feedback_site']['site_access_notes']        ?? ''),
            'delivery_routes'          => $survey->delivery_routes          ?? ($payload['engineer_feedback_site']['delivery_routes']          ?? ''),
        ];

        return view('surveys.show', [
            'survey'               => $survey,
            'token'                => $token,
            'rooms'                => $rooms,
            'readonly'             => $survey->isSubmitted(),
            'engineerFeedbackSite' => $engineerFeedbackSite,
        ]);
    }

    // ── Step save ─────────────────────────────────────────────────────────────

    /**
     * POST /survey/{token}/step-save
     *
     * Normalises the step contribution into canonical shape and persists.
     * Non-canonical fields are silently discarded; they must not pollute
     * the stored payload.
     */
    public function stepSave(Request $request, string $token): JsonResponse
    {
        $survey = $this->resolveSurvey($token);

        if ($survey->isSubmitted()) {
            return response()->json(['error' => 'Survey already submitted.'], 403);
        }

        $validated = $request->validate([
            'room_index' => ['required', 'integer', 'min:0'],
            // Step 0 = site-level engineer-feedback save (quick task 260503-u2x).
            // Steps 1–8 = the existing per-room wizard step contributions.
            'step'       => ['required', 'integer', 'between:0,8'],
            'data'       => ['required', 'array'],
        ]);

        $payload = $survey->survey_data ?? $this->initialPayload($survey);
        $idx     = $validated['room_index'];

        $stepError = $this->validateStep($validated['step'], $validated['data']);
        if ($stepError) {
            return response()->json(['error' => $stepError], 422);
        }

        // Step 0 — site-level engineer-feedback save. Does not target a room.
        if ($validated['step'] === 0) {
            $payload['engineer_feedback_site'] = $this->normalizeEngineerFeedbackSite(
                $validated['data']['engineer_feedback_site'] ?? []
            );
            $survey->update(['survey_data' => $payload]);
            // Mirror to DB columns so downstream services (RAMS pipeline 260503-tfb)
            // see the data without re-parsing JSON.
            $this->writeEngineerFeedbackSiteToColumns($survey, $payload['engineer_feedback_site']);
            return response()->json(['saved' => true, 'at' => now()->toISOString()]);
        }

        if (! isset($payload['rooms'][$idx])) {
            return response()->json(['error' => 'Invalid room index.'], 422);
        }

        $payload['rooms'][$idx] = $this->normalizeStepContribution(
            $payload['rooms'][$idx],
            $validated['step'],
            $validated['data']
        );

        $survey->update(['survey_data' => $payload]);

        // Mirror per-room engineer_feedback to DB columns at every Step-4 save so
        // downstream RamsBuilderService (260503-tfb) reads the live data without
        // waiting for the engineer to mark the room complete. (quick task 260503-u2x)
        if ($validated['step'] === 4) {
            $dbRooms  = $survey->rooms()->orderBy('sort_order')->get();
            $dbRoom   = $dbRooms->get($idx);
            if ($dbRoom !== null) {
                $this->writeEngineerFeedbackToColumns(
                    $dbRoom,
                    $payload['rooms'][$idx]['engineer_feedback'] ?? []
                );
            }
        }

        return response()->json(['saved' => true, 'at' => now()->toISOString()]);
    }

    // ── Private — initial canonical payload ───────────────────────────────────

    /**
     * Build an empty canonical payload seeded from the survey's DB room rows.
     * Only the canonical shape is produced — no UI-only keys.
     */
    private function initialPayload(SiteSurvey $survey): array
    {
        $rooms = $survey->rooms
            ->map(fn ($dbRoom) => $this->emptyCanonicalRoom((string) ($dbRoom->room_name ?? '')))
            ->values()
            ->toArray();

        return [
            'project_id' => $survey->project_id,
            'rooms'      => $rooms,
            // Site-level engineer-feedback canonical block (quick task 260503-u2x).
            // Captured once per visit on the rooms-list screen via stepSave step=0,
            // mirrored to SiteSurvey DB columns by writeEngineerFeedbackSiteToColumns.
            'engineer_feedback_site' => [
                'comms_room_access_status' => '',
                'comms_room_access_notes'  => '',
                'parking_restraints'       => '',
                'distance_from_base_miles' => null,
                'distance_from_base_notes' => '',
                'site_access_notes'        => '',
                'delivery_routes'          => '',
            ],
        ];
    }

    /**
     * Returns a canonical room array with safe defaults.
     * Risks is pre-populated with one default entry so the UI can bind to risks[0]
     * without additional null guards.
     */
    private function emptyCanonicalRoom(string $name = ''): array
    {
        return [
            'name'           => $name,
            'type'           => '',
            'photos'         => [],
            'infrastructure' => [
                'power' => [
                    'socket_locations'   => '',
                    'spare_capacity'     => false,
                    'distance_to_screen' => null,
                ],
                'network' => [
                    'ports_available' => null,
                    'vlan_required'   => false,
                    'switch_location' => '',
                ],
                'cable_routes' => [
                    'route_type'         => '',
                    'estimated_distance' => null,
                ],
            ],
            'equipment' => [],
            'risks'     => [[
                'working_height'       => '',
                'access_equipment'     => '',
                'out_of_hours'         => false,
                'permits_required'     => false,
                'manual_handling_risk' => false,
            ]],
            'notes'       => '',
            // Step 7 — site constraints captured by the wizard. Stored in a
            // strict 4-field shape so the shape is predictable for downstream
            // consumers and survives the canonical enforcer.
            'constraints' => [
                'obstructions'          => '',
                'noise_restrictions'    => '',
                'client_constraints'    => '',
                'programme_constraints' => '',
            ],
            // Step 8 — completion-critical engineer sign-off. ISO timestamp
            // captured server-side on the POST so clock-skew in the browser
            // doesn't fabricate a bogus signed_at.
            'signoff'     => [
                'engineer_name'       => '',
                'engineer_confirmed'  => false,
                'signed_at'           => null,
            ],
            // UI selections that need to survive a page reload but aren't
            // captured by other canonical fields. Initially these were
            // marked "UI-only / never persisted" — that meant work_type,
            // power_available, network_available and client_present always
            // came back blank when an engineer reopened the survey.
            // Stored here so reload restores what the engineer chose.
            'ui_state' => [
                'work_type'         => '',     // new_install|upgrade|retrofit|fault_repair
                'power_available'   => false,
                'network_available' => false,
                'access_issues'     => false,
                'working_at_height' => false,
                'client_present'    => false,
                'voice_note'        => null,
            ],
            // Engineer-feedback canonical block (quick task 260503-u2x — mirrors
            // SiteSurveyRoom DB columns added in 260503-rgg). Captured in Step 4
            // of the wizard. writeEngineerFeedbackToColumns() mirrors this onto
            // the SiteSurveyRoom columns at every stepSave + completeRoom so the
            // downstream RamsBuilderService extensions (260503-tfb) see the data.
            'engineer_feedback' => [
                'mounting_heights' => [
                    'screen_h_m'        => null,
                    'camera_h_m'        => null,
                    'booking_panel_h_m' => null,
                    'speaker_h_m'       => null,
                    'other'             => [],   // [{label, height_m}, ...]
                ],
                'work_at_height_methods'   => [],   // ['ladder', 'podium', ...]
                'cable_routes'             => [],   // [{category, from, to, length_m, notes}, ...]
                'wall_construction'        => [],   // ['plasterboard', ...]
                'wall_needs_reinforcement' => false,
                'wall_needs_chase_out'     => false,
                'wall_needs_conduit'       => false,
                'table_info' => [
                    'has_grommets'  => false,
                    'grommet_count' => null,
                    'grommet_size'  => '',          // '' | 'small' | 'standard' | 'large'
                    'notes'         => '',
                ],
                'floor_box_info' => [
                    'has_floor_box' => false,
                    'power_outlets' => null,
                    'data_outlets'  => null,
                    'cable_space'   => '',          // '' | 'tight' | 'adequate' | 'spacious'
                    'notes'         => '',
                ],
                'brackets_required' => [],          // [{equipment, model, pull_out, notes}, ...]
            ],
        ];
    }

    // ── Private — Alpine room builder ─────────────────────────────────────────

    /**
     * Build the extended room objects passed to Alpine.js.
     *
     * Each object contains:
     *   - Canonical fields (name, type, photos, infrastructure, equipment, risks, notes)
     *   - A `_ui` sub-object with UI-only state (room_id, is_completed, work_type,
     *     voice_note, quick_notes, toggles, constraints, signoff).
     *
     * The `_ui` block is never read by the server on step save. It exists only
     * to drive the wizard UI and enforce conditional visibility client-side.
     */
    private function buildAlpineRooms(SiteSurvey $survey, array $payload, string $token): array
    {
        $alpineRooms = [];

        // Pull the project's latest-package scope data once. We use it to
        // replace stale av_requirements column values that were seeded at
        // room-creation time but never refreshed when a quote is re-extracted.
        // Priority for "Planned AV Works" text:
        //   1. Per-room AI-cleaned description / works_summary / overview
        //   2. Project-level works_overview / scope_of_works
        //   3. The room's stored av_requirements column (legacy, often stale)
        $packageRoomScopes  = []; // canonical-lower-name → cleanest scope text (prose)
        $packageRoomBullets = []; // canonical-lower-name → install-action bullets (newline-separated)
        $projectScope       = '';
        if ($survey->project_id) {
            $project = \App\Models\Project::with('latestPackage')->find($survey->project_id);
            $rd = (array) ($project?->latestPackage?->reviewed_data  ?? []);
            $ed = (array) ($project?->latestPackage?->extracted_data ?? []);

            $projectScope = trim((string) (
                $rd['works_overview']  ??
                $ed['works_overview']  ??
                $rd['scope_of_works']  ??
                $ed['scope_of_works']  ??
                ''
            ));

            $roSource = ! empty($rd['room_overviews'])
                ? (array) $rd['room_overviews']
                : (array) ($ed['room_overviews'] ?? []);

            foreach ($roSource as $ro) {
                if (! is_array($ro)) continue;
                $name = trim((string) ($ro['room'] ?? $ro['room_name'] ?? $ro['name'] ?? ''));
                if ($name === '') continue;

                $text = '';
                foreach (['description', 'works_summary', 'overview', 'scope'] as $field) {
                    $candidate = trim((string) ($ro[$field] ?? ''));
                    if ($candidate !== '') { $text = $candidate; break; }
                }

                $packageRoomScopes[strtolower($name)] = $text;

                // works_summary is the install-action bullet list — captured
                // separately so the wizard can render it as a checklist
                // without losing the prose description / overview text.
                $packageRoomBullets[strtolower($name)] = trim((string) ($ro['works_summary'] ?? ''));
            }
        }

        foreach ($survey->rooms as $idx => $dbRoom) {
            $canonical = $payload['rooms'][$idx]
                ?? $this->emptyCanonicalRoom((string) ($dbRoom->room_name ?? ''));

            // Guarantee risks has at least one entry for UI binding
            if (empty($canonical['risks'])) {
                $canonical['risks'] = [[
                    'working_height'       => '',
                    'access_equipment'     => '',
                    'out_of_hours'         => false,
                    'permits_required'     => false,
                    'manual_handling_risk' => false,
                ]];
            }

            // Photos from DB are authoritative (uploaded via PublicSurveyController).
            // `type` is the system category slug used by the wizard to filter into
            // the right thumbnail group. `caption` is the engineer-supplied
            // free-text annotation (added post-Phase-15 for inspection clarity).
            // Legacy rows without a `category` fall back to `caption` since the
            // category slug used to live there before the migration split them.
            $photos = $dbRoom->photos->map(fn ($p) => [
                'id'        => $p->id,
                'type'      => $p->category ?? $p->caption ?? '',
                'caption'   => $p->category ? ($p->caption ?? '') : '',
                'file_path' => route('survey.photos.serve', [
                    'token' => $token,
                    'photo' => $p->id,
                ]),
            ])->toArray();

            $canonical = $this->enforceCanonicalShape($canonical);

            // Rehydrate engineer_feedback canonical block from DB columns when
            // the room has been completed (column wins over canonical JSON).
            // This is the column-wins bridge: an engineer who already filled the
            // internal admin form OR already submitted via the wizard sees their
            // saved data round-trip on the next page load. (quick task 260503-u2x)
            $efDefault   = $this->emptyCanonicalRoom('')['engineer_feedback'];
            $efCanonical = is_array($canonical['engineer_feedback'] ?? null)
                ? $canonical['engineer_feedback']
                : $efDefault;

            $efFromDb = [
                'mounting_heights'         => is_array($dbRoom->mounting_heights)        ? $dbRoom->mounting_heights         : null,
                'work_at_height_methods'   => is_array($dbRoom->work_at_height_methods)  ? $dbRoom->work_at_height_methods   : null,
                'cable_routes'             => is_array($dbRoom->cable_routes)            ? $dbRoom->cable_routes             : null,
                'wall_construction'        => is_array($dbRoom->wall_construction)       ? $dbRoom->wall_construction        : null,
                'wall_needs_reinforcement' => $dbRoom->wall_needs_reinforcement,
                'wall_needs_chase_out'     => $dbRoom->wall_needs_chase_out,
                'wall_needs_conduit'       => $dbRoom->wall_needs_conduit,
                'table_info'               => is_array($dbRoom->table_info)              ? $dbRoom->table_info               : null,
                'floor_box_info'           => is_array($dbRoom->floor_box_info)          ? $dbRoom->floor_box_info           : null,
                'brackets_required'        => is_array($dbRoom->brackets_required)       ? $dbRoom->brackets_required        : null,
            ];

            // Merge: column wins when non-null, otherwise canonical JSON, otherwise default.
            // mounting_heights merges deeply because it's a fixed-shape object with
            // optional 'other' array — a partial save shouldn't blank unsaved keys.
            $ef = $efDefault;
            if ($efFromDb['mounting_heights'] !== null) {
                $ef['mounting_heights'] = array_merge($efDefault['mounting_heights'], $efFromDb['mounting_heights']);
                $ef['mounting_heights']['other'] = is_array($ef['mounting_heights']['other'] ?? null)
                    ? array_values($ef['mounting_heights']['other'])
                    : [];
            } else {
                $ef['mounting_heights'] = $efCanonical['mounting_heights'] ?? $efDefault['mounting_heights'];
            }
            $ef['work_at_height_methods']   = $efFromDb['work_at_height_methods']  ?? ($efCanonical['work_at_height_methods']  ?? []);
            $ef['cable_routes']             = array_values($efFromDb['cable_routes']      ?? ($efCanonical['cable_routes']    ?? []));
            $ef['wall_construction']        = $efFromDb['wall_construction']      ?? ($efCanonical['wall_construction']      ?? []);
            $ef['wall_needs_reinforcement'] = $efFromDb['wall_needs_reinforcement'] ?? ($efCanonical['wall_needs_reinforcement'] ?? false);
            $ef['wall_needs_chase_out']     = $efFromDb['wall_needs_chase_out']     ?? ($efCanonical['wall_needs_chase_out']     ?? false);
            $ef['wall_needs_conduit']       = $efFromDb['wall_needs_conduit']       ?? ($efCanonical['wall_needs_conduit']       ?? false);
            $ef['table_info']               = is_array($efFromDb['table_info'])     ? array_merge($efDefault['table_info'],     $efFromDb['table_info'])     : ($efCanonical['table_info']     ?? $efDefault['table_info']);
            $ef['floor_box_info']           = is_array($efFromDb['floor_box_info']) ? array_merge($efDefault['floor_box_info'], $efFromDb['floor_box_info']) : ($efCanonical['floor_box_info'] ?? $efDefault['floor_box_info']);
            $ef['brackets_required']        = array_values($efFromDb['brackets_required'] ?? ($efCanonical['brackets_required'] ?? []));

            $canonical['engineer_feedback'] = $ef;

            // Checklist questions surfaced per room so engineers see the
            // guidance context before/while completing the room. Read-only —
            // never written back to survey_data.
            $questions = $dbRoom->relationLoaded('questions') ? $dbRoom->questions : collect();

            // Pick the cleanest "Planned AV Works" text available for this room.
            // The DB column $dbRoom->av_requirements is seeded at room creation
            // and goes stale when a quote is re-extracted with parser fixes —
            // prefer the live package data, fall back to the column, then to
            // project-level scope when nothing room-specific is meaningful.
            $columnAv     = trim((string) ($dbRoom->av_requirements ?? ''));
            $packageAv    = $packageRoomScopes[strtolower((string) $dbRoom->room_name)] ?? '';
            $plannedWorks = $columnAv;
            if (strlen($packageAv) > strlen($plannedWorks)) {
                $plannedWorks = $packageAv;
            }
            // If still short / fragmentary, prefer the project-wide scope.
            if (strlen(trim($plannedWorks)) < 60 && $projectScope !== '') {
                $plannedWorks = $projectScope;
            }

            $alpineRooms[] = array_merge($canonical, [
                'photos' => $photos ?: $canonical['photos'],

                // ── Job context — read-only per-room planning data from DB.
                // Never written back to survey_data; informational only.
                '_ctx' => [
                    'av_requirements'         => $plannedWorks,
                    // Per-room install-action bullets generated on the office
                    // review screen (Room/Space Overviews → AV Works Summary).
                    // One bullet per line, "- " prefix optional. Engineers see
                    // these in the Kit Info drawer as a checklist for THIS room.
                    // Phase 22.1 D-04: derived from the per-room summary fields
                    // (the project-wide payload key was removed by Plan 22.1-04).
                    'planned_actions'         => array_values(array_filter(
                        array_map('trim', preg_split('/\r?\n/', (string) ($packageRoomBullets[strtolower((string) $dbRoom->room_name)] ?? ''))),
                        fn ($l) => $l !== ''
                    )),
                    'av_equipment_list'  => (string) ($dbRoom->av_equipment_list ?? ''),
                    'question_count'     => $questions->count(),
                    'questions'          => $questions->map(fn ($q) => [
                        'id'         => $q->id,
                        'question'   => (string) $q->question,
                        'answer'     => $q->answer,           // 'yes' | 'no' | 'other' | null
                        'other_text' => (string) ($q->other_text ?? ''),
                        'answered'   => $q->answer !== null && $q->answer !== '',
                    ])->values()->toArray(),
                    // Solution-type reference checklist — what the office
                    // master checklist says an engineer should verify for
                    // this kind of room. Engineers cross-reference against
                    // this without leaving the wizard.
                    'solution_type_name' => $this->resolveSolutionTypeName($dbRoom),
                    'checklist_lines'    => $this->resolveSolutionChecklist($dbRoom),
                ],

                // ── UI block — most values now seeded from canonical
                //     ui_state so a reload restores the engineer's selections.
                //     room_id and is_completed remain from the DB row.
                '_ui' => [
                    'room_id'          => $dbRoom->id,
                    'is_completed'     => (bool) $dbRoom->is_completed,

                    // Step 1 logic state — persisted to canonical ui_state
                    'work_type'        => (string) ($canonical['ui_state']['work_type'] ?? ''),

                    // Step 2 capture fields
                    'voice_note'       =>          ($canonical['ui_state']['voice_note'] ?? null) ?: null,
                    'quick_notes'      => $canonical['notes'],

                    // Step 2 toggles — now persisted to canonical ui_state so
                    // they actually survive page reloads.
                    'power_available'  => (bool) ($canonical['ui_state']['power_available']  ?? false),
                    'network_available'=> (bool) ($canonical['ui_state']['network_available']?? false),
                    'access_issues'    => (bool) ($canonical['ui_state']['access_issues']    ?? false),
                    'working_at_height'=> (bool) ($canonical['ui_state']['working_at_height']?? false),
                    'client_present'   => (bool) ($canonical['ui_state']['client_present']   ?? false),

                    // Step 7 constraints — seeded from canonical survey_data
                    // so a reload after stepSave shows what was captured.
                    'constraints' => [
                        'obstructions'          => (string) ($canonical['constraints']['obstructions']          ?? ''),
                        'noise_restrictions'    => (string) ($canonical['constraints']['noise_restrictions']    ?? ''),
                        'client_constraints'    => (string) ($canonical['constraints']['client_constraints']    ?? ''),
                        'programme_constraints' => (string) ($canonical['constraints']['programme_constraints'] ?? ''),
                    ],

                    // Step 8 sign-off — seeded from canonical survey_data so
                    // a reload restores the engineer's confirmation state.
                    'signoff' => [
                        'engineer_name'    => (string) ($canonical['signoff']['engineer_name']      ?? ''),
                        'client_signature' => '',
                        'is_confirmed'     => (bool)   ($canonical['signoff']['engineer_confirmed'] ?? false),
                        'timestamp'        =>          ($canonical['signoff']['signed_at']          ?? null) ?: null,
                    ],
                ],
            ]);
        }

        return $alpineRooms;
    }

    /**
     * Resolve the solution type name for a survey room. Looks up the
     * matching SolutionType by the room's space_type slug. Returns empty
     * string if no match — the wizard then suppresses the reference panel.
     */
    private function resolveSolutionTypeName(\App\Models\SiteSurveyRoom $room): string
    {
        $slug = trim((string) ($room->space_type ?? ''));
        if ($slug === '' || $slug === 'general') return '';
        $st = \App\Models\SolutionType::where('slug', $slug)->first();
        return $st?->name ?? '';
    }

    /**
     * Resolve the solution-type reference checklist for a survey room. The
     * checklist is the office master list of what to verify for this kind
     * of room — engineers cross-reference against it as they capture data
     * in the wizard. Returns an array of trimmed lines, empty when no match.
     *
     * @return array<int, string>
     */
    private function resolveSolutionChecklist(\App\Models\SiteSurveyRoom $room): array
    {
        $slug = trim((string) ($room->space_type ?? ''));
        if ($slug === '' || $slug === 'general') return [];
        $st = \App\Models\SolutionType::where('slug', $slug)->first();
        return $st?->checklistLines() ?? [];
    }

    // ── Private — step validation ─────────────────────────────────────────────

    /**
     * Returns a string error message if the step fails server-side validation,
     * or null if the step is acceptable.
     *
     * Step 1 field presence is enforced client-side (UI disables Next button).
     * Server validates only structural integrity.
     */
    private function validateStep(int $step, array $data): ?string
    {
        // Step 0 — site-level engineer-feedback save (quick task 260503-u2x).
        // Defers shape normalization to normalizeEngineerFeedbackSite(); only
        // structural + enum guards here.
        if ($step === 0) {
            $site = $data['engineer_feedback_site'] ?? null;
            if ($site !== null && ! is_array($site)) {
                return 'engineer_feedback_site payload is invalid.';
            }
            $crs = $site['comms_room_access_status'] ?? '';
            if ($crs !== '' && $crs !== null && ! in_array($crs, ['yes','no','outsourced','unknown'], true)) {
                return 'Invalid comms_room_access_status value.';
            }
            return null;
        }

        if ($step === 1) {
            $name = trim((string) ($data['name'] ?? ''));
            $type = trim((string) ($data['type'] ?? ''));
            if ($name === '' || $type === '') {
                return 'Room name and room type are required.';
            }
            return null;
        }

        if ($step === 3) {
            if (array_key_exists('photos', $data) && ! is_array($data['photos'])) {
                return 'Photos payload is invalid.';
            }
            return null;
        }

        if ($step === 4) {
            $infra = $data['infrastructure'] ?? null;
            if ($infra !== null && ! is_array($infra)) {
                return 'Infrastructure payload is invalid.';
            }

            $distance = $infra['cable_routes']['estimated_distance'] ?? null;
            if ($distance !== null && $distance !== '' && ! is_numeric($distance)) {
                return 'Estimated cable distance must be numeric.';
            }

            // engineer_feedback per-room block (quick task 260503-u2x). Defer
            // shape normalization to normalizeStepContribution → normalizeEngineerFeedback().
            if (array_key_exists('engineer_feedback', $data) && ! is_array($data['engineer_feedback'])) {
                return 'engineer_feedback payload is invalid.';
            }

            return null;
        }

        if ($step === 5) {
            if (! array_key_exists('equipment', $data) || ! is_array($data['equipment'])) {
                return 'Equipment list is required.';
            }

            $allowedTypes = [
                'display', 'projector', 'camera', 'mic', 'dsp',
                'vc', 'control', 'switcher', 'speaker', 'other',
            ];
            $allowedStatus = ['new', 'existing'];

            foreach ($data['equipment'] as $item) {
                if (! is_array($item)) {
                    return 'Equipment entries must be objects.';
                }

                $type = strtolower(trim((string) ($item['type'] ?? '')));
                $status = strtolower(trim((string) ($item['status'] ?? '')));

                if ($type !== '' && ! in_array($type, $allowedTypes, true)) {
                    return "Invalid equipment type '{$type}'.";
                }

                if ($status !== '' && ! in_array($status, $allowedStatus, true)) {
                    return "Invalid equipment status '{$status}'.";
                }
            }

            return null;
        }

        if ($step === 6) {
            if (array_key_exists('risks', $data) && ! is_array($data['risks'])) {
                return 'Risks payload is invalid.';
            }

            $allowedWorkingHeight = ['under_2m', '2_to_4m', 'over_4m'];
            foreach ((array) ($data['risks'] ?? []) as $risk) {
                if (! is_array($risk)) {
                    return 'Risk entries must be objects.';
                }

                $workingHeight = strtolower(trim((string) ($risk['working_height'] ?? '')));
                if ($workingHeight !== '' && ! in_array($workingHeight, $allowedWorkingHeight, true)) {
                    return "Invalid working_height value '{$workingHeight}'.";
                }
            }

            return null;
        }

        if ($step === 7) {
            if (array_key_exists('constraints', $data) && ! is_array($data['constraints'])) {
                return 'Constraints payload is invalid.';
            }
            return null;
        }

        if ($step === 8) {
            if (array_key_exists('signoff', $data) && ! is_array($data['signoff'])) {
                return 'Signoff payload is invalid.';
            }
            $confirmed = (bool) ($data['signoff']['engineer_confirmed'] ?? $data['engineer_confirmed'] ?? false);
            $engineer  = trim((string) ($data['signoff']['engineer_name'] ?? $data['engineer_name'] ?? ''));
            if ($confirmed && $engineer === '') {
                return 'Engineer name is required to confirm sign-off.';
            }
            return null;
        }

        return null;
    }

    // ── Private — canonical normalization ─────────────────────────────────────

    /**
     * Map the step data to its canonical contribution and return the updated
     * canonical room. enforceCanonicalShape() is always called last, guaranteeing
     * no non-canonical keys survive persistence.
     */
    private function normalizeStepContribution(array $room, int $step, array $data): array
    {
        switch ($step) {
            case 1:
                // Canonical: name, type, and ui_state.work_type so the
                // engineer's Step 1 selection survives a reload.
                if (array_key_exists('name', $data)) {
                    $room['name'] = (string) $data['name'];
                }
                if (array_key_exists('type', $data)) {
                    $room['type'] = (string) $data['type'];
                }
                if (array_key_exists('work_type', $data)) {
                    $room['ui_state'] = (array) ($room['ui_state'] ?? []);
                    $room['ui_state']['work_type'] = (string) $data['work_type'];
                }
                break;

            case 2:
                // Canonical: notes = quick_notes, plus the Step 2 toggles
                // (power_available, network_available, access_issues,
                // working_at_height, client_present, voice_note) preserved
                // in ui_state so reload restores the engineer's selections.
                $room['notes']    = (string) ($data['quick_notes'] ?? $room['notes'] ?? '');
                $room['ui_state'] = (array)  ($room['ui_state']    ?? []);
                foreach (['power_available','network_available','access_issues','working_at_height','client_present'] as $k) {
                    if (array_key_exists($k, $data)) {
                        $room['ui_state'][$k] = (bool) $data[$k];
                    }
                }
                if (array_key_exists('voice_note', $data)) {
                    $v = $data['voice_note'];
                    $room['ui_state']['voice_note'] = ($v === '' || $v === null) ? null : (string) $v;
                }
                break;

            case 3:
                // Photos are uploaded via PublicSurveyController::uploadPhoto().
                // Step 3 save syncs the Alpine photo list into the canonical payload.
                if (array_key_exists('photos', $data)) {
                    $room['photos'] = $this->normalizePhotos($data['photos']);
                }
                break;

            case 4:
                // Full infrastructure written only when sent (work_type=new_install
                // enforced client-side; server normalizes whatever arrives).
                if (array_key_exists('infrastructure', $data)) {
                    $room['infrastructure'] = $this->normalizeInfrastructure($data['infrastructure']);
                }
                // Engineer-feedback block (quick task 260503-u2x). Final shape
                // normalization happens in enforceCanonicalShape() via the
                // normalizeEngineerFeedback() helper called below.
                if (array_key_exists('engineer_feedback', $data)) {
                    $room['engineer_feedback'] = $data['engineer_feedback'];
                }
                break;

            case 5:
                $room['equipment'] = $this->normalizeEquipment($data['equipment'] ?? []);
                if (array_key_exists('additional_items', $data)) {
                    $room['additional_items'] = $this->normalizeAdditionalItems($data['additional_items']);
                }
                break;

            case 6:
                // working_at_height sent as normalization context; never persisted.
                if (array_key_exists('risks', $data)) {
                    $workingAtHeight = (bool) ($data['working_at_height'] ?? false);
                    $room['risks']   = $this->normalizeRisks($data['risks'], $workingAtHeight);
                }
                break;

            case 7:
                // Step 7 — site constraints. Persist the structured 4-field
                // block so a superseded/reloaded survey still shows what the
                // engineer captured. Unknown keys are discarded by
                // normalizeConstraints().
                if (array_key_exists('constraints', $data)) {
                    $room['constraints'] = $this->normalizeConstraints($data['constraints']);
                }
                break;

            case 8:
                // Step 8 — engineer sign-off. Server timestamps the signing
                // so clock-skew in the browser cannot fabricate a signed_at.
                // engineer_confirmed gates is_completed on the client side.
                if (array_key_exists('signoff', $data) || array_key_exists('engineer_name', $data)) {
                    $incoming = is_array($data['signoff'] ?? null)
                        ? $data['signoff']
                        : [
                            'engineer_name'      => $data['engineer_name']      ?? null,
                            'engineer_confirmed' => $data['engineer_confirmed'] ?? false,
                        ];
                    $room['signoff'] = $this->normalizeSignoff($incoming, $room['signoff'] ?? []);
                }
                break;
        }

        return $this->enforceCanonicalShape($room);
    }

    private function normalizeConstraints(mixed $raw): array
    {
        $raw = is_array($raw) ? $raw : [];
        return [
            'obstructions'          => (string) ($raw['obstructions']          ?? ''),
            'noise_restrictions'    => (string) ($raw['noise_restrictions']    ?? ''),
            'client_constraints'    => (string) ($raw['client_constraints']    ?? ''),
            'programme_constraints' => (string) ($raw['programme_constraints'] ?? ''),
        ];
    }

    /**
     * Normalise the step 8 sign-off. `signed_at` is always server-stamped on
     * a new confirmation — we never trust the client clock. When the engineer
     * un-ticks confirmation, signed_at resets to null so a later re-tick
     * gets a fresh timestamp.
     */
    private function normalizeSignoff(array $raw, array $existing): array
    {
        $confirmed   = (bool) ($raw['engineer_confirmed'] ?? false);
        $engineer    = trim((string) ($raw['engineer_name'] ?? ''));
        $priorSigned = $existing['signed_at'] ?? null;

        return [
            'engineer_name'      => $engineer,
            'engineer_confirmed' => $confirmed,
            'signed_at'          => $confirmed ? ($priorSigned ?: now()->toIso8601String()) : null,
        ];
    }

    /**
     * Strips all non-canonical keys from a room array.
     * This is the single source of truth for what gets written to survey_data.
     */
    private function enforceCanonicalShape(array $room): array
    {
        $signoff = is_array($room['signoff']  ?? null) ? $room['signoff']  : [];
        $ui      = is_array($room['ui_state'] ?? null) ? $room['ui_state'] : [];
        return [
            'name'             => (string) ($room['name']  ?? ''),
            'type'             => (string) ($room['type']  ?? ''),
            'photos'           => (array)  ($room['photos'] ?? []),
            'infrastructure'   => $this->normalizeInfrastructure($room['infrastructure'] ?? []),
            'equipment'        => $this->normalizeEquipment($room['equipment'] ?? []),
            'risks'            => (array)  ($room['risks']  ?? []),
            'notes'            => (string) ($room['notes']  ?? ''),
            'constraints'      => $this->normalizeConstraints($room['constraints'] ?? []),
            'additional_items' => $this->normalizeAdditionalItems($room['additional_items'] ?? []),
            'signoff'        => [
                'engineer_name'      => (string) ($signoff['engineer_name']      ?? ''),
                'engineer_confirmed' => (bool)   ($signoff['engineer_confirmed'] ?? false),
                'signed_at'          =>          ($signoff['signed_at']          ?? null) ?: null,
            ],
            'ui_state'       => [
                'work_type'         => (string) ($ui['work_type']        ?? ''),
                'power_available'   => (bool)   ($ui['power_available']  ?? false),
                'network_available' => (bool)   ($ui['network_available']?? false),
                'access_issues'     => (bool)   ($ui['access_issues']    ?? false),
                'working_at_height' => (bool)   ($ui['working_at_height']?? false),
                'client_present'    => (bool)   ($ui['client_present']   ?? false),
                'voice_note'        =>          ($ui['voice_note']       ?? null) ?: null,
            ],
            // Engineer-feedback canonical block (quick task 260503-u2x). Mirrors
            // SiteSurveyRoom DB columns (260503-rgg). normalizeEngineerFeedback()
            // strips unknown keys and locks the shape so stale/malformed JSON
            // is never persisted.
            'engineer_feedback' => $this->normalizeEngineerFeedback($room['engineer_feedback'] ?? []),
        ];
    }

    private function normalizeInfrastructure(array $infra): array
    {
        $power  = $infra['power']        ?? [];
        $net    = $infra['network']      ?? [];
        $cables = $infra['cable_routes'] ?? [];

        return [
            'power' => [
                'socket_locations'   => (string) ($power['socket_locations']   ?? ''),
                'spare_capacity'     => (bool)   ($power['spare_capacity']     ?? false),
                'distance_to_screen' =>           $power['distance_to_screen'] ?? null,
            ],
            'network' => [
                'ports_available' =>           $net['ports_available'] ?? null,
                'vlan_required'   => (bool)   ($net['vlan_required']   ?? false),
                'switch_location' => (string) ($net['switch_location'] ?? ''),
            ],
            'cable_routes' => [
                'route_type'         => (string) ($cables['route_type']         ?? ''),
                'estimated_distance' =>           $cables['estimated_distance'] ?? null,
            ],
        ];
    }

    private function normalizePhotos(array $photos): array
    {
        $out = [];
        foreach ($photos as $p) {
            if (! is_array($p)) {
                continue;
            }
            $filePath = (string) ($p['file_path'] ?? '');
            if ($filePath === '') {
                continue;
            }
            $out[] = [
                'id'        =>           $p['id']        ?? null,
                'type'      => (string) ($p['type']      ?? ''),
                'caption'   => (string) ($p['caption']   ?? ''),
                'file_path' => $filePath,
            ];
        }
        return array_values($out);
    }

    private function normalizeEquipment(array $equipment): array
    {
        $allowed = array_flip(['type', 'status', 'location']);
        return array_values(array_map(
            fn ($item) => array_intersect_key((array) $item, $allowed),
            $equipment
        ));
    }

    /**
     * Normalise additional-items list. Each row is {qty, description, note};
     * empty rows (no description) are dropped so they don't pollute the
     * stored payload or aggregate burger view.
     */
    private function normalizeAdditionalItems(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $desc = trim((string) ($item['description'] ?? ''));
            if ($desc === '') {
                continue;
            }
            $out[] = [
                'qty'         => (string) ($item['qty']  ?? ''),
                'description' => $desc,
                'note'        => trim((string) ($item['note'] ?? '')),
            ];
        }
        return $out;
    }

    /**
     * Normalise risks and enforce the working_at_height conditional:
     * if working_at_height is false, access_equipment is cleared server-side.
     */
    private function normalizeRisks(array $risks, bool $workingAtHeight): array
    {
        return array_values(array_map(function ($risk) use ($workingAtHeight) {
            return [
                'working_height'       => (string) ($risk['working_height']       ?? ''),
                'access_equipment'     => $workingAtHeight
                                            ? (string) ($risk['access_equipment'] ?? '')
                                            : '',
                'out_of_hours'         => (bool)   ($risk['out_of_hours']         ?? false),
                'permits_required'     => (bool)   ($risk['permits_required']     ?? false),
                'manual_handling_risk' => (bool)   ($risk['manual_handling_risk'] ?? false),
            ];
        }, $risks));
    }

    // ── Download printable PDF form ───────────────────────────────────────────

    /**
     * GET /survey/{token}/download-form
     *
     * Streams a printable Field Survey Form PDF pre-populated with project,
     * client, site, and planned kit data — with blank manual-fill sections
     * per room so engineers can complete the survey by hand when the mobile
     * wizard isn't viable (no signal, device dead, etc).
     *
     * Token-gated (same 404/410 behaviour as show/stepSave). Read-only path:
     * no DB mutations, no filesystem writes — bytes are streamed in-memory.
     */
    public function downloadForm(string $token): Response
    {
        $survey = $this->resolveSurvey($token);

        $contents = $this->pdfService->buildFieldFormContents($survey);
        $filename = 'field-survey-form-' . $survey->id . '.pdf';

        return response($contents, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'Cache-Control'       => 'private, no-store',
        ]);
    }

    // ── Private — token gate ──────────────────────────────────────────────────

    private function resolveSurvey(string $token): SiteSurvey
    {
        $survey = SiteSurvey::where('access_token', $token)->with('project')->first();
        abort_if($survey === null, 404, 'Survey not found. Please check your link.');
        abort_if($survey->isTokenExpired(), 410, 'This survey link has expired.');
        if ($survey->project && method_exists($survey->project, 'trashed') && $survey->project->trashed()) {
            abort(410, 'This survey link has expired.');
        }
        return $survey;
    }

    // ══════════════════════════════════════════════════════════════════════════
    // Engineer-feedback normalization + DB-column mirror (quick task 260503-u2x)
    // ══════════════════════════════════════════════════════════════════════════

    /**
     * Lock the engineer_feedback canonical block to a strict shape so unknown
     * keys are stripped and missing keys default cleanly. Mirrors the
     * normalizeInfrastructure style — defensive against stale or malformed JSON.
     */
    private function normalizeEngineerFeedback(array $ef): array
    {
        $mh = is_array($ef['mounting_heights'] ?? null) ? $ef['mounting_heights'] : [];
        $ti = is_array($ef['table_info']       ?? null) ? $ef['table_info']       : [];
        $fb = is_array($ef['floor_box_info']   ?? null) ? $ef['floor_box_info']   : [];

        return [
            'mounting_heights' => [
                'screen_h_m'        => $this->numericOrNull($mh['screen_h_m']        ?? null),
                'camera_h_m'        => $this->numericOrNull($mh['camera_h_m']        ?? null),
                'booking_panel_h_m' => $this->numericOrNull($mh['booking_panel_h_m'] ?? null),
                'speaker_h_m'       => $this->numericOrNull($mh['speaker_h_m']       ?? null),
                'other'             => $this->normalizeMountingHeightOther($mh['other'] ?? []),
            ],
            'work_at_height_methods'   => $this->normalizeStringArray($ef['work_at_height_methods'] ?? [], ['ladder','podium','tower','mewp','scaffold','na']),
            'cable_routes'             => $this->normalizeCableRoutes($ef['cable_routes'] ?? []),
            'wall_construction'        => $this->normalizeStringArray($ef['wall_construction'] ?? [], ['ply_lined','solid','plasterboard','masonry','metal_stud','concrete']),
            'wall_needs_reinforcement' => (bool) ($ef['wall_needs_reinforcement'] ?? false),
            'wall_needs_chase_out'     => (bool) ($ef['wall_needs_chase_out']     ?? false),
            'wall_needs_conduit'       => (bool) ($ef['wall_needs_conduit']       ?? false),
            'table_info' => [
                'has_grommets'  => (bool) ($ti['has_grommets']  ?? false),
                'grommet_count' => $this->intOrNull($ti['grommet_count'] ?? null),
                'grommet_size'  => in_array($ti['grommet_size'] ?? '', ['small','standard','large'], true) ? $ti['grommet_size'] : '',
                'notes'         => (string) ($ti['notes'] ?? ''),
            ],
            'floor_box_info' => [
                'has_floor_box' => (bool) ($fb['has_floor_box'] ?? false),
                'power_outlets' => $this->intOrNull($fb['power_outlets'] ?? null),
                'data_outlets'  => $this->intOrNull($fb['data_outlets'] ?? null),
                'cable_space'   => in_array($fb['cable_space'] ?? '', ['tight','adequate','spacious'], true) ? $fb['cable_space'] : '',
                'notes'         => (string) ($fb['notes'] ?? ''),
            ],
            'brackets_required' => $this->normalizeBrackets($ef['brackets_required'] ?? []),
        ];
    }

    /**
     * Normalise the site-level engineer_feedback_site canonical block. Called by
     * stepSave() for step=0 site-level saves.
     */
    private function normalizeEngineerFeedbackSite(mixed $site): array
    {
        $site = is_array($site) ? $site : [];
        $crs  = $site['comms_room_access_status'] ?? '';
        return [
            'comms_room_access_status' => in_array($crs, ['yes','no','outsourced','unknown'], true) ? $crs : '',
            'comms_room_access_notes'  => mb_substr((string) ($site['comms_room_access_notes']  ?? ''), 0, 2000),
            'parking_restraints'       => mb_substr((string) ($site['parking_restraints']       ?? ''), 0, 2000),
            'distance_from_base_miles' => $this->numericOrNull($site['distance_from_base_miles'] ?? null),
            'distance_from_base_notes' => mb_substr((string) ($site['distance_from_base_notes'] ?? ''), 0, 2000),
            'site_access_notes'        => mb_substr((string) ($site['site_access_notes']        ?? ''), 0, 3000),
            'delivery_routes'          => mb_substr((string) ($site['delivery_routes']          ?? ''), 0, 3000),
        ];
    }

    private function normalizeMountingHeightOther(mixed $rows): array
    {
        $rows = is_array($rows) ? $rows : [];
        $out  = [];
        foreach ($rows as $r) {
            if (! is_array($r)) continue;
            $label  = trim((string) ($r['label'] ?? ''));
            $height = $this->numericOrNull($r['height_m'] ?? null);
            if ($label === '' && $height === null) continue;
            $out[] = ['label' => $label, 'height_m' => $height];
        }
        return $out;
    }

    private function normalizeCableRoutes(mixed $rows): array
    {
        $rows       = is_array($rows) ? $rows : [];
        $allowedCat = ['ceiling_speakers','desk_cables','mic_cables','booking_panel_cables','screen_cables','rack_to_room','other'];
        $out        = [];
        foreach ($rows as $r) {
            if (! is_array($r)) continue;
            $cat   = (string) ($r['category'] ?? '');
            $cat   = in_array($cat, $allowedCat, true) ? $cat : '';
            $from  = trim((string) ($r['from']  ?? ''));
            $to    = trim((string) ($r['to']    ?? ''));
            $len   = $this->numericOrNull($r['length_m'] ?? null);
            $notes = trim((string) ($r['notes'] ?? ''));
            if ($cat === '' && $from === '' && $to === '' && $len === null && $notes === '') continue;
            $out[] = ['category' => $cat, 'from' => $from, 'to' => $to, 'length_m' => $len, 'notes' => $notes];
        }
        return $out;
    }

    private function normalizeBrackets(mixed $rows): array
    {
        $rows = is_array($rows) ? $rows : [];
        $out  = [];
        foreach ($rows as $r) {
            if (! is_array($r)) continue;
            $eq    = trim((string) ($r['equipment'] ?? ''));
            $model = trim((string) ($r['model']     ?? ''));
            $pull  = (bool) ($r['pull_out'] ?? false);
            $notes = trim((string) ($r['notes'] ?? ''));
            if ($eq === '' && $model === '' && $notes === '' && ! $pull) continue;
            $out[] = ['equipment' => $eq, 'model' => $model, 'pull_out' => $pull, 'notes' => $notes];
        }
        return $out;
    }

    private function normalizeStringArray(mixed $vals, array $allowed): array
    {
        if (! is_array($vals)) return [];
        $out = [];
        foreach ($vals as $v) {
            $v = (string) $v;
            if (in_array($v, $allowed, true)) $out[] = $v;
        }
        return array_values(array_unique($out));
    }

    private function numericOrNull(mixed $v): ?float
    {
        if ($v === null || $v === '' || $v === false) return null;
        if (! is_numeric($v)) return null;
        return (float) $v;
    }

    private function intOrNull(mixed $v): ?int
    {
        if ($v === null || $v === '' || $v === false) return null;
        if (! is_numeric($v)) return null;
        return (int) $v;
    }

    /**
     * Mirror the per-room engineer_feedback canonical block onto the SiteSurveyRoom
     * DB columns. Called by stepSave() for step=4 AND by PublicSurveyController::
     * completeRoom() so both surfaces stay aligned. Eloquent's array casts on the
     * JSON columns handle encode/decode transparently. Booleans persist as boolean
     * even when false because the boolean answer carries information.
     *
     * Note: public so PublicSurveyController can resolve via app() without
     * widening its dependency surface.
     */
    public function writeEngineerFeedbackToColumns(\App\Models\SiteSurveyRoom $room, array $ef): void
    {
        $ef = $this->normalizeEngineerFeedback($ef);
        $room->update([
            'mounting_heights'         => $this->isMountingHeightsEmpty($ef['mounting_heights']) ? null : $ef['mounting_heights'],
            'work_at_height_methods'   => $ef['work_at_height_methods']   ?: null,
            'cable_routes'             => $ef['cable_routes']             ?: null,
            'wall_construction'        => $ef['wall_construction']        ?: null,
            'wall_needs_reinforcement' => $ef['wall_needs_reinforcement'],
            'wall_needs_chase_out'     => $ef['wall_needs_chase_out'],
            'wall_needs_conduit'       => $ef['wall_needs_conduit'],
            'table_info'               => $this->isTableInfoEmpty($ef['table_info'])     ? null : $ef['table_info'],
            'floor_box_info'           => $this->isFloorBoxInfoEmpty($ef['floor_box_info']) ? null : $ef['floor_box_info'],
            'brackets_required'        => $ef['brackets_required']        ?: null,
        ]);
    }

    /**
     * Mirror the site-level engineer_feedback canonical block onto SiteSurvey
     * DB columns. Called by stepSave() for step=0 site-level saves.
     */
    private function writeEngineerFeedbackSiteToColumns(SiteSurvey $survey, array $site): void
    {
        $survey->update([
            'comms_room_access_status' => $site['comms_room_access_status'] ?: null,
            'comms_room_access_notes'  => $site['comms_room_access_notes']  ?: null,
            'parking_restraints'       => $site['parking_restraints']       ?: null,
            'distance_from_base_miles' => $site['distance_from_base_miles'],
            'distance_from_base_notes' => $site['distance_from_base_notes'] ?: null,
            'site_access_notes'        => $site['site_access_notes']        ?: null,
            'delivery_routes'          => $site['delivery_routes']          ?: null,
        ]);
    }

    private function isMountingHeightsEmpty(array $mh): bool
    {
        foreach (['screen_h_m','camera_h_m','booking_panel_h_m','speaker_h_m'] as $k) {
            if (($mh[$k] ?? null) !== null) return false;
        }
        return empty($mh['other'] ?? []);
    }

    private function isTableInfoEmpty(array $ti): bool
    {
        return empty($ti['has_grommets'])
            && ($ti['grommet_count'] ?? null) === null
            && empty($ti['grommet_size'])
            && empty($ti['notes']);
    }

    private function isFloorBoxInfoEmpty(array $fb): bool
    {
        return empty($fb['has_floor_box'])
            && ($fb['power_outlets'] ?? null) === null
            && ($fb['data_outlets']  ?? null) === null
            && empty($fb['cable_space'])
            && empty($fb['notes']);
    }
}
