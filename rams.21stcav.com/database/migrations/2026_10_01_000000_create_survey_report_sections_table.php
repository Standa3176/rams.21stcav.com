<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 46.6, Plan 02 — `survey_report_sections`, the office's annotation of a
 * COMPLETED SURVEY's five-section report (D-03/D-08).
 *
 * 46.6-CONTEXT.md D-08, verbatim:
 *
 *   "Do NOT write a second PDF renderer, and do not invent a new field for
 *   the report's office content. ... follow the shipped doctrine in
 *   app/Support/Visits/VisitReworkState.php: the office annotates a returned
 *   visit via `visit_notes` WITHOUT CHANGING WHAT THE ENGINEER SAID."
 *
 * ── WHY A NEW TABLE, NOT `visit_notes` ITSELF ───────────────────────────────
 *
 * `VisitReworkState::forSurvey()` returns NULL for any survey with no `Visit`
 * wrapping it (pre-Phase-45 surveys, or any survey created outside the
 * visit-issuing flow). `visit_notes.visit_id` would then have nowhere valid
 * to point, and this report must work on EVERY completed survey, not only
 * visit-wrapped ones. `visit_notes` is also a single, undifferentiated
 * free-text stream (one `body` per note); the five-section report needs four
 * independently addressable sections so 46.6-04 can place each in its own
 * named spot in the PDF/XLSX. This table keeps `VisitNote`'s doctrine
 * (append-only, attributed, FK-safe) while keying off `site_survey_id`
 * directly — the same choice `SurveyVariation` already made for the same
 * reason (see `2026_05_08_120000_create_survey_variations_and_office_notes.php`).
 *
 * ── WHY `site_surveys.office_review_notes` / `site_survey_rooms.office_notes`
 *    WERE **NOT** REUSED (mirrors VisitNote's three reasons) ────────────────
 *
 *   1. Both are SINGLE-VALUED and OVERWRITABLE — a second entry destroys the
 *      first.
 *   2. Neither carries AN AUTHOR or A TIMESTAMP — "who said that, and when"
 *      is unanswerable.
 *   3. Both live ON THE ENGINEER'S / office's EXISTING SURFACE for a
 *      DIFFERENT purpose (the client-report cover note) — reusing them here
 *      would conflate two surfaces the next reader would then have to
 *      untangle. Those two columns are UNCHANGED by this migration and by
 *      every piece of code this plan adds.
 *
 * ── APPEND-ONLY, STRUCTURALLY (mirrors `visit_notes`) ───────────────────────
 *
 * `created_at` ONLY — no `updated_at` column at all. `SurveyReportSection::
 * UPDATED_AT` is null. THERE IS NO UPDATE PATH AND NO DELETE PATH: no route,
 * no controller method, no model method. A test greps `app/` and the route
 * table so the absence cannot quietly become a presence.
 *
 * ── FK dispositions ──────────────────────────────────────────────────────
 *
 *   - `site_survey_id` CASCADES: mirrors `SurveyVariation` — the annotation
 *     has no meaning without its survey.
 *   - `user_id` NULLS: the annotation outlives the account, same as
 *     `visit_notes.user_id`.
 *
 * ⚠️ Tests run sqlite `:memory:`; live is MySQL/MariaDB, which caps
 * identifiers at 64 characters. Every index is named explicitly here:
 *   - `survey_report_sections_survey_idx` — 33 characters.
 *
 * @see app/Models/SurveyReportSection.php
 * @see app/Models/VisitNote.php
 * @see .planning/phases/46.6-survey-review-completed-report/46.6-CONTEXT.md (D-03, D-08)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('survey_report_sections', function (Blueprint $table) {
            $table->id();

            $table->foreignId('site_survey_id')
                ->constrained('site_surveys')
                ->cascadeOnDelete();

            $table->enum('section', [
                'points_raised',
                'items_agreed',
                'client_items_required',
                'additional_comments',
            ]);

            // The office's words. Stored raw, escaped at render — `{{ }}`
            // only, never `{!! !!}`.
            $table->text('body');

            $table->foreignId('user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // APPEND-ONLY: created_at and nothing else. No updated_at column.
            $table->timestamp('created_at')->nullable();

            // The one read: all sections for a survey, in creation order.
            $table->index('site_survey_id', 'survey_report_sections_survey_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('survey_report_sections');
    }
};
