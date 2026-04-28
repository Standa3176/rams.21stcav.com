<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4 — Tier 1 data model extension (Project lifecycle dates).
 *
 *   handover_date         — formal handover to client (Tier 1 OM cover requires this).
 *   defects_liability_end — end of DLP / latent-defects window.
 *
 * Both nullable; existing rows are unaffected. Phase 1 validator already
 * requires handover_date in the OM-generation context, so populating this
 * column (via package review or admin form, surfaced in a later phase) is
 * how OM generation gets unblocked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->date('handover_date')->nullable()->after('completed_at');
            $table->date('defects_liability_end')->nullable()->after('handover_date');
        });
    }

    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn(['handover_date', 'defects_liability_end']);
        });
    }
};
