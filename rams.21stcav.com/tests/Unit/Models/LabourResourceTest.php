<?php

namespace Tests\Unit\Models;

use App\Models\LabourResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 44 Plan 01 Task 2 — locks LabourResource model behaviour per
 * 44-CONTEXT.md D-01 (one row, multiple roles), D-02 (deactivate never
 * deletes), D-04 (client-safe accessor omits email/phone).
 *
 * @see app/Models/LabourResource.php
 * @see database/migrations/2026_09_19_120000_create_labour_resources_table.php
 * @see .planning/phases/44-labour-resources/44-CONTEXT.md (D-01, D-02, D-04)
 */
class LabourResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_round_trip_as_array_and_support_more_than_one_value(): void
    {
        $resource = LabourResource::factory()->create([
            'roles' => [LabourResource::ROLE_ENGINEER, LabourResource::ROLE_PROGRAMMER],
        ]);

        $resource->refresh();

        $this->assertIsArray($resource->roles);
        $this->assertTrue($resource->hasRole(LabourResource::ROLE_ENGINEER));
        $this->assertTrue($resource->hasRole(LabourResource::ROLE_PROGRAMMER));
        $this->assertFalse($resource->hasRole(LabourResource::ROLE_OTHER));
    }

    public function test_active_scope_excludes_inactive_resources(): void
    {
        $active = LabourResource::factory()->create(['is_active' => true]);
        $inactive = LabourResource::factory()->create(['is_active' => false]);

        $activeIds = LabourResource::active()->pluck('id');

        $this->assertTrue($activeIds->contains($active->id));
        $this->assertFalse($activeIds->contains($inactive->id));
    }

    public function test_deactivating_a_resource_never_deletes_the_row(): void
    {
        $resource = LabourResource::factory()->create(['is_active' => true]);

        $resource->update(['is_active' => false]);

        $this->assertDatabaseHas('labour_resources', ['id' => $resource->id]);
        $this->assertFalse($resource->fresh()->is_active);
    }

    public function test_is_active_defaults_to_true_at_the_database_level(): void
    {
        $id = DB::table('labour_resources')->insertGetId([
            'name'       => 'Raw Insert Person',
            'roles'      => json_encode([LabourResource::ROLE_OTHER]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $resource = LabourResource::find($id);

        $this->assertTrue($resource->is_active);
    }

    public function test_to_client_safe_array_returns_only_id_and_name(): void
    {
        $resource = LabourResource::factory()->create([
            'name'  => 'Marcus Okafor',
            'email' => 'marcus@example.com',
            'phone' => '07700900000',
        ]);

        $safe = $resource->toClientSafeArray();

        $this->assertSame(['id', 'name'], array_keys($safe));
        $this->assertSame($resource->id, $safe['id']);
        $this->assertSame('Marcus Okafor', $safe['name']);
    }
}
