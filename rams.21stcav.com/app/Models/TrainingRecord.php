<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 4 — end-user training session record.
 *
 * Renders into the Training section of the Tier 1 OM (Phase 5) so the
 * client retains a record of who attended, what was covered, and whether
 * sign-off was captured on the day.
 */
class TrainingRecord extends Model
{
    protected $fillable = [
        'project_id',
        'attendees',
        'date',
        'topics',
        'signed_off',
    ];

    protected $casts = [
        'date'       => 'date',
        'signed_off' => 'boolean',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
