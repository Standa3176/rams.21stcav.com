<?php

namespace App\Support\Cockpit;

use App\Models\InstallProgramme;
use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\SiteSurvey;
use App\Models\Visit;
use App\Models\Worksheet;
use Illuminate\Support\Collection;

/**
 * CockpitSectionPresenter — the nine drawer rows of the read-only cockpit
 * (Phase 45, Plan 45-07).
 *
 * THIS CLASS DELIBERATELY DOES NOT EXTEND, SUBCLASS OR MODIFY
 * ProjectHealthService. That service is the DASHBOARD's derivation
 * (DashboardController:28,:59) and returns ONE status for the whole project
 * (app/DTO/ProjectHealth.php:17-24). Changing it would change what the
 * dashboard renders with the cockpit flag OFF, which is a ROADMAP criterion 4
 * violation. It is reused unchanged for the masthead/attention line only, and
 * Plan 45-06 already wired that. Per-drawer state comes from the existing
 * named model accessors cited against each section below — no new query
 * source, no new derivation, no new capture (criterion 5).
 *
 * The nine sections ARE ProjectDeliverable::ALL_KEYS, documented at
 * ProjectDeliverable.php:14 as "the single canonical nine-item deliverable
 * vocabulary". Using that list rather than inventing one keeps the cockpit and
 * the deliverables selection screen talking about the same nine things.
 *
 * PURE READING. No write, no cache warm, no touch(). Rendering the cockpit
 * must leave every table's row count unchanged — CockpitReadOnlyFenceTest
 * asserts exactly that over five tables.
 */
final class CockpitSectionPresenter
{
    /**
     * Group keys, in the fixed order Plan 45-06 set in the page shell.
     */
    public const GROUP_VISITS = 'visits';

    public const GROUP_DOCUMENTS = 'documents';

    public const GROUP_REFERENCE = 'reference';

    /**
     * Body kinds — which row component a drawer's body uses.
     */
    public const KIND_VISITS = 'visits';

    public const KIND_DOCS = 'docs';

    public const KIND_LIFECYCLE = 'lifecycle';

    /**
     * The nine sections, in render order.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function sections(Project $project): Collection
    {
        return collect([
            $this->siteSurvey($project),
            $this->worksheet($project),
            $this->installProgramme($project),
            $this->snagging($project),
            $this->rams($project),
            $this->drawings($project),
            $this->omManual($project),
            $this->cableSchedule($project),
            $this->programming($project),
        ]);
    }

    /**
     * True when nothing at all has been recorded against the project, so the
     * page can show its empty-state copy above a spine of waiting drawers.
     */
    public function isEmpty(Project $project): bool
    {
        return $project->visits->isEmpty()
            && $project->siteSurveys->isEmpty()
            && $project->worksheets->isEmpty()
            && $project->installProgrammes->isEmpty()
            && $project->ramsDocuments->isEmpty()
            && $project->drawings->isEmpty()
            && $project->omManuals->isEmpty()
            && $project->cableSchedules->isEmpty();
    }

    // -- Visits group --------------------------------------------------------

    /**
     * Site survey — SiteSurvey::isDraft() / isCompleted() (SiteSurvey.php:112-120).
     *
     * @return array<string, mixed>
     */
    private function siteSurvey(Project $project): array
    {
        $visits  = $this->visitsOfType($project, [Visit::TYPE_SITE_SURVEY]);
        $surveys = $project->siteSurveys;

        $pip    = 'waiting';
        $status = 'Not started';

        if ($surveys->contains(fn (SiteSurvey $s) => $s->isCompleted())) {
            $pip    = 'done';
            $status = 'Submitted';
        } elseif ($surveys->contains(fn (SiteSurvey $s) => $s->isDraft())) {
            $status = 'In progress';
        }

        return $this->section(
            key: ProjectDeliverable::KEY_SITE_SURVEY,
            group: self::GROUP_VISITS,
            title: 'Site survey',
            pip: $pip,
            status: $status,
            count: $this->visitDisclosure($visits),
            hint: 'The survey visit, read from the site survey record the app already holds.',
            project: $project,
            kind: self::KIND_VISITS,
            visits: $visits,
        );
    }

