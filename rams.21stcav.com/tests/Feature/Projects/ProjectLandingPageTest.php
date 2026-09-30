<?php

namespace Tests\Feature\Projects;

use App\Models\Project;
use App\Models\User;
use App\Models\Visit;
use App\Support\Cockpit\ProjectLanding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quick task 260930-cl9 — THE COCKPIT BECOMES THE PROJECT LANDING PAGE, and
 * the nine-tab page becomes an admin-only top-menu escape hatch (SCOPE.md
 * D-01b, D-03).
 *
 * WHY EVERY TEST HERE RENDERS A STATE RATHER THAN ASSERTING A HELPER'S RETURN
 * VALUE. Six defects in three weeks got through green suites, every one of
 * them an assertion that exercised a single state. The three axes that can
 * independently break this change are:
 *
 *   COCKPIT_ENABLED   on / off   — off must fall back, never 404 a click
 *   viewer role       admin / not — the escape hatch is admin-only
 *   project in context yes / no   — the menu item has no fixed href
 *
 * plus a project WITH delivery data and an EMPTY one, because the cockpit's
 * own docs (config/cockpit.php:19-38) warn it renders an empty spine before
 * `visits:backfill --apply` has run and that is now the first page everyone
 * sees. Each combination below is a real HTTP render, and the destination is
 * then FOLLOWED to prove it is not a 404.
 *
 * STATES RENDERED BY THIS FILE: 47 real HTTP renders, across 13 of the 14 tests
 * (the helper test renders none — it only builds URLs).
 * 24 of them live in test_the_state_matrix_is_complete, which walks the full
 * flag x role x project-shape cross product and ASSERTS both its render count
 * (24) and that its eight landings are eight DISTINCT states rather than eight
 * repeats — so deleting a case fails the file instead of quietly shrinking the
 * coverage. The remaining 23 are the per-scenario tests above it, each of
 * which names its state in its own failure message.
 *
 * NOT IN THIS TASK, and deliberately not asserted here: engineer-link
 * display/copy/revoke, visit accept/send-back/note/snag surfacing, and
 * returned-link review. SCOPE.md D-02 owns those; they are the next task.
 */
class ProjectLandingPageTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin']);
    }

    /** The default factory role is not 'admin', so this is the non-admin. */
    private function engineer(): User
    {
        $user = User::factory()->create();

        $this->assertFalse($user->isAdmin(), 'The default factory user must be a non-admin for this file to mean anything.');

        return $user;
    }

    private function project(string $name = 'Landing Test Job'): Project
    {
        return Project::factory()->create([
            'name'            => $name,
            'client_name'     => 'Acme Ltd',
            'quote_reference' => 'Q-460001',
            'site_address'    => '8 Example Street, Leeds',
            'status'          => Project::STATUS_INSTALLING,
        ]);
    }

    /** A project with delivery data on it — the non-empty spine. */
    private function projectWithData(): Project
    {
        $project = $this->project('Landing Test Job With Data');

        Visit::factory()->count(2)->create(['project_id' => $project->id]);

        $this->assertSame(2, Visit::where('project_id', $project->id)->count());

        return $project;
    }

    /**
     * One rendered state. The label is not decoration: it is the failure
     * message, so a red run names the state instead of a status code.
     */
    private function render(User $as, string $url, string $state): string
    {
        $response = $this->actingAs($as)->get($url);

        $this->assertTrue(
            $response->isOk(),
            'State "'.$state.'" did not render — HTTP '.$response->status().' from '.$url
        );

        return $response->getContent();
    }

    /**
     * The href of the admin-only "Classic" top-menu item, or null when the
     * item is not rendered at all. Matched on the title copy, which is the
     * only part of the markup unique to this item.
     */
    private function classicMenuHref(string $html): ?string
    {
        return preg_match('/<a href="([^"]+)"[^>]*title="[^"]*nine-tab[^"]*"/', $html, $m) === 1
            ? $m[1]
            : null;
    }

    /**
     * Every project-href inside THAT PROJECT'S OWN ROW, deduplicated.
     *
     * SCOPED TO THE ROW ON PURPOSE. A document-wide regex also catches the
     * shared edit-action-bar's `url()->previous()` link, so after a test has
     * GET the cockpit once, the NEXT page in the same test carries a stale
     * cockpit href that has nothing to do with the row. That is the exact trap
     * FlagOffBehaviourUnchangedTest warns about at its own snapshot
     * comparison, and it produced a false red here before this was scoped.
     */
    /**
     * The part of the page that actually holds the project rows.
     *
     * MEASURED, NOT GUESSED: the shared layout embeds the edit-action-bar's
     * own usage note, which renders a LIVE `url()->previous()` anchor carrying
     * `class="btn btn-outline btn-sm"` on EVERY page — including the project
     * list. Once a test has GET the cockpit, that anchor echoes the cockpit URL
     * back on the next page and a document-wide search reads it as a third row
     * link. It sits above the table, so bounding the search to the table body
     * (or, on the dashboard, to the first health row onwards) removes it
     * without depending on its markup.
     */
    private function rowRegion(string $html): string
    {
        if (preg_match('/<tbody>(.*?)<\/tbody>/s', $html, $tbody) === 1) {
            return $tbody[1];
        }

        $firstRow = strpos($html, 'dash-health-row"');

        return $firstRow === false ? $html : substr($html, $firstRow);
    }

    private function rowHrefs(string $html, Project $project): array
    {
        $rowLinkClasses = implode('|', [
            'proj-name-link',            // project list — the name
            'btn btn-outline btn-sm',    // project list — the View button
            'dash-health-row__link',     // dashboard    — the name
            'btn btn-ghost btn-sm',      // dashboard    — the View button
        ]);

        preg_match_all(
            '/<a\s+href="([^"]*\/projects\/'.$project->id.'(?:\/cockpit)?)"\s+class="(?:'.$rowLinkClasses.')"/',
            $this->rowRegion($html),
            $m
        );

        $this->assertCount(
            2,
            $m[1],
            'Each page must offer exactly two row links for project '.$project->id.' (the name and the View button); '
            .'found '.count($m[1]).'. A third is usually the shared edit-action-bar echoing url()->previous() — '
            .'fail loudly rather than average it into the result.'
        );

        return array_values(array_unique($m[1]));
    }

    // ── 1. Clicking a project opens the cockpit ──────────────────────────

    public function test_the_project_list_rows_point_at_the_cockpit_with_the_flag_on(): void
    {
        config(['cockpit.enabled' => true]);
        $admin   = $this->admin();
        $project = $this->project();

        $html = $this->render($admin, route('projects.index'), 'list · admin · flag ON');

        $this->assertSame(
            [url('/projects/'.$project->id.'/cockpit')],
            $this->rowHrefs($html, $project),
            'With the flag on, every project-row link must open the cockpit and none may still point at the nine-tab page.'
        );
    }

    public function test_the_project_list_rows_fall_back_to_the_nine_tab_page_with_the_flag_off(): void
    {
        config(['cockpit.enabled' => false]);
        $admin   = $this->admin();
        $project = $this->project();

        $html = $this->render($admin, route('projects.index'), 'list · admin · flag OFF');

        $this->assertSame(
            [url('/projects/'.$project->id)],
            $this->rowHrefs($html, $project),
            'With the flag off a project click must land on the nine-tab page, never on the cockpit 404.'
        );
        $this->assertStringNotContainsString('/projects/'.$project->id.'/cockpit', $html);
    }

    /**
     * BOTH FLAG STATES IN ONE ASSERTION, and the destination is followed.
     * This is the assertion that covers the fallback: it proves the rendered
     * href and the status code of GETting it agree in each state.
     */
    public function test_the_rendered_destination_is_reachable_in_both_flag_states(): void
    {
        $admin   = $this->admin();
        $project = $this->project();

        $expected = [
            true  => url('/projects/'.$project->id.'/cockpit'),
            false => url('/projects/'.$project->id),
        ];

        foreach ([true, false] as $enabled) {
            config(['cockpit.enabled' => $enabled]);

            $html = $this->render(
                $admin,
                route('projects.index'),
                'list · admin · flag '.($enabled ? 'ON' : 'OFF').' · destination followed'
            );

            $hrefs = $this->rowHrefs($html, $project);

            $this->assertSame([$expected[$enabled]], $hrefs, 'Wrong destination with the flag '.var_export($enabled, true));

            $this->actingAs($admin)->get($hrefs[0])->assertOk();
        }
    }

    public function test_the_dashboard_rows_follow_the_same_rule_in_both_flag_states(): void
    {
        $admin = $this->admin();

        foreach ([true, false] as $enabled) {
            config(['cockpit.enabled' => $enabled]);
            $project = $this->project('Dashboard Row '.var_export($enabled, true));

            $html = $this->render($admin, route('dashboard'), 'dashboard · admin · flag '.($enabled ? 'ON' : 'OFF'));

            $this->assertSame(
                [$enabled ? url('/projects/'.$project->id.'/cockpit') : url('/projects/'.$project->id)],
                $this->rowHrefs($html, $project),
                'The dashboard health rows must use the same landing rule as the project list.'
            );
        }
    }

    public function test_a_non_admin_gets_the_same_landing_rule(): void
    {
        $engineer = $this->engineer();

        foreach ([true, false] as $enabled) {
            config(['cockpit.enabled' => $enabled]);
            $project = $this->project('Engineer Row '.var_export($enabled, true));

            $html = $this->render($engineer, route('projects.index'), 'list · non-admin · flag '.($enabled ? 'ON' : 'OFF'));

            $this->assertSame(
                [$enabled ? url('/projects/'.$project->id.'/cockpit') : url('/projects/'.$project->id)],
                $this->rowHrefs($html, $project),
                'The landing rule is not role-scoped — only the escape hatch is.'
            );
        }
    }

    // ── 2. The cockpit renders for a project with data and an empty one ───

    public function test_the_cockpit_renders_for_a_project_with_data_and_for_an_empty_one(): void
    {
        config(['cockpit.enabled' => true]);
        $admin = $this->admin();

        $withData = $this->projectWithData();
        $empty    = $this->project('Landing Test Job Empty');

        $this->assertSame(0, Visit::where('project_id', $empty->id)->count(), 'The empty project must really have no visits.');

        foreach (['with data' => $withData, 'empty' => $empty] as $label => $project) {
            $html = $this->render($admin, route('projects.cockpit', $project), 'cockpit · '.$label);

            $this->assertStringContainsString('cav-cockpit', $html, 'The cockpit shell must render for a project '.$label.'.');
            $this->assertStringContainsString($project->name, $html);
        }
    }

    // ── 3. The nine-tab page is untouched and still works ────────────────

    public function test_the_nine_tab_page_still_renders_at_its_own_url_in_both_flag_states(): void
    {
        $admin = $this->admin();

        foreach ([true, false] as $enabled) {
            config(['cockpit.enabled' => $enabled]);
            $project = $this->project('Escape Hatch '.var_export($enabled, true));

            $url = route('projects.show', $project);
            $this->assertSame(url('/projects/'.$project->id), $url, 'The nine-tab page URL must stay /projects/{id}.');

            $html = $this->render($admin, $url, 'nine-tab page · admin · flag '.($enabled ? 'ON' : 'OFF'));

            $this->assertStringContainsString('ws-tab', $html, 'The nine-tab workspace strip must still render.');
        }
    }

    public function test_a_non_admin_can_still_open_the_nine_tab_page_directly(): void
    {
        config(['cockpit.enabled' => true]);
        $project = $this->project();

        $html = $this->render($this->engineer(), route('projects.show', $project), 'nine-tab page · non-admin · flag ON');

        $this->assertStringContainsString('ws-tab', $html, 'Nothing about this task removes access to the page itself.');
    }

    // ── 4. The admin-only top-menu item ──────────────────────────────────

    public function test_the_classic_menu_item_is_admin_only(): void
    {
        config(['cockpit.enabled' => true]);
        $this->project();

        $adminHtml = $this->render($this->admin(), route('projects.index'), 'menu item · admin');
        $this->assertNotNull($this->classicMenuHref($adminHtml), 'An admin must get the Classic menu item.');
        $this->assertMatchesRegularExpression(
            '/title="[^"]*nine-tab[^"]*"[^>]*>.*?Classic/s',
            $adminHtml,
            'The item must carry the visible label "Classic".'
        );

        $engineerHtml = $this->render($this->engineer(), route('projects.index'), 'menu item · non-admin');
        $this->assertNull($this->classicMenuHref($engineerHtml), 'A non-admin must NOT get the Classic menu item.');
    }

    /**
     * WITH NO PROJECT IN CONTEXT the item points at the project list, which is
     * the picker. Never disabled and never a dead link.
     */
    public function test_the_classic_menu_item_falls_back_to_the_project_list_with_no_project_in_context(): void
    {
        config(['cockpit.enabled' => true]);
        $admin = $this->admin();
        $this->project();

        foreach ([route('projects.index'), route('dashboard')] as $url) {
            $html = $this->render($admin, $url, 'menu item · no project in context · '.$url);

            $this->assertSame(
                route('projects.index'),
                $this->classicMenuHref($html),
                'With no project in context the Classic item must offer the project list as the picker.'
            );
        }
    }

    /**
     * WITH A PROJECT IN CONTEXT — including on the cockpit itself, which is the
     * whole reason the user asked for this item — it points at THAT project's
     * nine-tab page.
     */
    public function test_the_classic_menu_item_targets_the_project_in_context(): void
    {
        config(['cockpit.enabled' => true]);
        $admin   = $this->admin();
        $project = $this->project();

        $contexts = [
            'from the cockpit'       => route('projects.cockpit', $project),
            'from the nine-tab page' => route('projects.show', $project),
            'from the asset list'    => route('projects.asset-list', $project),
        ];

        foreach ($contexts as $label => $url) {
            $html = $this->render($admin, $url, 'menu item · project in context · '.$label);

            $this->assertSame(
                url('/projects/'.$project->id),
                $this->classicMenuHref($html),
                'The Classic item must target the project in context, '.$label.'.'
            );
        }
    }

    /** The layout must not blow up on a page whose {project} is not a project. */
    public function test_the_menu_item_survives_a_page_with_no_project_route_parameter(): void
    {
        config(['cockpit.enabled' => true]);
        $admin = $this->admin();

        $html = $this->render($admin, route('labour-resources.index'), 'menu item · unrelated page');

        $this->assertSame(route('projects.index'), $this->classicMenuHref($html));
    }

    // ── 5. The helper itself ─────────────────────────────────────────────

    public function test_the_helper_accepts_a_bare_id_as_well_as_a_model(): void
    {
        $project = $this->project();

        config(['cockpit.enabled' => true]);
        $this->assertSame(ProjectLanding::url($project), ProjectLanding::url($project->id));
        $this->assertTrue(ProjectLanding::cockpitIsTheFrontDoor());

        config(['cockpit.enabled' => false]);
        $this->assertSame(ProjectLanding::url($project), ProjectLanding::url($project->id));
        $this->assertFalse(ProjectLanding::cockpitIsTheFrontDoor());
        $this->assertSame(url('/projects/'.$project->id), ProjectLanding::url($project));
    }

    // ── 6. The measured state count ──────────────────────────────────────

    /**
     * THE FULL CROSS PRODUCT IN ONE TEST, and the count is asserted.
     *
     * flag(2) x viewer role(2) x project shape(2) = 8 landings. Each landing
     * is three renders: the list that produced the href, a GET of that href,
     * and the nav on the page it reached. 24 renders, 8 distinct landing
     * states. The two assertions at the end are what make the SUMMARY's count
     * a measurement.
     */
    public function test_the_state_matrix_is_complete(): void
    {
        $admin    = $this->admin();
        $engineer = $this->engineer();
        $withData = $this->projectWithData();
        $empty    = $this->project('Matrix Empty');

        $count   = 0;
        $renders = 0;
        $landings = [];

        foreach ([true, false] as $enabled) {
            config(['cockpit.enabled' => $enabled]);

            foreach ([$admin, $engineer] as $viewer) {
                foreach ([$withData, $empty] as $project) {
                    // The list row, and then the destination it rendered.
                    $html  = $this->actingAs($viewer)->get(route('projects.index'))->assertOk()->getContent();
                    $renders++;
                    $hrefs = $this->rowHrefs($html, $project);

                    $this->assertSame(
                        [$enabled ? url('/projects/'.$project->id.'/cockpit') : url('/projects/'.$project->id)],
                        $hrefs
                    );

                    $this->actingAs($viewer)->get($hrefs[0])->assertOk();
                    $renders++;
                    $count++;
                    $landings[] = ($enabled ? 'on' : 'off')
                        .'|'.($viewer->isAdmin() ? 'admin' : 'engineer')
                        .'|'.$project->id;

                    // The escape hatch, from that destination.
                    $navHtml = $this->actingAs($viewer)->get($hrefs[0])->assertOk()->getContent();
                    $renders++;

                    if ($viewer->isAdmin()) {
                        $this->assertSame(url('/projects/'.$project->id), $this->classicMenuHref($navHtml));
                    } else {
                        $this->assertNull($this->classicMenuHref($navHtml));
                    }
                }
            }
        }

        $this->assertSame(8, $count, 'flag(2) x role(2) x project-shape(2) = 8 rendered landings.');
        $this->assertCount(8, array_unique($landings), 'All eight landings must be DISTINCT states, not eight repeats.');
        $this->assertSame(24, $renders, '8 landings x (list render + destination GET + nav render) = 24 renders.');
    }
}
