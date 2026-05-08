<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Office-side capture of scope changes found during/after a site survey.
 *
 * Quick task 260508-v7g. Flat record-keeping surface for the sales team's
 * quote-revision conversation — no workflow state machine, no events, no
 * notifications (D-LOCK-1). The status enum exists so the office can flip the
 * dropdown freely as the commercial conversation evolves; no enforced order.
 *
 * Authority: D-LOCK-1 in the plan freezes the enum values and column shape.
 *
 * Fields:
 *   - site_survey_id      FK to site_surveys (cascade-delete)
 *   - room_name           free-text room label (nullable; survey-wide variations omit it)
 *   - type                extra_hardware | extra_labour | cable_change |
 *                         client_provided_change | access_issue | other
 *   - description         required (a variation with no description is junk)
 *   - qty                 default 1
 *   - photo_id            optional FK to site_survey_photos (nullOnDelete)
 *   - status              proposed | quoted | approved | rejected (default proposed)
 *   - notes               optional free-text
 *
 * Surfaced in: site-survey/edit page (Variations & Additions table),
 *              client-report.blade.php (per-room + survey-wide summary),
 *              variations CSV download (sales export).
 */
class SurveyVariation extends Model
{
    protected $fillable = [
        'site_survey_id',
        'room_name',
        'type',
        'description',
        'qty',
        'photo_id',
        'status',
        'notes',
    ];

    protected $casts = [
        'qty' => 'integer',
    ];

    /**
     * The survey that owns this variation. Cascade-deleted when the survey is
     * hard-deleted; soft-deletes leave variations intact (matches SiteSurvey's
     * SoftDeletes trait).
     */
    public function survey(): BelongsTo
    {
        return $this->belongsTo(SiteSurvey::class, 'site_survey_id');
    }

    /**
     * Optional reference photo (e.g. snapshot of the un-quoted hardware found
     * on-site). Photo must belong to a room in the SAME survey — guard is
     * enforced in SurveyVariationController::validate() via an explicit
     * allow-list, not at the DB layer.
     */
    public function photo(): BelongsTo
    {
        return $this->belongsTo(SiteSurveyPhoto::class, 'photo_id');
    }
}
