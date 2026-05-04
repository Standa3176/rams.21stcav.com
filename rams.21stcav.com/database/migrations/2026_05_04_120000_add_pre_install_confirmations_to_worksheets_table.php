<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a nullable JSON `pre_install_confirmations` column to `worksheets` so
 * the engineer-link `/worksheet/{token}` page can persist a per-room
 * "I have reviewed the survey" stamp before the page-level Sign-Off button is
 * unlocked (quick task 260504-hqe).
 *
 * Schema shape (when populated):
 *   {
 *     "Boardroom Floor 1": {
 *       "reviewed_at": "2026-05-04T11:30:00+00:00",
 *       "reviewed_by": "abc12345"          // first 8 chars of access_token
 *     },
 *     ...
 *   }
 *
 * Legacy worksheets created BEFORE this migration ran retain `null` for the
 * column, which the controller + Blade view treat as "no rooms reviewed yet".
 * No backfill is required — null is the correct legacy state.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('worksheets', function (Blueprint $table) {
            $table->json('pre_install_confirmations')->nullable()->after('access_token');
        });
    }

    public function down(): void
    {
        Schema::table('worksheets', function (Blueprint $table) {
            $table->dropColumn('pre_install_confirmations');
        });
    }
};
