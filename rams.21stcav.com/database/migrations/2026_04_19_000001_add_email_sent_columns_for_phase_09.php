<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 09 (Email Notifications) foundation migration.
 *
 * Adds idempotency timestamp columns for the completion / failure / review-needed
 * trigger paths across the four notifiable document models, plus the missing
 * `error_message` column on `cable_schedules` so NOTF-04c can surface the cause
 * in the failure email body.
 *
 * Requirements: NOTF-01c, NOTF-03c, NOTF-04b, NOTF-04c.
 * Design decisions: D-13 (completion), D-14 (failure), D-15 (review), RESEARCH
 * Pitfall 3 (cable_schedules missing error_message).
 *
 * IMPORTANT: the existing `rams_documents.email_sent_at` column is OWNED by the
 * manual-send path (RamsController@email) per D-13 — this migration does NOT
 * touch it. The new `completion_email_sent_at` column is the automated-trigger
 * peer.
 *
 * Reversibility note: `cable_schedules.error_message` is NEVER dropped on
 * rollback — preserving it protects any operational error data captured between
 * deploys (see 09-RESEARCH.md Example 4 footnote).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rams_documents', function (Blueprint $t) {
            $t->timestamp('completion_email_sent_at')->nullable()->after('email_sent_at');
            $t->timestamp('failed_email_sent_at')->nullable()->after('completion_email_sent_at');
            // NOTF-03c: review-needed idempotency across job retries (RAMS only —
            // other doc types have no review state per D-04).
            $t->timestamp('review_needed_email_sent_at')->nullable()->after('failed_email_sent_at');
        });

        Schema::table('om_manuals', function (Blueprint $t) {
            $t->timestamp('completion_email_sent_at')->nullable()->after('filename');
            $t->timestamp('failed_email_sent_at')->nullable()->after('completion_email_sent_at');
        });

        Schema::table('worksheets', function (Blueprint $t) {
            $t->timestamp('completion_email_sent_at')->nullable()->after('filename');
            $t->timestamp('failed_email_sent_at')->nullable()->after('completion_email_sent_at');
        });

        Schema::table('cable_schedules', function (Blueprint $t) {
            // Add error_message column (missing — RESEARCH Pitfall 3) so NOTF-04c
            // failure emails can include the cause. Guarded so re-run is safe.
            if (! Schema::hasColumn('cable_schedules', 'error_message')) {
                $t->string('error_message', 1000)->nullable()->after('status');
            }
            $t->timestamp('completion_email_sent_at')->nullable()->after('status');
            $t->timestamp('failed_email_sent_at')->nullable()->after('completion_email_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('rams_documents', function (Blueprint $t) {
            $t->dropColumn([
                'completion_email_sent_at',
                'failed_email_sent_at',
                'review_needed_email_sent_at',
            ]);
        });

        Schema::table('om_manuals', function (Blueprint $t) {
            $t->dropColumn([
                'completion_email_sent_at',
                'failed_email_sent_at',
            ]);
        });

        Schema::table('worksheets', function (Blueprint $t) {
            $t->dropColumn([
                'completion_email_sent_at',
                'failed_email_sent_at',
            ]);
        });

        Schema::table('cable_schedules', function (Blueprint $t) {
            $t->dropColumn([
                'completion_email_sent_at',
                'failed_email_sent_at',
            ]);
            // NOTE: leave cable_schedules.error_message in place on rollback —
            // dropping it would silently lose error data captured between deploys.
        });
    }
};
