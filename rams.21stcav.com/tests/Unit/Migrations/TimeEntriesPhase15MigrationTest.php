<?php

namespace Tests\Unit\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 15-01 schema extension — asserts the three Phase 15 columns
 * are added to time_entries without disturbing Phase 14 columns.
 *
 * Decisions covered: D-01 (category enum values), D-06 (notes ≤500 chars),
 * D-12 (closure_reason = 'stale_auto_close' sentinel).
 */
class TimeEntriesPhase15MigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_has_category_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('time_entries', 'category'),
            'category column missing — Phase 15 D-01',
        );
    }

    public function test_has_notes_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('time_entries', 'notes'),
            'notes column missing — Phase 15 D-06',
        );
    }

    public function test_has_closure_reason_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('time_entries', 'closure_reason'),
            'closure_reason column missing — Phase 15 D-12',
        );
    }

    public function test_preserves_phase_14_last_heartbeat_at_column(): void
    {
        $this->assertTrue(
            Schema::hasColumn('time_entries', 'last_heartbeat_at'),
            'Phase 14 last_heartbeat_at column must be preserved (additive migration only)',
        );
    }

    public function test_preserves_phase_14_core_columns(): void
    {
        $expected = [
            'id', 'project_id', 'user_id',
            'clocked_in_at', 'clocked_out_at',
            'created_at', 'updated_at',
        ];

        foreach ($expected as $col) {
            $this->assertTrue(
                Schema::hasColumn('time_entries', $col),
                "Phase 14 column {$col} must survive Phase 15 migration",
            );
        }
    }

    public function test_can_insert_row_with_phase_15_columns(): void
    {
        $user = \App\Models\User::factory()->create();
        $project = \App\Models\Project::factory()->create(['user_id' => $user->id]);

        \DB::table('time_entries')->insert([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'category'          => 'installation',
            'clocked_in_at'     => now(),
            'clocked_out_at'    => now(),
            'last_heartbeat_at' => now(),
            'notes'             => 'Ran first fix on rack A',
            'closure_reason'    => null,
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $this->assertDatabaseCount('time_entries', 1);
    }

    public function test_closure_reason_accepts_stale_auto_close_sentinel(): void
    {
        $user = \App\Models\User::factory()->create();
        $project = \App\Models\Project::factory()->create(['user_id' => $user->id]);

        \DB::table('time_entries')->insert([
            'project_id'        => $project->id,
            'user_id'           => $user->id,
            'category'          => 'testing',
            'clocked_in_at'     => now()->subHours(3),
            'clocked_out_at'    => now()->subHours(1),
            'last_heartbeat_at' => now()->subHours(1),
            'notes'             => null,
            'closure_reason'    => 'stale_auto_close',
            'created_at'        => now(),
            'updated_at'        => now(),
        ]);

        $this->assertDatabaseHas('time_entries', [
            'closure_reason' => 'stale_auto_close',
        ]);
    }
}
