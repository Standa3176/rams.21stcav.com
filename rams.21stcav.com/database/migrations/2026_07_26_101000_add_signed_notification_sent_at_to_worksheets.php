<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260726-fx4 Task 3 — record when the office notification email
 * actually went out after a public worksheet sign-off.
 *
 * Mirrors the batch-11 UX-08 pattern used on site_surveys:
 * PublicWorksheetController::sign() dispatches WorksheetSignedMail via
 * NotificationRecipientResolver, and stamps this timestamp the moment
 * Mail::send() returns without throwing. The worksheets/show page turns
 * that into a green "Office notified {diffForHumans}" pill; a null value
 * shows the amber "Office not notified" fallback so both PM and engineer
 * know the mail loop closed.
 *
 * Nullable — legacy signed worksheets stay untouched (their notification
 * either already went out under the silent regime or was never sent when
 * the resolver returned no recipient). The banner only fires on rows where
 * this timestamp is set.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('worksheets', function (Blueprint $table): void {
            $table->timestamp('signed_notification_sent_at')
                ->nullable()
                ->after('completion_email_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('worksheets', function (Blueprint $table): void {
            $table->dropColumn('signed_notification_sent_at');
        });
    }
};
