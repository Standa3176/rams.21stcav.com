<?php

namespace Tests\Feature\Console;

use App\Models\InstallProgramme;
use App\Models\InstallRecord;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VIS-09 / D-06 — `install-records:backfill`.
 *
 * Links pre-Phase-45 `install_programmes` rows to one durable
 * `install_records` row per project. Dry-run by DEFAULT, per the
 * `BackfillCablePortFksCommand` precedent: a migration that wrote these rows
 * would be an un-reviewable data change against live production programmes.
 *
 * Idempotency is the load-bearing property — a second `--apply` must be a
 * total no-op, because this command will realistically be run more than once
 * during the cutover.
 *
 * @see app/Console/Commands/BackfillInstallRecordsCommand.php
 */
class BackfillInstallRecordsCommandTest extends TestCase
{
    use RefreshDatabase;

    // ── Dry run ──────────────────────────────────────────────────────────────

    public function test_dry_run_is_the_default_and_writes_nothing(): void
    {
        $project   = Project::factory()->create();
        $programme = InstallProgramme::factory()->create(['project_id' => $project->id]);

        $this->artisan('install-records:backfill')->assertSuccessful();

        $this->assertSame(0, InstallRecord::count());
        $this->assertNull($programme->fresh()->install_record_id);
    }

    // ── Apply ────────────────────────────────────────────────────────────────

    public function test_apply_links_every_generation_for_a_project_to_one_record(): void
    {
        $project = Project::factory()->create();

        $archived = InstallProgramme::factory()->create([
            'project_id' => $project->id,
            'status'     => InstallProgramme::STATUS_ARCHIVED,
        ]);
        $active = InstallProgramme::factory()->create([
            'project_id' => $project->id,
            'status'     => InstallProgramme::STATUS_ACTIVE,
        ]);

        $this->artisan('install-records:backfill', ['--apply' => true])->assertSuccessful();

        $record = InstallRecord::where('project_id', $project->id)->firstOrFail();

        $this->assertSame(1, InstallRecord::count());
        $this->assertSame($record->id, $archived->fresh()->install_record_id);
        $this->assertSame($record->id, $active->fresh()->install_record_id);
    }

    public function test_each_project_gets_its_own_record(): void
    {
        $a = Project::factory()->create();
        $b = Project::factory()->create();

        $pa = InstallProgramme::factory()->create(['project_id' => $a->id]);
        $pb = InstallProgramme::factory()->create(['project_id' => $b->id]);

        $this->artisan('install-records:backfill', ['--apply' => true])->assertSuccessful();

        $this->assertSame(2, InstallRecord::count());
        $this->assertNotSame($pa->fresh()->install_record_id, $pb->fresh()->install_record_id);
    }

    // ── Idempotency ──────────────────────────────────────────────────────────

    public function test_a_second_apply_is_a_no_op(): void
    {
        $project   = Project::factory()->create();
        $programme = InstallProgramme::factory()->create(['project_id' => $project->id]);

        $this->artisan('install-records:backfill', ['--apply' => true])->assertSuccessful();

        $recordCountAfterFirst = InstallRecord::count();
        $recordIdAfterFirst    = $programme->fresh()->install_record_id;
        $updatedAtAfterFirst   = $programme->fresh()->updated_at->toISOString();

        $this->artisan('install-records:backfill', ['--apply' => true])
            ->expectsOutputToContain('already-linked: 1')
            ->assertSuccessful();

        $this->assertSame($recordCountAfterFirst, InstallRecord::count());
        $this->assertSame($recordIdAfterFirst, $programme->fresh()->install_record_id);
        $this->assertSame($updatedAtAfterFirst, $programme->fresh()->updated_at->toISOString());
    }

    // ── Orphans ──────────────────────────────────────────────────────────────

    public function test_a_programme_with_a_null_project_id_lands_in_orphan_no_project(): void
    {
        InstallProgramme::factory()->create(['project_id' => null]);

        $this->artisan('install-records:backfill', ['--apply' => true])
            ->expectsOutputToContain('orphan-no-project: 1')
            ->assertSuccessful();

        $this->assertSame(0, InstallRecord::count());
    }

    // ── Scoping ──────────────────────────────────────────────────────────────

    public function test_the_optional_project_argument_scopes_the_backfill(): void
    {
        $a = Project::factory()->create();
        $b = Project::factory()->create();

        $pa = InstallProgramme::factory()->create(['project_id' => $a->id]);
        $pb = InstallProgramme::factory()->create(['project_id' => $b->id]);

        $this->artisan('install-records:backfill', ['project' => $a->id, '--apply' => true])
            ->assertSuccessful();

        $this->assertSame(1, InstallRecord::count());
        $this->assertNotNull($pa->fresh()->install_record_id);
        $this->assertNull($pb->fresh()->install_record_id);
    }

    public function test_an_empty_set_exits_cleanly(): void
    {
        $this->artisan('install-records:backfill', ['--apply' => true])->assertSuccessful();

        $this->assertSame(0, InstallRecord::count());
    }
}
