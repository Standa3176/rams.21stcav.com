---
phase: 29-cdm-duty-holder-emergency-arrangements
reviewed: 2026-09-11T00:00:00Z
depth: standard
files_reviewed: 18
files_reviewed_list:
  - app/Services/Rams/SiteEmergencyResolver.php
  - app/Services/Rams/RamsComplianceUpgradeService.php
  - app/Services/Rams/RamsDisplayPatchService.php
  - app/Services/DocxBuilderService.php
  - app/Support/Rams/SectionComposers/EmergencyComposer.php
  - app/Support/Rams/Sections/EmergencySectionDto.php
  - config/rams_tier1.php
  - database/migrations/2026_09_11_180000_backfill_cdm_duty_holder_placeholder.php
  - resources/views/pdf/rams.blade.php
  - resources/views/pdf/rams-v2.blade.php
  - tests/Unit/Services/Rams/SiteEmergencyResolverTest.php
  - tests/Unit/Services/Rams/CdmDutyHolderWordingTest.php
  - tests/Unit/Services/Rams/CdmEmergencyGateTest.php
  - tests/Unit/Support/Rams/EmergencyComposerSiteEmergencyResolvedTest.php
  - tests/Feature/Rams/BackfillCdmDutyHolderMigrationTest.php
  - tests/Feature/Rams/CdmEmergencyDualPathGateTest.php
  - tests/Feature/Rams/SiteEmergencyRenderSitesRegressionTest.php
  - tests/Feature/Rams/PatchRamsForDisplayTest.php
findings:
  critical: 2
  warning: 2
  info: 0
  total: 4
status: issues_found
---

# Phase 29: Code Review Report — CDM Duty-Holder / Emergency Arrangements

**Reviewed:** 2026-09-11
**Depth:** standard
**Files Reviewed:** 18
**Status:** issues_found

## Summary

This phase adds `SiteEmergencyResolver` (GATE-12/RULE-08) and restates the CDM 2015
duty-holder wording (GATE-11/RULE-07), plus a production backfill migration for
already-persisted `'[To be confirmed]'` placeholders. The legal wording itself is
correctly hedged (no unequivocal "sole contractor" claim, Regulation 15 not 4/5,
notification correctly attributed to the Client) and is well covered by unit tests.

Two BLOCKER-level defects were found, both directly in the territory the domain
context flagged as high-risk:

1. `SiteEmergencyResolver::classify()` does **not** actually pass the sanctioned
   hold-point line clean if that exact string ever ends up in the raw
   `nearest_hospital` field — contradicting the class's own documented guarantee,
   and creating a false-positive that would fire the moment `RAMS_CDM_AE_GATE` is
   armed (the deliberate, planned next deploy step).
2. The CDM backfill migration's `reviewed_data['cdm']` substring guard is loose
   enough to have silently overwritten a genuine engineer-typed note on production
   rows during its already-executed run, with no way to detect or reverse it
   (`down()` is a documented no-op).

Two WARNING-level robustness gaps were also found in the "no render site
re-derives the A&E decision independently" design goal: the legacy `pdf.rams`
blade is not actually self-sufficient (unlike `pdf.rams-v2`/`EmergencyComposer`),
and `DocxBuilderService::buildCdmSection()`'s "defence-in-depth" fallback does not
catch the one value it needs to catch.

## Critical Issues

### CR-01: GATE-12's classify() false-positives on its own sanctioned hold-point output

**File:** `app/Services/Rams/SiteEmergencyResolver.php:99-137`

**Issue:** The class docblock (lines 36-38) and `classify()`'s own docblock
(lines 90-93) both assert that the D-05 hold-point line "passes GATE-12's
plausibility check clean... it is the sanctioned hold-point output, not a
defect." That claim is only true when `nearest_hospital` is **blank**
(`$name === ''` short-circuits at line 108). It is **false** when
`nearest_hospital` literally contains the hold-point sentence itself:

```php
SiteEmergencyResolver::classify([
    'nearest_hospital' => 'Nearest A&E — to be confirmed at induction (must be a 24/7 Emergency Department)',
    'hospital_address' => '',
]);
// => 'missing_address_or_postcode' — NOT null.
```

