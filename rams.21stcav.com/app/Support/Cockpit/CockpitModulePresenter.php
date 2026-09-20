<?php

namespace App\Support\Cockpit;

use App\Models\InstallProgramme;
use App\Models\InstallTask;
use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\Visit;
use Illuminate\Support\Collection;

/**
 * CockpitModulePresenter — the module rows of the delivery cockpit
 * (Phase 45, Plan 45-10; sketch 004, D-10 / D-11 / D-16).
 *
 * PURE READING. Every value on a row either traces to a named accessor that
 * already exists, or is ABSENT. Nothing here derives a second opinion, and
 * nothing here writes — no touch(), no cache warm, no firstOrCreate().
 * `CockpitModulePresenterTest::test_deriving_modules_writes_nothing()` asserts
 * that over six tables.
 *
 * COMPOSITION, NOT EXTENSION. `CockpitSectionPresenter` already derives the
 * nine sections and their `pip` state; those derivations were hard-won in Plan
 * 45-07 and are correct. This class calls it and TRANSLATES its output into the
 * design's vocabulary. It does not subclass it, copy its derivations, or touch
 * `ProjectHealthService` — editing either would change what the dashboard
 * renders with the cockpit flag OFF, a ROADMAP criterion 4 violation.
 *
 * NINE ROWS, NOT EIGHT (D-16, user ruling 2026-09-20). The design image showed
 * eight module rows above a "1 of 9 complete" count. The unaccounted ninth is
 * snagging. The deciding argument was not the arithmetic: `Visit::TYPE_SNAG`
 * already exists, so with eight rows a snag visit would sit in the database and
 * appear on no screen — the exact "collected but never turned into work"
 * failure this milestone exists to fix. Nine rows also preserve the invariant
 * that EVERY VISIT TYPE REACHES EXACTLY ONE MODULE ROW, which
 * `test_every_visit_type_maps_to_exactly_one_module()` proves over
 * `Visit::TYPES` rather than over a hand-written list, so a seventh visit type
 * added later fails loudly instead of rendering nowhere.
 *
 * WHERE EACH COUNT COMES FROM — fixed per module by its count mode, never
 * guessed per render:
 *   - `visits`    — the section's own visit collection (site survey, first fix
 *                   and install, snagging).
 *   - `tasks`     — `InstallTask` rows under the project's install programmes.
 *   - `documents` — the project relation named in the map (`ramsDocuments`,
 *                   `drawings`, `omManuals`, `cableSchedules`).
 *   - `none`      — PROGRAMMING ONLY. `ProjectDeliverable.php:14-26` states
 *                   there is no Programming model, generator or storage type
 *                   and forbids building one. A "0 files" here would claim a
 *                   file store exists, so the row renders its chip alone and
 *                   its count phrase is the empty string.
 *
 * `icon` is a KEY, never inline SVG and never a hex — the Blade layer owns the
 * glyph and the token layer owns the colour.
 */
final class CockpitModulePresenter
{
    /** D-10 — the only three chip states the design admits. */
    public const CHIP_NOT_STARTED = 'not-started';

    public const CHIP_IN_PROGRESS = 'in-progress';

    public const CHIP_ON_FILE = 'on-file';

    /** Count modes — see the class docblock. */
    public const COUNT_VISITS = 'visits';

    public const COUNT_TASKS = 'tasks';

    public const COUNT_DOCUMENTS = 'documents';

    public const COUNT_NONE = 'none';

    /**
     * The chip is a pure TRANSLATION of the section's existing `pip`. It is a
     * lookup, deliberately, so that no second derivation can drift from the
     * one `CockpitSectionPresenter` already made. `null` (Programming, which
     * has no derivation at all) reads as not started.
     */
    private const CHIP_FROM_PIP = [
        'waiting'   => self::CHIP_NOT_STARTED,
        'attention' => self::CHIP_IN_PROGRESS,
        'done'      => self::CHIP_ON_FILE,
    ];

