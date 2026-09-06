<?php

namespace App\Services\Rams;

/**
 * Phase 28 Plan 03 (research Q4 gap closure) — the single choke point for
 * folding closed-vocabulary PPE picklist strings forward when a house rule
 * changes the required wording.
 *
 * ── Why this class exists, and why it is NOT `ControlTextRuleViolations` ──
 *
 * `28-RESEARCH.md` Q4 found that `reviewed_data['ppe']` (and its downstream
 * `generated_data['ppe']`) is a closed pick-list array — checkboxes an
 * engineer ticks from a fixed set of options
 * (`RiskTemplateResolverService::PPE_BASE`/`PPE_ACTIVITY_MAP`, and the two
 * controllers' `PPE_OPTIONS` consts), never free engineer prose. Every call
 * site that touches it (`RamsBuilderService::reviewedToRisk()`'s ppe
 * assembly line, `RamsDataBuilderService::mergePpe()`) was plain
 * `array_unique(array_merge(...))`/`array_filter` with ZERO rule-scanning —
 * a stored `Dust Mask (FFP2)` string would survive forever on every future
 * regeneration, regardless of every other RULE-01 fix in this phase.
 * `ControlTextRuleViolations` is the wrong tool for this surface: its own
 * docblock and D-01's analysis are explicit that it exists for free-text
 * hazard `controls[]` lines, not closed-vocabulary picklist items.
 *
 * This class is the simpler sibling `LegacyHazardNameFoldMap` already
 * establishes the shape for: an all-static string-replace map with a single
 * choke point and a drift-guard test. It differs from
 * `LegacyHazardNameFoldMap::canonicalName()` in one respect — an unmapped
 * PPE item must pass through UNCHANGED (not null), since it is applied to a
 * whole array of items, most of which are not FFP2-related (see
 * `canonical()`'s docblock).
 *
 * Do not duplicate this map anywhere else — every caller resolves a stored
 * PPE string through `canonical()`/`canonicalAll()`, never a second
 * hand-copied replace.
 */
final class PpeVocabularyFoldMap
{
    /**
     * legacy PPE item (lowercase, trimmed) => canonical replacement string.
     *
     * @var array<string, string>
     */
    private const MAP = [
        'dust mask (ffp2)' => 'Dust Mask (FFP3)',
    ];

    /**
     * Resolve a single PPE item to its canonical replacement.
     *
     * Unlike `LegacyHazardNameFoldMap::canonicalName()`, a miss never
     * returns null — an unmapped PPE item (the vast majority of any real
     * PPE array) must pass through UNCHANGED, or it would be silently
     * dropped from the engineer's picked list. Returns the ORIGINAL,
     * untrimmed `$item` on a miss, not the trimmed lookup key.
     */
    public static function canonical(string $item): string
    {
        $key = strtolower(trim($item));

        return self::MAP[$key] ?? $item;
    }

    /**
     * Resolve every item in a PPE array, preserving order.
     *
     * @param  array<int, mixed>  $items
     * @return array<int, string>
     */
    public static function canonicalAll(array $items): array
    {
        return array_map([self::class, 'canonical'], $items);
    }

    /**
     * The full map, canonical-value side only. For tests — proves the
     * map's OUTPUT side can never re-introduce the banned FFP2 token,
     * mirroring `LegacyHazardNameFoldMap::all()`.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::MAP;
    }
}
