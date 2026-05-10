<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        // Phase 18 Plan 01 — apply the manufacturer rack-metadata JSON pack.
        // Idempotent: only updates Device rows whose part_no matches a pack
        // entry, devices outside the pack stay with NULL u_height (CRIT-06).
        $this->call(DeviceCatalogSeeder::class);

        // Phase 21 Plan 02 — apply the engineer-curated stencil seed pack.
        // Idempotent: upserts device_stencils + device_ports rows from
        // resources/data/device-stencils-seed/*.json (5 spike + 53 v1.3 +
        // ~39 gap-fill = ~97 stencils). Runs AFTER DeviceCatalogSeeder so
        // the v1.3 rack-metadata seeder lands first (independent surfaces;
        // no ordering dependency, but consistent ordering simplifies
        // operational mental model).
        $this->call(DeviceStencilSeeder::class);
    }
}
