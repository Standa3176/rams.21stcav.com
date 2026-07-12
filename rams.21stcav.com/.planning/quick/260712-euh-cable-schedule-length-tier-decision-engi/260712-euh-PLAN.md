---
quick_id: 260712-euh
slug: cable-schedule-length-tier-decision-engi
date: 2026-07-12
description: >
  Cable schedule length-tier decision engine — extends the Tier 3-D
  DeviceCableRule engine (from 260711-q7q) with a `length_tiers` JSON
  column so `inferCableRun()` picks the right cable spec based on the
  row's `approx_length_m`. Signal-type keyed auto-swap: passive → active
  copper → fibre based on the survey-parsed run length. Deletes the
  hardcoded `DISTANCE_WARNING_RULES` const + `computeDistanceWarnings`
  method; all length-aware behaviour now lives in DB rows editable by
  admin. Non-breaking: byte-for-byte identical output when length is
  null (which is the case in every existing regression test).
autonomous: true
files_modified:
  - database/migrations/2026_07_12_000000_add_length_tiers_to_device_cable_rules_table.php
  - app/Models/DeviceCableRule.php
  - app/Services/CableScheduleGeneratorService.php
  - database/seeders/DeviceCableRulesSeeder.php
  - app/Http/Requests/Admin/DeviceCableRuleRequest.php
  - app/Http/Controllers/Admin/DeviceCableRuleController.php
  - resources/views/admin/device-cable-rules/_form.blade.php
  - resources/views/admin/device-cable-rules/index.blade.php
  - tests/Unit/Services/Cable/DeviceCableRuleInferenceTest.php
  - tests/Feature/Admin/DeviceCableRuleControllerTest.php
must_haves:
  truths:
    - "T1 (schema): `length_tiers` JSON column live on `device_cable_rules`; DeviceCableRule casts it to array; migration reversible; existing 15 rows unaffected on `up()` (nullable, default null)."
    - "T2 (picker): `inferCableRun()` accepts optional `?float $lengthM`; when the matched rule has a non-empty `length_tiers`, first-match ascending on `max_m` picks the tier (over-max appends `⚠⚠ Length {L}m exceeds max range…`; null length appends `⚠ Length not confirmed on survey — assuming passive tier`); `DISTANCE_WARNING_RULES` const + `computeDistanceWarnings` method DELETED; all three inferCableRun callers pass through the row length."
    - "T3 (seed): seeder writes `length_tiers` on 12 existing canonical rules (HDMI 2.0, HDBaseT, speaker, Shure MXW, DSP, VC codec, camera, control PoE, generic control, switch, patch panel, MXWAPX) AND appends 5 new rules (USB 2.0 / USB 3.0 / DisplayPort / SDI / Optical fibre) at priorities 130+; idempotent via `updateOrCreate` keyed on priority; total = 20 rows (15 + 5)."
    - "T4 (admin UI): FormRequest validates `length_tiers.*` shape (max_m required numeric > 0, cable_type required max 200, cores/to_endpoint/notes optional); Alpine.js editor in `<details>` panel exposes add / remove per-tier rows; hidden input serialises sorted-ascending JSON on submit; index view shows `N tiers` badge in cable_type column when > 0."
    - "T5 (tests): 12+ new tier-related test cases green (8 tier-selection in inference test + 4 CRUD in controller test); ALL 96+ pre-existing tests still green; existing regression cases pass unchanged because they call `buildRowsFromEquipmentLines` with `approx_length_m` = null → tier picker returns flat cable_type + null-length warning appended (regression asserts on cable_type + signal_type + cores which are unchanged; notes assertion uses `assertStringContainsString` and does not regress)."
  artifacts:
    - path: database/migrations/2026_07_12_000000_add_length_tiers_to_device_cable_rules_table.php
      provides: "length_tiers nullable JSON column + reversible down()"
    - path: app/Models/DeviceCableRule.php
      provides: "length_tiers added to fillable + cast to array"
    - path: app/Services/CableScheduleGeneratorService.php
      provides: "length-aware inferCableRun($name, ?float $lengthM); DISTANCE_WARNING_RULES + computeDistanceWarnings retired"
    - path: database/seeders/DeviceCableRulesSeeder.php
      provides: "12 existing rules gain length_tiers arrays; 5 new rules (USB2/USB3/DP/SDI/fibre) appended; 20 total"
    - path: app/Http/Requests/Admin/DeviceCableRuleRequest.php
      provides: "length_tiers.* nested validation + prepareForValidation sort-ascending"
    - path: resources/views/admin/device-cable-rules/_form.blade.php
      provides: "Alpine.js collapsible tier editor with add/remove; hidden JSON input"
    - path: resources/views/admin/device-cable-rules/index.blade.php
      provides: "'N tiers' badge next to cable_type when length_tiers has entries"
    - path: tests/Unit/Services/Cable/DeviceCableRuleInferenceTest.php
      provides: "8 new tier-selection cases (short HDMI passive, long HDMI HDBaseT, over-max HDMI fibre, Cat6 PoE tiered, speaker length pick, null length passive warning, over-max warning, empty tiers → flat fallback)"
    - path: tests/Feature/Admin/DeviceCableRuleControllerTest.php
      provides: "4 new tier-CRUD cases (store with tiers, update tiers, validation rejects zero max_m, auto-sort ascending on save)"
  key_links:
    - from: app/Services/CableScheduleGeneratorService.php
      to: DeviceCableRule::forInference()
      via: "$rule->length_tiers array walk after keyword match"
    - from: resources/views/admin/device-cable-rules/_form.blade.php
      to: app/Http/Requests/Admin/DeviceCableRuleRequest.php
      via: "hidden name=\"length_tiers\" JSON input"
    - from: database/seeders/DeviceCableRulesSeeder.php
      to: app/Models/DeviceCableRule::updateOrCreate
      via: "keyed on priority; length_tiers merged onto rule payload"