    /**
     * First fix and install — Worksheet::isSigned() (:177), isReadyForSignoff()
     * (:420), hasEngineerActivity() (:375).
     *
     * @return array<string, mixed>
     */
    private function worksheet(Project $project): array
    {
        $visits     = $this->visitsOfType($project, [Visit::TYPE_FIRST_FIX, Visit::TYPE_INSTALL]);
        $worksheets = $project->worksheets;

        $pip       = 'waiting';
        $status    = 'Not started';
        $attention = false;

        if ($worksheets->contains(fn (Worksheet $w) => $w->isSigned())) {
            $pip    = 'done';
            $status = 'Signed';
        } elseif ($worksheets->contains(fn (Worksheet $w) => $w->isReadyForSignoff())) {
            $pip       = 'attention';
            $status    = 'Ready for signing';
            $attention = true;
        } elseif ($worksheets->contains(fn (Worksheet $w) => $w->hasEngineerActivity())) {
            $status = 'In progress';
        }

        return $this->section(
            key: ProjectDeliverable::KEY_WORKSHEET,
            group: self::GROUP_VISITS,
            title: 'First fix and install',
            pip: $pip,
            status: $status,
            count: $this->visitDisclosure($visits),
            hint: 'Install visits, read from the worksheets signed on site.',
            project: $project,
            kind: self::KIND_VISITS,
            visits: $visits,
            attention: $attention,
        );
    }

    /**
     * Programme and commissioning — InstallProgramme::statusLabel() (:120),
     * isDraft() (:148), isActive() (:156), commissioningSignoff() (:110).
     *
     * @return array<string, mixed>
     */
    private function installProgramme(Project $project): array
    {
        $visits    = $this->visitsOfType($project, [Visit::TYPE_COMMISSIONING]);
        $programme = $project->installProgrammes->first();

        $pip    = 'waiting';
        $status = 'Not started';
        $rows   = [];

        if ($programme instanceof InstallProgramme) {
            $status = $programme->statusLabel();
            $pip    = $programme->isDraft() ? 'waiting' : 'done';

            $signedOff = $programme->commissioningSignoff()->exists();

            $rows[] = [
                'name'  => 'Programme',
                'value' => $programme->statusLabel(),
            ];
            $rows[] = [
                'name'  => 'Commissioning sign-off',
                'value' => $signedOff ? 'Signed off' : 'Not signed off',
            ];

            if ($programme->isActive() && $signedOff) {
                $status = 'Signed off';
                $pip    = 'done';
            }
        }

        return $this->section(
            key: ProjectDeliverable::KEY_INSTALL_PROGRAMME,
            group: self::GROUP_VISITS,
            title: 'Programme and commissioning',
            pip: $pip,
            status: $status,
            count: $this->visitDisclosure($visits),
            hint: 'The delivery programme and its commissioning sign-off, read from the install record.',
            project: $project,
            kind: self::KIND_VISITS,
            visits: $visits,
            rows: $rows,
        );
    }

    /**
     * Snagging — Project::snaggingSignoffs() (Project.php:433-441). Its own
     * docblock at :418-432 says there is no Snagging model and no
     * Project::snagging() shortcut; do not look for one or add one.
     *
     * @return array<string, mixed>
     */
    private function snagging(Project $project): array
    {
        $visits   = $this->visitsOfType($project, [Visit::TYPE_SNAG]);
        $signoffs = $project->snaggingSignoffs()->count();

        return $this->section(
            key: ProjectDeliverable::KEY_SNAGGING,
            group: self::GROUP_VISITS,
            title: 'Snagging',
            pip: $signoffs > 0 ? 'done' : 'waiting',
            status: $signoffs > 0 ? 'Recorded' : 'Not started',
            count: $this->visitDisclosure($visits),
            hint: 'Return visits to clear snags, read from the commissioning sign-off records.',
            project: $project,
            kind: self::KIND_VISITS,
            visits: $visits,
        );
    }

