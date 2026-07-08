<?php

namespace Tests\Feature;

use App\Models\OmManual;
use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Worksheet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * GET /search — global search endpoint feeding the ⌘K command palette.
 *
 * Covers:
 *  - auth gate (401/redirect for guests)
 *  - short-query short-circuit (empty groups, no DB round-trip)
 *  - grouped results across projects / RAMS / surveys / O&M / worksheets
 *  - LIKE wildcards on client-name + reference columns
 *  - group-limit cap so a broad match doesn't flood the palette
 */
class GlobalSearchControllerTest extends TestCase
{
    use RefreshDatabase;

    private function authed(): User
    {
        $u = User::factory()->create();
        $this->actingAs($u);
        return $u;
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/search?q=anything')->assertRedirect(route('login'));
    }

    public function test_empty_query_returns_empty_groups(): void
    {
        $this->authed();
        $this->get('/search?q=')
            ->assertOk()
            ->assertExactJson(['groups' => [], 'query' => '']);
    }

    public function test_single_character_query_is_short_circuited(): void
    {
        $this->authed();
        Project::factory()->create(['name' => 'Alpha']);
        $this->get('/search?q=a')
            ->assertOk()
            ->assertJson(['groups' => []]);
    }

    public function test_project_match_by_name_returns_projects_group(): void
    {
        $u = $this->authed();
        Project::factory()->create(['user_id' => $u->id, 'name' => 'Volkswagen Group boardroom refresh']);
        Project::factory()->create(['user_id' => $u->id, 'name' => 'Metronet HQ Manchester']);

        $res = $this->get('/search?q=volk')->assertOk();

        $groups = $res->json('groups');
        $this->assertNotEmpty($groups);
        $projects = collect($groups)->firstWhere('key', 'projects');
        $this->assertNotNull($projects);
        $this->assertSame('Projects', $projects['label']);
        $this->assertCount(1, $projects['items']);
        $this->assertSame('Volkswagen Group boardroom refresh', $projects['items'][0]['title']);
        $this->assertSame('project', $projects['items'][0]['kind']);
        $this->assertStringContainsString('/projects/', $projects['items'][0]['url']);
    }

    public function test_project_match_by_client_name(): void
    {
        $u = $this->authed();
        Project::factory()->create([
            'user_id'     => $u->id,
            'name'        => 'Boardroom refresh',
            'client_name' => 'Volkswagen Group',
        ]);

        $groups = $this->get('/search?q=volkswagen')->assertOk()->json('groups');
        $projects = collect($groups)->firstWhere('key', 'projects');
        $this->assertNotNull($projects);
        $this->assertSame('Boardroom refresh', $projects['items'][0]['title']);
    }

    public function test_project_match_by_reference(): void
    {
        $u = $this->authed();
        Project::factory()->create([
            'user_id' => $u->id,
            'name'    => 'Boardroom refresh',
            'ref'     => '21CQ30698',
        ]);

        $groups = $this->get('/search?q=30698')->assertOk()->json('groups');
        $projects = collect($groups)->firstWhere('key', 'projects');
        $this->assertNotNull($projects);
        $this->assertSame(1, count($projects['items']));
    }

    public function test_worksheet_match_returns_worksheets_group(): void
    {
        $u = $this->authed();
        $project = Project::factory()->create(['user_id' => $u->id]);
        Worksheet::create([
            'user_id'      => $u->id,
            'project_id'   => $project->id,
            'project_name' => 'Yeomans Drive boardroom',
            'client_name'  => 'Volkswagen',
            'site_address' => '1 Test St',
            'project_ref'  => 'Q-001',
            'status'       => Worksheet::STATUS_DRAFT,
        ]);

        $groups = $this->get('/search?q=yeomans')->assertOk()->json('groups');
        $ws = collect($groups)->firstWhere('key', 'worksheets');
        $this->assertNotNull($ws);
        $this->assertSame('Worksheets', $ws['label']);
        $this->assertSame('Yeomans Drive boardroom', $ws['items'][0]['title']);
        $this->assertSame('worksheet', $ws['items'][0]['kind']);
    }

    public function test_group_limit_caps_results_at_five(): void
    {
        $u = $this->authed();

        for ($i = 1; $i <= 7; $i++) {
            Project::factory()->create([
                'user_id' => $u->id,
                'name'    => "Marker project {$i}",
            ]);
        }

        $groups = $this->get('/search?q=marker')->assertOk()->json('groups');
        $projects = collect($groups)->firstWhere('key', 'projects');
        $this->assertNotNull($projects);
        $this->assertCount(5, $projects['items']);
    }

    public function test_query_with_percent_wildcard_is_escaped(): void
    {
        // A bare `%` in the query should NOT expand to "match everything" —
        // the controller escapes user LIKE metacharacters before wrapping
        // the value in wildcards.
        $u = $this->authed();
        Project::factory()->create(['user_id' => $u->id, 'name' => 'Ordinary project']);
        Project::factory()->create(['user_id' => $u->id, 'name' => 'Another one']);

        $groups = $this->get('/search?q=%25%25')->assertOk()->json('groups');   // ?q=%%
        // No project actually contains the literal string "%%" so the group
        // should be empty (or absent).
        $projects = collect($groups)->firstWhere('key', 'projects');
        $this->assertNull($projects, 'Bare LIKE wildcard must not leak into the query.');
    }

    public function test_response_shape_is_stable_and_includes_query_echo(): void
    {
        $this->authed();
        $res = $this->get('/search?q=xyzzy-nomatch')->assertOk();
        $this->assertSame('xyzzy-nomatch', $res->json('query'));
        $this->assertSame([], $res->json('groups'));
    }
}