---

<objective>
Extend the Tier 3-D DeviceCableRule engine (shipped 2026-07-11 in quick
task 260711-q7q) with per-rule length-tier decision logic. Today
`inferCableRun($name)` returns one flat `cable_type` per matched rule
and the length dimension is bolted on afterwards through a hardcoded
`DISTANCE_WARNING_RULES` const that only emits warnings — never swaps
the cable. This plan makes the cable itself length-aware:

- HDMI: 0–15m passive HDMI 2.0 → 15–70m Cat6a HDBaseT → 70m+ fibre HDMI extender
- Cat6 PoE cameras: 0–90m Cat6 → 90m+ fibre + media converter recommendation
- Speaker cable: 0–30m 1.5mm 2-core → 30m+ upsize to 2.5mm 4-core star quad
- USB 3.0: 0–3m passive → 3m+ active optical USB
- Fibre + more: brand new rules for USB / DP / SDI / fibre at 130+ priorities

Purpose: retire the "warning-only" length model in favour of a data-driven
tier swap so engineers see the RIGHT cable spec in the schedule from day
one, not a passive HDMI row plus a warning telling them to "consider" an
extender. Zero AI. Deterministic first-match walk.

Output: 5 atomic commits (one per task), non-breaking, all existing
tests green, 12+ new tier-related tests green.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@CLAUDE.md
@app/Services/CableScheduleGeneratorService.php
@app/Models/DeviceCableRule.php
@database/seeders/DeviceCableRulesSeeder.php
@database/migrations/2026_07_11_000000_create_device_cable_rules_table.php
@app/Http/Requests/Admin/DeviceCableRuleRequest.php
@app/Http/Controllers/Admin/DeviceCableRuleController.php
@resources/views/admin/device-cable-rules/_form.blade.php
@resources/views/admin/device-cable-rules/index.blade.php
@tests/Unit/Services/Cable/DeviceCableRuleInferenceTest.php
@tests/Feature/Admin/DeviceCableRuleControllerTest.php

<interfaces>
<!-- Existing shape of inferCableRun() output (unchanged public shape): -->

```php
return [
    'cable_type'  => (string),   // e.g. 'HDMI 2.0' or (post-tier-pick) 'HDMI 2.0 (Fibre extender)'
    'signal_type' => (string),   // 'video' | 'audio' | 'network' | 'speaker' | 'control' | 'power' | 'usb' | 'unknown'
    'cores'       => ?string,    // e.g. '2', '3', null
    'to'          => (string),   // human-readable destination
    'notes'       => (string),   // may now carry ⚠ / ⚠⚠ warnings appended via ' | '
];
```

<!-- New length_tiers shape on DeviceCableRule (nullable, default null): -->

```php
// $rule->length_tiers  (array | null)
[
    ['max_m' => 15,  'cable_type' => 'HDMI 2.0',                 'cores' => null, 'to_endpoint' => 'AV Rack / Matrix Switcher', 'notes' => 'Passive HDMI — under 15m'],
    ['max_m' => 70,  'cable_type' => 'Cat6a (shielded) HDBaseT', 'cores' => null, 'to_endpoint' => 'HDBaseT receiver at display', 'notes' => 'HDBaseT link — 15–70m Cat6a'],
    ['max_m' => 300, 'cable_type' => 'HDMI over fibre extender', 'cores' => null, 'to_endpoint' => 'Fibre receiver at display',   'notes' => 'Fibre HDMI extender — long run'],
];
// Sorted ascending on max_m by the FormRequest before persist.
```

<!-- Existing three call sites in CableScheduleGeneratorService that call inferCableRun: -->

```
generate()                             ($cableInfo = $this->inferCableRun($equipName);)  // uses per-room $length
generateFromDevicesFlat()              ($cableInfo = $this->inferCableRun($equipName);)  // uses $lengthM param
emitDagEdges() → createDagEdge()       ($cableInfo = $this->inferCableRun($fromName);)   // uses $roomLengthM
buildSignalGraph()                     ($cableInfo = $this->inferCableRun($equipName);)  // classification only, pass null
buildRowsFromEquipmentLines()          ($cableInfo = $this->inferCableRun($equipName);)  // no survey → pass null
```
</interfaces>
</context>

<tasks>

<task type="auto">
  <name>Task 1: Add length_tiers JSON column + model cast</name>
  <files>database/migrations/2026_07_12_000000_add_length_tiers_to_device_cable_rules_table.php, app/Models/DeviceCableRule.php</files>
  <action>
Create a new migration that adds a nullable `length_tiers` JSON column to `device_cable_rules`, positioned after `notes`. The `up()` closure calls `Schema::table('device_cable_rules', fn (Blueprint $t) => $t->json('length_tiers')->nullable()->after('notes')->comment('Ordered ascending on max_m; first match wins. Nullable = flat cable_type used.'));`. The `down()` closure drops the same column via `$t->dropColumn('length_tiers')`. Docblock explains the tier picker walks ascending on `max_m`, first match returns that tier's cable_type/cores/to_endpoint/notes overriding the flat row; null / empty array means "no tier logic — use flat cable_type".

