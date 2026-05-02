<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 18 Plan 01 — adds rack-metadata columns to the devices table.
 *
 * CRIT-06 protection: u_height is nullable + has NO default. Devices outside
 * the manufacturer JSON pack stay at NULL so the rack renderer surfaces a
 * "U-height unknown" warning rather than silently placing a 1U placeholder.
 * is_rack_mounted is also nullable so the engineer-set classification (true /
 * false / unknown) is distinguishable from the not-yet-classified default.
 *
 * Column shape:
 *   - u_height                          DECIMAL(4,2) NULL  — supports 0.5, 1.0, 1.5, 2.0 ... 99.99
 *   - is_rack_mounted                   BOOLEAN      NULL  — palette greys non-rack devices but keeps them draggable
 *   - requires_ventilation_gap_above    BOOLEAN      NULL  — drives renderer to insert a 1U gap above (AVIXA F502.01 thermal guidance)
 *   - requires_ventilation_gap_below    BOOLEAN      NULL  — drives renderer to insert a 1U gap below
 *
 * Placement: after signal_role (Phase 17 column) — verified during planning
 * that the 2026_05_01_000002 migration declares signal_role on the devices
 * table, so ->after('signal_role') is safe.
 *
 * @see CRIT-06 in .planning/research/PITFALLS.md
 * @see database/migrations/2026_05_01_000002_add_signal_classification_to_devices_table.php
 * @see resources/data/device-port-catalog.json — manufacturer pack consumed by DeviceCatalogSeeder
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->decimal('u_height', 4, 2)->nullable()->after('signal_role');
            $table->boolean('is_rack_mounted')->nullable()->after('u_height');
            $table->boolean('requires_ventilation_gap_above')->nullable()->after('is_rack_mounted');
            $table->boolean('requires_ventilation_gap_below')->nullable()->after('requires_ventilation_gap_above');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn([
                'u_height',
                'is_rack_mounted',
                'requires_ventilation_gap_above',
                'requires_ventilation_gap_below',
            ]);
        });
    }
};
