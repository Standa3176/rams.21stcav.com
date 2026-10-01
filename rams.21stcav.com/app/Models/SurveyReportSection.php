<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 46.6, Plan 02 — an OFFICE ANNOTATION on one of the completed-survey
 * report's four named sections.
 *
 * 46.6-CONTEXT.md D-08, verbatim:
 *
 *   "Do NOT write a second PDF renderer, and do not invent a new field for
 *   the report's office content. ... follow the shipped doctrine in
 *   app/Support/Visits/VisitReworkState.php: the office annotates a returned
 *   visit via `visit_notes` WITHOUT CHANGING WHAT THE ENGINEER SAID."
 *
 * That sentence is this model's entire reason for existing as a separate
 * table, keyed off `site_survey_id` rather than `visit_id`: `VisitReworkState
 * ::forSurvey()` returns NULL for any survey with no `Visit` wrapping it, and
 * this report must work on EVERY completed survey — visit-wrapped or not.
 *
 * ── DO NOT "CONSOLIDATE" THIS WITH `site_surveys.office_review_notes` OR
 *    `site_survey_rooms.office_notes` ─────────────────────────────────────
 *
 * Both columns already exist and reusing either would have been one line.
 * Three reasons it was not, mirroring `VisitNote`'s own doctrine comment so
 * the next agent does not undo the decision by tidying:
 *
 *   1. Both are SINGLE-VALUED and OVERWRITABLE — the second entry destroys
 *      the first.
 *   2. Neither carries AN AUTHOR or A TIMESTAMP — "who said that, and when"
 *      is unanswerable.
 *   3. Both already serve a DIFFERENT purpose (the client-report cover
 *      note). Office text for the five-section report living inside them
 *      is exactly the shape D-08 forbids by analogy with D-02.
 *
 * ── APPEND-ONLY, STRUCTURALLY ───────────────────────────────────────────────
 *
 * `UPDATED_AT = null` and the table has no `updated_at` column, the same
 * shape `VisitNote` uses. THERE IS NO UPDATE PATH AND NO DELETE PATH: no
 * route, no controller method, no model method. A test greps `app/` and the
 * route table so the absence cannot quietly become a presence.
 *
 * @see database/migrations/2026_10_01_000000_create_survey_report_sections_table.php
 * @see app/Models/VisitNote.php (the doctrine mirrored here)
 * @see app/Support/Visits/VisitReworkState.php (why this keys off site_survey_id)
 * @see .planning/phases/46.6-survey-review-completed-report/46.6-CONTEXT.md (D-03, D-08)
 *
 * @property int $id
 * @property int $site_survey_id
 * @property string $section
 * @property string $body
 * @property ?int $user_id
 * @property ?\Illuminate\Support\Carbon $created_at
 */
class SurveyReportSection extends Model
{
    /** Append-only: no updated_at. */
    public const UPDATED_AT = null;

    /**
     * The four sections the office may write to. Fixed — not office
     * configurable (D-05 of 46.6-CONTEXT.md names exactly these, plus
     * "rooms summary" which is read directly off the survey's own rooms
     * rather than annotated here).
     */
    public const SECTIONS = [
        'points_raised',
        'items_agreed',
        'client_items_required',
        'additional_comments',
    ];

    protected $fillable = [
        'site_survey_id',
        'section',
        'body',
        'user_id',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    // ── Relationships ────────────────────────────────────────────────────

    public function survey(): BelongsTo
    {
        return $this->belongsTo(SiteSurvey::class, 'site_survey_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // ── Accessor ─────────────────────────────────────────────────────────

    /**
     * The same word `VisitNote` and the activity feed use for a deleted
     * login, so one person leaving does not read two different ways on the
     * same panel.
     *
     * @see VisitNote::getActorNameAttribute()
     */
    public function getActorNameAttribute(): string
    {
        return $this->author?->name ?? 'System';
    }

    // ── Reads ────────────────────────────────────────────────────────────

    /**
     * The most-recently-created row for a survey+section, or null if none
     * exists yet. Ordered by `id`, not `created_at` — multiple rows can
     * share a timestamp, and `id` is the only monotonic ordering guaranteed
     * to agree with insertion order.
     */
    public static function latestFor(int $siteSurveyId, string $section): ?self
    {
        return static::query()
            ->where('site_survey_id', $siteSurveyId)
            ->where('section', $section)
            ->latest('id')
            ->first();
    }
}
