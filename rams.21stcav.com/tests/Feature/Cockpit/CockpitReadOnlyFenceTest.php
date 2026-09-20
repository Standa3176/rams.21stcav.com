<?php

namespace Tests\Feature\Cockpit;

use App\Http\Controllers\ProjectCockpitController;
use App\Models\Project;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Support\Cockpit\CockpitModulePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 45, plan 45-07 — THE READ-ONLY FENCE.
 *
 * ROADMAP criteria 3 and 5 rest on this file. The sketch
 * (.planning/sketches/002-install-cockpit/cockpit-sections.html) draws fifteen
 * write affordances; Phase 45 renders NONE of them, and that has to be proven
 * by a test rather than by inspection, because inspection does not survive the
 * next agent.
 */
class CockpitReadOnlyFenceTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The fence, enumerated as DATA.
     *
     * Two lists, deliberately inspectable. A future phase that legitimately
     * adds an affordance must DELETE an entry here on purpose, rather than
     * watch a vague assertion quietly stop covering anything — the same
     * anti-rot discipline as
     * LabourResourceClientSurfacePrivacyTest::test_every_enumerated_path_exists().
     *
     * @var array<int, string>
     */
    private const FORBIDDEN_MARKUP = [
        '<form',
        '<input',
        '<select',
        '<textarea',
        '<button',
        '<script',
    ];

    /**
     * Every deferred write affordance, with the phase that owns it.
     *
     * Fifteen came from 45-UI-SPEC.md § Read-only Fence. Plan 45-13 added the
     * three that sketch 004 draws as a "Quick actions" block in the side panel
     * (D-15): Create visit, Add note and Upload files. They were never
     * rendered, not even disabled, but until now only CockpitPageTest and
     * CockpitPanelTest said so, each in its own private list. Naming them here
     * puts them behind the count assertion below, so a later phase that ships
     * one has to remove its entry deliberately.
     *
     * @var array<string, string>
     */
    private const DEFERRED_AFFORDANCES = [
        'Book another survey'      => 'Phase 46',
        'Prepare a visit'          => 'Phase 46 / 51',
        'Add a snag'               => 'Phase 47',
        'Book a visit'             => 'Phase 47',
        'Issue to client'          => 'Phase 48',
        'Send a RAMS to the client' => 'Phase 48',
        'Upload a drawing'         => 'Phase 48',
        'Add document'             => 'Phase 48',
        'Add anyway'               => 'Phase 48',
        'Edit details'             => 'Phase 49',
        'Re-import from QuoteWerks' => 'Phase 50',
        'Close this project'       => 'Phase 50',
        'Open register'            => 'Phase 48',
        'Export CSV'               => 'Phase 48',
        'Download'                 => 'Phase 48',

        // Sketch 004's Quick actions block — added by Plan 45-13 (D-15).
        'Create visit'             => 'Phase 46',
        'Add note'                 => 'Phase 46',
        'Upload files'             => 'Phase 48',
    ];

    /**
     * Handler attributes and template directives banned INSIDE the region.
     *
     * THE ALPINE RULING, ENFORCED RATHER THAN REMEMBERED. Alpine is loaded
     * globally by resources/views/layouts/app.blade.php and is therefore
     * AVAILABLE on this page — it is not absent, it is BANNED. Phase 45 ships
     * no JavaScript of its own: the side panel's open/closed and tab state is
     * URL state driven by plain <a href> GETs (Plan 45-11), which is why the
     * design's disclosure behaviour needed no directive. Plan 45-11 asserted
     * these strings inline inside CockpitPageTest; Plan 45-13 promotes them
     * here so the ruling lives in the fence.
     *
     * When Phase 46 introduces writes it MAY retire this ban — deliberately,
     * by an owner, by editing this list. Until then an Alpine directive inside
     * the cockpit region is a fence breach.
     *
     * @var array<int, string>
     */
    private const BANNED_HANDLER_ATTRIBUTES = [
        'onclick',
        'wire:',
        'x-on:',
        '@click',
        'x-data',
        'x-show',
        'x-init',
        'x-if',
        'x-text',
    ];

    /**
     * VIS-06 / criterion 5, asserted DIRECTLY rather than by absence-of-buttons.
     *
     * @var array<int, string>
     */
    private const WRITE_SURFACE_TABLES = [
        'visits',
        'install_records',
        'install_programmes',
        'site_surveys',
        'worksheets',
    ];

    /**
     * SCOPE OF THIS FENCE — a plan-time decision, recorded here on purpose.
     *
     * Every assertion below is scoped to the `cav-brand cav-cockpit` subtree,
     * never to the whole response body. The cockpit page extends the shared app
     * layout deliberately (Plan 45-06), and resources/views/layouts/app.blade.php
     * contains, in the chrome of EVERY authenticated page in this application:
     * 2 <form> (including the logout form at :1356), 1 <input> (the
     * command-palette search box), 6 <button> and 6 <script> (@vite bundles and
     * Alpine). A whole-body assertion of "no <form" would therefore fail on its
     * very first run against entirely unmodified global chrome, and whoever met
     * that failure would have to decide unsupervised how much of the fence to
     * weaken.
     *
     * That decision was made at plan time instead: the layout's nav, logout
     * form, command-palette input and @vite/Alpine <script> tags are OUT OF
     * SCOPE of this fence. They are pre-existing, owned by no part of Phase 45,
     * present identically on the eleven-tab page, and covered instead by Plan
     * 45-08's sha256 assertion that layouts/app.blade.php is byte-identical to
     * its pre-phase state. What this fence exists to catch is a write affordance
     * appearing INSIDE the cockpit.
     *
     * THE VACUITY RISK THAT SCOPING INTRODUCES, AND HOW IT IS CLOSED.
     * If the extraction matched nothing, every "contains no X" assertion would
     * pass trivially and the fence would prove nothing while showing green —
     * worse than a fence that fails. So this helper brackets the region at BOTH
     * ends before returning: it asserts the region is non-empty, that it
     * contains the masthead's project name (TOP bracket), and that it contains
     * the "Open full project" link text (BOTTOM bracket — that link is the
     * last element in the page shell). The bracket string was RETARGETED by
     * Plan 45-11 when sketch 004 shortened the link's copy from "Open the full
     * project page"; it was re-proved in the same commit by deliberately
     * truncating the extraction and watching this assertion go red. The
     * bracket is not optional and neither end may be removed — retargeting a
     * bracket to follow the markup is maintenance, deleting one is the
     * vacuity this whole helper exists to prevent.
     *
     * Both brackets live INSIDE this helper, not in a sibling test. The reason
     * is specific: the masthead is the FIRST thing in the subtree, so an
     * extraction that truncated early would still be non-empty and still
     * contain the project name — passing the top check while leaving all nine
     * drawers unexamined and the fence green. With both brackets there is no
     * extraction that is simultaneously wrong and green: truncate and the
     * bottom check fails; over-capture and the no-<form / no-<script
     * assertions fail on the layout's chrome.
     */
    private function cockpitRegion(string $html): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $node = (new \DOMXPath($dom))
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' cav-cockpit ')]")
            ->item(0);

        $this->assertNotNull($node, 'The cav-cockpit root element was not found — the fence would pass vacuously.');

        $region = html_entity_decode($dom->saveHTML($node), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $this->assertNotEmpty($region, 'The extracted cockpit region is empty — the fence would pass vacuously.');

        // TOP bracket — the masthead, the first thing in the subtree.
        $this->assertStringContainsString(
            self::PROJECT_NAME,
            $region,
            'The extracted region does not contain the masthead project name, so it is not the cockpit.'
        );

        // BOTTOM bracket — the last element in the page shell. Without this, a
        // truncated extraction would still satisfy the top bracket.
        $this->assertStringContainsString(
            'Open full project',
            $region,
            'The extracted region stops before the end of the page shell — it would leave the module rows unexamined.'
        );

        return $region;
    }

    private const PROJECT_NAME = 'Fence Test Job';

    /**
     * A project with a drawer of every shape: visit rows, a reconstructed row,
     * a superseded row, and document sections. The fence must hold over the
     * richest page this phase can render, not over an empty one.
     */
    private function populatedProject(): Project
    {
        $project = Project::factory()->create([
            'name'   => self::PROJECT_NAME,
            'status' => Project::STATUS_INSTALLING,
        ]);

        $signed = Worksheet::factory()->create(['project_id' => $project->id]);

        Visit::factory()->backfilledFromWorksheet($signed)->create([
            'project_id'     => $project->id,
            'title'          => 'Install day one',
            'scheduled_date' => '2026-09-02',
        ]);

        $gone   = Worksheet::factory()->create(['project_id' => $project->id]);
        $goneId = $gone->id;
        $gone->delete();

        Visit::factory()->create([
            'project_id'     => $project->id,
            'type'           => Visit::TYPE_INSTALL,
            'title'          => 'Install day two',
            'scheduled_date' => '2026-09-03',
            'source_type'    => Visit::SOURCE_WORKSHEET,
            'source_id'      => $goneId,
        ]);

        Visit::factory()->backfilledFromSurvey()->create([
            'project_id'     => $project->id,
            'title'          => 'Site survey',
            'scheduled_date' => '2026-08-11',
        ]);

        return $project;
    }

    /**
     * @param  array<string, string>  $query  `?module=` / `?tab=` — the page's
     *                                        only user-supplied input.
     */
    private function render(Project $project, array $query = []): string
    {
        config(['cockpit.enabled' => true]);

        $url = route('projects.cockpit', ['project' => $project] + $query);

        return $this->actingAs(User::factory()->create())
            ->get($url)
            ->assertOk()
            ->getContent();
    }

    // -- The fence ----------------------------------------------------------

    public function test_the_cockpit_region_contains_no_form_control_and_no_script(): void
    {
        $region = $this->cockpitRegion($this->render($this->populatedProject()));

        foreach (self::FORBIDDEN_MARKUP as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $region,
                "Read-only fence: {$forbidden} must not appear inside the cockpit. ".
                'Phase 45 renders no write affordance and ships no JavaScript of its own.'
            );
        }
    }

    public function test_none_of_the_deferred_affordances_appears(): void
    {
        $region = $this->cockpitRegion($this->render($this->populatedProject()));

        foreach (self::DEFERRED_AFFORDANCES as $copy => $owner) {
            $this->assertStringNotContainsString(
                $copy,
                $region,
                "Read-only fence: \"{$copy}\" is deferred to {$owner} and must not appear in Phase 45."
            );
        }
    }

    public function test_the_fence_enumerates_the_whole_deferred_set(): void
    {
        // MOVED DELIBERATELY, 15 -> 18, by Plan 45-13 when sketch 004's three
        // Quick actions joined the list. This number is the anti-rot mechanism:
        // it exists so that dropping an affordance is an edit somebody has to
        // make on purpose. It is never to be deleted to make a change fit.
        $this->assertCount(
            18,
            self::DEFERRED_AFFORDANCES,
            'Every affordance drawn in either sketch is enumerated; nothing is dropped silently.'
        );

        foreach (self::DEFERRED_AFFORDANCES as $copy => $owner) {
            $this->assertNotSame('', trim($copy));
            $this->assertNotSame('', trim($owner));
        }

        $this->assertCount(6, self::FORBIDDEN_MARKUP);
        $this->assertCount(5, self::WRITE_SURFACE_TABLES);
        $this->assertCount(9, self::BANNED_HANDLER_ATTRIBUTES);
    }

    /**
     * Alpine is AVAILABLE here and nonetheless BANNED — see
     * BANNED_HANDLER_ATTRIBUTES for the ruling and for who may retire it.
     *
     * Run over the bare page AND over every open panel: the panel is the
     * newest markup on the page and the likeliest place for a directive to
     * appear, because it is the one part of the design that behaves like a
     * widget.
     */
    public function test_rows_are_static_and_nothing_is_wired_to_a_handler(): void
    {
        $project = $this->populatedProject();

        $regions = [$this->cockpitRegion($this->render($project))];

        foreach (array_keys(CockpitModulePresenter::moduleMap()) as $moduleKey) {
            $regions[] = $this->cockpitRegion($this->render($project, ['module' => $moduleKey]));
        }

        foreach ($regions as $region) {
            foreach (self::BANNED_HANDLER_ATTRIBUTES as $banned) {
                $this->assertStringNotContainsString(
                    $banned,
                    $region,
                    "Read-only fence: `{$banned}` must not appear inside the cockpit. ".
                    'Phase 45 ships no JavaScript; the panel is URL state.'
                );
            }

            $this->assertStringNotContainsString('cursor: pointer', $region);
            $this->assertStringNotContainsString('cursor:pointer', $region);
            $this->assertStringNotContainsString('tabindex', $region);

            // Nothing on this page is a disclosure widget, so nothing may
            // announce an expanded state it does not own.
            $this->assertStringNotContainsString('aria-expanded', $region);
        }
    }

    public function test_the_one_permitted_navigation_affordance_is_present(): void
    {
        $project = $this->populatedProject();
        $region  = $this->cockpitRegion($this->render($project));

        $this->assertStringContainsString('Open full project', $region);
        $this->assertStringContainsString(route('projects.show', $project), $region);
    }

    // -- VIS-06 / criterion 5, asserted directly ---------------------------

    /**
     * Absence of form controls proves the page OFFERS no way to write. This
     * proves that RENDERING it writes nothing — the stronger and more literal
     * reading of "this phase adds no new writes".
     */
    public function test_rendering_the_cockpit_changes_no_row_count(): void
    {
        $project = $this->populatedProject();

        $before = [];

        foreach (self::WRITE_SURFACE_TABLES as $table) {
            $before[$table] = DB::table($table)->count();
        }

        $this->cockpitRegion($this->render($project));

        foreach (self::WRITE_SURFACE_TABLES as $table) {
            $this->assertSame(
                $before[$table],
                DB::table($table)->count(),
                "Rendering the cockpit changed the row count of `{$table}` — Phase 45 adds no new writes."
            );
        }
    }

    /**
     * The bare page was already proved inert. `?module=` is NEW request
     * surface (Plan 45-11) and gets its own proof: opening every module the
     * presenter exposes, on every tab, must leave all five write-surface
     * tables exactly where they were.
     */
    public function test_opening_a_panel_writes_nothing(): void
    {
        $project = $this->populatedProject();

        $before = [];

        foreach (self::WRITE_SURFACE_TABLES as $table) {
            $before[$table] = DB::table($table)->count();
        }

        // Iterated from the presenter's OWN key list, so a tenth module is
        // covered the day it is added rather than the day someone remembers.
        foreach (array_keys(CockpitModulePresenter::moduleMap()) as $moduleKey) {
            foreach (ProjectCockpitController::TABS as $tab) {
                $this->cockpitRegion($this->render($project, ['module' => $moduleKey, 'tab' => $tab]));
            }
        }

        foreach (self::WRITE_SURFACE_TABLES as $table) {
            $this->assertSame(
                $before[$table],
                DB::table($table)->count(),
                "Opening a panel changed the row count of `{$table}` — a GET on this page writes nothing."
            );
        }
    }

    /**
     * An unrecognised `?module=` is a stale bookmark, not an error worth
     * showing a PM. It renders 200 with the panel closed, and the submitted
     * value is NEVER echoed — asserted on the RAW body rather than on the
     * entity-decoded subtree, so even an escaped reflection fails here.
     */
    public function test_a_hostile_module_value_is_not_reflected(): void
    {
        $project = $this->populatedProject();

        $payloads = [
            '<script>alert(1)</script>',
            str_repeat('a', 5000),
        ];

        foreach ($payloads as $payload) {
            $body = $this->render($project, ['module' => $payload]);

            $this->assertStringNotContainsString(
                $payload,
                $body,
                'A submitted ?module= value must never be reflected into the page.'
            );

            // Still bracket-valid: cockpitRegion() asserts both ends, so a
            // payload that broke the page would fail inside this call.
            $region = $this->cockpitRegion($body);

            $this->assertStringNotContainsString('cav-panel', $region, 'An unknown module opens nothing.');
        }
    }

    public function test_repeated_renders_still_change_no_row_count(): void
    {
        $project = $this->populatedProject();

        $before = [];

        foreach (self::WRITE_SURFACE_TABLES as $table) {
            $before[$table] = DB::table($table)->count();
        }

        foreach (range(1, 3) as $ignored) {
            $this->cockpitRegion($this->render($project));
        }

        foreach (self::WRITE_SURFACE_TABLES as $table) {
            $this->assertSame($before[$table], DB::table($table)->count(), "`{$table}` row count moved.");
        }
    }
}
