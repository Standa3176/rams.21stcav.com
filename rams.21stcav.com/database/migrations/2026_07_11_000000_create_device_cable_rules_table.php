<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260711-q7q — Tier 3-D data-driven cable rules table.
 *
 * Replaces the hardcoded 13-branch cascade in
 * CableScheduleGeneratorService::inferCableRun() with an admin-editable
 * database table. Rules are walked priority ASC; first matching row's
 * keywords (word-boundary substring match against the equipment name)
 * wins.
 *
 * signal_type is stored as a VARCHAR — not an ENUM — so admins can add
 * new signal_type values through the UI in future (e.g. 'fibre',
 * 'coax-video') without a schema migration.
 *
 * Idempotent seeder (DeviceCableRulesSeeder) inserts the 13 canonical
 * rules keyed on priority so re-runs produce zero duplicates.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_cable_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('priority')->comment('Lower = matched first');
            $table->json('keywords')->comment('Array of substrings word-boundary-matched against strtolower(equipment_name)');
            $table->string('cable_type', 120);
            $table->string('cores', 20)->nullable();
            $table->string('signal_type', 20)
                ->comment('video / audio / network / speaker / control / power / usb / unknown — matches config(cables.signal_type_colours) keys');
            $table->string('to_endpoint', 200)->comment('Human-readable destination label for the cable row');
            $table->string('notes', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['priority', 'is_active'], 'device_cable_rules_priority_active_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_cable_rules');
    }
};
