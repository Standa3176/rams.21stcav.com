<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Tier 1 asset register.
 *
 * Normalised per-unit installed-device record (the JSON equipment_list on
 * project_packages stays unchanged for back-compat — this table sits
 * alongside it for assets that the OM Manual must enumerate by serial /
 * MAC / IP / firmware / commissioning date / warranty expiry).
 *
 * room_name is denormalised (string) because rooms remain JSON entries on
 * project_packages.extracted_data['rooms'] — adding a rooms table is out
 * of scope for Phase 4.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('room_name')->nullable();

            // Identity / classification
            $table->string('description');
            $table->string('model')->nullable();
            $table->string('manufacturer')->nullable();
            $table->string('part_no')->nullable();
            $table->unsignedInteger('qty')->default(1);

            // Asset register fields (Phase 4 contract)
            $table->string('serial_number')->nullable();
            $table->string('mac_address')->nullable();
            $table->string('ip_address')->nullable();
            $table->string('firmware_version')->nullable();
            $table->string('asset_tag')->nullable();
            $table->date('commissioning_date')->nullable();
            $table->date('warranty_expiry')->nullable();

            $table->timestamps();

            $table->index(['project_id', 'room_name']);
            $table->index('asset_tag');
            $table->index('mac_address');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
