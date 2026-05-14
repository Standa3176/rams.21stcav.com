# Phase 23 Discovery — Open Question 1: Category Vocabulary

**Resolved:** 2026-05-14
**Researcher:** Plan 23-01 Task 1 (per D-01 + D-09 generic naming)

## Production Category Strings (sample)

Tinker query against local DB:

```
"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan tinker --execute="echo json_encode(\App\Models\ProjectPackage::query()->whereNotNull('extracted_data')->latest()->take(20)->get()->flatMap(fn(\$p) => collect(data_get(\$p->extracted_data, 'equipment', []))->pluck('category'))->filter()->unique()->values()->all(), JSON_PRETTY_PRINT);"
```

Output verbatim:

```json
[]
```

Interpretation: Local dev DB has **0 quoted projects** at the time of resolution
(`ProjectPackage::count() = 0`). The empty result is not "no categories exist" — it is
"no quote data is loaded in this dev environment". The canonical category vocabulary
must therefore be derived from the **review form's `$categoryOptions` source-of-truth**
because every project that gets reviewed flows through that dropdown — its keys ARE the
real category strings persisted into `extracted_data['equipment'][N]['category']`.

Reference: `resources/views/project-packages/review.blade.php` lines 599–607:

```php
$categoryOptions = [
    'hardware'          => 'Hardware',
    'cables'            => 'Cables',
    'consumables'       => 'Consumables',
    'services'          => 'Services / Professional',
    'service_contracts' => 'Service Contracts',
    'customer_supplied' => 'Customer Supplied',
    'option'            => 'Option (Optional Items)',
];
```

These 7 high-level strings ARE the production category vocab.

## Comparison to D-01 Seed Map

D-01 candidate keys (from 23-CONTEXT.md lines 53-66):
- rack-mount-switch, network-switch, poe-switch, amplifier, dsp, matrix, processor → RACK
- ceiling-mic, ceiling-speaker, ceiling-camera → CEILING
- display, screen, projector → WALL
- touchpanel, desk-mic, tabletop-codec → TABLE
- paging-station, call-station → PAGING_STATION
- intercom, door-station → RECEPTION
- ups, distribution-strip → FLOOR

Real-data category strings (from review.blade.php $categoryOptions):
- hardware, cables, consumables, services, service_contracts, customer_supplied, option

Overlap:
- **0 of 22 D-01 keys** are present in the canonical $categoryOptions list.
- **Overlap = 0%.** The D-01 seed map's lower-level semantic strings describe what
  categories WOULD look like in a future world where the review form sub-categorises
  hardware (e.g. "hardware → ceiling-mic"). They are NOT what `extracted_data['equipment'][N]['category']`
  actually contains today.
- Same DB shape applies in production — the review form is the only writer, and it
  only writes the 7 high-level keys. The Phase 21 `Project::devicesWithStencils()`
  accessor filters by `category=hardware` precisely because `hardware` is what's there.

## Disposition

**Path B selected (Reduce to high-level categories only).**

D-01 seed map is replaced at the production-ship-time level with a 1-row map keyed on
`hardware`, which falls through to a **device-name-keyword secondary derivation** so that
hardware lines still grouped into the engineering-grade zones the XTEN-AV reference shows
(RACK / CEILING / WALL / TABLE etc.). The keyword derivation runs against the device's
`name` field (and falls back to `model` if `name` is empty) because that is where the
human-readable kind information lives in real quote data (e.g. "Sennheiser TeamConnect
Ceiling 2", "Samsung QM65C-T 65" Display", "Netgear GS312TP Rack Switch").

Concrete category_to_zone shape Plan 02 will ship (Task 3 of THIS plan seeds the lower-level
keys verbatim so the map is FUTURE-PROOF — if a future Phase 24 sub-category UI ever lands,
the lookup already knows what to do. But the RUNTIME path for Phase 23 will be the keyword
derivation):

- `hardware`            → fall through to name-keyword derivation (see below)
- `cables`              → OTHER  (cables aren't devices — won't be in `devicesWithStencils()` anyway, filtered out)
- `consumables`         → OTHER
- `services`            → OTHER
- `service_contracts`   → OTHER
- `customer_supplied`   → OTHER
- `option`              → OTHER

