<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 2 of the Tier 1 O&M Manual upgrade — manufacturer support resolver.
 *
 * Stores canonical UK support details for every brand whose equipment can
 * appear on an installed-equipment list. The OM generator's resolver hits
 * this table before any web lookup, so seeded entries are the authoritative
 * source. NO TBC POLICY: any field intentionally left null forces the
 * resolver to attempt a web lookup; if that also fails, OM generation
 * aborts with an exception (no soft fallback).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manufacturers', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('slug')->unique();   // Str::slug for case-insensitive lookup.
            $table->string('support_phone')->nullable();
            $table->string('support_email')->nullable();
            $table->string('support_url')->nullable();
            $table->unsignedTinyInteger('warranty_years')->nullable();
            $table->string('logo_path')->nullable();
            $table->timestamps();

            $table->index('slug');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('manufacturers');
    }
};
