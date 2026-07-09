<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Batch 11 UX-08 — record when the office notification email actually
 * went out on survey submission.
 *
 * Was: SurveyService::submit fires SurveySubmittedMail inside a try/catch
 * and Log::warning()s on failure — no user-visible signal that the office
 * ever heard. Engineers on-site had no way to confirm the notification
 * landed and would sometimes re-submit or phone the PM defensively.
 *
 * Now: this timestamp column is set the moment Mail::to()->send() returns
 * without throwing. The site-survey/show view surfaces it as "Office
 * notified {diffForHumans}" so both the engineer and the office know the
 * mail loop closed.
 *
 * Nullable — legacy submitted surveys stay untouched (their notification
 * either already went out under the silent regime or was never sent for a
 * projectless survey). The banner only fires on rows where this is set.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('site_surveys', function (Blueprint $table): void {
            $table->timestamp('submitted_notification_sent_at')
                ->nullable()
                ->after('submitted_at');
        });
    }

    public function down(): void
    {
        Schema::table('site_surveys', function (Blueprint $table): void {
            $table->dropColumn('submitted_notification_sent_at');
        });
    }
};