(All non-hardware categories are already filtered out by `Project::devicesWithStencils()` —
the renderer only sees `hardware` lines. The above map is for completeness / future
sub-category lookups.)

Name-keyword secondary derivation (case-insensitive substring match against `$line['name']`,
first-match wins, evaluated in this order to avoid false matches like "Ceiling Camera Bracket"
matching "BRACKET" before "CEILING"):

| Keyword (lowercase substring) | Zone           |
|-------------------------------|----------------|
| `ceiling`                     | CEILING        |
| `paging`                      | PAGING_STATION |
| `call station`                | PAGING_STATION |
| `intercom`                    | RECEPTION      |
| `door station`                | RECEPTION      |
| `reception`                   | RECEPTION      |
| `rack` / `switch` / `dsp` / `amplifier` / `amp` / `matrix` / `processor` | RACK |
| `display` / `screen` / `monitor` / `projector` / `signage` | WALL |
| `touchpanel` / `touch panel` / `tabletop` / `table mic` / `desk mic` / `codec`  | TABLE |
| `ups` / `pdu` / `distribution` | FLOOR         |
| (nothing matches)             | OTHER          |

**Rationale:**
1. Local DB has 0 packages, so the only honest path is to derive from the canonical
   `$categoryOptions` writer (review.blade.php). That writer emits 7 high-level keys —
   not the 22 D-01 lower-level ones — so Path A would ship a map keyed on strings that
   never appear in real data and ALL hardware lines would resolve to OTHER (broken).
2. Path C (regex against `name` only) discards the category field entirely. Path B keeps
   category=hardware as the gate (correctness — non-hardware never reaches the renderer),
   and uses the name field as the secondary derivation only for hardware lines.
3. The name-keyword map mirrors the D-01 SEMANTIC intent (RACK / CEILING / WALL / TABLE
   / PAGING_STATION / RECEPTION / FLOOR / OTHER zones) but against the field where the
   information actually lives in production data — the human-typed device name string.

**Implication for Plan 02 (ZoneGrouper):** Plan 02 Task 1 reads
`config('drawings.category_to_zone')` first as the primary lookup; if the category does
not resolve to a non-OTHER zone (true for all 7 high-level keys), it falls through to
the secondary name-keyword derivation per the table above. Plan 02 implements the
keyword list as a constant on the ZoneGrouper class with first-match-wins semantics.
Engineer-typed `$line['zone']` per D-02 ALWAYS overrides both lookups (D-04 escape hatch
preserved).

## Plan 02 carry-forward instruction

Plan 02 Task 1 (ZoneGrouper construction) reads `config('drawings.category_to_zone')`
populated in this plan's Task 3. Plan 02 implements the name-keyword secondary derivation
per the rules logged above as a `protected const NAME_KEYWORD_TO_ZONE` array on
ZoneGrouper, with the resolution method shape:

```php
public function zoneFor(array $line): string
{
    // 1. Engineer override (D-02) always wins.
    if (! empty($line['zone']) && is_string($line['zone'])) {
        return $line['zone']; // raw — D-04 free-text supported
    }
    // 2. Config category map (Path B: returns OTHER for all 7 keys except hardware fall-through).
    $cat = strtolower(trim((string) ($line['category'] ?? '')));
    $byCat = config('drawings.category_to_zone.' . $cat);
    if ($byCat !== null && $byCat !== 'OTHER') {
        return $byCat;
    }
    // 3. Name-keyword secondary derivation (hardware only — others already filtered out
    //    upstream by Project::devicesWithStencils()).
    $name = strtolower((string) ($line['name'] ?? $line['model'] ?? ''));
    foreach (self::NAME_KEYWORD_TO_ZONE as $needle => $zone) {
        if (str_contains($name, $needle)) {
            return $zone;
        }
    }
    // 4. Fallback.
    return 'OTHER';
}
```

`NAME_KEYWORD_TO_ZONE` ordering MUST follow the table above (`ceiling` before generic
`rack`, etc.) to avoid false matches.
