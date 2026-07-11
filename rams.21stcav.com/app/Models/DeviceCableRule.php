<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

/**
 * Quick task 260711-q7q — data-driven cable inference rule.
 *
 * Priority-ordered lookup rows consumed by
 * CableScheduleGeneratorService::inferCableRun(). The service iterates
 * `DeviceCableRule::forInference()` and returns the first rule whose
 * keyword list word-boundary-matches the equipment name.
 *
 * Cache: `forInference()` memoises the active rule set for 1 hour under
 * the key `device_cable_rules.for_inference`. The saved/deleted model
 * events flush the cache automatically so admin CRUD writes propagate
 * to the next generation instantly — no manual `cache:clear` needed.
 * A stale cache is bounded at 1 hour even if the boot hook is bypassed
 * (e.g. direct SQL via tinker).
 *
 * @see \App\Services\CableScheduleGeneratorService::inferCableRun
 */
class DeviceCableRule extends Model
{
    public const CACHE_KEY = 'device_cable_rules.for_inference';

    public const CACHE_TTL_SECONDS = 3600;

    protected $fillable = [
        'priority',
        'keywords',
        'cable_type',
        'cores',
        'signal_type',
        'to_endpoint',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'priority'  => 'integer',
        'keywords'  => 'array',
        'is_active' => 'boolean',
    ];

    /**
     * Return the active rule set ordered by priority ASC.
     *
     * @return Collection<int, self>
     */
    public static function forInference(): Collection
    {
        return Cache::remember(
            self::CACHE_KEY,
            self::CACHE_TTL_SECONDS,
            fn () => static::query()
                ->where('is_active', true)
                ->orderBy('priority')
                ->get()
        );
    }

    public static function flushCache(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    protected static function booted(): void
    {
        static::saved(fn () => static::flushCache());
        static::deleted(fn () => static::flushCache());
    }
}
