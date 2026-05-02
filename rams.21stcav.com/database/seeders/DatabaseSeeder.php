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
    }
}
