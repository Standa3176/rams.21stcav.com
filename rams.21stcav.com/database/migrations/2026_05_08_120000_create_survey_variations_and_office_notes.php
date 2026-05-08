<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Quick task 260508-v7g — office survey review surface.
 *
 * Adds:
 *   - site_survey_rooms.office_notes (text, nullable) — per-room office annotations.
 *   - site_surveys.office_review_notes (text, nullable) — survey-level office annotations.
 *   - survey_variations table — flat capture of scope changes for sales/quote conversations.
 *
 * Per D-LOCK-1: variations are flat record-keeping (no workflow state machine, no events).
 * Per D-LOCK-2: both office-notes fields are text/nullable, validated at the existing
 *               SiteSurveyController::validateSurvey() choke point.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Per-room office notes
        Schema::table('site_survey_rooms', function (Blueprint $table): void {
            $table->text('office_notes')->nullable()->after('notes');
        });

        // 2. Survey-level office review notes
        Schema::table('site_surveys', function (Blueprint $table): void {
            $table->text('office_review_notes')->nullable()->after('h_and_s_notes');
        });

        // 3. survey_variations table — flat capture-and-export (D-LOCK-1)
        Schema::create('survey_variations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('site_survey_id')->constrained('site_surveys')->cascadeOnDelete();
            // Free-text room label (NOT FK to site_survey_rooms — variations may
            // reference rooms that have been renamed/deleted; the office is
            // annotating semantically, not relationally).
            $table->string('room_name', 150)->nullable();
            $table->enum('type', [
                'extra_hardware',
                'extra_labour',
                'cable_change',
                'client_provided_change',
                'access_issue',
                'other',
            ]);
            // NOT nullable — a variation with no description is a deletion candidate.
            $table->text('description');
            $table->unsignedInteger('qty')->default(1);
            $table->foreignId('photo_id')->nullable()->constrained('site_survey_photos')->nullOnDelete();
            $table->enum('status', ['proposed', 'quoted', 'approved', 'rejected'])->default('proposed');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('site_survey_id', 'survey_variations_survey_idx');
        });
    }

    public function down(): void
    {
        // Reverse in opposite order (drop FK-bearing table first).
        Schema::dropIfExists('survey_variations');

        Schema::table('site_surveys', function (Blueprint $table): void {
            $table->dropColumn('office_review_notes');
        });

        Schema::table('site_survey_rooms', function (Blueprint $table): void {
            $table->dropColumn('office_notes');
        });
    }
};
