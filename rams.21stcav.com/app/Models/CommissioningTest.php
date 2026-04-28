<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 4 — commissioning test result.
 *
 * One row per check (audio gain, display sync, mic gating, network throughput, …).
 * Renders into the Commissioning Results section of the Tier 1 OM (Phase 5).
 */
class CommissioningTest extends Model
{
    public const RESULT_PASS    = 'pass';
    public const RESULT_FAIL    = 'fail';
    public const RESULT_PARTIAL = 'partial';
    public const RESULT_NA      = 'na';

    protected $fillable = [
        'project_id',
        'room',
        'test_type',
        'result',
        'value',
        'signed_off_by',
        'date',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
