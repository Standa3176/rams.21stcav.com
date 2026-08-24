<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HazardTemplate extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'description',
        'pre_likelihood',
        'pre_severity',
        'post_likelihood',
        'post_severity',
        'controls',
        'include_when',
        'is_global',
    ];

    protected $casts = [
        'controls'   => 'array',
        'is_global'  => 'boolean',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Scope: templates visible to a given user (global OR owned by them).
     */
    public function scopeVisibleTo($query, int $userId): void
    {
        $query->where(function ($q) use ($userId) {
            $q->where('is_global', true)
              ->orWhere('user_id', $userId);
        });
    }
}
