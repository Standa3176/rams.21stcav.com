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

    public function test_does_not_have_category_column_yet(): void
    {
        // CONTEXT.md — Phase 14 defers category to Phase 15
        $this->assertFalse(
            Schema::hasColumn('time_entries', 'category'),
            'category column should be deferred to Phase 15 (INST-04a)',
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