In `app/Models/DeviceCableRule.php`:
- Add `'length_tiers'` to the `$fillable` array (append after `'is_active'` — order matters for review, doesn't matter functionally).
- Add `'length_tiers' => 'array'` to the `$casts` array.
- Extend the class docblock with a "Length tiers (260712-euh)" paragraph explaining the schema shape and the picker contract.

Run `php artisan migrate` locally to apply. Then run `php -l` on both files.

DO NOT touch the seeder in this task — that's Task 3. DO NOT touch inferCableRun — that's Task 2. This task is schema + cast only. Existing 15 rows keep `length_tiers = null` → picker behaviour identical until Task 3 seeds real tiers.
  </action>
  <verify>
    <automated>php -l database/migrations/2026_07_12_000000_add_length_tiers_to_device_cable_rules_table.php && php -l app/Models/DeviceCableRule.php && php artisan migrate && php artisan migrate:rollback && php artisan migrate</automated>
  </verify>
  <done>
Migration up + down both succeed. `Schema::hasColumn('device_cable_rules', 'length_tiers')` returns true after `up()`. `DeviceCableRule::first()->length_tiers` returns null (cast to array works — never a string). Commit: `feat(cable-rules): add length_tiers JSON column + model cast (260712-euh T1)`.
  </done>
</task>

<task type="auto">
  <name>Task 2: Length-aware inferCableRun + retire DISTANCE_WARNING_RULES</name>
  <files>app/Services/CableScheduleGeneratorService.php</files>
  <action>
Refactor `inferCableRun` and DELETE the legacy distance-warning system:

1. **Change signature:** `private function inferCableRun(string $equipName, ?float $lengthM = null): array`. Default null keeps backward-compat for any future caller that forgets it.

2. **Add tier picker after the keyword match succeeds** — inside the `foreach (DeviceCableRule::forInference() as $rule)` loop, BEFORE the existing `return [...]`, check `$rule->length_tiers`:
   - If null OR empty array → return the flat row exactly as today (unchanged behaviour).
   - Else invoke a new private helper `pickTier(array $tiers, ?float $lengthM, array $flatRow): array` that walks `$tiers` (already sorted ascending on max_m — trust the FormRequest; do NOT re-sort at read time). Return shape is identical to the current flat return.

3. **Implement `pickTier`:**
   - When `$lengthM` is null → return the first tier (safest passive) with warning `⚠ Length not confirmed on survey — assuming passive tier` appended to notes via ` | `.
   - Else walk tiers ascending: first tier whose `max_m` is null OR `$lengthM <= $tier['max_m']` wins.
   - If no tier matches (length exceeds every tier's `max_m`), return the LAST tier with warning `⚠⚠ Length {L}m exceeds max range for this cable type — consider signal repeater / regen` appended to notes via ` | `. `{L}` is `rtrim(rtrim(number_format($lengthM, 1, '.', ''), '0'), '.')` (numeric length rounded to 1 dp, trailing zero stripped, e.g. 90.0 → '90', 92.5 → '92.5').
   - Warning join rule: `$notes = $tier['notes'] ?? ''; $notes = $notes === '' ? $warning : $notes . ' | ' . $warning;` — matches the ` | ` separator convention used throughout the service.
   - Fallback fields: each tier ONLY overrides `cable_type`, `cores`, `to_endpoint`, `notes`. It does NOT touch `signal_type` — that stays from the parent flat row so downstream port FK resolution + XLSX colouring stays consistent.

4. **Update the three call sites** to pass length through:
   - `generate()` — inside the `foreach ($classified['install_hardware'] as $item)` loop, change `$cableInfo = $this->inferCableRun($equipName);` → `$cableInfo = $this->inferCableRun($equipName, $length);` (`$length` is already resolved from the room's length map earlier in the same iteration).
   - `generateFromDevicesFlat()` — change `$cableInfo = $this->inferCableRun($equipName);` → `$cableInfo = $this->inferCableRun($equipName, $lengthM);` (`$lengthM` is a parameter to the method).
   - `createDagEdge()` — change `$cableInfo = $this->inferCableRun($fromName);` → `$cableInfo = $this->inferCableRun($fromName, $roomLengthM);` (`$roomLengthM` is a parameter to the method).
   - `buildSignalGraph()` (called twice — once for local devices, once for centrals) — pass null explicitly: `$cableInfo = $this->inferCableRun($equipName, null);`. This function only uses `$cableInfo['signal_type']` for classification; length doesn't affect signal_type so null is correct and preserves behaviour.
   - `buildRowsFromEquipmentLines()` — pass null explicitly: `$cableInfo = $this->inferCableRun($equipName, null);`. Standalone quote flow has no survey.

5. **DELETE the legacy distance-warning system:**
   - Delete the entire `DISTANCE_WARNING_RULES` const (lines ~107–132).
   - Delete the `computeDistanceWarnings` method (lines ~1376–1397).
   - Delete every call to `computeDistanceWarnings(...)` at the three call sites: `generate()`, `generateFromDevicesFlat()`, `createDagEdge()`. Delete the `if (! empty($warnings)) { $notes .= ' | ' . implode(' | ', $warnings); }` block that follows each call.
   - Add a comment block where `DISTANCE_WARNING_RULES` lived: `// ── T1-E: retired 2026-07-12 (260712-euh) ─────────────────────────────────\n    //\n    // The hardcoded DISTANCE_WARNING_RULES + computeDistanceWarnings method\n    // used to append '⚠' warnings after inferCableRun() picked a flat cable_type.\n    // Length-aware behaviour is now data-driven via DeviceCableRule::length_tiers —\n    // the tier picker in inferCableRun() both swaps the cable and appends the\n    // appropriate '⚠' / '⚠⚠' warning as part of its return value. See docblock.`

6. **Update `inferCableRun` docblock** — replace the reference to the "13-branch cascade refactor" with a paragraph explaining the tier picker: matched rule with length_tiers walks ascending; length null → passive tier + warning; over-max → last tier + escalation warning; empty/null tiers → flat cable_type unchanged.

`php -l app/Services/CableScheduleGeneratorService.php` must pass.

DO NOT rename `inferCableRun` (public shape stable). DO NOT change the `foreach (DeviceCableRule::forInference() ...)` walk order. DO NOT touch `signal_type` inside `pickTier` — the DAG walker relies on the flat rule's signal_type. DO NOT touch `applyQuotedCableOverride` (quoted-cable overrides run AFTER the tier picker and still win on cable_type — that's correct: an explicitly quoted cable trumps an inferred tier).
  </action>
  <verify>
    <automated>php -l app/Services/CableScheduleGeneratorService.php && php artisan test --filter=DeviceCableRuleInferenceTest && php artisan test --filter=CableScheduleGeneratorServiceTest && php artisan test --filter=CableScheduleDagGenerationTest</automated>
  </verify>
  <done>
File compiles. `DISTANCE_WARNING_RULES` + `computeDistanceWarnings` gone. Retirement comment present. All three call sites pass length. Existing regression tests pass (they use `buildRowsFromEquipmentLines` with null length or run through the seeder BEFORE Task 3 adds tiers, so the tier picker returns the flat cable_type). Commit: `feat(cable-generator): length-aware inferCableRun + retire DISTANCE_WARNING_RULES (260712-euh T2)`.
  </done>
</task>

<task type="auto">
  <name>Task 3: Seed length_tiers on 12 existing rules + append 5 new rules</name>
  <files>database/seeders/DeviceCableRulesSeeder.php</files>
  <action>
Extend the seeder to add `length_tiers` arrays to the 12 canonical rules that benefit from tier logic AND append 5 new rules for USB/DP/SDI/fibre. Total row count after seed: 20.

**Update 12 existing rules with `length_tiers`:**

- **Priority 10 (display / HDMI):** 3 tiers — 15m passive HDMI 2.0 → 70m Cat6a HDBaseT → 300m HDMI over fibre extender. `notes` per tier: 'Passive HDMI — under 15m' / 'HDBaseT link — 15–70m Cat6a shielded' / 'Fibre HDMI extender — long run'. `to_endpoint` progression: 'AV Rack / Matrix Switcher' → 'HDBaseT receiver at display' → 'Fibre receiver at display'.
- **Priority 20 (HDBaseT / extender / csc):** 2 tiers — 100m Cat6a (shielded) → 300m HDBaseT over fibre. `notes`: 'HDBaseT max 100m Cat6a' / 'HDBaseT-over-fibre extender'.
- **Priority 30 (speaker / pendant):** 2 tiers — 30m '2-core speaker cable (1.5mm LSZH)' → 100m '4-core star quad speaker cable (2.5mm LSZH)'. `cores`: '2' / '4'. `notes`: 'Speaker level — under 30m' / 'Long speaker run — star quad, thicker gauge'.
- **Priority 40 (Shure MXW):** 2 tiers — 90m 'Cat6 (Shure network)' → 300m 'Fibre + Shure media converter'. `notes`: 'Shure MXW Cat6 up to 90m' / 'Long Shure MXW run — fibre + media converter'.
- **Priority 50 (DSP / Q-Sys / biamp):** 2 tiers — 90m 'Cat6 (Dante/AES67)' → 300m 'Fibre + Dante media converter'. `notes`: 'Dante audio over Cat6' / 'Long Dante run — fibre + Dante media converter'.
- **Priority 70 (VC codec):** 2 tiers — 90m 'Cat6 (PoE)' → 300m 'Fibre + PoE media converter'. `notes`: 'VC codec Cat6 PoE' / 'Long codec run — fibre + PoE media converter'.
- **Priority 80 (camera / PTZ):** 2 tiers — 90m 'Cat6 (PoE)' → 300m 'Fibre + PoE media converter'. `notes`: 'Camera Cat6 PoE' / 'Long camera run — fibre + PoE media converter'.
- **Priority 90 (touch panel / keypad):** 2 tiers — 90m 'Cat6 (PoE)' → 200m 'Fibre + PoE media converter'. `notes`: 'Control PoE Cat6' / 'Long control run — fibre + PoE media converter'.
- **Priority 100 (generic control / crestron / extron):** 2 tiers — 100m 'Cat6' → 300m 'Fibre + media converter'. `notes`: 'Control Cat6' / 'Long control run — fibre + media converter'.
- **Priority 110 (switch / netgear / cisco switch):** 2 tiers — 100m 'Cat6' → 500m 'Single-mode fibre uplink'. `notes`: 'Uplink Cat6' / 'Long uplink — SMF fibre'.
- **Priority 120 (patch panel / keystone):** 2 tiers — 100m 'Cat6' → 500m 'Single-mode fibre patch'. `notes`: 'Cat6 patch' / 'Long patch — SMF fibre'.
- **Priority 130 (MXWAPX / access point / wap):** 2 tiers — 90m 'Cat6 (PoE)' → 300m 'Fibre + PoE media converter'. `notes`: 'WAP Cat6 PoE' / 'Long WAP run — fibre + PoE media converter'.

For each: preserve the existing flat `cable_type` / `signal_type` / `cores` / `to_endpoint` / `notes` fields UNCHANGED. Only ADD `'length_tiers' => [ ... ]`. This is critical for the byte-for-byte regression test — when the test calls with null length OR the tier picker returns tier 1, the outputs must match the pre-tier-refactor strings.

**Leave 3 rules WITHOUT length_tiers** (they don't benefit from tier logic — signal type is already length-invariant or the swap doesn't make engineering sense):
- Priority 41 (generic microphone / mic / lavalier XLR) — analogue XLR is fine to 100m+, no meaningful swap.
- Priority 60 (Dante amp / lea) — same tier logic as priority 50, but keep flat for now to prove null-tier fallthrough still works.
- Priority 61 (generic amplifier — analog multicore) — analogue speaker-level runs don't tier-swap.
Set `'length_tiers' => null` explicitly (or omit — `updateOrCreate` handles both since column is nullable; PREFER explicit `null` for review clarity).

**Append 5 NEW rules at priorities 140+:**

- **Priority 140 — USB 2.0:** keywords `['usb 2.0', 'usb2', 'usb 2']`. Flat `cable_type` = 'USB 2.0'. `signal_type` = 'usb'. `cores` = null. `to_endpoint` = 'USB host'. `notes` = 'USB 2.0 — 5m passive max'. `length_tiers`: 5m 'USB 2.0' → 20m 'USB 2.0 with active repeater' → 50m 'USB over fibre extender'.
- **Priority 141 — USB 3.0:** keywords `['usb 3.0', 'usb3', 'usb-c', 'usb 3']`. Flat `cable_type` = 'USB 3.0'. `signal_type` = 'usb'. `cores` = null. `to_endpoint` = 'USB host'. `notes` = 'USB 3.0 — 3m passive max'. `length_tiers`: 3m 'USB 3.0' → 15m 'Active optical USB 3.0' → 50m 'USB 3.0 over fibre extender'.
- **Priority 142 — DisplayPort:** keywords `['displayport', 'dp ', 'dp1.4', 'dp 1.4', 'dp2.1']`. Flat `cable_type` = 'DisplayPort 1.4'. `signal_type` = 'video'. `cores` = null. `to_endpoint` = 'DP host'. `notes` = 'DisplayPort — 2m passive max at 4K60'. `length_tiers`: 2m 'DisplayPort 1.4' → 15m 'Active DisplayPort optical' → 100m 'DisplayPort over fibre extender'.
- **Priority 143 — SDI:** keywords `['sdi', '3g-sdi', '12g-sdi', 'bnc']`. Flat `cable_type` = '3G-SDI coax'. `signal_type` = 'video'. `cores` = null. `to_endpoint` = 'SDI monitor / router'. `notes` = '3G-SDI over coax'. `length_tiers`: 100m '3G-SDI coax' → 60m '12G-SDI coax' → 500m 'SDI over fibre extender'. (Note: 12G tier max_m is intentionally SMALLER — engineers upgrade to 12G BELOW 60m for higher bandwidth, not above; ordering ascending by max_m still works: tier 1 (100m) matches 12G at ≤60m never fires because tier 0 (100m/3G) triggers first at any length ≤ 100m. For SDI, keep it simple: two tiers only — 100m coax → 500m fibre. Skip the 12G tier for now. Document the deferral in the seeder inline comment.)
- **Priority 144 — Optical fibre:** keywords `['fibre', 'fiber', 'om3', 'om4', 'os2', 'sfp', 'lc-lc', 'sc-sc']`. Flat `cable_type` = 'OM4 multimode fibre'. `signal_type` = 'network'. `cores` = null. `to_endpoint` = 'Fibre patch panel'. `notes` = 'Optical fibre run'. `length_tiers`: 550m 'OM4 multimode fibre' → 40000m 'OS2 single-mode fibre'. (40km — realistic max for SMF without amplification; still finite so the over-max warning could fire on absurdly long runs.)

**Idempotency:** every rule is written via `updateOrCreate(['priority' => $rule['priority']], $rule + ['is_active' => true])`. Re-running the seeder must not create duplicates — the existing test `test_seeder_produces_expected_row_count_and_is_idempotent` will need to update its expectation from `15` to `20` in Task 5.

**Docblock update:** amend the class docblock to reference 260712-euh and note the 5 new rules + 12 tier updates. Keep the DO NOT PARAPHRASE warning — the regression test still asserts byte-for-byte on the flat cable_type strings.

`php -l database/seeders/DeviceCableRulesSeeder.php` must pass.
  </action>
  <verify>
    <automated>php -l database/seeders/DeviceCableRulesSeeder.php && php artisan migrate:fresh --seed --seeder=Database\\Seeders\\DeviceCableRulesSeeder && php artisan tinker --execute="echo App\\Models\\DeviceCableRule::count();"</automated>
  </verify>
  <done>
Seeder runs clean. 20 rows total. Rules 10/20/30/40/50/70/80/90/100/110/120/130 have non-null `length_tiers` with 2–3 entries each. Rules 41/60/61 have null `length_tiers`. New rules 140/141/142/143/144 present with tiers. Re-running the seeder produces zero net change. Commit: `feat(cable-rules): seed length_tiers + add USB/DP/SDI/fibre rules (260712-euh T3)`.
  </done>
</task>

<task type="auto">
  <name>Task 4: Admin UI — Alpine tier editor + FormRequest validation + index badge</name>
  <files>app/Http/Requests/Admin/DeviceCableRuleRequest.php, resources/views/admin/device-cable-rules/_form.blade.php, resources/views/admin/device-cable-rules/index.blade.php</files>
  <action>
**FormRequest (`DeviceCableRuleRequest.php`):**

- Add these rules to `rules()`:
  - `'length_tiers'          => ['nullable', 'array']`
  - `'length_tiers.*.max_m'       => ['required_with:length_tiers', 'numeric', 'gt:0']`
  - `'length_tiers.*.cable_type'  => ['required_with:length_tiers', 'string', 'max:200']`
  - `'length_tiers.*.cores'       => ['nullable', 'string', 'max:50']`
  - `'length_tiers.*.to_endpoint' => ['nullable', 'string', 'max:200']`
  - `'length_tiers.*.notes'       => ['nullable', 'string', 'max:500']`
- Extend `prepareForValidation()` to:
  - Decode the hidden `length_tiers` JSON string (posted from the Alpine.js editor) via `json_decode($this->input('length_tiers'), true)`. Guard against non-string, non-array, malformed JSON — set to `null` if invalid.
  - Sort the decoded array ascending on `max_m` (integer/float compare) BEFORE `merge()`. Use `usort($tiers, fn ($a, $b) => ($a['max_m'] ?? 0) <=> ($b['max_m'] ?? 0));`.
  - `$this->merge(['length_tiers' => $tiers])`. If the sorted array is empty, merge `null` explicitly so the model stores null not `[]`.
- Update the class docblock: note that `length_tiers` posts as a hidden JSON string and is decoded + sorted here before validation runs.

**Form partial (`_form.blade.php`):**

Add a new `<div class="section-block">` after the Cable Output block, wrapping the whole editor in `<details>` so it's collapsed by default. Structure:

```blade
<div class="section-block" style="margin-bottom:1.25rem;">
    <details {{ ! empty($rule->length_tiers) ? 'open' : '' }}
             x-data='{ tiers: @json($rule->length_tiers ?? []) }'>
        <summary style="cursor:pointer;font-weight:600;">
            Length Tiers (<span x-text="tiers.length"></span>)
        </summary>
        <p style="font-size:.825rem;color:var(--text-muted);margin:.75rem 0;">
            Optional. When set, the inference engine walks tiers ascending on <code>max_m</code> and picks the first tier whose <code>max_m</code> ≥ the row's <code>approx_length_m</code>. Over-max lengths trigger the escalation warning. Null / empty = use the flat cable_type above.
        </p>
        <template x-for="(tier, i) in tiers" :key="i">
            <div class="form-grid-2" style="border:1px solid var(--border);border-radius:6px;padding:.75rem;margin-bottom:.5rem;">
                <div class="form-group"><label class="form-label">max_m *</label>
                    <input type="number" step="0.1" min="0.1" class="form-control" x-model.number="tier.max_m" required>
                </div>
                <div class="form-group"><label class="form-label">cable_type *</label>
                    <input type="text" class="form-control" x-model="tier.cable_type" maxlength="200" required>
                </div>
                <div class="form-group"><label class="form-label">cores</label>
                    <input type="text" class="form-control" x-model="tier.cores" maxlength="50">
                </div>
                <div class="form-group"><label class="form-label">to_endpoint</label>
                    <input type="text" class="form-control" x-model="tier.to_endpoint" maxlength="200">
                </div>
                <div class="form-group" style="grid-column:span 2;"><label class="form-label">notes</label>
                    <input type="text" class="form-control" x-model="tier.notes" maxlength="500">
                </div>
                <div style="grid-column:span 2;text-align:right;">
                    <button type="button" class="btn btn-danger-outline btn-sm" @click="tiers.splice(i,1)">Remove tier</button>
                </div>
            </div>
        </template>
        <button type="button" class="btn btn-outline btn-sm" @click="tiers.push({max_m: null, cable_type: '', cores: '', to_endpoint: '', notes: ''})">+ Add tier</button>
        <input type="hidden" name="length_tiers" :value="JSON.stringify(tiers.slice().sort((a,b) => (parseFloat(a.max_m)||0) - (parseFloat(b.max_m)||0)))">
    </details>
</div>
```

Match the existing partial's Blade style (`section-block`, `form-group`, `form-label`, `form-control`, `form-grid-2`, `.btn`). The whole editor uses vanilla Alpine.js — no new imports needed; Alpine is already booted in `resources/js/app.js` (used by ⌘K palette + confirm dialog + partition drawer).

**Index view (`index.blade.php`):**

In the `<td>` that renders `$rule->cable_type`, append a small pill next to the cable_type when `length_tiers` has entries:

```blade
@if (! empty($rule->length_tiers))
    <span style="display:inline-block;margin-left:6px;padding:1px 8px;border-radius:999px;font-size:10px;font-weight:600;background:var(--accent-soft, var(--surface-soft));color:var(--accent, var(--text-muted));border:1px solid var(--border);">
        {{ count($rule->length_tiers) }} tier{{ count($rule->length_tiers) === 1 ? '' : 's' }}
    </span>
@endif
```

Place it INSIDE the existing `<div style="color:var(--ink-900);font-weight:500;">{{ $rule->cable_type }}</div>` — right after `{{ $rule->cable_type }}` and before the closing `</div>`.

`php -l` on the FormRequest and both Blade files (Blade files: syntax is validated by Laravel compilation, but you can `php artisan view:clear && php artisan view:cache` to catch obvious errors — do NOT commit the cached views).
  </action>
  <verify>
    <automated>php -l app/Http/Requests/Admin/DeviceCableRuleRequest.php && php artisan view:clear && php artisan test --filter=DeviceCableRuleControllerTest</automated>
  </verify>
  <done>
FormRequest validates + auto-sorts. Blade partial has the `<details>` panel + hidden JSON input. Index view shows `N tiers` badge. Existing 6 CRUD tests still pass (nothing removed). Commit: `feat(cable-rules-admin): Alpine tier editor + validation + index badge (260712-euh T4)`.
  </done>
</task>

<task type="auto">
  <name>Task 5: Test coverage — 8 tier-selection + 4 CRUD cases + regression fixes</name>
  <files>tests/Unit/Services/Cable/DeviceCableRuleInferenceTest.php, tests/Feature/Admin/DeviceCableRuleControllerTest.php</files>
  <action>
**Update the existing seeder-count assertion:**

In `DeviceCableRuleInferenceTest::test_seeder_produces_expected_row_count_and_is_idempotent`, change the row count from `15` to `20` and update the docblock to `15 canonical rules + 5 new tier-aware rules (USB2, USB3, DP, SDI, fibre) = 20`.

**Add 8 new tier-selection cases to `DeviceCableRuleInferenceTest`:**

Because the existing `firstRow($name)` helper calls `buildRowsFromEquipmentLines` (which passes null length), it's not enough for the length-based cases. Add a NEW helper:

```php
private function inferDirect(string $name, ?float $lengthM): array
{
    // Reflection to call the private inferCableRun($name, $lengthM).
    $svc = $this->make();
    $ref = new \ReflectionMethod($svc, 'inferCableRun');
    $ref->setAccessible(true);
    return $ref->invoke($svc, $name, $lengthM);
}
```

Cases:

1. `test_hdmi_display_short_run_returns_passive_hdmi_tier` — call `inferDirect('Samsung QM85 Display', 10.0)`. Assert cable_type = 'HDMI 2.0'. Notes contain 'Passive HDMI'. Length within tier 1.
2. `test_hdmi_display_medium_run_returns_hdbaset_tier` — `inferDirect('Samsung QM85 Display', 40.0)`. Assert cable_type contains 'HDBaseT' or 'Cat6a'. Length in tier 2.
3. `test_hdmi_display_long_run_returns_fibre_tier` — `inferDirect('Samsung QM85 Display', 150.0)`. Assert cable_type contains 'fibre'. Length in tier 3.
4. `test_hdmi_display_over_max_appends_escalation_warning` — `inferDirect('Samsung QM85 Display', 400.0)`. Assert notes contain '⚠⚠' and 'exceeds max range' and '400m' (or '400.0m' after rounding — assert '400').
5. `test_hdmi_display_null_length_returns_passive_tier_with_warning` — `inferDirect('Samsung QM85 Display', null)`. Assert cable_type = 'HDMI 2.0' (tier 1). Notes contain '⚠' AND 'Length not confirmed'.
6. `test_ptz_camera_short_run_returns_cat6_poe` — `inferDirect('AVer PTZ Camera', 30.0)`. Assert cable_type = 'Cat6 (PoE)'.
7. `test_ptz_camera_long_run_swaps_to_fibre_poe` — `inferDirect('AVer PTZ Camera', 150.0)`. Assert cable_type contains 'fibre' or 'Fibre' (case-insensitive).
8. `test_generic_microphone_length_ignored_because_no_tiers` — `inferDirect('Sennheiser Microphone', 50.0)`. Assert cable_type = 'XLR' (rule 41 has null length_tiers → flat cable_type used, no length warning appended).

**Add 4 new CRUD cases to `DeviceCableRuleControllerTest`:**

1. `test_admin_can_store_a_rule_with_length_tiers` — POST with `length_tiers` as JSON-encoded string of 2 tiers (unsorted on max_m: e.g. `[{max_m: 70, ...}, {max_m: 15, ...}]`). Assert rule persists. Assert `$rule->length_tiers[0]['max_m'] === 15` (auto-sorted ascending).
2. `test_admin_can_update_length_tiers_on_existing_rule` — start from the seeded HDMI rule (priority 10). PUT with 4 tiers. Assert count 4. Assert ordered ascending.
3. `test_store_rejects_length_tier_with_zero_max_m` — POST with `length_tiers` containing `[{max_m: 0, cable_type: 'X'}]`. Assert 302 redirect back with a validation error on `length_tiers.0.max_m`.
4. `test_admin_can_clear_length_tiers_by_posting_empty_array` — start from HDMI rule (priority 10, has 3 tiers from seeder). PUT with `length_tiers: '[]'` (empty JSON array). Assert rule's `length_tiers` is null (empty array collapses to null via the FormRequest merge).

**Existing regression tests unchanged:** the byte-for-byte `DeviceCableRuleInferenceTest` cases (7 canonical assertions) all use `firstRow($name)` which goes through `buildRowsFromEquipmentLines(..., null)` — Task 2 pipes null through explicitly. For HDMI/Cat6/etc rules with tiers, null length → tier 1 (the safest passive tier which matches the pre-refactor flat cable_type). So `test_samsung_qm85_display_returns_hdmi_video` still sees 'HDMI 2.0' as cable_type. `test_cisco_room_kit_returns_cat6_poe_video`, `test_ptz_camera_returns_cat6_poe_video`, `test_qsys_core_returns_dante_aes67`, `test_ceiling_speaker_returns_2core_speaker_cable`, `test_netgear_switch_returns_cat6_network` all pass — tier 1 = existing flat cable_type.

One assertion to verify manually: `test_cisco_room_kit_returns_cat6_poe_video` uses `assertStringContainsString('VC codec', $row['notes'])`. Under the tier picker, notes at null length become 'VC codec Cat6 PoE | ⚠ Length not confirmed on survey — assuming passive tier'. `assertStringContainsString('VC codec', ...)` still passes because 'VC codec' is a substring. Same for any other `assertStringContainsString` cases — the warning is APPENDED via ` | ` separator, not replacing.

The other existing suites (`CableScheduleGeneratorServiceTest`, `CableScheduleDagGenerationTest`) run either without seeder loaded (rules table empty → falls through to TBC placeholder, unaffected) or with the seeder loaded but no length map available (survey rooms not populated → length is null everywhere → tier 1 wins → same output). If any assertion tightly compares `notes === '<exact string>'`, it may fail because of the null-length warning suffix. Scan those tests: prefer `assertStringContainsString` — if a test uses strict equality on notes, relax it to `assertStringContainsString` in the SAME commit as this task (mention in the commit body).

Run the full suite. Target: 96 pre-existing green + 12 new = 108 green.

`php -l` on both test files.
  </action>
  <verify>
    <automated>php -l tests/Unit/Services/Cable/DeviceCableRuleInferenceTest.php && php -l tests/Feature/Admin/DeviceCableRuleControllerTest.php && php artisan test</automated>
  </verify>
  <done>
All tests green. 108+ green (96 pre-existing + 12 new). Regression proven. Commit: `test(cable-rules): 12 tier-selection + CRUD cases + count bump to 20 (260712-euh T5)`.
  </done>
</task>

</tasks>

<verification>
After all 5 commits:
- `php -l` on every touched .php file → no errors.
- `php artisan migrate:fresh --seed` succeeds; 20 device_cable_rules rows present.
- `php artisan test` → all suites green (target: 108+).
- Manual admin flow: log in as admin, visit `/admin/device-cable-rules`, edit a seeded HDMI rule, verify the collapsible `Length Tiers (3)` section shows 3 pre-populated tiers, add a 4th tier out of order (e.g. max_m=25 between 15 and 70), save, verify it lands sorted ascending in DB.
- Manual regeneration flow: pick any project with a site survey, regenerate the cable schedule, verify:
  1. A short room (length parsed < 15m) with an HDMI display gets 'HDMI 2.0' cable_type and no over-max warning.
  2. A long room (length parsed > 70m) with an HDMI display gets 'Cat6a (shielded) HDBaseT' or 'HDMI over fibre extender' cable_type as appropriate.
  3. A room with no parsed length gets tier-1 cable_type and the `⚠ Length not confirmed on survey — assuming passive tier` notes suffix.
</verification>

<success_criteria>
1. Migration + model cast in place (Task 1).
2. Length-aware inferCableRun; DISTANCE_WARNING_RULES retired (Task 2).
3. 20 seeded rules total; 12 with tiers, 5 new (USB2/USB3/DP/SDI/fibre); idempotent (Task 3).
4. Admin UI editor + validation + index badge (Task 4).
5. 108+ tests green; regression preserved (Task 5).
6. 5 atomic commits landed on `feat/worksheet-classifier-universal` in strict Task 1 → Task 5 order.
7. Live deploy: run `git pull` on the RAMS box, `php artisan migrate --force`, `php artisan db:seed --class=Database\\Seeders\\DeviceCableRulesSeeder --force`, `php artisan cache:clear`, verify one live schedule generation.
</success_criteria>

<output>
Create `.planning/quick/260712-euh-cable-schedule-length-tier-decision-engi/260712-euh-SUMMARY.md` after Task 5.
</output>

## Deferred / Next

Items scoped OUT of this task, to revisit in future quick tasks:

- **Signal-integrity intelligence for AV over IP** — bandwidth-aware tier selection for NDI 5 (150 Mbps HX2 vs 200 Mbps HX3 vs full-bandwidth SHX vs uncompressed) and Dante channel-count vs. network throughput ceiling. Would require adding a `bandwidth_mbps` or `channel_capacity` column to length_tiers rows and a project-level "backbone capacity" input.
- **Per-manufacturer rule packs** — Shure MXW (already partially covered), Cisco Room Kit (already), QSC Q-Sys Core / Peripheral, Extron NAV series, Kramer switching. Ship as one seeder-batch per manufacturer at priorities 200+ so admin edits don't clash with the canonical set.
- **"Test-this-name" preview endpoint on the rules admin page** — paste an equipment name + optional length → see which rule fires, which tier is picked, and the notes suffix that would land on the schedule. Useful for troubleshooting keyword conflicts before saving a new rule. Route: `POST /admin/device-cable-rules/preview` returning JSON `{rule_id, tier_index, cable_type, notes, warnings}`.
