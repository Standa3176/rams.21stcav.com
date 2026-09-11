# Phase 29: CDM Duty-Holder & Emergency Arrangements - Research

**Researched:** 2026-09-11
**Domain:** Laravel PHP RAMS document generation — safety-content gates over persisted JSON + rendered documents
**Confidence:** HIGH (every claim below is file:line-verified against this repo; no external library research was needed — this phase is entirely internal-pattern replication)

## Summary

Phase 29 is a **pure pattern-replication** phase. Phases 27 and 28 already established, and
proved on live production, the exact two-part shape this phase needs: (1) a Tier-1
deterministic default-filler that runs inside `RamsComplianceUpgradeService::upgrade()`
(`addCdmDutyHolders()` already exists for CDM, at `:1093`), and (2) an independent throwing
re-check — `enforceDisplayLiftGate()` (GATE-09, `:1171-1240`) and
`enforceFfp2AndConfinedSpaceGate()` (GATE-06/07, `:1298+`) — config-gated by their OWN
`config/rams_tier1.php` flag, caught at all three `RamsController.php` `upgrade()` call
sites (`:603`, `:701`, `:857`), and surfaced as a friendly redirect via
`RamsGenerationException`. GATE-11/GATE-12 should be a third pair added to this exact
mechanism, sharing `upgrade()`'s pipeline and `RamsController`'s existing catch blocks —
no new surfacing code is needed anywhere.

The one genuinely new piece of architecture this phase needs is a **shared A&E resolution
helper** that does not exist yet. Today the A&E sentence is a hardcoded literal
independently duplicated in three render sites (`rams.blade.php:1960`,
`rams-v2.blade.php:2021`, `DocxBuilderService.php:2149`), completely disconnected from
`site_emergency.nearest_hospital`, which section 7.0 already renders separately with its OWN
independent `'TBC'` fallback in **both** blades (`rams.blade.php:1983`,
`rams-v2.blade.php:2066` — the CONTEXT.md D-01 table only names the legacy blade's `:1976`
fallback; the v2 blade has an equivalent one at a different line, see Pitfall 3). The fix
needs one deterministic resolver, called once inside `upgrade()` (mirroring how
`addCdmDutyHolders()` centralises its own defaulting), whose output every render site reads
— not five independent branch implementations.

`ControlTextRuleViolations` is **not** the right home for GATE-11/GATE-12 (see Finding 2) —
it is scoped to free-text hazard *control lines* and hazard *names* only. CDM and A&E are a
structured array and a single field respectively; the natural home is two new private static
methods on `RamsComplianceUpgradeService` itself, following the `enforceFfp2AndConfinedSpaceGate()`
shape directly (unconditional loop, no template-resolution branch, own config flag).

**Primary recommendation:** Add `enforceCdmAndEmergencyGate()` (or two separate methods,
GATE-11 and GATE-12, called back-to-back — see Open Question 1) to
`RamsComplianceUpgradeService`, gated by a new `RAMS_CDM_AE_GATE` flag defaulting `false` in
`config/rams_tier1.php` (D-03), called from `upgrade()` immediately after `addCdmDutyHolders()`
and after a new site-emergency resolution step. Build the shared A&E resolver as its own
class (e.g. `App\Services\Rams\SiteEmergencyResolver`) so both the gate and all five render
sites call the identical branch logic.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| CDM duty-holder default wording (RULE-07) | Backend / Tier-1 upgrade service | — | `RamsComplianceUpgradeService::addCdmDutyHolders()` is the sole origin; deterministic, no AI, no DB |
| A&E verified-vs-hold-point branch (RULE-08) | Backend / Tier-1 upgrade service (new resolver) | — | Must be computed once, centrally, and consumed identically by 5 render sites; currently scattered per-template logic |
| CDM/A&E gate enforcement (GATE-11/GATE-12) | Backend / Tier-1 upgrade service (throwing re-check) | Controller (catch + redirect) | Mirrors GATE-06/07/09 exactly — `RamsComplianceUpgradeService::upgrade()` throws, `RamsController` catches |
| Rendering the resolved CDM/A&E text | Blade templates (`rams.blade.php`, `rams-v2.blade.php`) + DOCX builder (`DocxBuilderService.php`) | Section composers (`EmergencyComposer`, `WelfareComposer`) for the unified-composer path | Presentation only — no branching logic should live here after the fix; all five sites should read a pre-resolved value |
| Existing production-row backfill | Database migration (Laravel migration, one-shot) | — | Code-only fix never reaches already-persisted `reviewed_data`/`generated_data` (D-02, Phase 28-07 precedent) |
| Per-project carry-forward guard | `RamsDisplayPatchService::patch()` | — | Must not re-propagate a placeholder value onto new documents (D-04) |

## User Constraints (from CONTEXT.md)

<user_constraints>

### Locked Decisions (verbatim, condensed — see 29-CONTEXT.md for full text)

- **D-01**: A&E line becomes data-driven; ALL THREE legacy sites (`rams.blade.php:1960`+`:1976`,
  `rams-v2.blade.php:2021`, `DocxBuilderService.php:2149`) plus the composer layer
  (`WelfareComposer`/`EmergencyComposer`/`EmergencySectionDto`) are fixed via one shared
  helper. Both `RAMS_UNIFIED_COMPOSER` states must be fixed because prod's actual setting is
  unverified from this session.
- **D-02**: Live rows get a measure-first checkpoint + idempotent backfill migration (the
  Phase 28-07 shape) for CDM only. A&E needs **no** backfill — it is template text, never
  persisted.
- **D-03**: GATE-11/GATE-12 ship DISARMED behind a NEW, OWN flag (e.g. `RAMS_CDM_AE_GATE`),
  default `false` in `config/rams_tier1.php`. Never reuse `RAMS_DISPLAY_LIFT_GATE` or
  `RAMS_PPE_CEILING_ELECTRICAL_GATE`.
- **D-04**: CDM/A&E carry-forward (`RamsDisplayPatchService.php:417-430`) copies only
  non-placeholder values — skip any field whose prior value is still the placeholder.
- **D-05**: When no verified 24/7 A&E is recorded, print the house-rule hold-point line —
  never guess a hospital name. **Overrides ROADMAP criterion 2 as currently written** —
  restate it before planning against it.
- **D-06**: RULE-08 restated to a two-branch requirement (verified named + hold point, never
  the passive banned string in either branch). Update `REQUIREMENTS.md:88` with a RESTATED
  2026-09-11 note in RULE-07's style.
- **D-07**: Section 7.0 is the single source of A&E truth. Welfare First Aid bullet drops its
  A&E sentence, replaced with a pointer to Section 7.0. The `'TBC'` fallback at
  `rams.blade.php:1976` (and its v2 equivalent, `rams-v2.blade.php:2066` — see Pitfall 3) is
  removed; `'TBC'` is not one of the two permitted branches.
