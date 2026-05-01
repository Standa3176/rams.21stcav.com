<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 17 — adds signal_role classification to the devices table.
 *
 * CRIT-05 protection: Phase 17 schematic generation MUST derive signal-flow
 * arrow direction from a per-device classification, never from cable-row
 * order in the project data. Without this column the generator would have
 * no canonical answer to "is this a source or a destination?" and would
 * have to guess from cable ordering — a silent-failure mode that produces
 * wrong-but-plausible diagrams (audio flowing from speaker into mic, etc).
 *
 * Values:
 *   - 'source'      — produces signal (laptop, doc-cam, mic, source PC)
 *   - 'destination' — consumes signal (display, projector, speaker, monitor)
 *   - 'processor'   — passes signal through (DSP, amp, switcher, codec, control proc)
 *   - null          — unclassified; schematic generator renders cables touching
 *                     this device as undirected lines and surfaces a warning
 *                     so engineers can fix the source data (Device::hasUnknownSignalRole()).
 *
 * Placement: after part_no — verified during planning that
 * 2026_04_28_151100_create_devices_table.php line 32 declares
 * `$table->string('part_no')->nullable();` so ->after('part_no') is safe.
 *
 * @see CRIT-05 in .planning/research/PITFALLS.md
 * @see app/Models/Device.php — isSource()/isDestination()/isProcessor()/hasUnknownSignalRole()
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->string('signal_role', 16)->nullable()->after('part_no');
        });
    }

    public function down(): void
    {
        Schema::table('devices', function (Blueprint $table) {
            $table->dropColumn('signal_role');
        });
    }
};
