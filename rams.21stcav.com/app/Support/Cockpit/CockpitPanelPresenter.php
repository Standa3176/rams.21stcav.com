<?php

namespace App\Support\Cockpit;

use App\Models\Project;
use App\Models\ProjectActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;

/**
 * CockpitPanelPresenter — the side panel's Files tab, Notes tab and Recent
 * activity feed (Phase 45, Plan 45-12; sketch 004, D-13 and D-14).
 *
 * PURE READING. Nothing here writes: no touch(), no firstOrCreate(), no cache
 * warm. `test_calling_the_three_methods_writes_nothing()` asserts that over
 * six tables.
 *
 * ── THREE DECISIONS RECORDED SO THEY ARE NOT RE-LITIGATED ────────────────
 *
 * 1. THE ACTIVITY FEED IS PROJECT-WIDE, NOT MODULE-FILTERED.
 *    `ProjectActivityLog` carries project_id, user_id, action, from_status,
 *    to_status, description, metadata and created_at — and NO module column.
 *    A module could only be guessed from `metadata` keys, and every entry that
 *    carries no such key would then vanish from the feed silently: the feed
 *    would look complete and be wrong, which is worse than a feed that is
 *    obviously broad. `activity()` therefore takes no module parameter at all,
 *    so the filtering is not merely absent but inexpressible. A unit test pins
 *    the signature and a feature test pins the identical rendered feed under
 *    every `?module=`.
 *
 * 2. A LINK-LESS FILE ROW IS CORRECT, AND IS NEVER A DROPPED ROW.
 *    D-13 is the user's own example of what this panel is for: every created
 *    project document in one place. A document that EXISTS and is not listed
 *    is the failure this tab exists to prevent. A document listed without a
 *    link is merely less useful. So `route` is nullable and the row survives:
 *    every `route()` call is guarded by `Route::has()`, so a renamed or
 *    removed route degrades to a plain row instead of throwing a
 *    RouteNotFoundException that would blank the whole page (T-45-12-03).
 *    The mapped names are asserted to exist by
 *    `test_every_mapped_view_route_name_exists()`, so a rename is caught by a
 *    red test rather than by a PM meeting a page with no links.
 *
 * 3. `Project::notes` IS NOT A NOTES FALLBACK.
 *    It is a PROJECT-level field. Using it when a module has no notes of its
 *    own would print the same paragraph under all nine modules and read as
 *    nine notes where there is one. A module with no note field and no
 *    note_added log entry has no notes, and the tab says so in one sentence.
 *
 * COPY: statuses are plain English and never contain the word "Error" — a
 * failed document reads "Could not be produced", the wording settled in Plan
 * 45-07 (CockpitSectionPresenter::label()). The only link copy this panel
 * uses is "View"; "Download" is Phase 48 copy and is banned by the read-only
 * fence.
 */
final class CockpitPanelPresenter
{
    /**
     * The document library, per module (D-13).
     *
     * A CONST MAP rather than a `match`, so tests iterate it as data instead
     * of sampling it — that is what makes "every mapped route still exists"
     * provable.
     *
     * `name_fields` is tried in order; the first non-empty value wins and the
     * `label` is the fallback, exactly as CockpitSectionPresenter already does
     * for the same models ("RAMS document", "Cable schedule").
     *
     * `route` / `route_params` were re-verified against `artisan route:list`
     * on 2026-09-20. All six are GET routes behind the `auth` middleware, so
     * each enforces its own authorisation; the panel adds no new read path and
     * exposes no id the project page does not already expose (T-45-12-02).
     *
     * `install_programme`, `programming` and `snagging` are ABSENT on purpose:
     * no document relation exists for them anywhere in this codebase, so their
     * file list is empty rather than invented.
     *
     * @var array<string, array<string, mixed>>
     */
    private const DOCUMENTS = [
        'site_survey' => [
            'relation'     => 'siteSurveys',
            'label'        => 'Site survey',
            'name_fields'  => ['filename'],
            'route'        => 'site-surveys.show',
            'route_params' => ['document'],
        ],
        'worksheet' => [
            'relation'     => 'worksheets',
            'label'        => 'Worksheet',
            'name_fields'  => ['filename'],
            'route'        => 'worksheets.show',
            'route_params' => ['document'],
        ],
        'rams' => [
            'relation'     => 'ramsDocuments',
            'label'        => 'RAMS document',
            'name_fields'  => ['filename'],
            'route'        => 'rams.review',
            'route_params' => ['document'],
        ],
        'drawings' => [
            'relation'     => 'drawings',
            'label'        => 'Drawing',
            'name_fields'  => ['filename'],
            // The only two-segment route in the map: projects/{project}/drawings/{drawing}.
            'route'        => 'projects.drawings.show',
            'route_params' => ['project', 'drawing'],
        ],
        'om' => [
            'relation'     => 'omManuals',
            'label'        => 'O&M manual',
            'name_fields'  => ['filename', 'source_filename'],
            'route'        => 'om-manuals.edit',
            'route_params' => ['document'],
        ],
        'cable_schedule' => [
            'relation'     => 'cableSchedules',
            'label'        => 'Cable schedule',
            'name_fields'  => ['source_filename', 'filename'],
            'route'        => 'cable-schedules.edit',
            'route_params' => ['document'],
        ],
    ];

