<?php

namespace Tests\Feature\Cockpit;

use App\Models\CableSchedule;
use App\Models\OmManual;
use App\Models\Project;
use App\Models\ProjectActivityLog;
use App\Models\RamsDocument;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Support\Cockpit\CockpitModulePresenter;
use App\Support\Cockpit\CockpitPanelPresenter;
use Illuminate\Foundation\Testing\Concerns\InteractsWithViews;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 45, Plan 45-12, Task 2 — the panel's Files tab, Notes tab and the
 * Recent activity feed.
 *
 * D-13, in the user's own words: "under docs a user can see all created
 * project docs in one place (ie click and view etc)". The Files tab is that
 * place, so these tests care first about COMPLETENESS (every document the
 * project holds appears) and second about the link.
 *
 * Every assertion runs against the extracted `cav-cockpit` subtree, never the
 * whole document — the shared layout's logout form, command-palette input and
 * @vite/Alpine tags are pre-existing global chrome, out of scope by the
 * decision recorded in 45-06-PLAN.md.
 */
class CockpitPanelTest extends TestCase
{
    use InteractsWithViews;
    use RefreshDatabase;

    private const THREE_TABS = ['overview', 'files', 'notes'];

    private function project(): Project
    {
        return Project::factory()->create([
            'name'   => 'Panel Render Job',
            'status' => Project::STATUS_INSTALLING,
        ]);
    }

