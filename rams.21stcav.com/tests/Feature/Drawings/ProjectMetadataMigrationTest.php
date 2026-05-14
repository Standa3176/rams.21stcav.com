<?php

namespace Tests\Feature\Drawings;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 23 Plan 01 Task 2 — locks the metadata JSON column shape per D-08 + D-09.
 *
 * Migration: database/migrations/2026_05_13_120000_add_metadata_to_projects_table.php
 * Column:    projects.metadata (json, nullable, default NULL).
 * Casts:     'metadata' => 'array' on App\Models\Project.
 *
 * Phase 21 D-10 invariant: NOT read by v1.3 D2 generator surfaces.
 */
class ProjectMetadataMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_table_has_nullable_json_metadata_column(): void
    {
        $this->assertTrue(Schema::hasColumn('projects', 'metadata'));
        $columnType = Schema::getColumnType('projects', 'metadata');
        // sqlite reports text/longtext; mysql reports json
        $this->assertContains($columnType, ['json', 'text', 'longtext']);
    }

    public function test_metadata_round_trips_via_array_cast(): void
    {
        $project = Project::factory()->create([
            'metadata' => [
                'drawing_checked_by' => 'Alice Engineer',
                'force_sheets'       => ['audio', 'video'],
            ],
        ]);

        $reloaded = Project::find($project->id);
        $this->assertSame('Alice Engineer', $reloaded->metadata['drawing_checked_by']);
        $this->assertSame(['audio', 'video'], $reloaded->metadata['force_sheets']);
    }

    public function test_metadata_defaults_null(): void
    {
        $project = Project::factory()->create();
        $this->assertNull($project->fresh()->metadata);
    }

    public function test_metadata_is_in_fillable(): void
    {
        $fillable = (new Project)->getFillable();
        $this->assertContains('metadata', $fillable);
    }

    public function test_metadata_cast_is_array(): void
    {
        $casts = (new Project)->getCasts();
        $this->assertArrayHasKey('metadata', $casts);
        $this->assertSame('array', $casts['metadata']);
    }
}