    /**
     * A module's OWN note field, where one exists on its document model.
     *
     * These two are the only note columns that belong to a module rather than
     * to the project. Nothing else is read — see decision 3 above.
     *
     * MEASURED 2026-09-20, correcting a plan-time assumption: `cable_schedules`
     * has NO `notes` column. The `notes` column in
     * 2026_03_09_000002_create_cable_schedules_table.php belongs to the SECOND
     * table that migration creates, `cable_schedule_items` — it is a per-cable
     * remark, not a note about the document. Mapping it here threw
     * "no such column: notes" against the real schema, so the cable schedule
     * module has no own note field and says so.
     *
     * @var array<string, array<string, string>>
     */
    private const NOTE_FIELDS = [
        'site_survey'       => ['relation' => 'siteSurveys', 'field' => 'general_notes', 'label' => 'Site survey'],
        'install_programme' => ['relation' => 'installProgrammes', 'field' => 'notes', 'label' => 'Programme'],
    ];

    /**
     * An action rendered as the phrase a PM reads. An action that is NOT in
     * this map falls back to its own value humanised — never to a generic
     * "updated", which would quietly mislabel every action a later phase adds.
     *
     * @var array<string, string>
     */
    private const ACTION_PHRASES = [
        ProjectActivityLog::ACTION_CREATED          => 'created the project',
        ProjectActivityLog::ACTION_STATUS_CHANGED   => 'changed the status',
        ProjectActivityLog::ACTION_REOPENED         => 'reopened the project',
        ProjectActivityLog::ACTION_DOCUMENT_ADDED   => 'added a document',
        ProjectActivityLog::ACTION_DOCUMENT_UPDATED => 'updated a document',
        ProjectActivityLog::ACTION_NOTE_ADDED       => 'added a note',
        ProjectActivityLog::ACTION_PACKAGE_IMPORTED => 'imported a package',
        ProjectActivityLog::ACTION_PACKAGE_REVIEWED => 'reviewed a package',
    ];

    /**
     * The mapped view-route names as data, so a test can assert every one of
     * them still exists rather than sampling one.
     *
     * @return array<string, string>
     */
    public static function viewRouteNames(): array
    {
        $names = [];

        foreach (self::DOCUMENTS as $module => $definition) {
            if ($definition['route'] !== null) {
                $names[$module] = $definition['route'];
            }
        }

        return $names;
    }

    /**
     * The module keys that hold documents, as data.
     *
     * @return array<int, string>
     */
    public static function documentModules(): array
    {
        return array_keys(self::DOCUMENTS);
    }

    // ── Files (D-13) ─────────────────────────────────────────────────────

    /**
     * Every document the project holds for one module, newest first.
     *
     * Soft-deleted rows are excluded by the relation's own global scope. A
     * force-deleted source simply is not there — there is nothing to exclude
     * and nothing to fail on.
     *
     * @return Collection<int, array{name: string, produced_at: \Illuminate\Support\Carbon|null, status: string, route: string|null}>
     */
    public function files(Project $project, string $moduleKey): Collection
    {
        $definition = self::DOCUMENTS[$moduleKey] ?? null;

        if ($definition === null) {
            return collect();
        }

        $relation = $definition['relation'];

        /** @var Collection<int, Model> $documents */
        $documents = collect($project->{$relation} ?? []);

        return $documents
            ->sortByDesc(fn (Model $document) => $document->created_at)
            ->values()
            ->map(fn (Model $document): array => [
                'name'        => $this->documentName($document, $definition),
                'produced_at' => $this->asDate($document->created_at),
                'status'      => $this->statusLabel($document->status ?? null),
                'route'       => $this->viewRoute($project, $document, $definition),
            ])
            ->values();
    }

    // ── Notes ────────────────────────────────────────────────────────────

    /**
     * The module's recorded notes: its own note field first, then the
     * project's `note_added` activity entries.
     *
     * @return Collection<int, array{text: string, source: string, at: \Illuminate\Support\Carbon|null}>
     */
    public function notes(Project $project, string $moduleKey): Collection
    {
        $notes = collect();

        $field = self::NOTE_FIELDS[$moduleKey] ?? null;

        if ($field !== null) {
            foreach (collect($project->{$field['relation']} ?? []) as $record) {
                $text = trim((string) ($record->{$field['field']} ?? ''));

                if ($text !== '') {
                    $notes->push([
                        'text'   => $text,
                        'source' => $field['label'],
                        'at'     => $this->asDate($record->created_at),
                    ]);
                }
            }
        }

        foreach ($this->log($project) as $entry) {
            if ($entry->action !== ProjectActivityLog::ACTION_NOTE_ADDED) {
                continue;
            }

            $text = trim((string) $entry->description);

            if ($text === '') {
                continue;
            }

            $notes->push([
                'text'   => $text,
                'source' => $entry->actor_name,
                'at'     => $this->asDate($entry->created_at),
            ]);
        }

        return $notes->values();
    }