- **D-08**: GATE-12 is a plausibility check only (banned string / urgent-care keyword match /
  named-with-no-address-or-postcode). No UK A&E dataset. Inherits Phase 28 D-01's
  conservative-by-construction rule verbatim — unclassifiable is CLEAN. The D-05 hold-point
  line passes clean; it is legitimate output.

### Claude's Discretion

- **CDM duty-holder wording (RULE-07) — constrained, not open.** Use the anticipated-sole-
  contractor sentence **exactly** as given in `standards-and-legislation.md:23-28`. Client
  row uses the known client's name, never "To be confirmed" beside it; "Accepted by
  (Client)" stays blank. Contractor/21CAV row is conditional on sole-vs-multiple-contractor
  (`:32-34`). Four forbidden statements must never appear (`:35-41`): client "retains
  Principal Designer responsibilities"; 21CAV "discharges its duties under Regulations 4 or
  5" (it's Regulation 15); "the Principal Contractor must notify HSE" (F10 is the Client's);
  any implication an F10 is always needed.
  - Undecided, left to planner: unconditional application vs. occupied-premises-only gating
    (see Finding 6 below — no clean deterministic signal exists for this); engineer
    review-form override for CDM rows; full-sentence vs. short-form-with-detail-elsewhere.
- Test-fixture regeneration strategy for the four `tilda-21cq29531` expected-output files.
- Whether GATE-11/GATE-12 share one detector class/method or take separate entries.

### Deferred Ideas (OUT OF SCOPE)

- Site-level A&E/asbestos/access/welfare inheritance (v3.1 deferral).
- Curated static list of verified 24/7 EDs (declined for D-08 — no owner, false positives).
- Escalating GATE-12 to block generation on a blank A&E (declined; revisit once a site-level
  A&E source exists).

