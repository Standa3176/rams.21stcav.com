<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — config-backup register.
 *
 * One row per stored configuration backup for a device (DSP preset, codec
 * settings export, switch running-config, etc.). Surfaces in the OM as
 * the "Configuration Backups" section so a future engineer knows what
 * exists and where to retrieve it.
 *
 * storage_location is free-text (e.g. "21CAV SharePoint /clients/Marubeni/configs",
 * "Biamp cloud", "/srv/backups/...") — kept human-readable rather than
 * forcing a single storage abstraction.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('config_backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained()->cascadeOnDelete();
            $table->string('filename');
            $table->string('storage_location')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index('device_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('config_backups');
    }
};