    private function subtree(string $html): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $node = (new \DOMXPath($dom))
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' cav-cockpit ')]")
            ->item(0);

        $this->assertNotNull($node, 'The cav-cockpit root element was not found in the response.');

        return html_entity_decode($dom->saveHTML($node), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** The RAW response body — entities intact, for escaping assertions. */
    private function raw(Project $project, string $module, string $tab = 'overview'): string
    {
        config(['cockpit.enabled' => true]);

        $url = route('projects.cockpit', ['project' => $project, 'module' => $module, 'tab' => $tab]);

        return $this->actingAs(User::factory()->create())->get($url)->assertOk()->getContent();
    }

    private function panel(Project $project, string $module, string $tab = 'overview'): string
    {
        return $this->subtree($this->raw($project, $module, $tab));
    }

    private function countByClass(string $html, string $class): int
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        return (new \DOMXPath($dom))
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]")
            ->length;
    }

    private function log(Project $project, array $overrides = []): ProjectActivityLog
    {
        $log = ProjectActivityLog::create(array_merge([
            'project_id'  => $project->id,
            'user_id'     => User::factory()->create(['name' => 'Alice Hartley'])->id,
            'action'      => ProjectActivityLog::ACTION_DOCUMENT_ADDED,
            'description' => 'added the RAMS document',
        ], $overrides));

        if (array_key_exists('created_at', $overrides)) {
            $log->forceFill(['created_at' => $overrides['created_at']])->saveQuietly();
        }

        return $log->refresh();
    }

    // ── The Files tab (D-13) ─────────────────────────────────────────────

    public function test_the_files_tab_lists_every_document_the_module_holds(): void
    {
        $project = $this->project();

        RamsDocument::factory()->create(['project_id' => $project->id, 'filename' => 'method-statement-a.docx']);
        RamsDocument::factory()->create(['project_id' => $project->id, 'filename' => 'method-statement-b.docx']);

        $html = $this->panel($project, 'rams', 'files');

        $this->assertSame(2, $this->countByClass($html, 'cav-file'), 'Every document reaches the library — D-13.');
        $this->assertStringContainsString('method-statement-a.docx', $html);
        $this->assertStringContainsString('method-statement-b.docx', $html);
    }

    public function test_a_listed_document_carries_its_date_status_and_a_view_link(): void
    {
        $project = $this->project();

        $doc = RamsDocument::factory()->create([
            'project_id' => $project->id,
            'filename'   => 'method-statement.docx',
            'status'     => RamsDocument::STATUS_COMPLETED,
        ]);
        $doc->forceFill(['created_at' => '2026-08-14 16:11:00'])->save();

        $html = $this->panel($project, 'rams', 'files');

        $this->assertStringContainsString('14 Aug 2026', $html);
        $this->assertStringContainsString('Completed', $html);
        $this->assertStringContainsString('View', $html);
        $this->assertStringContainsString(route('rams.review', $doc), $html);
    }

    /**
     * The copy fence: "Download" is Phase 48's word and is banned outright.
     * The only link copy this panel uses is "View".
     */
    public function test_the_link_copy_is_view_and_never_download(): void
    {
        $project = $this->project();
        RamsDocument::factory()->create(['project_id' => $project->id]);

        $html = $this->panel($project, 'rams', 'files');

        $this->assertStringContainsString('>View<', $html);
        $this->assertStringNotContainsString('Download', $html);
    }

    /**
     * A document with no resolvable route is LISTED, not dropped, and carries
     * no link text at all — "View" pointing at `#` would read as a broken
     * control, which is worse than a plain row.
     */
    public function test_a_document_with_no_route_renders_as_a_plain_row(): void
    {
        $html = $this->blade(
            '<x-cockpit.file-row :name="$name" :produced="$produced" :status="$status" :route="$route" />',
            [
                'name'     => 'unlinkable.pdf',
                'produced' => now(),
                'status'   => 'On file',
                'route'    => null,
            ]
        )->__toString();

        $this->assertStringContainsString('unlinkable.pdf', $html);
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringNotContainsString('View', $html);
        $this->assertStringNotContainsString('href="#"', $html);
    }

    public function test_a_module_with_no_document_relation_says_so_in_one_sentence(): void
    {
        $html = $this->panel($this->project(), 'programming', 'files');

        $this->assertStringContainsString('Programming holds no documents.', $html);
        $this->assertSame(0, $this->countByClass($html, 'cav-file'));
        $this->assertStringNotContainsString('coming soon', $html);
        $this->assertStringNotContainsString('Files and notes arrive in the next plan', $html);
    }

    public function test_a_module_with_a_library_but_nothing_in_it_says_so(): void
    {
        $html = $this->panel($this->project(), 'rams', 'files');

        $this->assertStringContainsString('No RAMS documents have been produced yet.', $html);
        $this->assertSame(0, $this->countByClass($html, 'cav-file'));
    }

    // ── The Notes tab ────────────────────────────────────────────────────

    public function test_the_notes_tab_renders_the_modules_own_notes(): void
    {
        $project = $this->project();

        SiteSurvey::create([
            'user_id'       => User::factory()->create()->id,
            'project_id'    => $project->id,
            'project_name'  => $project->name,
            'general_notes' => 'Lift booked for Tuesday.',
        ]);

        $html = $this->panel($project, 'site_survey', 'notes');

        $this->assertStringContainsString('Lift booked for Tuesday.', $html);
        $this->assertSame(1, $this->countByClass($html, 'cav-pnote'));
    }

    public function test_a_module_with_no_notes_says_so_in_one_sentence(): void
    {
        $html = $this->panel($this->project(), 'rams', 'notes');

        $this->assertStringContainsString('RAMS has no notes recorded.', $html);
        $this->assertSame(0, $this->countByClass($html, 'cav-pnote'));
    }

    // ── Recent activity (D-14) ───────────────────────────────────────────

    public function test_the_overview_tab_renders_the_activity_feed_newest_first(): void
    {
        $project = $this->project();

        // 14 Aug 2026 16:11 is the design's own timestamp; the other entry is
        // deliberately OLDER than it, so "newest first" is a real ordering
        // assertion rather than one satisfied by insertion order.
        $this->log($project, ['description' => 'imported the QuoteWerks package', 'created_at' => '2026-08-01 09:00:00']);
        $this->log($project, ['description' => 'approved the RAMS', 'created_at' => '2026-08-14 16:11:00']);

        $html = $this->panel($project, 'rams');

        $this->assertStringContainsString('Recent activity', $html);
        $this->assertSame(2, $this->countByClass($html, 'cav-act'));
        $this->assertStringContainsString('Alice Hartley', $html);
        $this->assertStringContainsString('AH', $html);
        $this->assertStringContainsString('approved the RAMS', $html);
        $this->assertStringContainsString('14 Aug 2026, 16:11', $html);

        $this->assertLessThan(
            strpos($html, 'imported the QuoteWerks package'),
            strpos($html, 'approved the RAMS'),
            'Newest first.'
        );
    }

    public function test_a_project_with_no_activity_gets_one_sentence(): void
    {
        $html = $this->panel($this->project(), 'rams');

        $this->assertStringContainsString('Nothing has been recorded against this project yet.', $html);
        $this->assertSame(0, $this->countByClass($html, 'cav-act'));
    }

    /**
     * THE RECORDED DECISION, RENDERED. `ProjectActivityLog` has no module
     * column; filtering by guessing at `metadata` keys would silently drop
     * every entry that carries none. The feed is project-wide, and this test
     * pins it so it reads as a decision rather than a bug.
     */
    public function test_the_same_feed_renders_under_every_module(): void
    {
        $project = $this->project();
        $this->log($project, ['description' => 'the only entry']);

        foreach (array_keys(CockpitModulePresenter::moduleMap()) as $module) {
            $html = $this->panel($project, $module);

            $this->assertSame(1, $this->countByClass($html, 'cav-act'), "{$module} lost the feed.");
            $this->assertStringContainsString('the only entry', $html, "{$module} lost the feed's entry.");
        }
    }

    /**
     * The design's "View all" goes to a project activity page. No such GET
     * route exists, and creating one is a new surface outside this phase — so
     * the affordance is omitted rather than pointed somewhere plausible.
     */
    public function test_no_view_all_affordance_is_rendered(): void
    {
        $project = $this->project();
        $this->log($project);

        $this->assertStringNotContainsString('View all', $this->panel($project, 'rams'));
    }

    // ── Every module x every tab ─────────────────────────────────────────

    public function test_every_module_renders_on_every_tab(): void
    {
        $project = $this->project();

        RamsDocument::factory()->create(['project_id' => $project->id]);
        OmManual::factory()->create(['project_id' => $project->id]);
        CableSchedule::factory()->create(['project_id' => $project->id]);
        $this->log($project);

        foreach (array_keys(CockpitModulePresenter::moduleMap()) as $module) {
            foreach (self::THREE_TABS as $tab) {
                $html = $this->panel($project, $module, $tab);

                $this->assertSame(1, $this->countByClass($html, 'cav-panel'), "{$module}/{$tab} must render one panel.");
            }
        }
    }

    public function test_the_document_modules_each_list_their_own_documents(): void
    {
        $project = $this->project();

        RamsDocument::factory()->create(['project_id' => $project->id, 'filename' => 'the-rams.docx']);
        OmManual::factory()->create(['project_id' => $project->id, 'filename' => 'the-om.docx']);
        CableSchedule::factory()->create(['project_id' => $project->id, 'source_filename' => 'the-cables.xlsx']);

        $this->assertStringContainsString('the-rams.docx', $this->panel($project, 'rams', 'files'));
        $this->assertStringContainsString('the-om.docx', $this->panel($project, 'om', 'files'));
        $this->assertStringContainsString('the-cables.xlsx', $this->panel($project, 'cable_schedule', 'files'));
    }

    // ── The read-only fence over this plan's own markup ──────────────────

    public function test_the_filled_panel_carries_no_control_no_handler_and_no_banned_copy(): void
    {
        $project = $this->project();

        RamsDocument::factory()->create(['project_id' => $project->id]);
        SiteSurvey::create([
            'user_id'       => User::factory()->create()->id,
            'project_id'    => $project->id,
            'project_name'  => $project->name,
            'general_notes' => 'A note.',
        ]);
        $this->log($project, ['action' => ProjectActivityLog::ACTION_NOTE_ADDED, 'description' => 'A logged note.']);

        foreach (['rams', 'site_survey'] as $module) {
            foreach (self::THREE_TABS as $tab) {
                $html = $this->panel($project, $module, $tab);

                // RETIRED IN PART BY PLAN 46-04, in step with the canonical
                // list in CockpitReadOnlyFenceTest: `<form`, `<input`,
                // `<button` and `<textarea` were lifted BY NAME for the Quick
                // actions form. `<select` and `<script` stay banned.
                foreach (['<select', '<script'] as $forbidden) {
                    $this->assertStringNotContainsString($forbidden, $html, "{$forbidden} in {$module}/{$tab}.");
                }

                foreach (['x-data', 'x-show', 'x-init', 'x-if', 'x-text', 'x-on:', '@click', 'wire:', 'onclick'] as $handler) {
                    $this->assertStringNotContainsString($handler, $html, "{$handler} is script inside the region.");
                }

                // `Create visit` SHIPPED in Plan 46-04 and `Add note` ships in
                // 46-05; both were lifted from DEFERRED_AFFORDANCES by name.
                // Everything else here is still Phase 48.
                foreach (['Download', 'Upload files', 'Add document', 'Open register'] as $banned) {
                    $this->assertStringNotContainsString($banned, $html, "\"{$banned}\" is deferred write copy.");
                }
            }
        }
    }

    /**
     * T-45-12-01. Document names, note text and activity descriptions are all
     * user-authored strings. Nothing in the cockpit may use {!! !!}.
     */
    public function test_no_cockpit_view_uses_unescaped_output(): void
    {
        // NO EXCLUSIONS. Plan 45-12 excluded attention.blade.php by name — it
        // predated that plan, assembled its sentence in PHP and echoed it with
        // {!! !!}, and Plan 45-11 had already made it unreachable by folding
        // the health summary into the Overall status KPI card. Plan 45-13
        // DELETED it, so the exclusion went with it and this guard now covers
        // every cockpit view without a carve-out. An unrendered Blade file
        // carrying unescaped output is a loaded gun for a later phase: the
        // next agent who needs an attention line would find it, render it, and
        // inherit the {!! !!} along with it.
        $views = glob(resource_path('views/components/cockpit/*.blade.php'));

        $this->assertNotEmpty($views);

        foreach ($views as $view) {
            $this->assertStringNotContainsString('{!!', file_get_contents($view), basename($view).' uses unescaped output.');
        }
    }

    /**
     * Retargeted from `test_the_excluded_legacy_component_is_rendered_by_nothing`.
     * Its subject was "the excluded component stays unreachable"; the component
     * is now deleted, so the property is asserted in its stronger form — the
     * file is gone AND nothing references it, which together mean it cannot
     * come back by accident.
     */
    public function test_the_legacy_attention_component_is_deleted_and_referenced_by_nothing(): void
    {
        $this->assertFileDoesNotExist(
            resource_path('views/components/cockpit/attention.blade.php'),
            'The attention line moved into the Overall status KPI card; the component is deleted.'
        );

        $views = array_merge(
            glob(resource_path('views/components/cockpit/*.blade.php')),
            glob(resource_path('views/projects/*.blade.php'))
        );

        foreach ($views as $view) {
            $this->assertStringNotContainsString('x-cockpit.attention', file_get_contents($view), basename($view).' renders it.');
        }

        // Its styles went with it — a rule with no markup is the other half of
        // the same loaded gun.
        $this->assertStringNotContainsString('cav-attn', file_get_contents(resource_path('css/cockpit.css')));
    }

    public function test_hostile_document_names_and_note_text_are_escaped(): void
    {
        $project = $this->project();

        RamsDocument::factory()->create([
            'project_id' => $project->id,
            'filename'   => '<script>alert(1)</script>',
        ]);

        $this->log($project, [
            'action'      => ProjectActivityLog::ACTION_NOTE_ADDED,
            'description' => '<script>alert(2)</script>',
        ]);

        foreach (['files', 'notes', 'overview'] as $tab) {
            $raw = $this->raw($project, 'rams', $tab);

            $this->assertStringNotContainsString('<script>alert(1)</script>', $raw);
            $this->assertStringNotContainsString('<script>alert(2)</script>', $raw);
            $this->assertStringContainsString('&lt;script&gt;', $raw);
        }
    }

    public function test_a_very_long_document_name_still_renders_200(): void
    {
        $project = $this->project();

        RamsDocument::factory()->create([
            'project_id' => $project->id,
            'filename'   => str_repeat('a', 5000),
        ]);

        $this->assertSame(1, $this->countByClass($this->panel($project, 'rams', 'files'), 'cav-file'));
    }

    // ── The presenter's route map, re-proved through the page ────────────

    public function test_every_mapped_view_route_still_exists(): void
    {
        foreach (CockpitPanelPresenter::viewRouteNames() as $module => $name) {
            $this->assertTrue(\Illuminate\Support\Facades\Route::has($name), "{$module} maps to the missing route `{$name}`.");
        }
    }
}
