<?php

namespace Tests\Feature\Cockpit;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\User;
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
}
