<?php

namespace App\Services\Worksheet;

/**
 * Resolves a client-friendly description for a worksheet line item when the
 * rendered name looks like a bare SKU / part-number token.
 *
 * Lookup order:
 *   1. If name is already a readable description (spaces present, length > 12) → return as-is.
 *   2. If item's part_no / sku / name (upper-cased) matches config('worksheet_friendly_names')
 *      → return the friendly value.
 *   3. Fall back to description, then name.
 *
 * Stateless; safe to resolve as a singleton.
 */
class FriendlyNameResolver
{
    /** @var array<string, string> Upper-cased key → friendly description */
    private array $map;

    public function __construct(?array $mapOverride = null)
    {
        $source = $mapOverride ?? (array) config('worksheet_friendly_names', []);
        $this->map = [];
        foreach ($source as $k => $v) {
            if ($k === null || $v === null) continue;
            $this->map[strtoupper((string) $k)] = (string) $v;
        }
    }

    /**
     * Resolve the best user-visible name for this item.
     */
    public function resolve(array $item): string
    {
        $name        = trim((string) ($item['name']        ?? ''));
        $description = trim((string) ($item['description'] ?? ''));
        $partNo      = trim((string) ($item['part_no']     ?? $item['sku'] ?? $item['model'] ?? ''));

        // Fast path: name already reads as a description (contains space,
        // meaningfully long). Use it.
        if ($name !== '' && $this->isReadable($name)) {
            return $name;
        }

        // Try the friendly map by part_no, then by bare name (common when
        // the parser put the SKU into the name field).
        foreach ([$partNo, $name] as $candidate) {
            if ($candidate === '') continue;
            $key = strtoupper($candidate);
            if (isset($this->map[$key])) {
                return $this->map[$key];
            }
        }

        // No friendly hit: prefer the longer of description / name.
        if ($description !== '' && $this->isReadable($description)) {
            return $description;
        }
        if ($description !== '' && $name === '') {
            return $description;
        }
        return $name !== '' ? $name : $description;
    }

    /**
     * True if the string reads like a human description rather than a SKU.
     * Heuristic: contains at least one space AND is longer than 12 chars,
     * OR contains at least two whitespace-separated words.
     */
    private function isReadable(string $s): bool
    {
        $s = trim($s);
        if ($s === '') return false;
        $wordCount = preg_match_all('/\S+/u', $s);
        if ($wordCount >= 3) return true;
        return $wordCount >= 2 && strlen($s) > 12;
    }
}
