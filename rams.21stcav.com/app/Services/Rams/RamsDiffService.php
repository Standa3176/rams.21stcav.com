<?php

namespace App\Services\Rams;

/**
 * RamsDiffService
 *
 * Compares extracted_data and reviewed_data on a RAMS document to produce
 * a structured list of field-level changes for UI highlighting.
 *
 * INPUT:
 *   array $original  — extracted_data (before review)
 *   array $reviewed  — reviewed_data  (after review edits)
 *
 * OUTPUT:
 *   {
 *     "changes": [
 *       {
 *         "field": string,    // dot-notation path (e.g. "hazards.2.hazard")
 *         "old":   mixed,     // original value (null if added)
 *         "new":   mixed,     // reviewed value (null if removed)
 *         "type":  string,    // "added" | "modified" | "removed"
 *       }
 *     ],
 *     "summary": {
 *       "added":    int,
 *       "modified": int,
 *       "removed":  int,
 *       "total":    int,
 *     }
 *   }
 *
 * Deterministic. No AI. No database.
 */
class RamsDiffService
{
    /**
     * Produce a structured diff between extracted and reviewed data.
     *
     * @param  array  $original  extracted_data payload.
     * @param  array  $reviewed  reviewed_data payload.
     * @return array  { changes: array[], summary: array }
     */
    public static function diff(array $original, array $reviewed): array
    {
        $changes = [];

        self::compareRecursive($original, $reviewed, '', $changes);

        // Count by type
        $added    = 0;
        $modified = 0;
        $removed  = 0;

        foreach ($changes as $c) {
            match ($c['type']) {
                'added'    => $added++,
                'modified' => $modified++,
                'removed'  => $removed++,
                default    => null,
            };
        }

        return [
            'changes' => $changes,
            'summary' => [
                'added'    => $added,
                'modified' => $modified,
                'removed'  => $removed,
                'total'    => count($changes),
            ],
        ];
    }

    /**
     * Check if a specific field path has a change.
     *
     * @param  array   $diff   Output of diff().
     * @param  string  $field  Dot-notation field path.
     * @return array|null      The change entry, or null if unchanged.
     */
    public static function fieldChange(array $diff, string $field): ?array
    {
        foreach ($diff['changes'] ?? [] as $change) {
            if ($change['field'] === $field) {
                return $change;
            }
        }

        return null;
    }

    /**
     * Check if any field matching a prefix has changes.
     *
     * @param  array   $diff    Output of diff().
     * @param  string  $prefix  Dot-notation prefix (e.g. "hazards" matches "hazards.0.hazard").
     * @return array[]           All matching change entries.
     */
    public static function fieldChangesUnder(array $diff, string $prefix): array
    {
        $matches = [];
        $prefixDot = $prefix . '.';

        foreach ($diff['changes'] ?? [] as $change) {
            if ($change['field'] === $prefix || str_starts_with($change['field'], $prefixDot)) {
                $matches[] = $change;
            }
        }

        return $matches;
    }

    // =========================================================================
    // PRIVATE — RECURSIVE COMPARISON
    // =========================================================================

