<?php

namespace App\Support\Cockpit;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\Worksheet;
use App\Support\Visits\VisitLinkIssuer;

/**
 * CockpitLinkPresenter — the engineer link and its state, read-only (Phase 47,
 * Plan 47-01; 47-CONTEXT D-01 remainder / D-02).
 *
 * TWO MODULES, AND ONLY TWO, BECAUSE `VisitLinkIssuer::moduleKeys()` SAYS SO.
 * `linkFor()` never hand-writes `in_array(['site_survey', 'worksheet'], ...)`
 * — it iterates the issuer's own module list, so a module losing its link (or
 * a third module gaining one) is picked up here the day `VISIT_MODULES`
 * changes, with no second list to drift.
 *
 * "THE CURRENT LINK" MEANS TWO DIFFERENT THINGS, AND THAT IS DELIBERATE:
 *
 *   - site_survey: ADOPTION. `VisitLinkIssuer::liveSurveyFor()` — the SAME
 *     four clauses `SiteSurveyController::createFromProject()` and
 *     `CockpitCombinedCreator` use — so this card can never name a different
 *     survey than the one a new visit would adopt.
 *   - worksheet: MOST-RECENT-CREATED. There is no adoption concept for
 *     worksheets (`VisitLinkIssuer`'s own docblock, lines 151-160 — a
 *     first-fix visit and an install visit are different trips with
 *     different sign-offs, so each gets its own worksheet). The ordering is
 *     the SAME `created_at`-desc convention `CockpitPanelPresenter::files()`
 *     already uses for "the current worksheet", read here rather than
 *     copied into a second query shape.
 *
 * PURE READING. No `touch()`, no `firstOrCreate()`, no cache warm, no token
 * minted, rotated or mass-assigned. `SiteSurvey::boot()` and
 * `Worksheet::boot()` assign `access_token` by direct property assignment,
 * bypassing `$fillable`, and this class never writes to either model — it
 * calls `publicUrl()` and reads plain columns. Calling `linkFor()` twice in a
 * row against an unchanged record returns identical arrays; nothing here is
 * memoised because nothing needs to be.
 */
final class CockpitLinkPresenter
{
    public function __construct(
        private readonly VisitLinkIssuer $issuer,
    ) {
    }

    /**
     * The current link and its state for one module, or null when the
     * module carries no link at all (rams, om) or holds no document yet.
     *
     * @return array{url: string, state: string, can_revoke: bool, revoke_target: Worksheet|null}|null
     */
    public function linkFor(Project $project, string $moduleKey): ?array
    {
        if (! in_array($moduleKey, VisitLinkIssuer::moduleKeys(), true)) {
            return null;
        }

        $source = match ($moduleKey) {
            'site_survey' => $this->issuer->liveSurveyFor($project),
            'worksheet'   => $project->worksheets->sortByDesc(fn (Worksheet $worksheet) => $worksheet->created_at)->first(),
            default       => null,
        };

        if ($source === null) {
            return null;
        }

        if ($source instanceof SiteSurvey) {
            return [
                'url'           => $source->publicUrl(),
                'state'         => $this->stateForSurvey($source),
                'can_revoke'    => false,
                'revoke_target' => null,
            ];
        }

        /** @var Worksheet $source */
        return [
            'url'           => $source->publicUrl(),
            'state'         => $this->stateForWorksheet($source),
            'can_revoke'    => true,
            'revoke_target' => $source,
        ];
    }

    /**
     * Site survey state vocabulary (47-01's `<link_state_vocabulary>`). No
     * "opened" tracking exists anywhere in this schema and none is invented
     * here.
     */
    private function stateForSurvey(SiteSurvey $survey): string
    {
        if ($survey->isTokenExpired()) {
            return 'Link expired';
        }

        if ($survey->isSubmitted()) {
            return 'Submitted '.$survey->submitted_at->format('d M Y');
        }

        return 'Issued — awaiting the engineer';
    }

    /**
     * Worksheet state vocabulary. Reads `latestSignoff()->client_name` and
     * `->signed_at` only, exactly as `CockpitEvidencePresenter::signoff()`
     * already exposes them — never `ip_address` or `user_agent` (the same
     * ban that applies to `worksheet_signoffs.ip_address` / `::user_agent`
     * elsewhere in this codebase).
     */
    private function stateForWorksheet(Worksheet $worksheet): string
    {
        if ($worksheet->isTokenExpired()) {
            return 'Link expired';
        }

        $signoff = $worksheet->latestSignoff();

        if ($signoff !== null) {
            return 'Signed '.$signoff->signed_at->format('d M Y').' by '.$signoff->client_name;
        }

        return $worksheet->statusLabel();
    }
}
