<?php

namespace Tests\Feature\Cockpit;

use App\Http\Controllers\ProjectCockpitController;
use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Models\WorksheetSignoff;
use App\Support\Cockpit\CockpitLinkPresenter;
use App\Support\Visits\VisitLinkIssuer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 47, Plan 47-01 — the engineer link, visible and copyable on the
 * Overview tab whenever one exists (47-CONTEXT D-01 remainder / D-02).
 *
 * TASK 1 covers CockpitLinkPresenter in isolation. TASK 2 covers the card as
 * rendered. TASK 3 walks the worksheet revoke through HTTP.
 */
class CockpitLinkCardTest extends TestCase
{
    use RefreshDatabase;

    // -- Task 1: CockpitLinkPresenter ---------------------------------------

    public function test_linkfor_returns_null_for_rams_and_om(): void
    {
        $project = Project::factory()->create();

        $this->assertNotContains('rams', VisitLinkIssuer::moduleKeys());
        $this->assertNotContains('om', VisitLinkIssuer::moduleKeys());

        $presenter = app(CockpitLinkPresenter::class);

        $this->assertNull($presenter->linkFor($project, 'rams'));
        $this->assertNull($presenter->linkFor($project, 'om'));
    }

    public function test_linkfor_returns_null_for_a_module_with_no_document_yet(): void
    {
        $project = Project::factory()->create();

        $presenter = app(CockpitLinkPresenter::class);

        $this->assertNull($presenter->linkFor($project, 'site_survey'));
        $this->assertNull($presenter->linkFor($project, 'worksheet'));
    }

    public function test_linkfor_resolves_the_live_survey_and_its_url(): void
    {
        $project = Project::factory()->create();

        $survey = SiteSurvey::create([
            'project_id'    => $project->id,
            'user_id'       => User::factory()->create()->id,
            'project_name'  => $project->name,
            'status'        => 'draft',
            'surveyor_name' => 'Link Presenter Surveyor',
        ]);

        $presenter = app(CockpitLinkPresenter::class);
        $link      = $presenter->linkFor($project, 'site_survey');

        $this->assertNotNull($link);
        $this->assertSame($survey->publicUrl(), $link['url']);
        $this->assertFalse($link['can_revoke']);
        $this->assertNull($link['revoke_target']);
    }

    public function test_linkfor_resolves_the_most_recently_created_worksheet(): void
    {
        $project = Project::factory()->create();

        $older = Worksheet::factory()->create(['project_id' => $project->id, 'created_at' => now()->subDay()]);
        $newer = Worksheet::factory()->create(['project_id' => $project->id, 'created_at' => now()]);

        $presenter = app(CockpitLinkPresenter::class);
        $link      = $presenter->linkFor($project, 'worksheet');

        $this->assertNotNull($link);
        $this->assertSame($newer->publicUrl(), $link['url']);
        $this->assertNotSame($older->publicUrl(), $link['url']);
        $this->assertTrue($link['can_revoke']);
        $this->assertSame($newer->id, $link['revoke_target']->id);
    }

    public function test_survey_state_vocabulary(): void
    {
        $project = Project::factory()->create();
        $presenter = app(CockpitLinkPresenter::class);

        $issued = SiteSurvey::create([
            'project_id'    => $project->id,
            'user_id'       => User::factory()->create()->id,
            'project_name'  => $project->name,
            'status'        => 'draft',
            'surveyor_name' => 'Issued Surveyor',
        ]);

        $this->assertSame('Issued — awaiting the engineer', $presenter->linkFor($project, 'site_survey')['state']);

        $issued->update(['submitted_at' => now()->subDay()]);
        $this->assertSame(
            'Submitted '.$issued->submitted_at->format('d M Y'),
            $presenter->linkFor($project, 'site_survey')['state']
        );

        $issued->update(['expires_at' => now()->subHour()]);
        $this->assertSame('Link expired', $presenter->linkFor($project, 'site_survey')['state']);
    }

    public function test_worksheet_state_vocabulary(): void
    {
        $project   = Project::factory()->create();
        $presenter = app(CockpitLinkPresenter::class);

        $worksheet = Worksheet::factory()->create([
            'project_id' => $project->id,
            'status'     => Worksheet::STATUS_DRAFT,
        ]);

        $this->assertSame('Draft', $presenter->linkFor($project, 'worksheet')['state']);

        WorksheetSignoff::create([
            'worksheet_id'         => $worksheet->id,
            'client_name'          => 'A Signing Client',
            'signature_png_base64' => 'iVBORw0KGgo=',
            'signed_with_comments' => false,
            'signed_at'            => now()->subDay(),
        ]);

        $worksheet->refresh();
        $this->assertSame(
            'Signed '.$worksheet->latestSignoff()->signed_at->format('d M Y').' by A Signing Client',
            $presenter->linkFor($project, 'worksheet')['state']
        );

        // `access_token_expires_at` is deliberately dropped from $fillable
        // (S-03) — ->update() silently ignores it, so the boot/
        // regenerateAccessToken() convention of direct property assignment
        // is mirrored here with ->forceFill() rather than mass assignment.
        $worksheet->forceFill(['access_token_expires_at' => now()->subHour()])->save();

        // `$project->worksheets` was cached by the earlier calls in this test
        // (Eloquent caches a relation after its first load, same as a real
        // request's single `loadMissing()`); unset it so this read sees the
        // row this test just saved, not the stale in-memory collection.
        $project->unsetRelation('worksheets');

        $this->assertSame('Link expired', $presenter->linkFor($project, 'worksheet')['state']);
    }