</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| RULE-07 | CDM duty-holder note replaces `[To be confirmed]` with the anticipated sole-Contractor wording | Finding 1 (default-filler location), Finding 6 (full precedence chain — **DOCX never reads `cdmRows`, see Pitfall 2**), Finding 5 (backfill precedent + measurement query) |
| RULE-08 | Nearest A&E named with address, or the hold-point line; passive "to be identified at site induction" banned in both branches | Finding 3 (5-site render map), Finding 4 (`RAMS_UNIFIED_COMPOSER` state), Pitfall 3 (v2's own hidden `'TBC'` fallback) |
| GATE-11 | CDM duty-holder table left as placeholder → error | Finding 1 (gate shape to replicate), Finding 2 (wrong choke point identified), Finding 6 (no occupied-premises signal exists — open question) |
| GATE-12 | Named A&E must be a real A&E (plausibility check only, D-08) | Finding 1 (gate shape), Finding 2 (wrong choke point), Finding 3 (single resolver needed before the gate can inspect one value) |
</phase_requirements>

## Standard Stack

Not applicable — this phase adds no new dependencies. It extends existing, hand-rolled
internal services (`RamsComplianceUpgradeService`, Laravel migrations, PHPUnit) using the
same patterns as Phases 27/28. No package installs, so the Package Legitimacy Audit section
is omitted.

## Architecture Patterns

### System Architecture Diagram

```
Engineer review form (RamsController::updateAndDownload)
        │  writes reviewed_data['cdm'] (cdmRows) — NOT mirrored to generated_data
        │  writes reviewed_data['site_emergency'] — IS mirrored to generated_data
        ▼
RamsDisplayPatchService::patch()  (transient, not persisted)
        │  carry-forward: seeds reviewed_data['cdm'] / ['site_emergency']
        │  from prior completed RAMS on same project IF the field is empty
        │  ⚠ D-04 requires: skip carry-forward when prior value == placeholder
        ▼
RamsComplianceUpgradeService::upgrade($generatedData)   ← THE PIPELINE
        │  ... (existing Tier-1 steps) ...
        │  [NEW] resolve site_emergency → one A&E branch value (verified | hold-point)
        │  addCdmDutyHolders($data)         → $data['cdm_duty_holders'] (defaults)
        │  [NEW] if (config flag) enforceCdmAndEmergencyGate($data)   ← GATE-11/GATE-12
        │        throws RamsGenerationException on violation
        ▼
   ┌────────────────────────┬──────────────────────────┐
   │ RAMS_UNIFIED_COMPOSER  │ RAMS_UNIFIED_COMPOSER     │
   │      = false (legacy)  │      = true               │
   ▼                        ▼                            
rams.blade.php          RamsDocumentComposer → DTOs → rams-v2.blade.php
  reads reviewed_data['cdm'] (cdmRows)   EmergencyComposer/WelfareComposer read
  as override over cdm_duty_holders      generated_data/reviewed_data directly
  (blade-only precedence — DOCX          (NOT the resolved upgrade() output —
  never sees this override, Pitfall 2)   composers run independently of upgrade())
   │                        │
   └───────────┬────────────┘
               ▼
   DocxBuilderService::build()
     → unified_composer ? DocxBuilderServiceV2 : buildLegacy()
     → buildCdmSection() / buildWelfareArrangements() SHARED by both paths
       (part of buildRestOfDocument(), reused verbatim by V2 — CDM/welfare text
       is NOT yet composer-driven even when RAMS_UNIFIED_COMPOSER=true)
     → reads $data['cdm_duty_holders'] ONLY — never reviewed_data['cdm']
```

### Pattern 1: Auto-correct + independent throwing re-check (GATE-06/07/09 shape)

**What:** A Tier-1 default-filler runs unconditionally inside `upgrade()`. Immediately
after (or later in the same pipeline), a config-gated independent re-check inspects the
SAME final payload and throws `RamsGenerationException` if a violation survives.

**When to use:** Any settled house-rule position that must never leave `upgrade()` unmet.

**Example (GATE-09, the shape to copy verbatim):**
```php
// Source: app/Services/Rams/RamsComplianceUpgradeService.php:62-79
if (config('rams_tier1.display_lift_gate_enabled', true)) {
    $ramsData = self::enforceDisplayLiftGate($ramsData);
}
// GATE-06/GATE-07 — independent re-check ... Config-gated with its OWN flag
// (D-08 — never reuses RAMS_DISPLAY_LIFT_GATE) ...
if (config('rams_tier1.ffp2_confined_space_gate_enabled', true)) {
    $ramsData = self::enforceFfp2AndConfinedSpaceGate($ramsData);
}
```
GATE-11/GATE-12 should be inserted the same way, directly after the `addCdmDutyHolders($ramsData)`
call at the end of `upgrade()` (`:70` in the current method body), gated on
`config('rams_tier1.cdm_ae_gate_enabled', false)` — **default false per D-03**, unlike
GATE-06/07/09 which default `true` (those were already measured-clean before arming; GATE-11/12
have not been).

### Pattern 2: Config flag block (per-gate isolation)

**What:** Each gate gets its own env-backed config key in `config/rams_tier1.php`, with a
doc-comment block naming exactly which method it gates and why it is independent from the
others.

**Example (copy this shape, add a third block):**
```php
// Source: config/rams_tier1.php:74-98
'display_lift_gate_enabled' => env('RAMS_DISPLAY_LIFT_GATE', true),
// ...
'ffp2_confined_space_gate_enabled' => env('RAMS_PPE_CEILING_ELECTRICAL_GATE', true),
```
New block: `'cdm_ae_gate_enabled' => env('RAMS_CDM_AE_GATE', false),` — **note the `false`
default, which both existing gates do NOT use** (they default `true` because Phase 27/28
measured their corpora clean before shipping; Phase 29 has not yet measured, so it must
default off per D-03).

### Pattern 3: Throw + catch + friendly redirect

**What:** `RamsGenerationException` thrown inside `upgrade()` propagates to exactly three
`RamsController` call sites, each already wrapped in a catch block. No new surfacing code
needed.

**Confirmed call sites (all three already catch `\App\Exceptions\RamsGenerationException`):**
```php
// Source: app/Http/Controllers/RamsController.php:603-606 (Save Review)
try {
    $generatedData = \App\Services\Rams\RamsComplianceUpgradeService::upgrade($generatedData);
} catch (\App\Exceptions\RamsGenerationException $e) {
    return back()->withInput()->with('error', $e->getMessage());
}

// Source: app/Http/Controllers/RamsController.php:857-861 (PDF render / downloadPdf)
try {
    $rams->generated_data = \App\Services\Rams\RamsComplianceUpgradeService::upgrade(
        $rams->generated_data
    );
} catch (\App\Exceptions\RamsGenerationException $e) {
    return back()->with('error', $e->getMessage());
}

// Source: app/Http/Controllers/RamsController.php:697-701 (download/DOCX rebuild)
// wrapped in a broader catch (\Throwable $e) at :705, not exception-specific,
// but still surfaces the message via Log::error + presumably a redirect —
// verify this third site's user-facing behaviour is acceptable, it is
// slightly looser than the other two.
```

### Recommended new file/method structure

```
app/Services/Rams/
├── RamsComplianceUpgradeService.php
│   ├── addCdmDutyHolders()                    [EXISTING — no change to signature]
│   ├── resolveSiteEmergency()  [NEW]           — the shared A&E branch resolver,
│   │                                              called from upgrade() before addCdmDutyHolders()
│   │                                              (or a small dedicated class, see below)
│   └── enforceCdmAndEmergencyGate()  [NEW]     — GATE-11 + GATE-12, mirrors
│                                                  enforceFfp2AndConfinedSpaceGate() shape
config/rams_tier1.php
│   └── 'cdm_ae_gate_enabled' => env('RAMS_CDM_AE_GATE', false),  [NEW block]
database/migrations/
│   └── 2026_09_1X_backfill_cdm_duty_holder_placeholder.php  [NEW — mirrors 28-07's migration]
```

**Where the shared A&E resolver should live — planner discretion, two viable options:**
1. A new private static method on `RamsComplianceUpgradeService` (simplest, matches
   `addCdmDutyHolders()`'s own placement) that writes a resolved value into
   `$data['site_emergency_resolved']` (new key) for all render sites to read.
2. A new standalone class (e.g. `App\Services\Rams\SiteEmergencyResolver`) if the branch
   logic needs to be called from BOTH `upgrade()` (to populate `generated_data`) AND
   `EmergencyComposer`/`WelfareComposer` (which run independently of `upgrade()` — see
   Pitfall 4) without those composers depending on `RamsComplianceUpgradeService`.
   **Option 2 is recommended** — the composers currently have no dependency on
   `RamsComplianceUpgradeService` and introducing one would be a bigger structural change
   than a small standalone resolver class both sides can inject/call statically.

### Anti-Patterns to Avoid

- **Adding GATE-11/GATE-12 detectors to `ControlTextRuleViolations`.** That class's own
  docblock (`:19-25`) names its intended extensions (`coshh_wrong_category` for Phase 31)
  and neither name is CDM or A&E — see Finding 2. Its `DETECTORS` registry classifies
  single free-text strings (a hazard control line or hazard name); CDM is a 7-key
  associative array and A&E is a resolved sentence with address/postcode sub-parts. Forcing
  this into `detect(string $control): ?string`'s signature would require serialising
  structured data into a string first, which is exactly the kind of indirection the class
  was NOT designed for.
- **Re-deriving the A&E branch logic independently in each of the 5 render sites.** This is
  the literal defect D-01 exists to close — two already-diverged copies of the same sentence
  (Welfare bullet vs. Section 7.0) are today's bug. A sixth copy for the DOCX path, a
  seventh for the v2 blade's own `'TBC'` fallback (Pitfall 3), etc. would recreate the same
  class of drift GATE-12 exists to catch.
- **Trusting `reviewed_data['cdm']` (`cdmRows`) as reaching the live DOCX renderer.** It does
  not — see Pitfall 2. Any gate/backfill design that assumes symmetry between the PDF-blade
  precedence and the DOCX precedence will silently miss the primary production render path.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Detecting a "not really A&E" name (urgent care / minor injury / UTC) | A new NLP/fuzzy classifier | A small keyword list mirroring `ControlTextRuleViolations::CONFINED_SPACE_AFFIRMATIVE`'s exact shape (static const array, checked with `stripos`) | D-08 explicitly scopes this to plausibility keyword matching, not real classification — matching the existing negation-aware-detector pattern (`:92-109`) is proportionate and consistent |
| Verifying a hospital is a real 24/7 ED | A UK hospital dataset / API lookup | Nothing — D-08 explicitly declines this | Declined twice in CONTEXT.md; out of scope |
| Backfill idempotency / dry-run measurement tooling | A new measurement framework | The exact `chunkById(100)` + placeholder-equality-guard shape from `2026_09_05_180000_backfill_ppe_ffp2_and_electrical_exclusion_bullet.php` | Proven live on 54/54 production documents; `down()` documented as a deliberate no-op for the same non-reversibility reason this phase's backfill will share |

**Key insight:** every mechanism this phase needs (gate pairing, config flag isolation,
throw/catch surfacing, backfill idempotency) already has a working, live-verified
implementation in this exact codebase from the immediately preceding two phases. The
research risk here is not "what pattern to use" — it is "which of the 5 render sites still
needs manual wiring" and "does the chosen resolver reach all of them", which Findings 3-6
below answer concretely.

## Findings (numbered — referenced above)

### Finding 1 — Gate block shape (fully confirmed)

`RamsComplianceUpgradeService::upgrade()` (`:36-77`) is a single deterministic pipeline.
The two existing gates are called via `if (config(...)) { $ramsData = self::enforceX($ramsData); }`
immediately after the step that produces the data they check, in the SAME method. Both
`enforceDisplayLiftGate()` (`:1171`) and `enforceFfp2AndConfinedSpaceGate()` (`:1298`) are
`private static function(array $data): array` — they either return `$data` unchanged or
throw `RamsGenerationException` with a message that (a) names the offending value, (b) names
the rule ID, and (c) tells the operator which env var disables the check. Both are surfaced
identically at all three `RamsController` `upgrade()` call sites (`:603`, `:701`/`:857` —
verified exact line ranges above in Pattern 3).

`addCdmDutyHolders()` is the LAST-but-one step in `upgrade()` (`:70`, right before
`cleanTextArtifacts()`). GATE-11/GATE-12 should be inserted directly after it, config-gated
per Pattern 2.

### Finding 2 — `ControlTextRuleViolations` is NOT the right home (confirmed, stated plainly)

Read in full (`app/Services/Rams/ControlTextRuleViolations.php:1-160`). Its `DETECTORS`
registry (`:84-89`) currently holds `kg_threshold`, `size_conditional_lift`, `ffp2`,
`confined_space` — all classify a single free-text **control-measure line** or **hazard
name** via `detect(string $control): ?string`. Its docblock (`:19-25`) explicitly names its
planned extension point: "Phase 28 adds `ffp2`/`confined_space`, Phase 31 adds
`coshh_wrong_category`" — Phase 29 is not mentioned, and neither `cdm_duty_holders` (a
7-key structured array) nor `site_emergency` (a field with address/postcode sub-structure)
fit the `string $control` signature without lossy serialisation. **The right choke point is
two new private static methods directly on `RamsComplianceUpgradeService`**, following
`enforceFfp2AndConfinedSpaceGate()`'s shape (unconditional per-item loop, no template-
resolution branch, own throwing conditions) rather than routing through the detector
registry.

