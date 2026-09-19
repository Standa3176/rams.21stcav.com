<?php

namespace Tests\Unit\Models;

use App\Models\InstallProgramme;
use App\Models\InstallRecord;
use App\Models\Project;
use App\Models\Visit;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VIS-09 / D-06 — the `InstallRecord` model contract.
 *
 * `install_records` is the DURABLE half of the D-06 split: one row per
 * project, never archived, never replaced. `install_programmes` remains the
 * regenerable half — every regenerate mints a new programme row under the
 * SAME record, which is what stops a filed visit being orphaned.
 *
 * The unique-index case is load-bearing: "one durable record per project" is
 * a database fact, not a convention, and `InstallProgrammeService`'s
 * resolve-or-create leans on it under concurrency.
 *
 * `install_tasks` deliberately do NOT hang off this model — re-pointing them
 * is Phase 51's work (see the migration docblock).
 *
 * @see app/Models/InstallRecord.php
 * @see .planning/phases/45-visit-model-read-only-cockpit/45-CONTEXT.md (D-06)
 */
class InstallRecordTest extends TestCase
{
    use RefreshDatabase;

    // ── One record per project ───────────────────────────────────────────────

    public function test_a_second_record_for_the_same_project_is_rejected_by_the_unique_index(): void
    {
        $project = Project::factory()->create();

        InstallRecord::create(['project_id' => $project->id]);

        $this->expectException(QueryException::class);

        InstallRecord::create(['project_id' => $project->id]);
    }

    public function test_first_or_create_returns_the_existing_record_rather_than_minting_a_second(): void
    {
        $project = Project::factory()->create();

        $first  = InstallRecord::firstOrCreate(['project_id' => $project->id]);
        $second = InstallRecord::firstOrCreate(['project_id' => $project->id]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, InstallRecord::where('project_id', $project->id)->count());
    }

    // ── Relationships ────────────────────────────────────────────────────────

    public function test_programmes_returns_every_generation_newest_first(): void
    {
        $project = Project::factory()->create();
        $record  = InstallRecord::factory()->create(['project_id' => $project->id]);

        $older = InstallProgramme::factory()->create([
            'project_id'        => $project->id,
            'install_record_id' => $record->id,
            'status'            => InstallProgramme::STATUS_ARCHIVED,
            'created_at'        => now()->subDay(),
        ]);

        $newer = InstallProgramme::factory()->create([
            'project_id'        => $project->id,
            'install_record_id' => $record->id,
            'status'            => InstallProgramme::STATUS_ACTIVE,
            'created_at'        => now(),
        ]);

        $ids = $record->programmes()->pluck('id')->all();

        $this->assertSame([$newer->id, $older->id], $ids);
    }

    public function test_active_programme_returns_the_active_generation_or_null(): void
    {
        $project = Project::factory()->create();
        $record  = InstallRecord::factory()->create(['project_id' => $project->id]);

        InstallProgramme::factory()->create([
            'project_id'        => $project->id,
            'install_record_id' => $record->id,
            'status'            => InstallProgramme::STATUS_ARCHIVED,
        ]);

        $this->assertNull($record->fresh()->activeProgramme);

        $active = InstallProgramme::factory()->create([
            'project_id'        => $project->id,
            'install_record_id' => $record->id,
            'status'            => InstallProgramme::STATUS_ACTIVE,
        ]);

        $this->assertSame($active->id, $record->fresh()->activeProgramme->id);
    }

    public function test_project_and_visits_relations_resolve(): void
    {
        $project = Project::factory()->create();
        $record  = InstallRecord::factory()->create(['project_id' => $project->id]);

        $visit = Visit::factory()->create([
            'project_id'        => $project->id,
            'install_record_id' => $record->id,
        ]);

        $this->assertSame($project->id, $record->project->id);
        $this->assertSame([$visit->id], $record->visits()->pluck('id')->all());
    }

    // ── Durability ───────────────────────────────────────────────────────────

    public function test_hard_deleting_a_project_nulls_project_id_rather_than_deleting_the_record(): void
    {
        $project = Project::factory()->create();
        $record  = InstallRecord::factory()->create(['project_id' => $project->id]);

        $project->forceDelete();

        $this->assertDatabaseHas('install_records', [
            'id'         => $record->id,
            'project_id' => null,
        ]);
    }

    public function test_install_record_id_is_nullable_on_install_programmes(): void
    {
        // A programme built straight from the factory sets no record at all.
        // If this column were NOT NULL, every factory in the programme suite
        // would detonate here.
        $programme = InstallProgramme::factory()->create();

        $this->assertNull($programme->install_record_id);
    }

    public function test_install_record_id_is_nullable_on_visits(): void
    {
        $visit = Visit::factory()->create();

        $this->assertNull($visit->install_record_id);
    }
}
