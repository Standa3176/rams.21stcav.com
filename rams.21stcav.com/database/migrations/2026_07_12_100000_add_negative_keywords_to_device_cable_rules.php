<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260712-ip3 — negative_keywords exclusion column for
 * DeviceCableRule. Kills brand-name collisions surfaced during
 * 260712-euh verification (real bug: a `Logitech USB 3.0 Webcam` line
 * word-boundary-matches `logitech` on the priority 70 VC codec rule
 * BEFORE the priority 141 USB 3 rule can win, so the schedule
 * mis-routes the run as Cat6 PoE / video instead of USB 3.0 / usb).
 *
 * When populated on a rule, the inference walker treats the rule as
 * SKIPPED whenever the equipment name matches ANY entry in this list —
 * even if the positive keyword list also matched. Null / empty array =
 * no exclusion (behaviour identical to pre-260712-ip3).
 *
 * Column is additive + nullable + reversible: existing 20 seeded rows
 * are unaffected on `up()`; `down()` drops the column cleanly. The
 * seeder in the same commit backfills the exclusion list on rules 61,
 * 70 and 80.
 *
 * Ordering: the 100000 suffix guarantees this migration runs AFTER
 * `2026_07_12_000000_add_length_tiers_to_device_cable_rules_table.php`
 * so the negative_keywords column always sits after `length_tiers`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_cable_rules', function (Blueprint $table) {
            $table->json('negative_keywords')
                ->nullable()
                ->after('keywords')
                ->comment('If a rule matches on keywords AND on any of these, the rule is SKIPPED. Null = no exclusion.');
        });
    }

    public function down(): void
    {
        Schema::table('device_cable_rules', function (Blueprint $table) {
            $table->dropColumn('negative_keywords');
        });
    }
};