    public function test_calling_linkfor_twice_returns_identical_arrays(): void
    {
        $project = Project::factory()->create();
        Worksheet::factory()->create(['project_id' => $project->id]);

        $presenter = app(CockpitLinkPresenter::class);

        $this->assertSame($presenter->linkFor($project, 'worksheet'), $presenter->linkFor($project, 'worksheet'));
    }

    public function test_linkfor_writes_nothing(): void
    {
        $project = Project::factory()->create();
        Worksheet::factory()->create(['project_id' => $project->id]);
        SiteSurvey::create([
            'project_id'    => $project->id,
            'user_id'       => User::factory()->create()->id,
            'project_name'  => $project->name,
            'status'        => 'draft',
            'surveyor_name' => 'No Write Surveyor',
        ]);

        $tables = ['visits', 'site_surveys', 'worksheets', 'worksheet_signoffs', 'project_activity_logs'];

        $before = [];
        foreach ($tables as $table) {
            $before[$table] = DB::table($table)->count();
        }

        $presenter = app(CockpitLinkPresenter::class);
        $presenter->linkFor($project, 'site_survey');
        $presenter->linkFor($project, 'worksheet');
        $presenter->linkFor($project, 'rams');
        $presenter->linkFor($project, 'om');

        foreach ($tables as $table) {
            $this->assertSame($before[$table], DB::table($table)->count(), "CockpitLinkPresenter moved `{$table}`.");
        }
    }

    // -- Task 2: the card as rendered ---------------------------------------

    /**
     * The new copy, checked as a substring against the fence's own
     * DEFERRED_AFFORDANCES and FORBIDDEN_MARKUP constants — not a second,
     * hand-maintained list of them, so the check cannot silently drift from
     * the fence it is meant to prove compliance with.
     *
     * @return array<int, string>
     */
    private function fenceStrings(string $constant): array
    {
        $reflection = new \ReflectionClass(CockpitReadOnlyFenceTest::class);

        $value = $reflection->getConstant($constant);

        return $constant === 'DEFERRED_AFFORDANCES' ? array_keys($value) : $value;
    }

    private function render(Project $project, array $query = []): string
    {
        config(['cockpit.enabled' => true]);

        $url = route('projects.cockpit', ['project' => $project] + $query);

        return $this->actingAs(User::factory()->create())
            ->get($url)
            ->assertOk()
            ->getContent();
    }

    public function test_the_new_copy_collides_with_no_fence_entry(): void
    {
        $newCopy = [
            'Engineer link',
            'Current link:',
            'Revoke and reissue',
            'Revoking mints a fresh link and invalidates the one shown above.',
            'There is no way to revoke a survey link. Superseding this survey below starts a fresh one instead.',
            'Issued — awaiting the engineer',
            'Link expired',
        ];

        foreach ($this->fenceStrings('DEFERRED_AFFORDANCES') as $deferred) {
            foreach ($newCopy as $copy) {
                $this->assertStringNotContainsString(
                    $deferred,
                    $copy,
                    "\"{$copy}\" collides with the deferred affordance \"{$deferred}\"."
                );
            }
        }

        foreach ($this->fenceStrings('FORBIDDEN_MARKUP') as $forbidden) {
            foreach ($newCopy as $copy) {
                $this->assertStringNotContainsString($forbidden, $copy);
            }
        }
    }

    public function test_opening_site_survey_with_a_live_survey_shows_the_link_above_visits(): void
    {
        $project = Project::factory()->create();

        $survey = SiteSurvey::create([
            'project_id'    => $project->id,
            'user_id'       => User::factory()->create()->id,
            'project_name'  => $project->name,
            'status'        => 'draft',
            'surveyor_name' => 'Rendered Surveyor',
        ]);

        // A visit, so the Visits card actually renders — without one the
        // ordering assertion below would be proving the link card sits
        // above a card that was never there.
        Visit::factory()->backfilledFromSurvey($survey)->create([
            'project_id'     => $project->id,
            'title'          => 'Site survey',
            'scheduled_date' => '2026-08-11',
        ]);

        $body = $this->render($project, ['module' => 'site_survey', 'tab' => 'overview']);

        $this->assertStringContainsString('Engineer link', $body);
        $this->assertStringContainsString(
            '<a href="'.e($survey->publicUrl()).'">'.e($survey->publicUrl()).'</a>',
            $body
        );
        $this->assertStringContainsString('Issued — awaiting the engineer', $body);
        $this->assertStringContainsString(
            'There is no way to revoke a survey link. Superseding this survey below starts a fresh one instead.',
            $body
        );

        // The link card is ABOVE the Visits card.
        $linkPos   = strpos($body, 'Engineer link');
        $visitsPos = strpos($body, 'cav-panel__card-head">Visits');
        $this->assertNotFalse($linkPos);
        $this->assertNotFalse($visitsPos);
        $this->assertLessThan($visitsPos, $linkPos);
    }

