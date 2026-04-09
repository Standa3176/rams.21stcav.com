<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds space_type to site_survey_rooms so that each individual space
 * within a survey can carry its own discipline classification.
 *
 * This supersedes the survey-level survey_type discriminator for field
 * visibility — a single survey can now contain a boardroom (general),
 * a reception (signage), and a warehouse (pa_system) side by side.
 *
 * Values: general | pa_system | infrastructure | signage | upgrade | mixed
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_survey_rooms', function (Blueprint $table) {
            $table->string('space_type', 30)->default('general')->after('area_type');
        });
    }

    public function down(): void
    {
        Schema::table('site_survey_rooms', function (Blueprint $table) {
            $table->dropColumn('space_type');
        });
    }
};