    /**
     * The nine module rows, in D-11's order as amended by D-16 (Snagging last).
     *
     * This is a CONST MAP rather than a `match`, so a test can iterate it as
     * data — which is what makes the "every visit type reaches exactly one
     * module" invariant provable rather than sampleable.
     *
     * Descriptions are taken verbatim from the design image. They are labels,
     * not claims about data, so they are safe to copy as written. Snagging's is
     * written here because the design image had no ninth row to copy from.
     *
     * @var array<string, array<string, mixed>>
     */
    private const MODULE_MAP = [
        ProjectDeliverable::KEY_SITE_SURVEY => [
            'title'       => 'Site survey',
            'description' => 'Manage site surveys and outputs.',
            'icon'        => 'clipboard',
            'count_mode'  => self::COUNT_VISITS,
            'relation'    => null,
            'visit_types' => [Visit::TYPE_SITE_SURVEY],
        ],
        ProjectDeliverable::KEY_WORKSHEET => [
            'title'       => 'First fix and install',
            'description' => 'Manage visits, tasks and evidence.',
            'icon'        => 'wrench',
            'count_mode'  => self::COUNT_VISITS,
            'relation'    => null,
            'visit_types' => [Visit::TYPE_FIRST_FIX, Visit::TYPE_INSTALL],
        ],
        ProjectDeliverable::KEY_INSTALL_PROGRAMME => [
            'title'       => 'Programme and commissioning',
            'description' => 'Plan activities and capture test results.',
            'icon'        => 'calendar',
            'count_mode'  => self::COUNT_TASKS,
            'relation'    => null,
            'visit_types' => [Visit::TYPE_COMMISSIONING],
        ],
        ProjectDeliverable::KEY_RAMS => [
            'title'       => 'RAMS',
            'description' => 'Method statements and risk assessments.',
            'icon'        => 'shield',
            'count_mode'  => self::COUNT_DOCUMENTS,
            'relation'    => 'ramsDocuments',
            'visit_types' => [],
        ],
        ProjectDeliverable::KEY_DRAWINGS => [
            'title'       => 'Drawings',
            'description' => 'Designs, elevations and connection diagrams.',
            'icon'        => 'ruler',
            'count_mode'  => self::COUNT_DOCUMENTS,
            'relation'    => 'drawings',
            'visit_types' => [],
        ],
        ProjectDeliverable::KEY_OM => [
            'title'       => 'O&M manual',
            'description' => 'Operation and maintenance documentation.',
            'icon'        => 'book',
            'count_mode'  => self::COUNT_DOCUMENTS,
            'relation'    => 'omManuals',
            'visit_types' => [],
        ],
        ProjectDeliverable::KEY_CABLE_SCHEDULE => [
            'title'       => 'Cable schedule',
            'description' => 'Cable schedules and infrastructure details.',
            'icon'        => 'cable',
            'count_mode'  => self::COUNT_DOCUMENTS,
            'relation'    => 'cableSchedules',
            'visit_types' => [],
        ],
        ProjectDeliverable::KEY_PROGRAMMING => [
            'title'       => 'Programming',
            'description' => 'Control system programming files and notes.',
            'icon'        => 'gear',
            'count_mode'  => self::COUNT_NONE,
            'relation'    => null,
            'visit_types' => [Visit::TYPE_PROGRAMMING],
        ],
        ProjectDeliverable::KEY_SNAGGING => [
            'title'       => 'Snagging',
            'description' => 'Return visits to clear outstanding snags.',
            'icon'        => 'flag',
            'count_mode'  => self::COUNT_VISITS,
            'relation'    => null,
            'visit_types' => [Visit::TYPE_SNAG],
        ],
    ];

    public function __construct(
        private CockpitSectionPresenter $sections,
    ) {
    }

    /**
     * The module map as data, so tests can iterate it rather than sample it.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function moduleMap(): array
    {
        return self::MODULE_MAP;
    }

    /**
     * The nine module rows the cockpit renders, in design order.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function modules(Project $project): Collection
    {
        $sections = $this->sections->sections($project)->keyBy('key');

        return collect(self::MODULE_MAP)
            ->map(function (array $definition, string $key) use ($project, $sections): array {
                /** @var array<string, mixed> $section */
                $section = $sections->get($key, []);