    public function test_opening_worksheet_with_a_worksheet_shows_the_link_and_a_working_revoke_form(): void
    {
        $project   = Project::factory()->create();
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

        $body = $this->render($project, ['module' => 'worksheet', 'tab' => 'overview']);

        $this->assertStringContainsString('Engineer link', $body);
        $this->assertStringContainsString(
            '<a href="'.e($worksheet->publicUrl()).'">'.e($worksheet->publicUrl()).'</a>',
            $body
        );
        $this->assertStringContainsString(
            'action="'.e(route('worksheets.revoke-token', $worksheet)).'"',
            $body
        );
        $this->assertStringContainsString('Revoke and reissue', $body);
        $this->assertStringContainsString('Revoking mints a fresh link and invalidates the one shown above.', $body);

        // No "no revoke" sentence on a module that CAN revoke.
        $this->assertStringNotContainsString('There is no way to revoke a survey link.', $body);
    }

    public function test_a_module_with_no_document_yet_shows_no_link_card(): void
    {
        $project = Project::factory()->create();

        foreach (['site_survey', 'worksheet'] as $moduleKey) {
            $body = $this->render($project, ['module' => $moduleKey, 'tab' => 'overview']);

            $this->assertStringNotContainsString('Engineer link', $body);
            $this->assertStringNotContainsString('Current link:', $body);
        }
    }

    public function test_rams_and_om_never_show_a_link_card(): void
    {
        $project = Project::factory()->create();

        foreach (['rams', 'om'] as $moduleKey) {
            foreach (ProjectCockpitController::TABS as $tab) {
                $body = $this->render($project, ['module' => $moduleKey, 'tab' => $tab]);

                $this->assertStringNotContainsString('Engineer link', $body);
                $this->assertStringNotContainsString('Current link:', $body);
            }
        }
    }

    /**
     * Scoped to the `.cav-panel` subtree, not the whole response — the page
     * shell's own @vite bundle tag is a real, pre-existing `<script` outside
     * this plan's scope (the same scoping `CockpitReadOnlyFenceTest` applies
     * to `.cav-cockpit`).
     */
    private function panelRegion(string $html): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $node = (new \DOMXPath($dom))
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' cav-panel ')]")
            ->item(0);

        $this->assertNotNull($node, 'The cav-panel element was not found.');

        return html_entity_decode($dom->saveHTML($node), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    public function test_the_card_renders_no_select_no_script_and_no_handler_attribute(): void
    {
        $project   = Project::factory()->create();
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

        $body   = $this->render($project, ['module' => 'worksheet', 'tab' => 'overview']);
        $region = $this->panelRegion($body);

        $this->assertStringContainsString('Engineer link', $region, 'The panel region does not carry the card — the check below would be vacuous.');

        $forbidden = ['<select', '<script', 'onclick', 'wire:', 'x-on:', '@click', 'x-data', 'x-show', 'x-init', 'x-if', 'x-text'];

        foreach ($forbidden as $banned) {
            $this->assertStringNotContainsString($banned, $region);
        }

        // The URL is escaped output, never a raw directive.
        $this->assertStringNotContainsString('{!!', $region);
    }

    public function test_opening_an_unmapped_module_key_still_renders_no_link_card(): void
    {
        $this->assertNotContains('snagging', VisitLinkIssuer::moduleKeys());

        $project = Project::factory()->create();

        $body = $this->render($project, ['module' => 'snagging']);

        $this->assertStringNotContainsString('Engineer link', $body);
    }

    // -- Task 3: the revoke, walked through HTTP -----------------------------

    /**
     * The revoke button's REAL effect, driven through the actual HTTP route
     * rather than asserted from the presenter alone — proving the drawer's
     * control and `worksheets.revoke-token` agree about what "revoke and
     * reissue" means, and that it is a replace, never a second row.
     */
    public function test_the_revoke_replaces_the_shown_token_through_http(): void
    {
        $project   = Project::factory()->create();
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

        $oldToken = $worksheet->access_token;

        $before = $this->render($project, ['module' => 'worksheet', 'tab' => 'overview']);
        $this->assertStringContainsString($oldToken, $before);

        $worksheetsBefore = DB::table('worksheets')->count();

        $this->actingAs(User::factory()->create())
            ->post(route('worksheets.revoke-token', $worksheet))
            ->assertRedirect();

        $this->assertSame(
            $worksheetsBefore,
            DB::table('worksheets')->count(),
            'The revoke updates one row; it must not create a second one.'
        );

        $after = $this->render($project, ['module' => 'worksheet', 'tab' => 'overview']);

        $newToken = $worksheet->fresh()->access_token;

        $this->assertNotSame($oldToken, $newToken, 'Revoking must mint a genuinely different token.');
        $this->assertStringNotContainsString($oldToken, $after, 'The old token must no longer appear anywhere on the drawer.');
        $this->assertStringContainsString($newToken, $after, 'The new token must appear in its place.');
    }
}
