<?php

namespace App\Support\Visits;

use App\Models\Project;
use App\Models\SiteSurvey;

/**
 * SurveyCarryForward — the survey → install carry-forward (Phase 46, Plan 46-03).
 *
 * ── D-01, QUOTED VERBATIM ───────────────────────────────────────────────────
 *
 *   "A survey's site findings are carried forward to the install visit
 *    automatically and shown read-only. The install engineer's link shows the
 *    survey's access constraints, parking, comms-room access and notes,
 *    delivery routes, site access notes, site risks and H&S notes.
 *
 *    It reads from the survey record; it is never copied. A copy can go stale
 *    and then contradict the survey it came from, and the PM would have no way
 *    to tell which is true. Reading through means the install link always shows
 *    what the survey actually says."
 *
 * ── THE TWO RULES THAT FOLLOW FROM IT ───────────────────────────────────────
 *
 *  1. NO CACHING ACROSS REQUESTS. `forProject()` resolves the project's current
 *     survey on every call. There is no static store, no `Cache::remember`, no
 *     memoisation keyed by project id. A cached value is a copy with a nicer
 *     name, and it goes stale exactly the same way.
 *
 *  2. NO COLUMN ON `worksheets` EVER HOLDS ONE OF THESE VALUES. This class
 *     writes nothing, anywhere — not to `worksheets`, not to `visits`, not to
 *     `site_surveys`. If a future plan adds a `worksheets.site_risks` column,
 *     D-01 has been broken, not implemented.
 *
 * ── `office_review_notes` IS EXCLUDED BY NAME ───────────────────────────────
 *
 * `SiteSurvey` also carries `office_review_notes` (quick task 260508-v7g). It is
 * DELIBERATELY absent from FIELDS and must stay absent. It is the office's own
 * internal commentary on the survey, and `/worksheet/{token}` — the one page
 * this class feeds — is unauthenticated and is signed by the CLIENT. Adding it
 * would put office-only remarks in front of the customer they are about.
 * This is threat T-46-03-01 and `SurveyCarryForwardTest` asserts the exclusion
 * by reading FIELDS as data, so "completing the list" fails a test rather than
 * shipping.
 *
 * ── RENDERING CONTRACT ──────────────────────────────────────────────────────
 *
 * Values are returned as RAW strings. Escaping belongs at the render site, which
 * uses `{{ }}`. Pre-escaping here would double-escape and show an engineer
 * `&amp;` in a note about a contractor's name.
 *
 * Modelled on `app/Support/Cockpit/CockpitPanelPresenter.php`: pure reading,
 * derivation here, printing in Blade.
 */
final class SurveyCarryForward
{
    /**
     * The ten D-01 columns, keyed by the `SiteSurvey` column that drives the
     * row, mapped to the label an engineer reads, IN RENDER ORDER.
     *
     * Two of the ten rows are composed from a pair of columns and so appear
     * once here, under their primary column:
     *   comms_room_access_status  +  comms_room_access_notes
     *   distance_from_base_miles  +  distance_from_base_notes
     *
     * Order is arrival-first: how do I park, how do I get in, what stops me,
     * where do deliveries go — then the safety fields the surveyor recorded and
     * the installing engineer has never been shown.
     *
     * NOT PRESENT, DELIBERATELY: `office_review_notes`. See the class docblock.
     *
     * @var array<string, string>
     */
    public const FIELDS = [
        'parking_restraints'       => 'Parking arrangements',
        'site_access_notes'        => 'Site access notes',
        'access_constraints'       => 'Access constraints',
        'delivery_routes'          => 'Delivery routes',
        'comms_room_access_status' => 'Comms room access',
        'distance_from_base_miles' => 'Distance from depot',
        'site_risks'               => 'Site risks',
        'h_and_s_notes'            => 'Health and safety',
        'general_notes'            => "Surveyor's notes",
    ];

    /**
     * Moved here from the inline `$commsRoomLabels` map in
     * `resources/views/worksheets/public-show.blade.php` rather than copied:
     * two maps would disagree the first time a status is added, and the page
     * would then show one wording while any other reader showed another.
     *
     * @var array<string, string>
     */
    public const COMMS_ROOM_LABELS = [
        'yes'        => 'Permission required',
        'no'         => 'Free access',
        'outsourced' => 'Outsourced facilities team',
        'unknown'    => 'Status unknown',
    ];

    /**
     * The project's current survey findings, resolved LIVE, ready to print.
     *
     * Survey selection is unchanged from what the engineer link already did:
     * the newest survey for the project by id. Soft-deleted surveys are
     * excluded by the model's `SoftDeletes` global scope.
     *
     * @return list<array{key: string, label: string, value: string}>
     *         Only fields that have a value, in FIELDS order. `[]` when the
     *         project has no survey, or every carried field is empty — the
     *         caller then renders no drawer at all, exactly as it does today.
     */
    public static function forProject(?Project $project): array
    {
        if (! $project || ! $project->getKey()) {
            return [];
        }

        $survey = SiteSurvey::query()
            ->where('project_id', $project->getKey())
            ->latest('id')
            ->first();

        return self::forSurvey($survey);
    }

    /**
     * The same derivation, for a survey the caller has already resolved.
     *
     * The engineer link loads `$survey` once for its per-room drawer; handing
     * that instance straight in keeps the page at a single survey query
     * (T-46-03-05 — no second lookup on a field view used on flaky mobile
     * networks). It is still a read of the survey RECORD, not a copy of it:
     * the instance is resolved fresh on every request.
     *
     * @return list<array{key: string, label: string, value: string}>
     */
    public static function forSurvey(?SiteSurvey $survey): array
    {
        if (! $survey) {
            return [];
        }

        $rows = [];

        foreach (self::FIELDS as $key => $label) {
            $value = match ($key) {
                'comms_room_access_status' => self::commsRoomAccess($survey),
                'distance_from_base_miles' => self::distanceFromBase($survey),
                default                    => trim((string) ($survey->{$key} ?? '')),
            };

            if ($value === '') {
                continue;
            }

            $rows[] = [
                'key'   => $key,
                'label' => $label,
                'value' => $value,
            ];
        }

        return $rows;
    }

    /** "Permission required — keys held by reception", or either half alone. */
    private static function commsRoomAccess(SiteSurvey $survey): string
    {
        $status = trim((string) ($survey->comms_room_access_status ?? ''));
        $notes  = trim((string) ($survey->comms_room_access_notes ?? ''));

        return self::join([
            self::COMMS_ROOM_LABELS[$status] ?? '',
            $notes,
        ]);
    }

    /** "42 miles from depot — M4 J11 then 10 min", or either half alone. */
    private static function distanceFromBase(SiteSurvey $survey): string
    {
        $miles = trim((string) ($survey->distance_from_base_miles ?? ''));
        $notes = trim((string) ($survey->distance_from_base_notes ?? ''));

        return self::join([
            $miles === '' ? '' : $miles . ' miles from depot',
            $notes,
        ]);
    }

    /**
     * Join the present halves of a composed row. Filtering first is what stops
     * a dangling " — " when only one half was recorded.
     *
     * @param  list<string>  $parts
     */
    private static function join(array $parts): string
    {
        return implode(' — ', array_filter($parts, static fn (string $p): bool => $p !== ''));
    }
}
