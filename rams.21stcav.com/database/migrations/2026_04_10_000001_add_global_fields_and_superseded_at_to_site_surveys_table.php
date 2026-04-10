<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds three global text fields and a superseded_at timestamp to site_surveys.
 *
 * Columns added:
 *   site_risks          — Free-text description of site-level risks.
 *                         Captured once per survey, shared across all rooms.
 *   access_constraints  — Free-text description of access constraints on site.
 *   h_and_s_notes       — Free-text health & safety notes for the site visit.
 *   superseded_at       — Timestamp set when a survey is replaced by a newer one.
 *                         Null = survey is the current active version.
 *                         Not a soft-delete — superseded surveys remain visible
 *                         to admins and are retained for audit purposes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_surveys', function (Blueprint $table) {
            $table->text('site_risks')->nullable()->after('general_notes');
            $table->text('access_constraints')->nullable()->after('site_risks');
            $table->text('h_and_s_notes')->nullable()->after('access_constraints');
            $table->timestamp('superseded_at')->nullable()->after('h_and_s_notes');
        });
    }

    public function down(): void
    {
        Schema::table('site_surveys', function (Blueprint $table) {
            $table->dropColumn(['site_risks', 'access_constraints', 'h_and_s_notes', 'superseded_at']);
        });
    }
};
