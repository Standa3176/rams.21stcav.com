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
        $survey->load(['rooms.photos', 'rooms.questions']);

        $payload = $survey->survey_data;

        if (empty($payload['rooms'])) {
            $payload = $this->initialPayload($survey);
            $survey->update(['survey_data' => $payload]);
        }

        $rooms = $this->buildAlpineRooms($survey, $payload, $token);

        return view('surveys.show', [
            'survey'   => $survey,
            'token'    => $token,
            'rooms'    => $rooms,
            'readonly' => $survey->isSubmitted(),
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
            'step'       => ['required', 'integer', 'between:1,8'],
            'data'       => ['required', 'array'],
        ]);

        $payload = $survey->survey_data ?? $this->initialPayload($survey);
        $idx     = $validated['room_index'];

        if (! isset($payload['rooms'][$idx])) {
            return response()->json(['error' => 'Invalid room index.'], 422);
        }

        $stepError = $this->validateStep($validated['step'], $validated['data']);
        if ($stepError) {
            return response()->json(['error' => $stepError], 422);
        }

        $payload['rooms'][$idx] = $this->normalizeStepContribution(
            $payload['rooms'][$idx],
            $validated['step'],
            $validated['data']
        );

        $survey->update(['survey_data' => $payload]);

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

            // Photos from DB are authoritative (uploaded via PublicSurveyController)
            $photos = $dbRoom->photos->map(fn ($p) => [
                'type'      => $p->caption ?? '',
                'file_path' => route('survey.photos.serve', [
                    'token' => $token,
                    'photo' => $p->id,
                ]),
            ])->toArray();

            $canonical = $this->enforceCanonicalShape($canonical);

            // Checklist questions surfaced per room so engineers see the
            // guidance context before/while completing the room. Read-only —
            // never written back to survey_data.
            $questions = $dbRoom->relationLoaded('questions') ? $dbRoom->questions : collect();

            $alpineRooms[] = array_merge($canonical, [
                'photos' => $photos ?: $canonical['photos'],

                // ── Job context — read-only per-room planning data from DB.
                // Never written back to survey_data; informational only.
                '_ctx' => [
                    'av_requirements'   => (string) ($dbRoom->av_requirements ?? ''),
                    'av_equipment_list' => (string) ($dbRoom->av_equipment_list ?? ''),
                    'question_count'    => $questions->count(),
                    'questions'         => $questions->map(fn ($q) => [
                        'question' => (string) $q->question,
                        'answered' => $q->answer !== null && $q->answer !== '',
                    ])->values()->toArray(),
                ],

                // ── UI-only block — never written to survey_data ──────────────
                '_ui' => [
                    'room_id'          => $dbRoom->id,
                    'is_completed'     => (bool) $dbRoom->is_completed,

                    // Step 1 logic state — not canonical
                    'work_type'        => '',

                    // Step 2 capture fields — map to canonical notes on save
                    'voice_note'       => null,
                    'quick_notes'      => $canonical['notes'],

                    // Step 2 toggles — not canonical
                    'power_available'  => (bool) ($dbRoom->has_power  ?? false),
                    'network_available'=> (bool) ($dbRoom->has_network ?? false),
                    'access_issues'    => false,
                    'working_at_height'=> false,
                    'client_present'   => false,

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
                // Canonical: name, type only. work_type is UI-only — discarded.
                if (array_key_exists('name', $data)) {
                    $room['name'] = (string) $data['name'];
                }
                if (array_key_exists('type', $data)) {
                    $room['type'] = (string) $data['type'];
                }
                break;

            case 2:
                // Canonical: notes = quick_notes. All toggles/voice_note are UI-only.
                $room['notes'] = (string) ($data['quick_notes'] ?? $room['notes'] ?? '');
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
                break;

            case 5:
                $room['equipment'] = $this->normalizeEquipment($data['equipment'] ?? []);
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
        $signoff = is_array($room['signoff'] ?? null) ? $room['signoff'] : [];
        return [
            'name'           => (string) ($room['name']  ?? ''),
            'type'           => (string) ($room['type']  ?? ''),
            'photos'         => (array)  ($room['photos'] ?? []),
            'infrastructure' => $this->normalizeInfrastructure($room['infrastructure'] ?? []),
            'equipment'      => $this->normalizeEquipment($room['equipment'] ?? []),
            'risks'          => (array)  ($room['risks']  ?? []),
            'notes'          => (string) ($room['notes']  ?? ''),
            'constraints'    => $this->normalizeConstraints($room['constraints'] ?? []),
            'signoff'        => [
                'engineer_name'      => (string) ($signoff['engineer_name']      ?? ''),
                'engineer_confirmed' => (bool)   ($signoff['engineer_confirmed'] ?? false),
                'signed_at'          =>          ($signoff['signed_at']          ?? null) ?: null,
            ],
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
                'type'      => (string) ($p['type']      ?? ''),
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
}
