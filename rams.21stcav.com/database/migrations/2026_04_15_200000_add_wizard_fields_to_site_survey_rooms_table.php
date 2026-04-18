<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds structured wizard fields to site_survey_rooms.
 *
 * These columns support the step-based mobile survey wizard:
 *   - work_type          — nature of work per room (new_install, upgrade, retrofit, fault)
 *   - access_issues      — quick-capture toggle: access problems present
 *   - working_at_height  — quick-capture toggle: WAH work expected
 *   - client_present     — quick-capture toggle: client on site
 *   - hs_flags           — JSON: structured H&S step data (working_height, access_equipment, out_of_hours, etc.)
 *   - constraints_data   — JSON: structured constraints step data (obstructions, noise, client, programme)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_survey_rooms', function (Blueprint $table) {
            $table->string('work_type', 50)->nullable();
            $table->boolean('access_issues')->nullable();
            $table->boolean('working_at_height')->nullable();
            $table->boolean('client_present')->nullable();
            $table->json('hs_flags')->nullable();
            $table->json('constraints_data')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('site_survey_rooms', function (Blueprint $table) {
            $table->dropColumn([
                'work_type',
                'access_issues',
                'working_at_height',
                'client_present',
                'hs_flags',
                'constraints_data',
            ]);
        });
    }
};
