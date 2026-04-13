---
phase: quick
plan: 260413-fjj
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Services/QuoteParserService.php
autonomous: true
requirements: []
must_haves:
  truths:
    - "Part numbers starting with digits (e.g. 920-02270-00003, 875K5AA) are extracted correctly"
    - "Pure-numeric strings (e.g. 1234) are still rejected as part numbers"
    - "All existing tests pass after the change"
  artifacts:
    - path: "app/Services/QuoteParserService.php"
      provides: "Widened part-number regex across all detection locations"
      contains: "[A-Za-z0-9][A-Za-z0-9\\-\\.]"
  key_links:
    - from: "extractEquipment() Gate 1b"
      to: "extractEquipment() Strategy 1/2/3"
      via: "Consistent regex widening"
      pattern: "\\[A-Za-z0-9\\]"
---

<objective>
Widen the part-number first-character regex from `[A-Za-z]` to `[A-Za-z0-9]` in every
location inside QuoteParserService.php where part numbers are detected or boundary-scanned.

Purpose: Valid AV part numbers such as `920-02270-00003` (Biamp EasyConnect MPX 250) and
`875K5AA` (HP/Poly TC10) start with a digit and are currently silently rejected, causing
equipment rows to be dropped from the extracted list.

Output: Updated QuoteParserService.php with 9 regex sites widened; all existing guard logic
(hasHyphen / hasDigit+hasAlpha) unchanged so pure-numeric strings remain rejected.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/PROJECT.md
@.planning/STATE.md
</context>

<tasks>

<task type="auto">
  <name>Task 1: Widen part-number first-character regex in all 9 locations</name>
  <files>app/Services/QuoteParserService.php</files>
  <action>
Make the following targeted regex changes. Change ONLY the character class for the first
character of a part-number token from `[A-Za-z]` to `[A-Za-z0-9]`. Do NOT change any
guard logic (hasHyphen, hasDigit, hasAlpha checks) or any other part of the patterns.

**Location 1 — extractOverviewSection() line ~409 (table boundary: pricing row with price):**
```php
// BEFORE:
if (preg_match('/^[A-Za-z][A-Za-z0-9\-\.]{3,29}\s+\S.+\s[\d,]+\.\d{2}/', $trimmed)) {
// AFTER:
if (preg_match('/^[A-Za-z0-9][A-Za-z0-9\-\.]{3,29}\s+\S.+\s[\d,]+\.\d{2}/', $trimmed)) {
```

**Location 2 — extractOverviewSection() line ~482 (boundary-scan fallback: pricing row with price):**
```php
// BEFORE:
if (preg_match('/^[A-Za-z][A-Za-z0-9\-\.]{3,29}\s+\S.+\s[\d,]+\.\d{2}/', $t)) {
// AFTER:
if (preg_match('/^[A-Za-z0-9][A-Za-z0-9\-\.]{3,29}\s+\S.+\s[\d,]+\.\d{2}/', $t)) {
```

**Location 3 — extractOverviewSection() line ~491 (boundary-scan fallback: bare part-number row):**
```php
// BEFORE:
'/^[A-Za-z][A-Za-z0-9\-\.]{3,29}\s+[A-Za-z].{3,}$/',
// AFTER:
'/^[A-Za-z0-9][A-Za-z0-9\-\.]{3,29}\s+[A-Za-z].{3,}$/',
```

**Location 4 — extractEquipment() Gate 1b line ~631:**
```php
// BEFORE:
if (preg_match('/^([A-Za-z][A-Za-z0-9\-\.]{3,29})\s+(.{4,})$/', $tmpDesc, $pm)) {
// AFTER:
if (preg_match('/^([A-Za-z0-9][A-Za-z0-9\-\.]{3,29})\s+(.{4,})$/', $tmpDesc, $pm)) {
```

**Location 5 — extractEquipment() Strategy 1 line ~720:**
```php
// BEFORE:
if (preg_match('/^([A-Za-z][A-Za-z0-9\-\.]{3,29})\s+(.{4,})$/', $desc, $pm)) {
// AFTER:
if (preg_match('/^([A-Za-z0-9][A-Za-z0-9\-\.]{3,29})\s+(.{4,})$/', $desc, $pm)) {
```

**Location 6 — extractEquipment() Strategy 2 line ~741:**
```php
// BEFORE:
if (preg_match('/^(.{3,}?)\s*\(([A-Za-z][A-Za-z0-9\-\.]{2,29})\)\s*$/', $desc, $pm)) {
// AFTER:
if (preg_match('/^(.{3,}?)\s*\(([A-Za-z0-9][A-Za-z0-9\-\.]{2,29})\)\s*$/', $desc, $pm)) {
```

**Location 7 — extractEquipment() Strategy 3 line ~764:**
```php
// BEFORE:
if (preg_match('/^(.{5,})\s+([A-Za-z][A-Za-z0-9\-\.]{2,29})$/', $desc, $pm)) {
// AFTER:
if (preg_match('/^(.{5,})\s+([A-Za-z0-9][A-Za-z0-9\-\.]{2,29})$/', $desc, $pm)) {
```

