<?php

namespace App\Services\Worksheet;

use Illuminate\Support\Facades\Log;

/**
 * Deterministic tiered classifier for worksheet line items.
 *
 * Returns one of the six canonical categories (display / video_conferencing /
 * audio / control / rack / network) OR one of the internal sentinels
 * (unclassified / existing_unknown / warranty_service / mount_accessory).
 *
 * Tier precedence:
 *   T1 sku_map              — exact part_no match (highest confidence)
 *   T2 manufacturer_rules   — manufacturer + product-family keyword pair
 *   T3 keyword_rules        — description keyword match
 *   T4a warranty            — if warranty kw present, try T1-T3 on own text
 *                             first, then parent-line inheritance
 *   T4b mount               — mount_inherit cascade based on other items in room
 *   T4c existing            — "utilise existing X" → classify X; else existing_unknown
 *   T5 unclassified         — explicit, never silently mapped to "Other Hardware"
 *
 * Every verdict includes:
 *   category   — string (taxonomy key or sentinel)
 *   tier       — int 1..5
 *   reason     — human-readable explanation
 *   signal     — the input substring that matched (for observability)
 *   fallback_used — true if tier > 2
 *
 * This class is stateless and safe to resolve as a singleton. Configuration
 * is loaded once per instance via config('worksheet_taxonomy').
 */
class WorksheetClassifier
{
    /**
     * @var array<string, mixed> Loaded taxonomy config
     */
    private array $taxonomy;

    public function __construct(?array $taxonomyOverride = null)
    {
        $this->taxonomy = $taxonomyOverride ?? (array) config('worksheet_taxonomy', []);
    }

    // ─── Public API ──────────────────────────────────────────────────────────

    /**
     * Classify a single item. $roomContext is the list of other items in the
     * same room; required for Tier 4 inheritance. Pass an empty array if no
     * context is available — the classifier will fall through to T5.
     *
     * @param array $item        Item dict with keys: name / description / part_no / sku / manufacturer / category (source) / item_type
     * @param array $roomContext Other items in the same room (may be empty)
     * @return array{category: string, tier: int, reason: string, signal: ?string, fallback_used: bool}
     */
    public function classify(array $item, array $roomContext = []): array
    {
        $name        = $this->text($item['name'] ?? $item['description'] ?? '');
        $description = $this->text($item['description'] ?? $item['name'] ?? '');
        $partNo      = $this->text($item['part_no'] ?? $item['sku'] ?? $item['model'] ?? '');
        $mfg         = $this->text($item['manufacturer'] ?? '');

        $nameLower   = strtolower($name);
        $descLower   = strtolower($description);
        $mfgLower    = strtolower($mfg);

        $haystacks = array_filter([$nameLower, $descLower, $mfgLower]);

        // ── Skip excluded: labour / services / delivery — classifier never
        //    lands these in a category. Caller already filters these; this is
        //    belt-and-braces.
        foreach ((array) ($this->taxonomy['exclude_keywords'] ?? []) as $kw) {
            foreach ($haystacks as $h) {
                if (str_contains($h, strtolower($kw))) {
                    return $this->verdict('warranty_service', 5, 'Excluded: labour/service keyword', $kw, true);
                    // Caller should have filtered these upstream; return as
                    // sentinel so telemetry surfaces it if any slip through.
                }
            }
        }

        // ── T4a warranty: detect first, classify on own text, inherit only as fallback ──
        $isWarranty = $this->matchesAnyKeyword($haystacks, (array) ($this->taxonomy['warranty_keywords'] ?? []));

        // ── T4c existing: detect now so we can still try tiers 1–3 on the rest of the text ──
        $isExisting = $this->matchesAnyKeyword($haystacks, (array) ($this->taxonomy['existing_keywords'] ?? []));

        // ── T1: exact SKU match ──
        $t1 = $this->tier1SkuMap($partNo, $name);
        if ($t1 !== null) {
            return $this->finaliseContextOverrides($t1, $isWarranty, $isExisting);
        }

        // ── T2: manufacturer + keyword rules ──
        $t2 = $this->tier2Manufacturer($mfgLower, $nameLower, $descLower);
        if ($t2 !== null) {
            // T2 may return `mount_inherit` — handle below under T4b.
            if ($t2['category'] === 'mount_inherit') {
                $mountCat = $this->resolveMountInheritance($item, $roomContext);
                if ($mountCat !== null) {
                    return $this->verdict(
                        $mountCat['category'],
                        4,
                        'Mount inherit from ' . $mountCat['parent_name'],
                        $mountCat['parent_name'],
                        true,
                    );
                }
                return $this->verdict('mount_accessory', 4, 'Mount/bracket with no identifiable parent', null, true);
            }
            return $this->finaliseContextOverrides($t2, $isWarranty, $isExisting);
        }

        // ── T3: description keyword rules ──
        $t3 = $this->tier3Keywords($nameLower, $descLower);
        if ($t3 !== null) {
            return $this->finaliseContextOverrides($t3, $isWarranty, $isExisting);
        }

        // ── T4a warranty parent inheritance (tiers 1–3 inconclusive on own text) ──
        if ($isWarranty) {
            $inherited = $this->inheritFromPrecedingContext($item, $roomContext);
            if ($inherited !== null) {
                return $this->verdict(
                    $inherited['category'],
                    4,
                    'Warranty inherits from ' . $inherited['parent_name'],
                    $inherited['parent_name'],
                    true,
                );
            }
            return $this->verdict('warranty_service', 4, 'Warranty with no identifiable parent', null, true);
        }

        // ── T4c existing-unknown ──
        if ($isExisting) {
            return $this->verdict('existing_unknown', 4, 'Utilise-existing line with no identifiable category', null, true);
        }

        // ── T5 unclassified ──
        return $this->verdict('unclassified', 5, 'No tier matched', null, true);
    }

