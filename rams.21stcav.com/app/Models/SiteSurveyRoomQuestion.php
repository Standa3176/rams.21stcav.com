<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SiteSurveyRoomQuestion — one generated pre-install check question per survey room.
 *
 * Records are created by GenerateSurveyQuestionsJob immediately after survey creation.
 * Engineers answer each question via the public survey form before marking a room complete.
 *
 * answer is an enum: yes | no | other (null = unanswered).
 * When answer = 'other', other_text holds the engineer's explanation.
 */
class SiteSurveyRoomQuestion extends Model
{
    protected $fillable = [
        'site_survey_room_id',
        'question',
        'sort_order',
        'answer',
        'other_text',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    // ─── Relationships ───────────────────────────────────────────────────

    public function room(): BelongsTo
    {
        return $this->belongsTo(SiteSurveyRoom::class, 'site_survey_room_id');
    }
}