    // ── Recent activity (D-14) ───────────────────────────────────────────

    /**
     * The project's recent activity, newest first.
     *
     * Project-wide by design — see decision 1 in the class docblock. There is
     * deliberately no module parameter.
     *
     * @return Collection<int, array{initials: string, actor: string, phrase: string, at: \Illuminate\Support\Carbon|null}>
     */
    public function activity(Project $project, int $limit = 6): Collection
    {
        return $this->log($project)
            ->sortByDesc(fn (ProjectActivityLog $entry) => $entry->created_at)
            ->take(max($limit, 0))
            ->values()
            ->map(function (ProjectActivityLog $entry): array {
                // actor_name is the model's OWN accessor, so a deleted user
                // reads "System" here exactly as it does everywhere else.
                $actor = $entry->actor_name;

                return [
                    'initials' => $this->initials($actor),
                    'actor'    => $actor,
                    'phrase'   => $this->phrase($entry),
                    'at'       => $this->asDate($entry->created_at),
                ];
            })
            ->values();
    }

    // ── Derivation ───────────────────────────────────────────────────────

    /**
     * A timestamp as a Carbon instance, whatever the model handed over.
     *
     * NOT defensive padding — MEASURED: these models declare their own
     * `$casts` arrays and several of them do not list `created_at`, so the
     * attribute arrives as a raw string and `->format()` on it is a fatal
     * that blanks the whole page. Normalising here keeps the one date format
     * the design specifies ("14 Aug 2026") in one place, rather than making
     * every Blade guess at the type it was given.
     */
    private function asDate(mixed $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value);
        }

        try {
            return Carbon::parse((string) $value);
        } catch (\Throwable) {
            // An unparseable timestamp is not worth a 500 on a read-only
            // page: the row renders with "Date not recorded".
            return null;
        }
    }

    /**
     * @return Collection<int, ProjectActivityLog>
     */
    private function log(Project $project): Collection
    {
        return collect($project->activityLog ?? []);
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function documentName(Model $document, array $definition): string
    {
        foreach ($definition['name_fields'] as $field) {
            $value = trim((string) ($document->{$field} ?? ''));

            if ($value !== '') {
                return $value;
            }
        }

        return $definition['label'];
    }

    /**
     * The "View" target, or null.
     *
     * `Route::has()` guards the call, so a renamed route produces a link-less
     * row rather than an exception that blanks the page (T-45-12-03).
     *
     * @param  array<string, mixed>  $definition
     */
    private function viewRoute(Project $project, Model $document, array $definition): ?string
    {
        $name = $definition['route'] ?? null;

        if ($name === null || ! Route::has($name)) {
            return null;
        }

        $parameters = [];

        foreach ($definition['route_params'] as $parameter) {
            $parameters[$parameter === 'document' ? 0 : $parameter] = $parameter === 'project' ? $project : $document;
        }

        return route($name, $parameters);
    }

    /**
     * A model status column rendered as plain English.
     *
     * The wording, including "Could not be produced", is the one Plan 45-07
     * settled in CockpitSectionPresenter::label(). It is repeated rather than
     * shared because that method is private to a presenter this class must not
     * couple itself to; the Copywriting Contract rule it enforces — never the
     * word "Error" in user-facing copy — is the part that matters.
     */
    private function statusLabel(?string $status): string
    {
        if ($status === null || $status === '') {
            return 'On file';
        }

        if (str_contains($status, 'fail') || str_contains($status, 'error')) {
            return 'Could not be produced';
        }

        return ucfirst(str_replace('_', ' ', $status));
    }

    /**
     * The design draws avatars; `User` has no avatar column, and initials are
     * what the design actually renders inside the circle anyway.
     */
    private function initials(string $actor): string
    {
        $words = preg_split('/\s+/', trim($actor), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $letters = '';

        foreach (array_slice($words, 0, 2) as $word) {
            $letters .= mb_substr($word, 0, 1);
        }

        return mb_strtoupper($letters);
    }

    private function phrase(ProjectActivityLog $entry): string
    {
        $description = trim((string) $entry->description);

        if ($description !== '') {
            return $description;
        }

        $action = (string) $entry->action;

        return self::ACTION_PHRASES[$action] ?? trim(str_replace('_', ' ', $action));
    }
}