    /**
     * Classify every item in a room. Returns:
     *   ['items' => [...same items with _classification merged in],
     *    'telemetry' => [...per-item verdicts for logging]]
     *
     * @param array $items
     * @return array{items: array<int,array>, telemetry: array<int,array>}
     */
    public function classifyRoom(array $items): array
    {
        $classified = [];
        $telemetry  = [];
        foreach ($items as $idx => $item) {
            if (! is_array($item)) continue;
            $v = $this->classify($item, $items);
            $classified[] = $item + ['_classification' => $v];
            $telemetry[]  = [
                'room_index'     => $idx,
                'name'           => $item['name'] ?? $item['description'] ?? '(no name)',
                'part_no'        => $item['part_no'] ?? $item['sku'] ?? null,
                'category'       => $v['category'],
                'tier'           => $v['tier'],
                'reason'         => $v['reason'],
                'fallback_used'  => $v['fallback_used'],
            ];
        }
        return ['items' => $classified, 'telemetry' => $telemetry];
    }

    /**
     * Shadow run over every room. Returns aggregate telemetry WITHOUT mutating
     * the input rooms. Used in Pass A to observe classifier behaviour before
     * the generator switches to authoritative mode.
     */
    public function runShadow(array $rooms): array
    {
        $per_room  = [];
        $histogram = array_fill_keys(
            array_merge(
                array_keys((array) ($this->taxonomy['categories'] ?? [])),
                array_keys((array) ($this->taxonomy['sentinels'] ?? []))
            ),
            0,
        );
        $tierCounts = [1 => 0, 2 => 0, 3 => 0, 4 => 0, 5 => 0];
        $totalItems = 0;

        foreach ($rooms as $room) {
            if (! is_array($room)) continue;
            $roomName = $room['name'] ?? $room['room_name'] ?? '(unnamed)';
            $items = $room['equipment'] ?? [];
            if (! is_array($items)) continue;

            $result = $this->classifyRoom($items);

            $roomHisto = array_fill_keys(array_keys($histogram), 0);
            foreach ($result['telemetry'] as $t) {
                $histogram[$t['category']]  = ($histogram[$t['category']]  ?? 0) + 1;
                $roomHisto[$t['category']]  = ($roomHisto[$t['category']]  ?? 0) + 1;
                $tierCounts[$t['tier']]     = ($tierCounts[$t['tier']]     ?? 0) + 1;
                $totalItems++;
            }

            $per_room[] = [
                'room'       => $roomName,
                'item_count' => count($items),
                'histogram'  => array_filter($roomHisto, fn ($n) => $n > 0),
                'telemetry'  => $result['telemetry'],
            ];
        }

        return [
            'total_items'   => $totalItems,
            'histogram'     => array_filter($histogram, fn ($n) => $n > 0),
            'tier_counts'   => array_filter($tierCounts, fn ($n) => $n > 0),
            'unclassified_count' => $histogram['unclassified'] ?? 0,
            'per_room'      => $per_room,
            'run_at'        => date('c'),
        ];
    }

    // ─── Tier implementations ────────────────────────────────────────────────

    private function tier1SkuMap(string $partNo, string $name): ?array
    {
        $map = (array) ($this->taxonomy['sku_map'] ?? []);
        if ($map === []) return null;

        // Exact part_no match (case-insensitive)
        $upperPart = strtoupper(trim($partNo));
        if ($upperPart !== '' && isset($map[$upperPart])) {
            return $this->verdict($map[$upperPart], 1, 'SKU map hit (part_no)', $upperPart, false);
        }

        // Name may contain the SKU as a substring (common when parsers put
        // part number in the description). Scan map keys for word-boundary matches.
        $upperName = strtoupper($name);
        foreach ($map as $sku => $category) {
            if ($sku !== '' && str_contains($upperName, $sku)) {
                return $this->verdict($category, 1, 'SKU map hit (name contains)', $sku, false);
            }
        }
        return null;
    }

