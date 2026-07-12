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
 * Length tiers (260712-euh):
 * When a rule's `length_tiers` array is populated, inferCableRun($name,
 * ?float $lengthM) picks the FIRST tier whose `max_m` is ≥ $lengthM;
 * that tier's cable_type / cores / to_endpoint / notes OVERRIDE the
 * flat row values (signal_type stays from the flat row). Null / empty
 * tiers array = flat cable_type used. Null $lengthM = tier 1 (safest
 * passive) + '⚠ Length not confirmed' warning. Over-max $lengthM =
 * LAST tier + '⚠⚠ exceeds max range' warning. Shape per tier:
 *   ['max_m' => (int|float), 'cable_type' => (string), 'cores' => ?string,
 *    'to_endpoint' => ?string, 'notes' => ?string]
 * The admin FormRequest sorts entries ascending on max_m at persist
 * time so the read-side never re-sorts.
 *
 * Negative keywords (260712-ip3):
 * When `negative_keywords` is a non-empty array, the inference walker
 * treats the rule as SKIPPED whenever the equipment name matches ANY
 * entry in this list — even if the positive keyword list ALSO matched.
 * This kills brand-name collisions like `Logitech USB 3.0 Webcam`
 * hitting the priority 70 VC codec rule on the `logitech` keyword
 * BEFORE the priority 141 USB 3 rule can win. Null / empty array =
 * no exclusion, behaviour identical to pre-260712-ip3.
 *
 * @see \App\Services\CableScheduleGeneratorService::inferCableRun
 * @see \App\Services\CableScheduleGeneratorService::ruleMatches
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
        'length_tiers',
        'negative_keywords',
    ];

    protected $casts = [
        'priority'          => 'integer',
        'keywords'          => 'array',
        'is_active'         => 'boolean',
        'length_tiers'      => 'array',
        'negative_keywords' => 'array',
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