**Location 8 — isSolePartNumber() line ~1330:**
```php
// BEFORE:
if (! preg_match('/^([A-Za-z][A-Za-z0-9\-\.]{3,29})$/', $trimmed, $m)) {
// AFTER:
if (! preg_match('/^([A-Za-z0-9][A-Za-z0-9\-\.]{3,29})$/', $trimmed, $m)) {
```
Update the PHPDoc comment on line ~1318 from:
`- Matches [A-Za-z][A-Za-z0-9\-\.]{3,29}  (4–30 chars)`
to:
`- Matches [A-Za-z0-9][A-Za-z0-9\-\.]{3,29}  (4–30 chars)`

**Location 9 — line ~2119 (skip standalone part-token filter in tag-based path):**
```php
// BEFORE:
if (preg_match('/^[A-Za-z][A-Za-z0-9\-\.\/]{2,49}$/', $clean)) {
// AFTER:
if (preg_match('/^[A-Za-z0-9][A-Za-z0-9\-\.\/]{2,49}$/', $clean)) {
```

**Location 10 — extractPartNumFromDescription() Strategy A line ~2942:**
```php
// BEFORE:
if (preg_match('/^(.{3,}?)\s*\(([A-Za-z][A-Za-z0-9\-\.]{2,29})\)\s*$/', $desc, $m)) {
// AFTER:
if (preg_match('/^(.{3,}?)\s*\(([A-Za-z0-9][A-Za-z0-9\-\.]{2,29})\)\s*$/', $desc, $m)) {
```

**Location 11 — extractPartNumFromDescription() Strategy B line ~2947:**
```php
// BEFORE:
if (preg_match('/^(.{5,})\s+([A-Za-z][A-Za-z0-9\-\.]{2,29})$/', $desc, $m)) {
// AFTER:
if (preg_match('/^(.{5,})\s+([A-Za-z0-9][A-Za-z0-9\-\.]{2,29})$/', $desc, $m)) {
```

After making all changes, confirm no `[A-Za-z][A-Za-z0-9` pattern remains in the file
(it should all now read `[A-Za-z0-9][A-Za-z0-9`).
  </action>
  <verify>
    <automated>cd "C:\Users\sonny.tanda\Documents\1 - Laravel Projects\scm-21cav" || cd "C:\Users\sonny.tanda\Documents\1 - Claude Projects\Rams2\rams.21stcav.com" && php artisan test --filter=QuoteParser 2>&1 | tail -20</automated>
  </verify>
  <done>
- All 11 regex sites changed from `[A-Za-z]` to `[A-Za-z0-9]` as first character class
- No remaining `[A-Za-z][A-Za-z0-9` occurrences in the file
- Guard logic (hasHyphen, hasDigit, hasAlpha) untouched
- Existing QuoteParser tests pass
  </done>
</task>

<task type="auto">
  <name>Task 2: Run full test suite and verify no regressions</name>
  <files></files>
  <action>
Run the full PHPUnit test suite to confirm no regressions from the regex widening.
If any test fails, inspect whether it is a pre-existing failure or caused by this change.
Report only failures introduced by this change.
  </action>
  <verify>
    <automated>php artisan test 2>&1 | tail -30</automated>
  </verify>
  <done>
Full test suite passes (or only pre-existing failures remain — none new from this change).
  </done>
</task>

</tasks>

<threat_model>
## Trust Boundaries

| Boundary | Description |
|----------|-------------|
| PDF input → parser | Untrusted PDF text is parsed; no user-controlled regex construction |

## STRIDE Threat Register

| Threat ID | Category | Component | Disposition | Mitigation Plan |
|-----------|----------|-----------|-------------|-----------------|
| T-fjj-01 | Spoofing | Part-number extraction | accept | Regex widening does not introduce new trust boundaries; guard logic (hasHyphen/hasDigit+hasAlpha) still rejects pure-numeric and trivial tokens |
| T-fjj-02 | Denial of Service | Regex catastrophic backtracking | accept | Patterns use possessive-equivalent bounded quantifiers `{3,29}`; no unbounded `.+` in the widened character class itself |
</threat_model>

<verification>
After both tasks complete:

1. Confirm grep finds no remaining `[A-Za-z][A-Za-z0-9` in QuoteParserService.php
2. Confirm all QuoteParser-related tests pass
3. Confirm a manual spot-check: given input line `920-02270-00003 Biamp EasyConnect MPX 250`, the parser extracts part_number=`920-02270-00003`
</verification>

<success_criteria>
- Part numbers starting with digits are extracted correctly from equipment rows
- Pure-numeric strings (no hyphen, no alpha chars) remain rejected
- No test regressions
- All 11 regex sites (locations 1–11) widened consistently
</success_criteria>

<output>
After completion, create `.planning/quick/260413-fjj-fix-digit-starting-part-number-parsing-i/260413-fjj-SUMMARY.md`
</output>