### Finding 3 — A&E render path: 5 sites, not the 3 named in CONTEXT.md D-01

CONTEXT.md's D-01 table names 3 sites plus "the composer layer." Verified exact locations:

| # | File | Line(s) | What it does |
|---|------|---------|--------------|
| 1 | `resources/views/pdf/rams.blade.php` | `:1960` (Welfare bullet, hardcoded sentence) | Legacy blade, banned string literal |
| 2 | `resources/views/pdf/rams.blade.php` | `:1983` (`'TBC'` fallback in §7.0 table) | Legacy blade, D-05/D-07 requires removing `'TBC'` |
| 3 | `resources/views/pdf/rams-v2.blade.php` | `:2021` (Welfare bullet, hardcoded sentence — **identical text to #1**) | Unified-composer blade |
| 4 | `resources/views/pdf/rams-v2.blade.php` | `:2066` (`'TBC'` fallback — **CONTEXT.md D-01 does not name this line; it exists and must also be fixed**) | See Pitfall 3 |
| 5 | `app/Services/DocxBuilderService.php` | `:2149` (Welfare bullet array literal, `buildWelfareArrangements()`) | Shared by BOTH `RAMS_UNIFIED_COMPOSER` states — see Pitfall 4 |

`app/Support/Rams/SectionComposers/EmergencyComposer.php` composes `EmergencySectionDto`
from `generated_data['site_emergency']` → `reviewed_data['site_emergency']` → empty defaults
(`:29`, docblock `:18-20`). It does NOT currently apply any verified-vs-hold-point branch
logic — it passes `nearest_hospital` through raw. The blade then independently applies its
own `?: 'TBC'` fallback (site #4 above). **`EmergencySectionDto` carries a `nearestHospital`
field (`:47`) but no separate "is this verified" boolean** — the DTO has no concept of the
D-05 branch at all today; this is new surface, not an existing field to repurpose.

`app/Support/Rams/SectionComposers/WelfareComposer.php` and
`app/Support/Rams/Sections/WelfareSectionDto.php` were read in full. **`WelfareSectionDto`
carries NO A&E field of any kind** (`toilets`, `washing`, `restArea`, `firstAid`,
`drinkingWater` only — `:14-20`). Its `firstAid` default string
(`WelfareComposer.php:18`, `'First-aid kit carried in every 21CAV vehicle; qualified
first-aider details captured in Section 7.'`) already points to Section 7.0 and contains
**no A&E sentence and no banned string** — it is already D-07-compliant. **But this DTO is
dead code for the A&E question**: grepped both blades for any `$welfareDto`/`->firstAid`
reference and found none (Pitfall 4) — the actual rendered "Welfare Arrangements" `<ul>` in
BOTH blades is 100% hardcoded HTML (`rams.blade.php:1955-1962`,
`rams-v2.blade.php:2016-2023`), never reading the composed `WelfareSectionDto` at all, even
though `RamsDocumentComposer.php:100` composes it into the DTO bundle. Fixing D-07 therefore
means editing the blade `<li>` text directly at sites #1 and #3 above — not the DTO/composer,
which is unused for this purpose (though updating `WelfareSectionDto`'s docblock to note
this dead-code status may be worth a note, out of scope for this phase to fix the dead-code
issue itself).

### Finding 4 — `RAMS_UNIFIED_COMPOSER` live state: cannot be confirmed from this session

`config/rams.php:43` — `'unified_composer' => env('RAMS_UNIFIED_COMPOSER', false)` —
confirmed default `false`. The local `.env` file at the project root (dated 2026-04-15, a
dev-machine artefact, NOT the VPS) has **no `RAMS_UNIFIED_COMPOSER` line at all** (grep
returned nothing), so locally it resolves to the config default `false`. **This tells us
nothing about production** — `.env.example` also has no line, and per D-01's own warning,
`config:cache` on the live VPS could make a direct `.env` read misleading even if this
session had VPS access, which it does not.

`app/Console/Commands/RamsRegenerateSnapshotsCommand.php:224-231` toggles the flag at
runtime for BOTH values in the same command run (`config(['rams.unified_composer' => false])`
then `true`) specifically to capture golden snapshots for both paths — confirming the
mechanism used to prove parity, and confirming D-01's instruction ("fix both layers") is
correct regardless of prod's actual setting, since this command's own test infrastructure
depends on both paths being correct.

**Action for the planner:** include a `checkpoint:human-verify` or a live `php artisan
tinker` step (`config('rams.unified_composer')`) run on the VPS as `stcav` before deploy —
do not assume either state.

### Finding 5 — Backfill precedent + measurement command

`database/migrations/2026_09_05_180000_backfill_ppe_ffp2_and_electrical_exclusion_bullet.php`
read in full. Shape to replicate for CDM's `[To be confirmed]` backfill:

- `DB::table('rams_documents')->select('id', 'reviewed_data', 'generated_data')->chunkById(100, ...)`
  — chunked, safe for production scale (54 rows currently, per 28-07-MEASUREMENT.md, though
  this phase must re-measure — do not reuse that count, it's for a different corpus scan).
- Loops both `reviewed_data` and `generated_data` columns independently per row.
- Idempotency via **value-equality guard**, not a "already migrated" marker: only writes
  when the computed new value differs from what's stored (`if ($folded !== $original)`
  pattern) — CDM's equivalent is "only overwrite a duty-holder field if its current value is
  literally the placeholder string."
- `down()` is a documented deliberate no-op — cannot distinguish a migration-touched row from
  one an engineer independently corrected by hand afterward; reverting would destroy genuine
  post-migration correctness. Phase 29's CDM backfill should carry the same justification.
- Echoes an auditable per-surface count at the end of `up()`.

**Exact measurement command the planner should put in a measurement task** (read-only, run
via `php artisan tinker` or a one-off script on the VPS as `stcav`, mirroring
28-07-MEASUREMENT.md's "Run on rams.21stcav.com as stcav, not root, read-only" protocol):

```php
// Count rows where the CDM block still carries the placeholder anywhere in
// EITHER JSON column. Mirrors the pattern in
// 2026_09_05_180000_backfill_ppe_ffp2_and_electrical_exclusion_bullet.php.
$total = \App\Models\RamsDocument::count();

$placeholderCount = \App\Models\RamsDocument::query()
    ->get(['id', 'reviewed_data', 'generated_data'])
    ->filter(function ($rams) {
        $rd = (array) ($rams->reviewed_data ?? []);
        $gd = (array) ($rams->generated_data ?? []);

        // reviewed_data['cdm'] is a LIST of {role, name} rows (RamsController.php:506),
        // not the same keyed shape as generated_data['cdm_duty_holders'] — check both
        // shapes.
        $cdmRowsHavePlaceholder = collect((array) ($rd['cdm'] ?? []))
            ->contains(fn ($row) => str_contains((string) ($row['name'] ?? ''), 'To be confirmed'));

        $cdmDefaultHasPlaceholder = collect((array) ($gd['cdm_duty_holders'] ?? []))
            ->contains(fn ($v) => is_string($v) && str_contains($v, 'To be confirmed'));

        return $cdmRowsHavePlaceholder || $cdmDefaultHasPlaceholder;
    })
    ->count();

echo "Total rows: {$total}, rows with a CDM placeholder in reviewed_data.cdm or generated_data.cdm_duty_holders: {$placeholderCount}\n";
```

I did **not** run this against production — it requires VPS access this research session
does not have. The measurement task in the plan must run it (or an equivalent SQL/`jq`
scan mirroring `28-07-MEASUREMENT.md`'s Pass 1/2 structure) as a `checkpoint:human-action`
before the backfill migration is written, per D-02.

### Finding 6 — Full CDM precedence chain, and the "occupied premises" signal question

**Precedence chain (confirmed by direct code read, corrects a gap in CONTEXT.md's framing):**

1. `RamsComplianceUpgradeService::addCdmDutyHolders()` (`:1093-1111`) — always computes
   `$data['cdm_duty_holders']` fresh from `$data['project']`, unconditionally, every
   `upgrade()` call. This is the ONLY producer of the placeholder default.
2. `RamsController::updateAndDownload()` (`:504-509`) — captures the engineer's typed CDM
   rows from the review form into `$reviewedData['cdm']` (a list of `{role, value}` rows).
   **There is no corresponding `$generatedData['cdm'] = ...` mirror line** — unlike
   `site_emergency` (`:539`) and `material_handling` (`:550`), which ARE explicitly
   mirrored into `generatedData` in the same method with a comment explaining why. CDM is
   NOT mirrored.
3. `rams.blade.php:439-440` / `rams-v2.blade.php:478-479` — read `$cdmRows =
   $rams->reviewed_data['cdm'] ?? []` directly from the model, independent of whatever
   `generated_data` holds.
4. `rams.blade.php:1822-1826` / `rams-v2.blade.php:1883-1887` — merge: use `$cdmRows` if
   non-empty, otherwise fall back to `$data['cdm_duty_holders']` (the Tier-1 default).
5. `DocxBuilderService::buildCdmSection()` (`:1680-1712`) reads **only**
   `$data['cdm_duty_holders']` — confirmed by grep across `DocxBuilderService.php` and the
   whole `app/Support/Rams/` tree for `'cdm'`/`reviewed_data['cdm']`: **zero matches**.

**Pitfall (load-bearing finding, not previously documented in CONTEXT.md): the DOCX renderer
— confirmed the "live primary renderer" by Phase 28's own CONTEXT.md — never sees an
engineer's typed CDM override at all.** The `cdmRows`-wins-over-default precedence exists
ONLY in the two PDF blades. Any engineer who fills in real duty-holder names via the review
form (`review.blade.php`'s CDM section, `reviewedData['cdm']`) will see those names in a PDF
render but get the raw `[To be confirmed]` default in the DOCX download — the two output
formats can already disagree today, independent of this phase's fix. **This matters for
planning because:**
- D-02's backfill only needs to patch `generated_data['cdm_duty_holders']` and
  `reviewed_data['cdm']` for the PDF-blade precedence to resolve correctly — but the DOCX
  path is governed by `generated_data['cdm_duty_holders']` ALONE regardless of what
  `reviewed_data['cdm']` says, so the backfill's `generated_data['cdm_duty_holders']` fix is
  the one that actually matters for the DOCX (live primary) path.
- If the planner wants engineer-typed CDM overrides to actually reach the DOCX (arguably
  desirable, but **not in this phase's stated scope** — CONTEXT.md's Claude's Discretion
  list only asks "whether engineers get a review-form input to override the CDM rows," which
  already exists on the reviewed_data/PDF side but is silently inert on DOCX), that would be
  new work beyond RULE-07/GATE-11's minimum. Flag this to the user as a scope question if
  raised; do not silently fix it as part of "restating RULE-07," since RULE-07 only concerns
  the DEFAULT wording, not the override mechanism.

**"Occupied-premises job" signal — does it exist? Answer: NO deterministic job-level signal
exists.** Searched `app/Services/Rams/HazardIncludeWhenResolver.php` and
`database/seeders/HazardTemplateSeeder.php`. "Occupied premises" exists only as a **tier-3
`confirm:occupied_premises` hazard** (`HazardTemplateSeeder.php:194-208`) — per
`HazardIncludeWhenResolver`'s own docblock (`:23-28`), tier-3 hazards are **always included
with `needs_confirmation=true` on every job, no exceptions** — there is no deterministic
derivation of whether a job IS occupied-premises; it is purely an engineer-ticked
confirmation on a specific hazard row, not a general boolean available to other services.
`TIER3_KEYWORD_PRECHECK['occupied_premises']` (`:105-111`) is explicitly a UI pre-tick
ordering hint only — "a hit here never decides inclusion or confirmation state" per its own
docblock. **CLAUDE.md:12 bars AI from deciding scope, and Phase 26 D-05 bars any deterministic
keyword match from silently deciding a safety-relevant condition either** — so even the
pre-tick keyword list cannot be repurposed as GATE-11's trigger without violating that
precedent. **The planner must treat "unconditional application" as the only clean option**
unless it wants to introduce a NEW review-form checkbox specifically for "is this an
occupied-premises job" — which is new UI surface, not something Phase 29 can derive from
existing data. Recommend: apply the fixed RULE-07 CDM wording unconditionally (every
generated RAMS gets the anticipated-sole-contractor wording, since the wording is already
correct for both single- and multi-contractor scenarios per the conditional Contractor row)
— this avoids inventing a signal that doesn't exist and matches D-06's own
"occupied-premises job" framing being left genuinely undecided rather than resolved by this
research.

## Common Pitfalls

### Pitfall 1: Assuming `ControlTextRuleViolations`'s docblock is exhaustive/current

**What goes wrong:** A planner skimming the class docblock sees "Phase 28 adds..., Phase 31
adds..." and might assume Phase 29 belongs there too by pattern-matching "another phase
extends this registry."
**Why it happens:** The docblock genuinely IS the extension contract for two other phases,
creating an availability bias.
**How to avoid:** Read `DETECTORS`' actual signature (`string $control): bool`) against
CDM's actual shape (7-key array) before assuming fit. See Finding 2.
**Warning signs:** Trying to serialise `cdm_duty_holders` into a string to run it through
`detect()` is the tell that the wrong abstraction is being forced.

### Pitfall 2: Assuming the DOCX renderer honors `reviewed_data['cdm']` the same way blades do

**What goes wrong:** A gate/backfill that only patches `generated_data['cdm_duty_holders']`
and `reviewed_data['cdm']` symmetrically, assuming both feed both render paths equally, will
be internally consistent but miss that **DOCX literally never reads `reviewed_data['cdm']`**
— see Finding 6. This isn't a bug this phase needs to FIX (out of scope, per Finding 6's
analysis), but a design that assumes DOCX and PDF share identical CDM precedence will be
subtly wrong when reasoning about "does the backfill actually fix the live document."
**Why it happens:** The two blades DO share identical precedence with each other, making it
easy to assume DOCX matches too.
**How to avoid:** State explicitly in the plan that the backfill's real target — the value
that matters for the live DOCX path — is `generated_data['cdm_duty_holders']`, and that
`reviewed_data['cdm']` only matters for the PDF-blade path.
**Warning signs:** A verification step that only checks the PDF render, not the DOCX
download, for a document with a distinct `reviewed_data['cdm']` override.

### Pitfall 3: Missing the v2 blade's own `'TBC'` fallback

**What goes wrong:** CONTEXT.md's D-01 table names `rams.blade.php:1976`'s `'TBC'` fallback
explicitly but does not name `rams-v2.blade.php:2066`'s equivalent
(`{{ $emergencyDto->nearestHospital ?: 'TBC' }}`). A planner working strictly off the
CONTEXT.md table would fix the legacy blade's fallback and miss the unified-composer
blade's own independent one.
**Why it happens:** CONTEXT.md's canonical_refs section names `rams-v2.blade.php` only once,
at `:2021` (the Welfare bullet), not its §7.0 table fallback.
**How to avoid:** Both `'TBC'` fallbacks (legacy `:1983`, v2 `:2066`) must be removed per
D-07's "`'TBC'` is not one of the two permitted branches" rule.
**Warning signs:** A regenerated fixture still showing literal `TBC` text in the §7.0 table
after the fix — this is the smoke test that would catch it.

### Pitfall 4: Assuming the section-composer DTOs are the render source of truth

**What goes wrong:** Both `WelfareSectionDto`/`WelfareComposer` and
`EmergencySectionDto`/`EmergencyComposer` exist, are wired into `RamsDocumentComposer`
(`:100`), and LOOK like the natural place to add the resolved A&E branch value. But the
Welfare "Arrangements" `<ul>` block in BOTH blades is hardcoded HTML that never reads
`$welfareDto` at all (confirmed by grep — zero `$welfareDto`/`->firstAid` references in
either blade). Fixing only the composer/DTO layer, believing it feeds the rendered welfare
bullet, would ship no visible change.
**Why it happens:** The composer infrastructure exists and is actively used for OTHER
sections (the §7.0 emergency table genuinely does read `$emergencyDto` in the v2 blade), so
it's reasonable to assume welfare does too.
**How to avoid:** Trace each of the 5 render sites (Finding 3) back to its actual data
source before assuming DTO involvement; edit the blade `<li>` text directly for the Welfare
bullet sites.
**Warning signs:** Changing `WelfareComposer`'s default string and the rendered PDF/DOCX not
changing at all in a test run.

### Pitfall 5: A gate defaulting `true` like GATE-06/07/09 did

**What goes wrong:** Copy-pasting the `env('RAMS_..._GATE', true)` pattern from GATE-06/07/09
verbatim for GATE-11/12 would arm the gate by default on next deploy, before the CDM backfill
has run — reproducing the exact "content gate defaulting ON is a deploy-order trap" failure
Phase 28's own retrospective (28-CONTEXT.md, carried into 29-CONTEXT.md D-03) explicitly
warns against.
**Why it happens:** GATE-06/07/09's `true` default is the more common pattern in this
codebase (2 of 2 existing examples), making it the "obvious" thing to copy.
**How to avoid:** D-03 is explicit and locked: `RAMS_CDM_AE_GATE` defaults **`false`**.
**Warning signs:** A plan task that doesn't explicitly call out the `false` default as
deliberately different from the two precedents it's otherwise copying.

## Code Examples

### CDM default-filler (existing, unchanged shape to extend the wording of)

```php
// Source: app/Services/Rams/RamsComplianceUpgradeService.php:1093-1111
private static function addCdmDutyHolders(array $data): array
{
    $project = (array) ($data['project'] ?? []);

    $data['cdm_duty_holders'] = [
        'client'               => trim((string) ($project['client'] ?? '')) ?: '[Client Name]',
        'principal_designer'   => '[To be confirmed]',
        'principal_contractor' => '[To be confirmed]',
        'contractor'           => '21st Century AV Ltd',
        'subcontractor'        => '21st Century AV Ltd',
        'project_manager'      => trim((string) ($project['project_manager'] ?? '')) ?: '[To be confirmed]',
        'site_supervisor'      => trim((string) ($project['lead_engineer'] ?? '')) ?: '[To be confirmed]',
        'cdm_regulation'       => 'Construction (Design and Management) Regulations 2015',
        'notification'         => 'F10 notification submitted by Principal Contractor where applicable',
    ];

    return $data;
}
```
RULE-07's fix targets the semantics this method assigns to `principal_designer`,
`principal_contractor` and the CONDITIONAL Contractor-row wording — the planner should NOT
simply replace `'[To be confirmed]'` with a static string; the skill's conditional
Contractor row (`standards-and-legislation.md:32-34`) requires the sole-vs-multiple-
contractor wording to be assembled here, likely as a new key or a restructured
`contractor`/`principal_contractor` value.

### An existing throwing gate to copy structurally (GATE-06/07)

```php
// Source: app/Services/Rams/RamsComplianceUpgradeService.php:1298-1360 (abridged)
private static function enforceFfp2AndConfinedSpaceGate(array $data): array
{
    $hazards = (array) ($data['hazards'] ?? []);

    foreach ($hazards as $hazard) {
        $hazard = (array) $hazard;
        $name = (string) ($hazard['hazard'] ?? '');
        $nameViolation = ControlTextRuleViolations::detect($name);

        if ($nameViolation === 'confined_space') {
            throw new RamsGenerationException(sprintf(
                'Hazard name "%s" is classified as a confined-space house-rule violation (GATE-07/RULE-06). '
                . 'Rename this hazard before regenerating, or set '
                . 'RAMS_PPE_CEILING_ELECTRICAL_GATE=false to disable this check.',
                $name,
            ));
        }
        // ... control-line loop, PPE array loop ...
    }

    return $data;
}
```
GATE-11/GATE-12 should follow this EXACT shape: unconditional loop, no
template-resolution branching, a message naming the offending value + the rule ID + the
disabling env var.

## State of the Art

Not applicable — no external library or framework versioning is involved in this phase.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | The measurement count for CDM placeholder rows is unknown — the example query in Finding 5 is untested against production data | Finding 5 | Low — it is explicitly flagged as a `checkpoint:human-action` task for the planner, not a hardcoded number used anywhere |
| A2 | Recommending "unconditional application" of RULE-07's wording (no occupied-premises gating) is a RECOMMENDATION, not a locked decision — CONTEXT.md leaves this genuinely open | Finding 6 | Medium — if the user actually wants occupied-premises-only gating, a new review-form signal must be built first, which is materially more work than this research assumed as the default path. The planner should surface this as an explicit checkpoint question rather than silently deciding. |
| A3 | `RamsController.php:697-701`'s catch block for the download/DOCX-rebuild path is looser (`catch (\Throwable $e)`) than the other two call sites — read only 15 lines around it, not the full handler | Pattern 3 | Low-Medium — worth the planner double-checking this third site's user-facing error message actually surfaces `RamsGenerationException`'s message text cleanly, not a generic 500 |

**If this table is empty:** N/A — see above.

## Open Questions

1. **Should GATE-11 and GATE-12 be one method or two?** CONTEXT.md leaves this to planner
   discretion ("subject to the `ControlTextRuleViolations` single-choke-point rule below" —
   but Finding 2 establishes that rule does not apply here since neither gate belongs in
   that class). Recommendation: two independent private static methods (`enforceCdmGate()`,
   `enforceEmergencyGate()`) called back-to-back under the SAME config flag
   (`cdm_ae_gate_enabled`) — mirrors how GATE-06 and GATE-07 are two distinct checks inside
   one shared `enforceFfp2AndConfinedSpaceGate()` method, which is the closest existing
   precedent for "two related but distinct rule IDs, one flag."
2. **Where exactly does the shared A&E resolver's output get stored** so both `upgrade()`'s
   gate and all 5 render sites read the identical value? See Architecture Patterns'
   "Recommended new file/method structure" — this needs a planner decision, not just a
   researcher recommendation, since it touches the composer/DTO boundary (Pitfall 4) as well
   as the raw-array legacy path.
3. **Does the third `RamsController` catch site (`:697-701`, download/DOCX-rebuild) surface
   `RamsGenerationException` messages as cleanly as the other two?** Flagged as Assumption A3
   — worth a quick planner-side read of the full method before committing to "no new
   surfacing code needed" as a blanket claim.

## Environment Availability

Skipped — no external tool/service dependencies for this phase (pure Laravel/PHP internal
code + a database migration against the existing `rams_documents` table).

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5.3 (via `php artisan test`) |
| Config file | `phpunit.xml` (repo root) — `Unit`/`Feature` suites; `snapshot` group excluded from default run |
| Quick run command | `php artisan test --filter=<TestClassName>` |
| Full suite command | `php artisan test` (excludes `@group snapshot`); `vendor/bin/phpunit --group snapshot` separately for snapshot fixtures |

### Phase Requirements → Test Map
| Req ID | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| RULE-07 | CDM default wording matches skill's anticipated-sole-contractor sentence, no forbidden statements | unit | `php artisan test --filter=CdmDutyHolderWordingTest` (new file, mirrors `Ffp2ConfinedSpaceGateTest`'s reflection-based private-method invocation pattern) | ❌ Wave 0 — new file needed |
| RULE-08 | A&E resolves to verified-name-with-address OR hold-point line, never the banned passive string, across all 5 render sites | feature/snapshot | `php artisan test --filter=SiteEmergencyResolverTest` (new unit test for the resolver) + `vendor/bin/phpunit --group snapshot` (regenerated `tilda-21cq29531` fixtures) | ❌ Wave 0 — new unit test needed; snapshot fixtures need regeneration via `php artisan rams:regenerate-snapshots tilda-21cq29531` |
| GATE-11 | Throws when CDM still carries placeholder after `upgrade()`, mirrors Ffp2ConfinedSpaceGateTest / DisplayLiftGateTest structure | unit | `php artisan test --filter=CdmEmergencyGateTest` (new file) | ❌ Wave 0 |
| GATE-12 | Throws on banned string / urgent-care keyword / named-with-no-address, clean on hold-point line and on verified name+address | unit | same new `CdmEmergencyGateTest` file, additional test methods | ❌ Wave 0 |
| GATE-11/12 dual-path (Save Review vs regeneration) | Mirrors `Ffp2ConfinedSpaceDualPathGateTest` / `Ffp2ConfinedSpaceSaveReviewGateTest` — both entry points must independently be gated | feature | `php artisan test --filter=CdmEmergencyDualPathGateTest` (new file) | ❌ Wave 0 |
| Backfill migration | Idempotent, placeholder-only, both JSON columns | feature | `php artisan test --filter=BackfillCdmDutyHolderMigrationTest` (mirrors `BackfillPpeFfp2AndElectricalExclusionBulletMigrationTest.php`) | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** `php artisan test --filter=<relevant class>`
- **Per wave merge:** `php artisan test` (fast suite) + `vendor/bin/phpunit --group snapshot` if any render-site change touched
- **Phase gate:** Full suite green (including snapshot group) before `/gsd:verify-work`, plus
  a live regeneration of 21CQ30960 per this project's standing "validate on live" practice
  (carried from Phases 26-28) — this is a manual/VPS step, not automatable from this repo.

### Wave 0 Gaps
- [ ] `tests/Unit/Services/Rams/CdmDutyHolderWordingTest.php` — covers RULE-07 (mirror
      `Ffp2ConfinedSpaceGateTest.php`'s reflection-based private-method-invocation pattern,
      `tests/Unit/Services/Rams/Ffp2ConfinedSpaceGateTest.php:58-64`)
- [ ] `tests/Unit/Services/Rams/SiteEmergencyResolverTest.php` (or equivalent name matching
      whatever class Open Question 2 resolves to) — covers RULE-08's branch logic in
      isolation from any render site
- [ ] `tests/Unit/Services/Rams/CdmEmergencyGateTest.php` — covers GATE-11 + GATE-12 throw/
      no-throw boundaries, mirroring `Ffp2ConfinedSpaceGateTest.php`'s "break-the-fix" non-
      vacuity proof procedure documented in its class docblock (`:22-51`) — worth repeating
      that discipline for a new safety gate
- [ ] `tests/Feature/Rams/CdmEmergencyDualPathGateTest.php` — mirrors
      `Ffp2ConfinedSpaceDualPathGateTest.php` (both `runFromReview()` and Save Review must be
      independently proven gated, per the "prove all generation entry points" established
      pattern from Phase 26/27)
- [ ] `database/migrations/..._backfill_cdm_duty_holder_placeholder.php` +
      `tests/Feature/Rams/BackfillCdmDutyHolderMigrationTest.php` — mirrors
      `BackfillPpeFfp2AndElectricalExclusionBulletMigrationTest.php`
- [ ] Fixture regeneration: `php artisan rams:regenerate-snapshots tilda-21cq29531` (or
      per-fixture arg) after the render-site fixes, to refresh
      `expected-html-v1.html:1278`, `expected-html-v2.html:1313`,
      `expected-docx-v1.xml.norm`, `expected-docx-v2.xml.norm` — confirm command exists per
      `tests/Feature/Rams/Snapshot/PdfSnapshotTest.php:38`

## Security Domain

Not applicable in the ASVS sense — this phase changes generated-document text content and a
gate over persisted JSON; it introduces no new auth/session/input-validation surface beyond
what `RamsController::updateAndDownload()` already validates (`site_emergency_nearest_hospital`
etc. at `:412`, existing `nullable|string|max:255` rules — unchanged by this phase). No new
ASVS category is triggered.

## Sources

### Primary (HIGH confidence — all file:line-verified in this repo during this session)
- `app/Services/Rams/RamsComplianceUpgradeService.php` (`:1-100`, `:1080-1220`, `:1298-1360`)
- `app/Services/Rams/ControlTextRuleViolations.php` (`:1-180`)
- `app/Http/Controllers/RamsController.php` (`:495-610`, `:685-705`, `:840-870`)
- `config/rams_tier1.php` (`:60-105`)
- `config/rams.php` (`:30-43`)
- `resources/views/pdf/rams.blade.php` (`:1940-1995`)
- `resources/views/pdf/rams-v2.blade.php` (`:2000-2115`)
- `app/Services/DocxBuilderService.php` (`:85-130`, `:1670-1725`, `:2125-2165`)
- `app/Support/Rams/SectionComposers/EmergencyComposer.php` (full file)
- `app/Support/Rams/SectionComposers/WelfareComposer.php` (full file)
- `app/Support/Rams/Sections/EmergencySectionDto.php` (full file)
- `app/Support/Rams/Sections/WelfareSectionDto.php` (full file)
- `app/Services/Rams/RamsDisplayPatchService.php` (`:395-435`)
- `database/migrations/2026_09_05_180000_backfill_ppe_ffp2_and_electrical_exclusion_bullet.php` (full file)
- `.planning/phases/28-ppe-ceiling-electrical-boundary-house-rules/28-07-MEASUREMENT.md` (full file)
- `.planning/phases/28-ppe-ceiling-electrical-boundary-house-rules/28-CONTEXT.md` (full file)
- `.planning/phases/29-cdm-duty-holder-emergency-arrangements/29-CONTEXT.md` (full file)
- `.planning/REQUIREMENTS.md` (full milestone v3.0 section)
- `.planning/reference/21cav-rams-skill/references/house-rules.md` (`:255-283`)
- `.planning/reference/21cav-rams-skill/references/standards-and-legislation.md` (full file)
- `app/Services/Rams/HazardIncludeWhenResolver.php` (`:90-135`)
- `database/seeders/HazardTemplateSeeder.php` (`:185-215`)
- `tests/Unit/Services/Rams/Ffp2ConfinedSpaceGateTest.php` (`:1-80`)
- `tests/Feature/Rams/Snapshot/PdfSnapshotTest.php` (`:1-70`)
- `phpunit.xml`, `.env`, `.env.example` (existence/content checks)

### Secondary (MEDIUM confidence)
- None — this research required no external documentation; entirely internal-codebase
  pattern verification.

### Tertiary (LOW confidence)
- None.

## Metadata

**Confidence breakdown:**
- Gate mechanics (shape to replicate): HIGH — copied from two live, production-proven
  precedents in the same file
- A&E render-site mapping: HIGH — all 5 sites directly grepped and read, including one
  (`rams-v2.blade.php:2066`) not named in CONTEXT.md's own D-01 table
- CDM precedence chain: HIGH — directly traced through all producers/consumers; the
  DOCX-never-reads-cdmRows finding is a new discovery this session, not previously recorded
- Occupied-premises signal existence: HIGH (confirmed absent) — but the RECOMMENDATION to
  apply RULE-07 unconditionally is a judgement call flagged as Assumption A2, not a locked
  fact
- Production `RAMS_UNIFIED_COMPOSER` state: LOW/UNKNOWN — explicitly could not be verified
  from this session; flagged for a VPS checkpoint
- Production CDM placeholder row count: UNKNOWN — query provided, not run; flagged as a
  measurement task

**Research date:** 2026-09-11
**Valid until:** No external dependency drift risk (internal codebase only) — but re-verify
the `RAMS_UNIFIED_COMPOSER` production state and row counts at implementation time regardless
of research date, since both are live/mutable state, not documentation.
