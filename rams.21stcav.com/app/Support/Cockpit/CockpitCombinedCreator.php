<?php

namespace App\Support\Cockpit;

use App\Core\Modules\Projects\ProjectService;
use App\Models\LabourResource;
use App\Models\Project;
use App\Models\ProjectActivityLog;
use App\Models\ProjectDeliverable;
use App\Models\User;
use App\Models\Visit;
use App\Support\Visits\VisitLinkIssuer;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * CockpitCombinedCreator — ONE CREATION, ONE OUTCOME (Phase 46.5, Plan
 * 46.5-06; 46.5-CONTEXT.md D-07).
 *
 * The user's words: *"generate visit , engineer link , pdf/word version all
 * under a signl creation version."* Three separate acts become one. Both the
 * visit model and `VisitLinkIssuer` already exist (Phase 46) — THIS IS WIRING,
 * NOT NEW MACHINERY, and `VisitLinkIssuer` is NOT edited by it.
 *
 * ═══ THE ORDERING, DECIDED PER MODULE, AND WHAT A RETRY DOES ═══════════════
 *
 * ONE TRANSACTION. The document row, the field persistence, the visit and
 * `VisitLinkIssuer::issue()` all sit inside a single `DB::transaction`, exactly
 * as `ProjectCockpitActionController::storeVisit()` already does. A throw
 * anywhere rolls back all of it, so the answer to "which half happened?" is,
 * for everything transactional, NEITHER — and the PM is told so in those words
 * by `CockpitCreationOutcome::sentence()`. This was chosen over a
 * compensating-action design because there is nothing to compensate.
 *
 * ── site_survey: THE DOCUMENT GOES FIRST, AND THE ISSUER ADOPTS IT ────────
 *
 * Adopt-or-create the survey, persist its columns, THEN create the visit and
 * let `VisitLinkIssuer::surveyFor()` ADOPT THAT SAME SURVEY. It will:
 * `liveSurveyFor()` uses the same four clauses the controller's `activeSurvey()`
 * does, so the survey created moments earlier IN THIS TRANSACTION is the one it
 * finds. THERE IS NEVER A SECOND SURVEY.
 *
 *   A RETRY after a failure re-enters with NO survey (it rolled back) and
 *   creates one cleanly. A retry after a PARTIAL SUCCESS cannot happen —
 *   there is no partial success. A retry on a project that ALREADY had a live
 *   survey ADOPTS it, which is the existing, tested behaviour: survey count
 *   stays 1 and the token is the one that was already there.
 *
 * ── worksheet: ⚠ THE TRAP. THE VISIT GOES FIRST AND THE ISSUER'S ──────────
 *    WORKSHEET *IS* THE DOCUMENT.
 *
 * `VisitLinkIssuer::worksheetFor()` HAS NO ADOPTION — read it beside
 * `surveyFor()` and the asymmetry is the first thing visible. EVERY call
 * `Worksheet::create()`s a row and dispatches a `BuildWorksheetJob`. So a naive
 * "generate the document, then create the visit" for an install would produce
 * TWO WORKSHEETS AND TWO QUEUED AI BUILDS FROM ONE CLICK — a duplicate record
 * and duplicate model spend on the user's account.
 *
 *   THEREFORE: this class NEVER calls `WorksheetController::generateFromProject`
 *   for the worksheet module. The visit is created FIRST and the issuer
 *   produces the one and only worksheet. The worksheet module has ZERO
 *   PM-enterable fields (a finding, not an omission), so there is nothing to
 *   persist before the visit either.
 *   `CockpitCombinedCreationTest::test_one_install_creation_produces_exactly_one_worksheet()`
 *   asserts `Worksheet::where('project_id', …)->count() === 1` AND that exactly
 *   one build job was dispatched.
 *
 *   A RETRY after a failure re-enters with no worksheet (rolled back) and
 *   produces exactly one. A SECOND deliberate creation produces a SECOND
 *   worksheet, and that is the existing project-page behaviour, deliberately
 *   unchanged: a first-fix trip and an install trip are different visits with
 *   different sign-offs.
 *
 * ── rams / om: DOCUMENT ONLY. NO VISIT AND NO LINK (D-04) ─────────────────
 *
 * An install's link is the install's. This class is not called for them at all;
 * `ProjectCockpitDocumentController` keeps its existing path unchanged.
 *
 * ═══ THE ONE THING A TRANSACTION CANNOT HOLD ══════════════════════════════
 *
 * `BuildWorksheetJob::dispatch()` is QUEUED, and it is dispatched INSIDE the
 * transaction because it happens inside `VisitLinkIssuer::worksheetFor()`,
 * which this plan does not edit (that class's adoption rules are load-bearing
 * and already tested). THAT WAS A DELIBERATE CHOICE, NOT AN OVERSIGHT, and it
 * is bounded by two MEASURED facts rather than by hope:
 *
 *   1. LIVE RUNS `QUEUE_CONNECTION=database` (.env:39, config/queue.php:16).
 *      A dispatch on the database connection INSERTS a `jobs` row through the
 *      same connection, so it is inside this transaction too and ROLLS BACK
 *      WITH EVERYTHING ELSE.
 *   2. EVEN IF IT DID NOT, `BuildWorksheetJob::handle()` resolves the worksheet
 *      by id and RETURNS SILENTLY when the row is gone
 *      (`app/Jobs/BuildWorksheetJob.php:50-55`). A job that outran a rollback
 *      finds nothing and does nothing.
 *
 * So the outcome NEVER claims a file exists. It says the document was created
 * and its build is QUEUED, which is the truth and is what the existing panel
 * already reports.
 *
 * ═══ THE PERSISTENCE STAYS WHERE IT LIVES ═════════════════════════════════
 *
 * The document row and the field persistence arrive as CLOSURES from
 * `ProjectCockpitDocumentController`, which owns both and whose docblock says
 * so. This class owns the ORDERING and nothing else: one place to read what
 * happens when, one place to read where values go. Two copies of either would
 * be two rules.
 */