Trace: `$name` is non-empty, so the early-return at line 108-110 is skipped.
`BANNED_STRING` ('to be identified at site induction') does not match. None of
`URGENT_CARE_KEYWORDS` match. The `/\butc\b/i` check does not match. `$address`
is empty (as it would be for a genuine hold-point value) → line 132-134 returns
`'missing_address_or_postcode'`.

This is exactly the false-positive class the domain context calls out: a false
positive here "would silently overwrite an engineer's deliberate wording on a
live safety document" once `RAMS_CDM_AE_GATE` is armed — and arming that flag is
the explicitly planned next step per `config/rams_tier1.php:119-131`. The
hold-point sentence is displayed verbatim on every generated RAMS PDF/DOCX
(`rams.blade.php:1983`, `rams-v2.blade.php:2066`, welfare bullet in
`DocxBuilderService.php`), so a PM copy-pasting that visible text into the
`nearest_hospital` form field (e.g. believing they are "confirming" the
placeholder, or restoring it after an accidental clear) is a realistic
real-world trigger — not a purely theoretical one.

No test exercises this path: `SiteEmergencyResolverTest::test_classify_passes_hold_point_clean`
(line 57-63) only tests `nearest_hospital => ''`, never the literal HOLD_POINT
string.

**Fix:** Special-case the exact hold-point string (or, more robustly, route
`classify()` through `resolve()` for the empty-vs-hold-point decision) so the
sanctioned output can never trip the gate it is supposed to be exempt from:

```php
public static function classify(array $siteEmergency): ?string
{
    $rawName = (string) ($siteEmergency['nearest_hospital'] ?? '');
    $name = trim($rawName);

    if ($name === '' || $name === self::HOLD_POINT) {
        return null;
    }
    // ... rest unchanged
}
```
(Requires widening `HOLD_POINT` visibility or duplicating the literal with a
comment, matching the convention `RamsDisplayPatchService::isPlaceholderNearestHospital()`
already uses.)

---

### CR-02: CDM backfill migration's substring guard can silently overwrite genuine engineer-typed CDM text

**File:** `database/migrations/2026_09_11_180000_backfill_cdm_duty_holder_placeholder.php:192-211`

**Issue:** For `reviewed_data['cdm']` rows, the migration replaces
`$cdmRow['name']` whenever the row's role is Principal Designer/Contractor
**and** `str_contains($name, 'To be confirmed')` — a substring match, not an
exact-literal match (unlike the `generated_data['cdm_duty_holders']` branch,
which correctly uses `=== self::RAW_PLACEHOLDER`). The class docblock (lines
42-54) acknowledges this is deliberately looser than the generated_data guard
"since the review form captures free text," but the practical consequence is
that **any** genuine PM-authored note that happens to contain the phrase "To be
confirmed" — e.g. `"Contact details to be confirmed — Alex Carter is acting PD"`
or `"PD to be confirmed once client appoints one"` — is entirely replaced by the
generic `DEFAULT_PRINCIPAL_DESIGNER_NOTE`/`DEFAULT_PRINCIPAL_CONTRACTOR_NOTE`
boilerplate, discarding the real, potentially load-bearing, project-specific
information the PM typed.

