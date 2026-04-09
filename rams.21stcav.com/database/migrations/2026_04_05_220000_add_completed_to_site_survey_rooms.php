<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds per-room completion tracking to site_survey_rooms.
 *
 * is_completed — engineer has marked this space as fully surveyed
 * completed_at — timestamp when it was marked complete (nullable)
 *
 * Existing rows default to 0 / NULL — fully backward compatible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_survey_rooms', function (Blueprint $table) {
            $table->boolean('is_completed')->default(false)->after('sort_order');
            $table->timestamp('completed_at')->nullable()->after('is_completed');
        });
    }

    public function down(): void
    {
        Schema::table('site_survey_rooms', function (Blueprint $table) {
            $table->dropColumn(['is_completed', 'completed_at']);
        });
    }
};