final class CockpitCombinedCreator
{
    /**
     * THE ORDERING, AS DATA, so the per-module ruling is readable rather than
     * inferred from a branch — and so a third module joining is a row.
     *
     * `document_first` is the whole of the worksheet trap: FALSE means the
     * issuer's own record IS the document and creating one first would be the
     * second one.
     *
     * @var array<string, array<string, mixed>>
     */
    private const ORDERING = [
        ProjectDeliverable::KEY_SITE_SURVEY => [
            'document_first' => true,
            'visit_type'     => Visit::TYPE_SITE_SURVEY,
            'label'          => 'site survey',
        ],
        ProjectDeliverable::KEY_WORKSHEET => [
            // FALSE — see the trap in this class's docblock. `worksheetFor()`
            // has no adoption, so the issuer's worksheet is the ONLY worksheet.
            'document_first' => false,
            // The module is the INSTALL. `VisitLinkIssuer::typesFor('worksheet')`
            // offers first_fix and install, and the wizard asks for neither, so
            // the module's own name decides rather than a guess.
            'visit_type'     => Visit::TYPE_INSTALL,
            'label'          => 'install',
        ],
    ];

    public function __construct(
        private readonly VisitLinkIssuer $issuer,
        private readonly ProjectService $projects,
    ) {
    }

    /** Whether this module creates a visit and a link at all. */
    public static function handles(string $module): bool
    {
        return array_key_exists($module, self::ORDERING);
    }