                return [
                    'key'         => $key,
                    'title'       => $definition['title'],
                    'description' => $definition['description'],
                    'icon'        => $definition['icon'],
                    'chip'        => $this->chip($section),
                    'count'       => $this->count($project, $definition, $section),
                    'section'     => $section,
                ];
            })
            ->values();
    }

    /**
     * Visit progress for one module.
     *
     * Returns NULL when the module has no visits at all, so the panel can omit
     * the ring rather than draw a 0-of-0 circle — which reads as "nothing done"
     * when the truth is "nothing planned". An unknown key is also null; this
     * presenter never invents a module.
     *
     * @return array{completed: int, total: int, percent: int}|null
     */
    public function progress(Project $project, string $moduleKey): ?array
    {
        $definition = self::MODULE_MAP[$moduleKey] ?? null;

        if ($definition === null) {
            return null;
        }

        $visits = $this->visitsFor($project, $definition);
        $total  = $visits->count();

        if ($total === 0) {
            return null;
        }

        $completed = $visits
            ->filter(fn (Visit $visit): bool => $visit->status === Visit::STATUS_COMPLETED)
            ->count();

        return [
            'completed' => $completed,
            'total'     => $total,
            'percent'   => (int) floor(($completed / $total) * 100),
        ];
    }

    // -- Translation ---------------------------------------------------------

    /**
     * @param  array<string, mixed>  $section
     */
    private function chip(array $section): string
    {
        $pip = $section['pip'] ?? null;

        return self::CHIP_FROM_PIP[$pip] ?? self::CHIP_NOT_STARTED;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @param  array<string, mixed>  $section
     */
    private function count(Project $project, array $definition, array $section): string
    {
        // Programming has no store, so it has no count phrase. Checked BEFORE
        // the not-required branch: a phrase there would still imply a store.
        if ($definition['count_mode'] === self::COUNT_NONE) {
            return '';
        }

        // A not-required module still renders — a missing row would read as an
        // app fault. It says why its count is empty rather than showing a zero
        // that looks like neglect. This mirrors what CockpitSectionPresenter
        // already decided; it is not a second opinion.
        if (($section['not_required'] ?? false) === true) {
            return 'Not required';
        }

        return match ($definition['count_mode']) {
            self::COUNT_VISITS    => $this->phrase($this->visitsFromSection($section), 'visit', 'visits'),
            self::COUNT_TASKS     => $this->phrase($this->taskCount($project), 'task', 'tasks'),
            self::COUNT_DOCUMENTS => $this->phrase($this->documentCount($project, $definition), 'document', 'documents'),
            default               => '',
        };
    }

    private function phrase(int $n, string $singular, string $plural): string
    {
        return $n === 1 ? '1 '.$singular : $n.' '.$plural;
    }

    // -- Sources -------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $section
     */
    private function visitsFromSection(array $section): int
    {
        $visits = $section['visits'] ?? null;

        return $visits instanceof Collection ? $visits->count() : 0;
    }

    /**
     * @param  array<string, mixed>  $definition
     * @return Collection<int, Visit>
     */
    private function visitsFor(Project $project, array $definition): Collection
    {
        $types = $definition['visit_types'];

        if ($types === []) {
            return collect();
        }

        return $project->visits
            ->filter(fn (Visit $visit): bool => in_array($visit->type, $types, true))
            ->values();
    }

    /**
     * Install tasks across the project's install programmes. Counted in one
     * query against the programme ids the project already holds, so a project
     * with several programmes does not fan out.
     */
    private function taskCount(Project $project): int
    {
        $programmeIds = $project->installProgrammes
            ->map(fn (InstallProgramme $programme): int => $programme->id)
            ->all();

        if ($programmeIds === []) {
            return 0;
        }

        return InstallTask::whereIn('install_programme_id', $programmeIds)->count();
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function documentCount(Project $project, array $definition): int
    {
        $relation = $definition['relation'];

        if ($relation === null) {
            return 0;
        }

        return $project->{$relation}->count();
    }
}
