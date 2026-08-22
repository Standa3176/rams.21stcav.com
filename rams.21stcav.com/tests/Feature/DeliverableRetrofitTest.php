<?php

namespace Tests\Feature;

use App\Models\CommissioningSignoff;
use App\Models\InstallProgramme;
use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Feature tests for the D-17 back-catalogue retrofit migration
 * (database/migrations/2026_08_22_150000_backfill_project_deliverables_for_existing_projects.php).
 *
 * RefreshDatabase runs ALL migrations — including this backfill one — before
 * every test, but at that point there are zero `projects` rows, so the
 * migration's own up() call (via the normal migrator) is a no-op. To exercise
 * the actual inference logic against seeded fixtures, each test here
 * `require`s the migration file directly (the same pattern Laravel's own
 * migrator uses internally for anonymous-class migrations — the file
 * `return`s a fresh `new class extends Migration {...}` instance every time
 * it is executed) and calls ->up() manually after seeding data.
 */
class DeliverableRetrofitTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION_PATH = 'migrations/2026_08_22_150000_backfill_project_deliverables_for_existing_projects.php';

    private function runBackfill(): void
    {
        /** @var Migration $migration */
        $migration = require database_path(self::MIGRATION_PATH);
        $migration->up();
    }

    // ── Case 1: RAMS present, nothing else ──────────────────────────────────

    public function test_project_with_rams_document_backfills_rams_required_and_rest_not_yet_decided(): void
    {
        $project = Project::factory()->create();
        RamsDocument::factory()->create(['project_id' => $project->id]);

        $this->runBackfill();

        $this->assertDatabaseHas('project_deliverables', [
            'project_id' => $project->id,
            'deliverable_key' => 'rams',
            'state' => 'required',
        ]);

        foreach (['site_survey', 'worksheet', 'om', 'cable_schedule', 'install_programme', 'drawings', 'snagging', 'programming'] as $key) {
            $this->assertDatabaseHas('project_deliverables', [
                'project_id' => $project->id,
                'deliverable_key' => $key,
                'state' => 'not_yet_decided',
            ]);
        }

        $this->assertDatabaseCount('project_deliverables', 9);
    }

    // ── Case 2: through-relation Snagging inference ─────────────────────────

    public function test_project_with_commissioning_signoff_backfills_snagging_required(): void
    {
        $project = Project::factory()->create();
        $programme = InstallProgramme::factory()->create(['project_id' => $project->id]);
        CommissioningSignoff::factory()->create(['install_programme_id' => $programme->id]);

        $this->runBackfill();

        $this->assertDatabaseHas('project_deliverables', [
            'project_id' => $project->id,
            'deliverable_key' => 'snagging',
            'state' => 'required',
        ]);

        // install_programme itself also has a row (the programme exists),
        // proving the two counted keys derived from the same install
        // programme (its own presence, and its signoff) are independent.
        $this->assertDatabaseHas('project_deliverables', [
            'project_id' => $project->id,
            'deliverable_key' => 'install_programme',
            'state' => 'required',
        ]);

        $this->assertDatabaseHas('project_deliverables', [
            'project_id' => $project->id,
            'deliverable_key' => 'rams',
            'state' => 'not_yet_decided',
        ]);
    }

    // ── Case 3: zero related documents of any kind ──────────────────────────

    public function test_project_with_no_related_documents_backfills_everything_not_yet_decided(): void
    {
        $project = Project::factory()->create();

        $this->runBackfill();

        foreach ([
            'site_survey', 'rams', 'worksheet', 'om', 'cable_schedule',
            'install_programme', 'drawings', 'snagging', 'programming',
        ] as $key) {
            $this->assertDatabaseHas('project_deliverables', [
                'project_id' => $project->id,
                'deliverable_key' => $key,
                'state' => 'not_yet_decided',
            ]);
        }

        $this->assertDatabaseCount('project_deliverables', 9);
    }

    // ── Case 4: idempotency — run twice, no duplicates ──────────────────────

    public function test_running_backfill_twice_does_not_duplicate_rows_or_audits(): void
    {
        $project = Project::factory()->create();
        RamsDocument::factory()->create(['project_id' => $project->id]);

        $this->runBackfill();

        $deliverableCountAfterFirstRun = DB::table('project_deliverables')
            ->where('project_id', $project->id)
            ->count();
        $auditCountAfterFirstRun = DB::table('project_deliverable_audits')
            ->whereIn('project_deliverable_id', DB::table('project_deliverables')->where('project_id', $project->id)->pluck('id'))
            ->count();

        $this->assertSame(9, $deliverableCountAfterFirstRun);
        $this->assertSame(9, $auditCountAfterFirstRun);

        $this->runBackfill();

        $deliverableCountAfterSecondRun = DB::table('project_deliverables')
            ->where('project_id', $project->id)
            ->count();
        $auditCountAfterSecondRun = DB::table('project_deliverable_audits')
            ->whereIn('project_deliverable_id', DB::table('project_deliverables')->where('project_id', $project->id)->pluck('id'))
            ->count();

        $this->assertSame(9, $deliverableCountAfterSecondRun, 'Second run must not duplicate project_deliverables rows');
        $this->assertSame(9, $auditCountAfterSecondRun, 'Second run must not duplicate project_deliverable_audits rows');

        // And the state written by the first run is untouched — still 'required'.
        $this->assertDatabaseHas('project_deliverables', [
            'project_id' => $project->id,
            'deliverable_key' => 'rams',
            'state' => 'required',
        ]);
    }

    // ── Case 5: no admin-role user exists — fallback must not SQL-error ─────

    public function test_backfill_does_not_error_when_no_admin_role_user_exists(): void
    {
        // Only a plain 'user'-role account exists (role defaults to 'user'
        // per 2026_03_05_000001_add_role_to_users_table.php) — no admin.
        User::factory()->create();

        $project = Project::factory()->create();
        RamsDocument::factory()->create(['project_id' => $project->id]);

        $this->runBackfill();

        $this->assertDatabaseHas('project_deliverables', [
            'project_id' => $project->id,
            'deliverable_key' => 'rams',
            'state' => 'required',
        ]);
        $this->assertDatabaseCount('project_deliverables', 9);
    }
}