    /**
     * Recursively compare two arrays and collect changes.
     */
    private static function compareRecursive(
        array  $original,
        array  $reviewed,
        string $prefix,
        array  &$changes,
    ): void {
        // Keys present in both + only in original + only in reviewed
        $allKeys = array_unique(array_merge(array_keys($original), array_keys($reviewed)));

        foreach ($allKeys as $key) {
            $field       = $prefix === '' ? (string) $key : $prefix . '.' . $key;
            $inOriginal  = array_key_exists($key, $original);
            $inReviewed  = array_key_exists($key, $reviewed);

            // ── Key removed ──────────────────────────────────────────────────
            if ($inOriginal && ! $inReviewed) {
                $changes[] = [
                    'field' => $field,
                    'old'   => $original[$key],
                    'new'   => null,
                    'type'  => 'removed',
                ];
                continue;
            }

            // ── Key added ────────────────────────────────────────────────────
            if (! $inOriginal && $inReviewed) {
                if (self::isEmpty($reviewed[$key])) {
                    continue;
                }

                $changes[] = [
                    'field' => $field,
                    'old'   => null,
                    'new'   => $reviewed[$key],
                    'type'  => 'added',
                ];
                continue;
            }

            // ── Both present — compare values ────────────────────────────────
            $oldVal = $original[$key];
            $newVal = $reviewed[$key];

            // Both are arrays
            if (is_array($oldVal) && is_array($newVal)) {
                // Flat scalar arrays → compare as sets (order-insensitive)
                if (self::isFlatScalarArray($oldVal) && self::isFlatScalarArray($newVal)) {
                    self::compareScalarArrays($oldVal, $newVal, $field, $changes);
                    continue;
                }

                // Nested arrays → recurse
                self::compareRecursive($oldVal, $newVal, $field, $changes);
                continue;
            }

            // One is array, other isn't → type change = modified
            if (is_array($oldVal) !== is_array($newVal)) {
                $changes[] = [
                    'field' => $field,
                    'old'   => $oldVal,
                    'new'   => $newVal,
                    'type'  => 'modified',
                ];
                continue;
            }

            // Scalar comparison (normalise types for comparison)
            if (self::normalise($oldVal) !== self::normalise($newVal)) {
                $changes[] = [
                    'field' => $field,
                    'old'   => $oldVal,
                    'new'   => $newVal,
                    'type'  => 'modified',
                ];
            }
        }
    }

    // =========================================================================
    // PRIVATE — FLAT SCALAR ARRAY COMPARISON (SET-BASED)
    // =========================================================================

    /**
     * Check if an array is a flat list of scalars (strings, ints, floats, bools, nulls).
     * Returns false for associative arrays or arrays containing sub-arrays.
     */
    private static function isFlatScalarArray(array $arr): bool
    {
        if (empty($arr)) {
            return true;
        }

        // Must be sequentially indexed (list, not associative)
        if (array_keys($arr) !== range(0, count($arr) - 1)) {
            return false;
        }

        foreach ($arr as $v) {
            if (is_array($v) || is_object($v)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Compare two flat scalar arrays as sets (order-insensitive).
     * Only emits added/removed entries — not "modified" for reordering.
     */
    private static function compareScalarArrays(
        array  $oldArr,
        array  $newArr,
        string $field,
        array  &$changes,
    ): void {
        $oldSet = array_values(array_unique(array_map([self::class, 'normalise'], $oldArr)));
        $newSet = array_values(array_unique(array_map([self::class, 'normalise'], $newArr)));

        $added   = array_values(array_diff($newSet, $oldSet));
        $removed = array_values(array_diff($oldSet, $newSet));

        foreach ($added as $val) {
            $changes[] = [
                'field' => $field,
                'old'   => null,
                'new'   => $val,
                'type'  => 'added',
            ];
        }

        foreach ($removed as $val) {
            $changes[] = [
                'field' => $field,
                'old'   => $val,
                'new'   => null,
                'type'  => 'removed',
            ];
        }
    }

    // =========================================================================
    // PRIVATE — HELPERS
    // =========================================================================

    /**
     * Normalise a scalar value for comparison.
     * Trims strings and casts numeric strings to numbers.
     */
    private static function normalise(mixed $value): mixed
    {
        if (is_string($value)) {
            $trimmed = trim($value);
            if (is_numeric($trimmed)) {
                return $trimmed + 0;
            }
            return $trimmed;
        }

        return $value;
    }

    /**
     * Check if a value is meaningfully empty.
     */
    private static function isEmpty(mixed $value): bool
    {
        if ($value === null || $value === '' || $value === []) {
            return true;
        }

        if (is_array($value)) {
            foreach ($value as $v) {
                if (! self::isEmpty($v)) {
                    return false;
                }
            }
            return true;
        }

        return false;
    }
}