    // -- Documents group -----------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function rams(Project $project): array
    {
        $docs = $project->ramsDocuments;

        return $this->section(
            key: ProjectDeliverable::KEY_RAMS,
            group: self::GROUP_DOCUMENTS,
            title: 'RAMS',
            pip: $docs->isNotEmpty() ? 'done' : 'waiting',
            status: $docs->isNotEmpty() ? 'On file' : 'Not started',
            count: $this->plainCount($docs->count(), 'document', 'documents'),
            hint: 'Risk assessments and method statements produced for this job.',
            project: $project,
            kind: self::KIND_DOCS,
            rows: $docs->map(fn ($doc) => [
                'name'  => $doc->filename ?: 'RAMS document',
                'value' => $this->label($doc->status),
            ])->all(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function drawings(Project $project): array
    {
        $drawings = $project->drawings;
        $sent     = $drawings->filter(fn ($drawing) => $drawing->completion_email_sent_at !== null);

        return $this->section(
            key: ProjectDeliverable::KEY_DRAWINGS,
            group: self::GROUP_DOCUMENTS,
            title: 'Drawings',
            pip: $drawings->isNotEmpty() ? 'done' : 'waiting',
            status: $drawings->isNotEmpty() ? 'On file' : 'Not started',
            count: $this->plainCount($drawings->count(), 'drawing', 'drawings'),
            hint: 'Schematics, rack elevations and floor plans drawn for this job.',
            project: $project,
            kind: self::KIND_LIFECYCLE,
            rows: [
                [
                    'name'  => 'Drawn',
                    'value' => $drawings->isNotEmpty()
                        ? $this->plainCount($drawings->count(), 'drawing', 'drawings')
                        : 'Not drawn',
                    'done'  => $drawings->isNotEmpty(),
                ],
                [
                    'name'  => 'Sent to the client',
                    'value' => $sent->isNotEmpty()
                        ? $this->plainCount($sent->count(), 'drawing', 'drawings')
                        : 'Not sent',
                    'done'  => $sent->isNotEmpty(),
                ],
            ],
        );
    }

    /**
     * The sketch's full-versus-mini select renders as STATIC TEXT of the
     * current selection. There is no form control of any kind on this page.
     *
     * @return array<string, mixed>
     */
    private function omManual(Project $project): array
    {
        $manuals = $project->omManuals;

        return $this->section(
            key: ProjectDeliverable::KEY_OM,
            group: self::GROUP_DOCUMENTS,
            title: 'O&M manual',
            pip: $manuals->isNotEmpty() ? 'done' : 'waiting',
            status: $manuals->isNotEmpty() ? 'On file' : 'Not started',
            count: $this->plainCount($manuals->count(), 'manual', 'manuals'),
            hint: 'The operation and maintenance manual handed over at the end of the job.',
            project: $project,
            kind: self::KIND_DOCS,
            rows: $manuals->map(fn ($manual) => [
                'name'  => 'Full O&M manual',
                'value' => $manual->filename ?: $this->label($manual->status),
            ])->all(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function cableSchedule(Project $project): array
    {
        $schedules = $project->cableSchedules;

        return $this->section(
            key: ProjectDeliverable::KEY_CABLE_SCHEDULE,
            group: self::GROUP_DOCUMENTS,
            title: 'Cable schedule',
            pip: $schedules->isNotEmpty() ? 'done' : 'waiting',
            status: $schedules->isNotEmpty() ? 'On file' : 'Not started',
            count: $this->plainCount($schedules->count(), 'schedule', 'schedules'),
            hint: 'The cable schedule the engineers work to on site.',
            project: $project,
            kind: self::KIND_DOCS,
            rows: $schedules->map(fn ($schedule) => [
                'name'  => $schedule->source_filename ?: 'Cable schedule',
                'value' => $this->label($schedule->status),
            ])->all(),
        );
    }

    // -- Reference group -----------------------------------------------------

    /**
     * Programming — NO derivation exists. ProjectHealthService.php:105-107 says
     * it verbatim: "Programming (KEY_PROGRAMMING) is skipped entirely: no
     * model, table, or relation exists for it anywhere in this codebase".
     *
     * So this section carries a BOX, not a light (pip => null), and PHASE 45
     * RENDERS THE UNTICKED STATE ONLY — the tick would need somewhere to store
     * who ticked it and when, and criterion 5 forbids new writes. The body copy
     * therefore drops its final "Ticked by ..." sentence.
     *
     * @return array<string, mixed>
     */
    private function programming(Project $project): array
    {
        $visits = $this->visitsOfType($project, [Visit::TYPE_PROGRAMMING]);

        return $this->section(
            key: ProjectDeliverable::KEY_PROGRAMMING,
            group: self::GROUP_REFERENCE,
            title: 'Programming',
            pip: null,
            status: 'Not marked',
            count: $this->visitDisclosure($visits),
            hint: 'Nothing in the system can evidence this one, so it is a box someone ticks by hand.',
            project: $project,
            kind: self::KIND_VISITS,
            visits: $visits,
        );
    }

    // -- Shared assembly -----------------------------------------------------

    /**
     * @param  Collection<int, Visit>|null  $visits
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function section(
        string $key,
        string $group,
        string $title,
        ?string $pip,
        string $status,
        string $count,
        string $hint,
        Project $project,
        string $kind,
        ?Collection $visits = null,
        array $rows = [],
        bool $attention = false,
    ): array {
        $notRequired = $project->deliverableState($key) === ProjectDeliverable::STATE_NOT_REQUIRED;

        // A not-required section still renders — a missing drawer would read as
        // an app fault (Layout Contract 7). It recedes by its text and its chip,
        // never by opacity on an element carrying small text.
        if ($notRequired) {
            $status    = 'Not required';
            $attention = false;
            $pip       = $pip === null ? null : 'waiting';
        }

        return [
            'key'          => $key,
            'group'        => $group,
            'title'        => $title,
            'pip'          => $pip,
            'ticked'       => false,
            'status'       => $status,
            'count'        => $count,
            'hint'         => $hint,
            'kind'         => $kind,
            'visits'       => $visits ?? collect(),
            'rows'         => $rows,
            'attention'    => $attention,
            'not_required' => $notRequired,
        ];
    }

    /**
     * Visits of the given types, in date order. Superseded visits are NEVER
     * filtered out (D-04) — they are marked in place by the row component.
     *
     * @param  array<int, string>  $types
     * @return Collection<int, Visit>
     */
    private function visitsOfType(Project $project, array $types): Collection
    {
        return $project->visits
            ->filter(fn (Visit $visit) => in_array($visit->type, $types, true))
            ->values();
    }

    /**
     * The count-slot disclosure. Drawers are closed at rest, so this slot is
     * the ONLY place a reconstructed or superseded visit is visible to a PM who
     * opens nothing. Omitting it would mislead by omission — the precise
     * failure D-02 exists to prevent.
     *
     *   "3 visits · 2 reconstructed" · "2 visits · 1 superseded"
     *   singular drops the numeral: "1 visit · reconstructed"
     *
     * Neither qualifier ever drives the drawer's pip or its status text: gold
     * means someone must act, and neither can be actioned on a read-only page.
     *
     * @param  Collection<int, Visit>  $visits
     */
    private function visitDisclosure(Collection $visits): string
    {
        if ($visits->isEmpty()) {
            return 'none yet';
        }

        $total  = $visits->count();
        $phrase = $total === 1 ? '1 visit' : $total.' visits';

        $qualifiers = self::visitQualifiers($visits);

        return $qualifiers === '' ? $phrase : $phrase.' · '.$qualifiers;
    }

    /**
     * THE AT-REST QUALIFIER PHRASE — D-02 and D-04, in one place.
     *
     *   "reconstructed" · "2 reconstructed" · "1 superseded"
     *   "2 reconstructed · 1 superseded" when a module carries both
     *   singular drops the numeral, so a lone one reads as a word not a sum
     *
     * PUBLIC AND STATIC BECAUSE CockpitModulePresenter NEEDS THE SAME WORDS.
     * Sketch 004 replaced the accordion, and with it the <summary> slot this
     * phrase used to live in. The new at-rest slot is the module row's count
     * phrase, which Plan 45-10 built by re-counting visits and therefore
     * printed a bare "1 visit" — dropping the disclosure. A PM who opens
     * nothing must still learn that a visit is inferred (D-02) or superseded
     * (D-04); Plan 45-13 restored that by having the module presenter call
     * THIS method rather than write a second copy of these rules, so the two
     * presenters can never disagree about the same visits.
     *
     * Returns the empty string when no visit carries a qualifier, so the
     * caller appends nothing rather than a dangling separator.
     *
     * @param  Collection<int, Visit>  $visits
     */
    public static function visitQualifiers(Collection $visits): string
    {
        $parts = [];

        $reconstructed = $visits->filter(fn (Visit $visit) => $visit->isBackfilled())->count();
        $superseded    = $visits->filter(fn (Visit $visit) => $visit->isSuperseded())->count();

        if ($reconstructed > 0) {
            $parts[] = $reconstructed === 1 ? 'reconstructed' : $reconstructed.' reconstructed';
        }

        if ($superseded > 0) {
            $parts[] = $superseded === 1 ? 'superseded' : $superseded.' superseded';
        }

        return implode(' · ', $parts);
    }

    private function plainCount(int $n, string $singular, string $plural): string
    {
        if ($n === 0) {
            return 'none yet';
        }

        return $n === 1 ? '1 '.$singular : $n.' '.$plural;
    }

    /**
     * A model status column rendered as plain English. Never the word "Error"
     * in user-facing copy (Copywriting Contract).
     */
    private function label(?string $status): string
    {
        if ($status === null || $status === '') {
            return 'On file';
        }

        if (str_contains($status, 'fail') || str_contains($status, 'error')) {
            return 'Could not be produced';
        }

        return ucfirst(str_replace('_', ' ', $status));
    }
}
