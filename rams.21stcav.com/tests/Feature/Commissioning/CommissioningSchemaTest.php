<?php

namespace Tests\Feature\Commissioning;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * INST-05a — commissioning_items and commissioning_signoffs schema contract.
 *
 * Red until Plan 02 lands the two migrations. Asserts every column that the
 * downstream services / endpoints / PDF generator rely on.
 */
class CommissioningSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_commissioning_items_table_has_required_columns(): void
    {
        $this->assertTrue(Schema::hasTable('commissioning_items'));

        $this->assertTrue(Schema::hasColumns('commissioning_items', [
            'id',
            'install_programme_id',
            'install_task_id',
            'equipment_name',
            'room_name',
            'category',
            'status',
            'evidence_photo_path',
            'notes',
            'signed_off_by',
            'signed_off_at',
            'created_at',
            'updated_at',
            'deleted_at',
        ]));
    }

    public function test_status_column_is_varchar_with_default_pending(): void
    {
        $this->assertSame(
            'varchar',
            Schema::getColumnType('commissioning_items', 'status'),
            'status must be stored as varchar so enum values can evolve without a migration',
        );

        // Default should be 'pending' — check via raw DB reflection through a
        // driver-agnostic helper (sqlite in tests)
        $default = \DB::selectOne(
            "SELECT `dflt_value` AS dflt FROM pragma_table_info('commissioning_items') WHERE name = 'status'"
        );
        $this->assertNotNull($default, 'status column default should be discoverable');
        $this->assertMatchesRegularExpression('/pending/i', (string) $default->dflt);
    }

    public function test_commissioning_signoffs_table_has_required_columns(): void
    {
        $this->assertTrue(Schema::hasTable('commissioning_signoffs'));

        $this->assertTrue(Schema::hasColumns('commissioning_signoffs', [
            'id',
            'install_programme_id',
            'client_name',
            'client_role',
            'client_company',
            'signature_png_base64',
            'certification_text',
            'snagging_pdf_path',
            'signed_at',
            'signed_off_engineer_id',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_commissioning_signoffs_install_programme_id_is_unique(): void
    {
        // Pitfall 7 — race guard via unique index at DB level. Inspect
        // index list for sqlite (Schema::getIndexes exists on Laravel 11+).
        $indexes = Schema::getIndexes('commissioning_signoffs');

        $found = collect($indexes)->first(
            fn ($idx) => in_array('install_programme_id', $idx['columns'], true) && $idx['unique'] === true,
        );

        $this->assertNotNull(
            $found,
            'commissioning_signoffs.install_programme_id must carry a unique index (Pitfall 7 race guard)',
        );
    }
}
