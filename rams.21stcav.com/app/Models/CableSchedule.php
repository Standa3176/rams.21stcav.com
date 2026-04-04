<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class CableSchedule extends Model
{
    use SoftDeletes;
    protected $fillable = [
        'user_id',
        'project_name',
        'project_ref',
        'client_name',
        'source_filename',
        'status',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(CableScheduleItem::class)->orderBy('sort_order');
    }
}