    private function tier2Manufacturer(string $mfgLower, string $nameLower, string $descLower): ?array
    {
        $rules = (array) ($this->taxonomy['manufacturer_rules'] ?? []);
        if ($rules === []) return null;

        $haystacks = array_filter([$mfgLower, $nameLower, $descLower]);

        foreach ($rules as $rule) {
            $manufacturers = (array) ($rule['manufacturer'] ?? []);
            $keywords      = (array) ($rule['keywords']     ?? []);
            $category      = (string) ($rule['category']    ?? '');
            if ($category === '' || $manufacturers === []) continue;

            $mfgMatch = null;
            foreach ($manufacturers as $m) {
                $mLower = strtolower($m);
                foreach ($haystacks as $h) {
                    if (str_contains($h, $mLower)) {
                        $mfgMatch = $mLower;
                        break 2;
                    }
                }
            }
            if ($mfgMatch === null) continue;

            // If keywords present, require at least one; otherwise manufacturer alone suffices.
            if ($keywords !== []) {
                $kwMatch = null;
                foreach ($keywords as $k) {
                    $kLower = strtolower($k);
                    foreach ($haystacks as $h) {
                        if (str_contains($h, $kLower)) {
                            $kwMatch = $kLower;
                            break 2;
                        }
                    }
                }
                if ($kwMatch === null) continue;
                return $this->verdict(
                    $category,
                    2,
                    "Manufacturer+keyword: {$mfgMatch} + {$kwMatch}",
                    "{$mfgMatch}|{$kwMatch}",
                    false,
                );
            }

            return $this->verdict($category, 2, "Manufacturer match: {$mfgMatch}", $mfgMatch, false);
        }
        return null;
    }

    private function tier3Keywords(string $nameLower, string $descLower): ?array
    {
        $rules = (array) ($this->taxonomy['keyword_rules'] ?? []);
        if ($rules === []) return null;

        foreach ($rules as $category => $keywords) {
            foreach ((array) $keywords as $k) {
                $kLower = strtolower($k);
                if (str_contains($nameLower, $kLower) || str_contains($descLower, $kLower)) {
                    return $this->verdict($category, 3, "Keyword: {$kLower}", $kLower, true);
                }
            }
        }
        return null;
    }

    private function resolveMountInheritance(array $mountItem, array $roomContext): ?array
    {
        $mountKeywords = (array) ($this->taxonomy['mount_inherit_keywords'] ?? []);
        $mountName  = strtolower($this->text($mountItem['name'] ?? $mountItem['description'] ?? ''));

        // 1. First check the mount's own name/description for a target hint.
        foreach ($mountKeywords as $category => $keywords) {
            foreach ((array) $keywords as $k) {
                if (str_contains($mountName, strtolower($k))) {
                    return ['category' => $category, 'parent_name' => "self:{$k}"];
                }
            }
        }

        // 2. Otherwise look at the first non-mount item in the same room that
        //    has a category signal. This handles the common case where a quote
        //    lists a display then its mount as adjacent lines.
        foreach ($roomContext as $peer) {
            if (! is_array($peer)) continue;
            $peerName = strtolower($this->text($peer['name'] ?? $peer['description'] ?? ''));
            if ($peerName === '' || $peerName === $mountName) continue;
            foreach ($mountKeywords as $category => $keywords) {
                foreach ((array) $keywords as $k) {
                    if (str_contains($peerName, strtolower($k))) {
                        return ['category' => $category, 'parent_name' => $peer['name'] ?? $peer['description'] ?? 'peer'];
                    }
                }
            }
        }
        return null;
    }

    private function inheritFromPrecedingContext(array $warrantyItem, array $roomContext): ?array
    {
        // Run tiers 1–3 on each peer; return the first decisive hit whose
        // category is a real taxonomy key (not a sentinel).
        $realCategories = array_keys((array) ($this->taxonomy['categories'] ?? []));

        foreach ($roomContext as $peer) {
            if (! is_array($peer) || $peer === $warrantyItem) continue;
            $peerClass = $this->classify($peer, []); // no recursion: empty context prevents loops
            if (in_array($peerClass['category'], $realCategories, true)) {
                return [
                    'category'    => $peerClass['category'],
                    'parent_name' => $peer['name'] ?? $peer['description'] ?? 'peer',
                ];
            }
        }
        return null;
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function finaliseContextOverrides(array $verdict, bool $isWarranty, bool $isExisting): array
    {
        // If the line is a warranty that was classified by its own text, that's fine — keep.
        // If it's existing, mark fallback_used but preserve the real category.
        if ($isExisting) {
            $verdict['reason'] = 'existing + ' . $verdict['reason'];
            $verdict['fallback_used'] = true;
        }
        if ($isWarranty) {
            $verdict['reason'] = 'warranty + ' . $verdict['reason'];
        }
        return $verdict;
    }

    private function matchesAnyKeyword(array $haystacks, array $keywords): bool
    {
        foreach ($keywords as $kw) {
            $kwLower = strtolower((string) $kw);
            foreach ($haystacks as $h) {
                if (str_contains($h, $kwLower)) return true;
            }
        }
        return false;
    }

    private function text(mixed $v): string
    {
        if ($v === null) return '';
        if (is_array($v)) return '';
        return trim((string) $v);
    }

    private function verdict(string $category, int $tier, string $reason, ?string $signal, bool $fallback): array
    {
        return [
            'category'      => $category,
            'tier'          => $tier,
            'reason'        => $reason,
            'signal'        => $signal,
            'fallback_used' => $fallback,
        ];
    }
}