This migration has **already been run against production** (per its own
docblock: "Measured 2026-09-11... 46 of 54 `RamsDocument` rows... carry the CDM
`'[To be confirmed]'` placeholder"). `down()` is a documented, deliberate no-op,
so if any of those 46 rows contained a genuine free-text note matching the
substring, that data has already been permanently lost with no audit trail
distinguishing "was the bare placeholder" from "was real text containing the
phrase." `BackfillCdmDutyHolderMigrationTest::test_backfill_never_overwrites_an_engineer_typed_real_name`
(lines 109-140) only tests a name with **no** occurrence of the substring at
all (`'Jane Smith, ABC Construction Ltd'`), so this exact risk is untested.

**Fix (for future migrations of this shape):** Scope the substring guard more
tightly — e.g. only replace when the name is the bracket-free literal
`'To be confirmed'` alone (mirroring `addCdmDutyHolders()`'s pre-restatement
placeholder exactly), or when it matches a small enumerated set of known
seeded-default variants, rather than any string containing the phrase anywhere.
For this specific migration (already executed), recommend auditing the 46
touched rows' `updated_at`/audit log (if any) against the pre-migration
`reviewed_data['cdm']` values to confirm no row lost genuine content — this is a
data-integrity concern the team should verify, not something code review alone
can rule in or out after the fact.

## Warnings

### WR-01: Legacy `pdf.rams` blade is not self-sufficient for the A&E resolution — breaks the "single source of truth" design goal

**File:** `resources/views/pdf/rams.blade.php:1980-1987`

**Issue:** `SiteEmergencyResolver`'s docblock states "No render site... re-derives
this decision independently — every caller goes through `self::resolve()`."
That is true for `EmergencyComposer::compose()` (calls `SiteEmergencyResolver::resolve()`
directly, line 37 of `EmergencyComposer.php`) and therefore for `rams-v2.blade.php`.
It is **not** true for `rams.blade.php`, which instead reads a pre-computed
`$data['site_emergency_resolved']['text']` (line 1983) with a bare `?? ''`
fallback — it never calls the resolver itself. That key is only populated by
`RamsComplianceUpgradeService::resolveSiteEmergency()` inside `upgrade()`.

In the current controller (`RamsController::downloadPdf()`), `upgrade()` is
always called immediately before `pdfService->buildRams()`, so this gap is
currently masked in the primary user-facing download path. But the "single
source of truth, no independent re-derivation" invariant the resolver's
docblock asserts is not actually enforced by the type system or by
`rams.blade.php` itself — it depends entirely on every future/alternate caller
remembering to call `upgrade()` first. This has already happened once:
`app/Console/Commands/RamsRegenerateSnapshotsCommand.php:208-211` renders
`pdf.rams` directly from raw fixture `generated_data` (only running
`RamsDisplayPatchService::patch()` first, never `RamsComplianceUpgradeService::upgrade()`),
so any fixture lacking `site_emergency_resolved` renders a **blank** "Nearest
A&E Hospital" cell — silently worse than the hold-point line, and worse than
showing nothing at all. `SiteEmergencyRenderSitesRegressionTest.php` (the test
suite for this exact class of regression) always calls `upgrade()` before
rendering `pdf.rams` (line 134), so it does not catch this.

**Fix:** Make `rams.blade.php` compute the fallback itself, matching v2's
pattern, e.g.:

```php
$resolved = $data['site_emergency_resolved']
    ?? \App\Services\Rams\SiteEmergencyResolver::resolve($siteEmerg);
```

so a missing key degrades to the safe hold-point line instead of blank output.

---

### WR-02: DocxBuilderService::buildCdmSection()'s "defence-in-depth" fallback does not catch the one value it is documented to catch

**File:** `app/Services/DocxBuilderService.php:1704-1711`

**Issue:** The class docblock and `SiteEmergencyResolver`-adjacent comments
describe `buildCdmSection()`'s `?? DEFAULT_PRINCIPAL_DESIGNER_NOTE` /
`?? DEFAULT_PRINCIPAL_CONTRACTOR_NOTE` fallback as a "defence-in-depth
fallback." In PHP, `??` only substitutes when the left operand is `null`
(or the key is absent) — it does **not** substitute when the key is present but
holds the literal bad value `'[To be confirmed]'`. For any `RamsDocument`
generated before Plan 29-03 shipped, `generated_data['cdm_duty_holders']['principal_designer']`
already has that literal string **persisted as a value**, not as a missing key.
Re-rendering that document's DOCX (without going through the backfill migration
or a fresh `upgrade()` pass) will render the raw `'[To be confirmed]'` string
verbatim — the fallback provides no protection against exactly the scenario the
migration exists to fix. In practice this is a documentation/behaviour mismatch
rather than an active defect today (the migration has already run against the
current production data), but the comment overstates what the code guarantees,
and it means any future regression that reintroduces the raw literal into
`cdm_duty_holders` (e.g. a bug in `addCdmDutyHolders()`) would not be caught
here.

**Fix:** Either correct the comment to state this only guards against a
missing key (not the known-bad literal), or make it a genuine guard:

```php
$pdValue = $cdm['principal_designer'] ?? null;
$pdValue = ($pdValue === null || $pdValue === '[To be confirmed]')
    ? \App\Services\Rams\RamsComplianceUpgradeService::DEFAULT_PRINCIPAL_DESIGNER_NOTE
    : $pdValue;
```

---

_Reviewed: 2026-09-11_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
