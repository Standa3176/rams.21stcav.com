<?php

namespace Tests\Unit\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * CONTEXT discretion + REQUIREMENTS.md Technical Constraints —
 * time_entries minimal schema + last_heartbeat_at required day-one.
 */
class TimeEntriesSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('time_entries'));
    }

    public function test_has_last_heartbeat_at_column(): void
    {
        // REQUIREMENTS.md Technical Constraints: "last_heartbeat_at required day one — not retrofittable"
        $this->assertTrue(Schema::hasColumn('time_entries', 'last_heartbeat_at'));
    }

    public function test_has_minimal_columns(): void
    {
        $required = [
            'id', 'project_id', 'user_id',
            'clocked_in_at', 'clocked_out_at', 'last_heartbeat_at',
            'created_at', 'updated_at',
        ];

        foreach ($required as $col) {
            $this->assertTrue(
                Schema::hasColumn('time_entries', $col),
                "Column {$col} missing from time_entries",
            );
        }
    }

    public function test_phase_14_baseline_excludes_category(): void
    {
        // CONTEXT.md — Phase 14 deferred category to Phase 15.
        // Phase 15-01 has now added it; this test now documents the historical
        // boundary: the Phase 14 create migration itself does not create the
        // column (the Phase 15 ALTER migration does).
        $baselinePath = database_path('migrations/2026_04_20_000002_create_time_entries_table.php');
        $this->assertFileExists($baselinePath);
        $this->assertStringNotContainsString(
            "'category'",
            file_get_contents($baselinePath),
            'Phase 14 baseline migration must not define category — Phase 15 owns that column',
        );
    }

    public function test_clocked_out_at_is_nullable(): void
    {
        $user = \App\Models\User::factory()->create();
        $project = \App\Models\Project::factory()->create(['user_id' => $user->id]);

        \DB::table('time_entries')->insert([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'clocked_in_at'     => now(),
            'clocked_out_at'    => null, // must accept null
            'last_heartbeat_at' => now(),
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $this->assertDatabaseCount('time_entries', 1);
    }
}
