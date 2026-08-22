<?php

namespace Tests\Feature\Drawings;

use App\Models\DeviceStencil;
use App\Models\DeviceStencilAudit;
use App\Models\User;
use App\Services\Drawings\DeviceStencilCacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Phase 24 Plan 01 Task 1 — locks the schema foundation for stencil curation
 * (CONTEXT.md D-03 device_stencil_audits, D-10 needs_review, D-15 logo_path).
 *
 * @see database/migrations/2026_08_13_140000_add_needs_review_and_logo_path_to_device_stencils_and_create_device_stencil_audits.php
 * @see app/Models/DeviceStencilAudit.php
 * @see app/Services/Drawings/DeviceStencilCacheService.php
 */
class DeviceStencilAuditTest extends TestCase
{
    /**
     * The one migration under test. Targeted by path so this test is immune
     * to any future migration being added after it (Phase 260822-esf broke the
     * previous --step 1 approach exactly that way).
     */
    private const MIGRATION_PATH = 'database/migrations/2026_08_13_140000_add_needs_review_and_logo_path_to_device_stencils_and_create_device_stencil_audits.php';

    use RefreshDatabase;

    public function test_migration_adds_indexed_needs_review_and_nullable_logo_path_columns(): void
    {
        $this->assertTrue(Schema::hasColumn('device_stencils', 'needs_review'));
        $this->assertTrue(Schema::hasColumn('device_stencils', 'logo_path'));

        $indexes = collect(Schema::getIndexes('device_stencils'));
        $this->assertTrue(
            $indexes->contains(fn (array $index) => in_array('needs_review', $index['columns'], true)),
            'device_stencils.needs_review must be indexed (D-10).'
        );
    }

    public function test_device_stencil_audits_table_has_expected_columns_and_foreign_keys(): void
    {
        $this->assertTrue(Schema::hasTable('device_stencil_audits'));
        $this->assertTrue(Schema::hasColumns('device_stencil_audits', [
            'device_stencil_id',
            'user_id',
            'action',
            'before_snapshot',
            'after_snapshot',
            'created_at',
        ]));

        $foreignKeys = collect(Schema::getForeignKeys('device_stencil_audits'));
        $this->assertTrue($foreignKeys->contains(fn (array $fk) => in_array('device_stencil_id', $fk['columns'], true)));
        $this->assertTrue($foreignKeys->contains(fn (array $fk) => in_array('user_id', $fk['columns'], true)));
    }

    public function test_backfill_carries_existing_needs_phase_24_curation_flag_into_needs_review_column(): void
    {
        // Simulate a pre-Phase-24 device_stencils row: roll back this
        // migration (drops needs_review/logo_path + device_stencil_audits),
        // insert a row carrying the legacy metadata flag written by
        // Plan 21-02's seed pack, then re-run the migration so its PHP-based
        // backfill step (Pitfall 1 — no raw SQL JSON functions) has to act
        // on genuinely pre-existing data, not a freshly created empty table.
        // Target THIS migration by path, not --step 1. The original used
        // --step 1, which silently assumed the device-stencils migration was
        // the newest one in the repo. Phase 260822-esf added two later
        // migrations, so --step 1 began rolling back an unrelated migration
        // and this backfill never re-ran (caught by the full suite 2026-08-23).
        Artisan::call('migrate:rollback', ['--path' => self::MIGRATION_PATH, '--realpath' => false]);

        $id = DB::table('device_stencils')->insertGetId([
            'part_number' => 'legacy-flagged-part',
            'mxgraph_xml' => '<shape name="legacy"></shape>',
            'source'      => DeviceStencil::SOURCE_AUTO_GENERATED,
            'metadata'    => json_encode(['needs_phase_24_curation' => true]),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        // A sibling row with no flag must NOT be touched by the backfill.
        $unflaggedId = DB::table('device_stencils')->insertGetId([
            'part_number' => 'legacy-unflagged-part',
            'mxgraph_xml' => '<shape name="legacy-2"></shape>',
            'source'      => DeviceStencil::SOURCE_AUTO_GENERATED,
            'metadata'    => json_encode(['some_other_key' => true]),
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        Artisan::call('migrate', ['--path' => self::MIGRATION_PATH, '--realpath' => false]);

        $this->assertTrue((bool) DB::table('device_stencils')->where('id', $id)->value('needs_review'));
        $this->assertFalse((bool) DB::table('device_stencils')->where('id', $unflaggedId)->value('needs_review'));
    }

    public function test_resolve_for_part_number_sets_needs_review_true_on_fresh_row(): void
    {
        /** @var DeviceStencilCacheService $cache */
        $cache = $this->app->make(DeviceStencilCacheService::class);

        $stencil = $cache->resolveForPartNumber('NEW-PART-001');

        $this->assertTrue($stencil->needs_review);
        $this->assertSame(DeviceStencil::SOURCE_AUTO_GENERATED, $stencil->source);
    }

    public function test_device_stencil_audits_relation_and_action_constants(): void
    {
        $this->assertSame('promote', DeviceStencilAudit::ACTION_PROMOTE);
        $this->assertSame('edit', DeviceStencilAudit::ACTION_EDIT);
        $this->assertSame('discard-regenerate', DeviceStencilAudit::ACTION_DISCARD_REGENERATE);

        $user = User::factory()->create();
        $stencil = DeviceStencil::create([
            'part_number' => 'audit-relation-test',
            'mxgraph_xml' => '<shape></shape>',
            'source'      => DeviceStencil::SOURCE_AUTO_GENERATED,
        ]);

        $audit = DeviceStencilAudit::create([
            'device_stencil_id' => $stencil->id,
            'user_id'           => $user->id,
            'action'            => DeviceStencilAudit::ACTION_PROMOTE,
            'before_snapshot'   => ['source' => DeviceStencil::SOURCE_AUTO_GENERATED],
            'after_snapshot'    => ['source' => DeviceStencil::SOURCE_ENGINEER_CURATED],
        ]);

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\HasMany::class, $stencil->audits());
        $this->assertCount(1, $stencil->fresh()->audits);
        $this->assertSame(DeviceStencilAudit::ACTION_PROMOTE, $audit->action);
        $this->assertSame(['source' => DeviceStencil::SOURCE_AUTO_GENERATED], $audit->fresh()->before_snapshot);
    }
}
