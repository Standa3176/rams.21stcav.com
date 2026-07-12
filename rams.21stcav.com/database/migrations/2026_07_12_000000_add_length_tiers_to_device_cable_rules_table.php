<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260712-euh — length-tier decision engine for DeviceCableRule.
 *
 * Adds a nullable `length_tiers` JSON column to `device_cable_rules`. When
 * populated, the inference engine walks the tier list ascending on
 * `max_m`; the first tier whose `max_m` is ≥ the row's `approx_length_m`
 * wins and its `cable_type` / `cores` / `to_endpoint` / `notes` override
 * the flat row values. Null / empty array = "no tier logic" (behaviour
 * identical to pre-260712-euh flat cable_type).
 *
 * Column is additive + nullable + reversible: existing 15 seeded rows are
 * unaffected on `up()`; `down()` drops the column cleanly.
 *
 * Tier shape (each entry):
 *   [
 *     'max_m'       => (int|float),          // required, > 0
 *     'cable_type'  => (string),             // required, overrides flat
 *     'cores'       => (string|null),        // optional
 *     'to_endpoint' => (string|null),        // optional
 *     'notes'       => (string|null),        // optional
 *   ]
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_cable_rules', function (Blueprint $table) {
            $table->json('length_tiers')
                ->nullable()
                ->after('notes')
                ->comment('Ordered ascending on max_m; first match wins. Nullable = flat cable_type used.');
        });
    }

    public function down(): void
    {
        Schema::table('device_cable_rules', function (Blueprint $table) {
            $table->dropColumn('length_tiers');
        });
    }
};