    /**
     * ONE POST -> document + visit + engineer link, or NOTHING AT ALL.
     *
     * Throws whatever the collaborators throw. The caller catches, `report()`s
     * it and builds the honest sentence from `CockpitCreationOutcome::rolledBack()`
     * — the exact shape `storeVisit()` uses.
     *
     * @param  array<string, mixed>  $validated
     * @param  Closure(): bool       $ensureDocument  true when THIS request created it
     * @param  Closure(): void       $persistFields
     */
    public function create(
        Project $project,
        string $module,
        array $validated,
        User $user,
        Closure $ensureDocument,
        Closure $persistFields,
    ): CockpitCreationOutcome {
        $ordering = self::ORDERING[$module];

        $documentState = CockpitCreationOutcome::DOCUMENT_CREATED;

        DB::transaction(function () use (
            $project, $module, $validated, $user, $ordering, $ensureDocument, $persistFields, &$documentState
        ): void {
            if ($ordering['document_first'] === true) {
                $documentState = $ensureDocument() === true
                    ? CockpitCreationOutcome::DOCUMENT_CREATED
                    : CockpitCreationOutcome::DOCUMENT_ADOPTED;

                $persistFields();
            }

            $visit = new Visit();

            $visit->fill([
                'project_id'          => $project->id,
                'type'                => $ordering['visit_type'],
                'status'              => Visit::STATUS_PLANNED,
                'scheduled_date'      => $this->visitValue($module, $validated, 'scheduled_date'),
                'rooms_in_scope'      => $this->rooms($module, $validated),
                'labour_resource_ids' => $this->labourResourceIds($module, $validated),
            ]);

            // A visit a PM created is NOT an inference — the Phase 45 backfill
            // is the only thing that may set this flag.
            $visit->is_backfilled = false;
            $visit->created_by_user_id ??= $user->id;

            $visit->save();

            // `storeVisit`'s sequence EXACTLY: save, issue, sent_at, save.
            // `sent` is a DERIVED state (`Visit::state()`), never a stored
            // status — growing the stored vocabulary would mean rewriting the
            // 24 reconstructed rows on live.
            $this->issuer->issue($visit, $user);
            $visit->sent_at = now();
            $visit->save();

            $this->projects->log(
                project:     $project,
                user:        $user,
                action:      ProjectActivityLog::ACTION_VISIT_CREATED,
                description: "{$user->name} created a {$ordering['label']} visit.",
                metadata:    ['visit_id' => $visit->id, 'visit_type' => $visit->type],
            );
        });

        return CockpitCreationOutcome::complete($documentState);
    }

    /**
     * WAS A DOCUMENT ALREADY THERE BEFORE THIS REQUEST?
     *
     * Asked BEFORE the transaction opens, so the failure sentence can tell the
     * PM whether the survey they can still see is one this request adopted (and
     * left exactly as it was) or one that never existed. Same reader the issuer
     * uses, so the two cannot disagree.
     */
    public function documentPreExists(Project $project, string $module): bool
    {
        return $module === ProjectDeliverable::KEY_SITE_SURVEY
            && $this->issuer->liveSurveyFor($project) !== null;
    }

    /**
     * A `visit.*` value off the map, resolved BY TARGET rather than by key, so
     * renaming a form field is a map edit and never a change here.
     *
     * @param  array<string, mixed>  $validated
     */
    private function visitValue(string $module, array $validated, string $leaf): mixed
    {
        foreach (CockpitDocumentFormPresenter::documentFieldMap()[$module]['groups'] as $group) {
            foreach ($group['fields'] as $field) {
                if ($field['target'] !== 'visit.'.$leaf) {
                    continue;
                }

                return $validated[$field['key']] ?? null;
            }
        }

        return null;
    }

    /**
     * THE TICKED SPACES, AND AN EMPTY TICK IS AN ANSWER.
     *
     * Ticking none stores an empty scope: a PM may genuinely not know yet, and
     * refusing the submission would invent a requirement nobody asked for. The
     * values are stored as `rooms_in_scope` JSON and never used to build a path
     * or a query identifier (T-46.5-06-02).
     *
     * @param  array<string, mixed>  $validated
     * @return array<int, string>
     */
    private function rooms(string $module, array $validated): array
    {
        $rooms = $this->visitValue($module, $validated, 'rooms_in_scope');

        return array_values(array_filter(
            array_map(static fn (mixed $room): string => trim((string) $room), is_array($rooms) ? $rooms : []),
            static fn (string $room): bool => $room !== '',
        ));
    }

    /**
     * THE TICKED ENGINEER NAMES, RESOLVED BACK TO IDS (LR-04, T-46.5-06-03).
     *
     * The page carries NAMES and nothing else — never an id, an email or a
     * phone — because that is what `resource-list` renders. So the names are
     * looked up against ACTIVE labour resources and ANYTHING MATCHING NONE IS
     * DROPPED. An unknown name therefore cannot become an id at all, which is a
     * stronger guarantee than an `exists` rule on an id the page never renders.
     *
     * @param  array<string, mixed>  $validated
     * @return array<int, int>
     */
    private function labourResourceIds(string $module, array $validated): array
    {
        $names = $this->visitValue($module, $validated, 'labour_resource_ids');
        $names = array_values(array_filter(
            array_map(static fn (mixed $name): string => trim((string) $name), is_array($names) ? $names : []),
            static fn (string $name): bool => $name !== '',
        ));

        if ($names === []) {
            return [];
        }

        return LabourResource::query()
            ->active()
            ->whereIn('name', $names)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }
}
