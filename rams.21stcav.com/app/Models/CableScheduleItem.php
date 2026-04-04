<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CableScheduleItem extends Model
{
    protected $fillable = [
        'cable_schedule_id',
        'cable_id',
        'from_location',
        'to_location',
        'cable_type',
        'cores',
        'approx_length_m',
        'notes',
        'sort_order',
    ];

    protected $casts = [
        'approx_length_m' => 'float',
    ];

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(CableSchedule::class, 'cable_schedule_id');
    }
}
