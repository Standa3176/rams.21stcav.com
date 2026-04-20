<?php

namespace Tests\Unit\Migrations;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * CONTEXT D-09 — install_task_photos table must mirror site_survey_photos.
 */
class InstallTaskPhotosSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_table_exists(): void
    {
        $this->assertTrue(Schema::hasTable('install_task_photos'));
    }

    public function test_table_has_required_columns(): void
    {
        $required = [
            'id', 'install_task_id', 'filename', 'original_name',
            'mime_type', 'caption', 'sort_order', 'created_at', 'updated_at',
        ];

        foreach ($required as $col) {
            $this->assertTrue(
                Schema::hasColumn('install_task_photos', $col),
                "Column {$col} missing from install_task_photos",
            );
        }
    }

    public function test_install_task_id_is_foreign_key(): void
    {
        // Guard: if the table doesn't exist yet (Wave 0 red state), this test
        // can't assert a real FK constraint. Fail explicitly so the assertion
        // stays meaningfully red until the migration ships in plan 14-02.
        $this->assertTrue(
            Schema::hasTable('install_task_photos'),
            'install_task_photos table must exist before FK constraints can be tested',
        );

        // SQLite requires PRAGMA foreign_keys=ON for FK enforcement; ensure it.
        \DB::statement('PRAGMA foreign_keys = ON');

        // Inserting a photo with a non-existent install_task_id should fail
        $this->expectException(\Illuminate\Database\QueryException::class);

        \DB::table('install_task_photos')->insert([
            'install_task_id' => 999999,
            'filename'        => 'x.jpg',
            'original_name'   => 'x.jpg',
            'mime_type'       => 'image/jpeg',
            'sort_order'      => 0,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }
}
