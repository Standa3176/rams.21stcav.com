# Phase 26: Hazard Library Structural Inversion - Research

**Researched:** 2026-08-23
**Domain:** Laravel/PHP RAMS (Risk Assessment & Method Statement) document generation — hazard data modelling, deterministic + AI-assisted content resolution, dual DOCX/PDF rendering
**Confidence:** HIGH (codebase-verified via direct file reads; no external library research required — this phase is pure application logic)

## Summary

Phase 26 replaces two hazard-injection mechanisms the ROADMAP names, plus three more the phase's own CONTEXT.md discovered, with a single conditional hazard library. All five paths currently read `config('rams_tier1.enabled')` (env `RAMS_TIER1_DEFAULTS`, default `true`) as a common gate, which is the existing, proven, all-or-nothing kill switch — three tests (`Tier1BaselineHazardsRenderTest`) already prove it flips cleanly between "inject the fixed 11" and "render nothing / legacy empty text." That test file's assertions directly contradict Phase 26's target behaviour and must be rewritten as part of this phase, not left as a silent regression.

The codebase already has the pieces this phase needs to assemble, not build from scratch: a `hazard_templates` table + model with `visibleTo()` global/personal scoping, an idempotent upsert-by-name seeder pattern, a fuzzy-match hazard resolver (`HazardLibraryService::resolveFromSeeds`), and a dual DOCX/PDF render pipeline that both compute `RA{NN}` refs by array position at generation time (not by any stored ID) — which, combined with the already-shipped GATE-03 fix (quick task 260817-r5e) that made RA-ID *citations* in the method statement also index-derived from the same array, means ref stability is not actually at risk **within one generation**, only **across regenerations after an engineer edits which hazards are included** (an existing, already-accepted characteristic of the system — RA-IDs are not stable identifiers across edits today either).

The single most consequential codebase finding for planning: the pre-generation review-and-edit screen (`resources/views/rams/quote-review.blade.php`) exposes hazard scoring as a **Low/Medium/High select**, not raw likelihood×severity numbers — `RamsBuilderService::riskLevelsFromString()` maps that string to `[preL, preS]` pairs (`High→[4,4]`, `Medium→[3,3]`, `Low→[2,2]`), and only overrides those with the library's numeric typical score when the freetext hazard name happens to fuzzy-match a `hazard_templates` row. HAZ-04 ("typical scores are editable defaults, never silently applied") therefore cannot be satisfied by editing `config/rams_tier1.php` alone — it requires either extending this specific form with real L×S inputs plus an unreviewed-marker, or an equivalent touch-point the planner must design (this is explicitly Claude's Discretion in CONTEXT.md, constrained only by "must not reach `generated_data` untouched").

**Primary recommendation:** Model the 18 hazards as `hazard_templates` rows (D-01) with a new nullable `include_when` column; resolve them at the point `RiskTemplateResolverService`/`RamsBuilderService::reviewedToRisk()` already builds the risk array, tier-gating the four `Always` hazards, deterministic keyword/tag matches, and an AI-judgement tier that plugs into the existing `HazardLibraryService::resolveFromSeeds()` fuzzy-match surface rather than adding a new AI call; gate all five injection-path removals behind a **new, narrower** env flag (not overloading `RAMS_TIER1_DEFAULTS`, since that flag also controls standards references and the unconditional COSHH baseline, which Phase 26 must not touch); and extend `quote-review.blade.php`'s hazard row with an editable score input plus a reviewed/unreviewed marker before any hazard row can reach `generated_data`.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| 18-hazard vocabulary (source of truth) | Database (`hazard_templates`) | Version-controlled seeder (git) | D-01 — DB is runtime store, seeder file is the git-auditable settled position |
| Include-when tier 1 (Always) | Backend service (`RiskTemplateResolverService` / `RamsBuilderService`) | — | Pure constant-set inclusion, no external signal needed |
| Include-when tier 2 (deterministic match) | Backend service | `form_data` / `reviewed_data` (data source) | Keyword/tag match against structured fields + scope narrative — same layer as existing `EquipmentClassifierService`/`RiskTemplateResolverService::ACCESS_EQUIPMENT_MAP` pattern |
| Include-when tier 3 (AI judgement) | AI prompt layer (`app/Core/AI/Prompts`) or `HazardLibraryService::resolveFromSeeds()` fuzzy-match | Backend service | Reuses the existing AI-extraction → fuzzy-match pipeline; no new AI call needed per CONTEXT.md's steer |
| Score defaults + "unreviewed" marker | Backend data shape (`reviewed_data['hazards'][*]`) | Review UI (`quote-review.blade.php`) | Score storage/marker is a data-model concern; visibility/editability is a UI concern — both owned by this phase (HAZ-04) |
| Render-time hazard consumption | PDF template (`rams.blade.php`) + DOCX builder (`DocxBuilderService`) | Legacy fallback removal | Both must stop reading `config('rams_tier1.baseline_hazards')`; both currently compute RA refs by array position, independently |
| Seeder / migration | Database migration + seeder (Laravel) | — | Standard Laravel data-layer concern; D-03 upsert-by-name is the established pattern (`HazardTemplateSeeder`) |
| Kill-switch / env gate | Config (`config/rams_tier1.php` or a new file) + `.env` | — | Must remain a config-driven runtime toggle per the live-validation constraint (Claude's Discretion) |

## User Constraints (from CONTEXT.md)

<user_constraints>

### Locked Decisions

**Hazard source of truth**
- **D-01:** The 18 hazards live in a **version-controlled seeder/reference file; the DB is the runtime store**. `hazard_templates` gains an `include_when` column and is reseeded from that file. Git holds the settled 21CAV positions and stays auditable against `hazard-library.md`; `HazardTemplateController`, the RAMS review form and the AI resolver keep reading the DB and need no rewiring. `config/rams_tier1.php` drops out of the hazard business entirely.
- **D-02:** The app's hazards that are absent from the skill's 18 — *Struck by Falling Objects*, *Hidden Services (Electrical, Plumbing, Gas)*, *Sharps & Hand / Power Tools*, *Display Installation / Wall Mounting*, *Fixings / Substrate Failure*, *Interaction with Other Trades* — are **folded into the nearest skill hazard**, not kept alongside it. Suggested mapping (planner may refine against the source text): Hidden Services + Fixings / Substrate Failure → *Fixings into walls, ceilings and pillars*; Display Installation / Wall Mounting → *Manual handling*; Struck by Falling Objects → *Working at height*; Sharps & Hand / Power Tools → *Cable pulling and termination* or *Dust from drilling and cutting*; Interaction with Other Trades → *Occupied premises*. Control wording worth keeping is merged into the surviving hazard's control list. The 18 are the register vocabulary; no parallel list survives.
- **D-03:** Reseed **replaces the global rows and leaves user rows alone**. The 13 `is_global=true` rows from `HazardTemplateSeeder` are superseded by the 18; rows with `is_global=false` (user-created, owned via `user_id`) are untouched. The seeder **upserts by name** so it is safely re-runnable on live without duplicating. No truncate — a wipe would destroy engineer-created hazards unrecoverably.
- **D-04:** `include_when` is **nullable, and null means manual-only**. A user-created custom hazard never auto-populates; it appears only when an engineer explicitly picks it. No new authoring UI this phase — that keeps non-settled conditions out of the auto-population path and cannot re-create the padding problem.

**Include-when evaluation**
- **D-05:** **Hybrid evaluation**, in three tiers:
  1. **Always** — the 4 `Include when: Always` hazards are included unconditionally.
  2. **Deterministic match** — clean triggers resolve by keyword/tag match against the scope narrative and the existing structured form fields: drilling / percussive tools, strip-out or decommission, ceiling void or riser, mains connection or disconnection, first-fix cabling, any penetration, any display/mount/rack, mounting above standing reach.
  3. **AI judgement** — conditions needing reading rather than matching go to the AI pass that already runs during generation: building pre-2000 or age unknown (asbestos), fewer than 3 operatives or split areas (lone working), site outside normal travel radius (occupational road risk), warehouse/workshop/yard/loading bay (vehicle and plant movement), live building with staff present (occupied premises).

  Deterministic wherever it can be, model only where it must be. Tier assignment per hazard is a planning decision; the tiering rule itself is locked.
- **D-06:** When a condition **cannot be evaluated** because the data isn't captured, the hazard is **included and visibly flagged as needing confirmation** — not silently dropped. Safety-critical default: an engineer removing a surplus hazard is a better failure than one never seeing a hazard that applied. The skill points the same way — asbestos is *"Any building pre-2000, **or age unknown**"*. Flagged-for-confirmation hazards must be distinguishable on the review screen from confidently-matched ones.

### Claude's Discretion

- **Score-default touch-point (HAZ-04).** The requirement is absolute — *"Do not let the app apply the typical scores silently."* Typical L×S must be visibly pre-filled and editable, and must not reach `generated_data` untouched. Suggested default (planner may choose otherwise if it satisfies HAZ-04): carry the typical score as a default on the hazard row plus a marker recording that it is un-reviewed, surface it pre-filled on the review screen, and treat a human or model touch as what promotes it to a committed score. **Constraint:** Working at Height must render residual `1×4`, not `2×3` (`config/rams_tier1.php:67-68`).
- **Removal strategy for the five injection paths.** All five must stop injecting the fixed 11. **Strong steer:** gate the new behaviour behind an env flag rather than deleting outright — `config('rams_tier1.enabled') = env('RAMS_TIER1_DEFAULTS', true)` already gates all five and is the established precedent (see also `RAMS_UNIFIED_COMPOSER`). Phase 26 is being validated **on live against production data**, so a bad hazard set must be one `.env` edit from off, not a git revert plus redeploy. Note the existing flag is all-or-nothing across hazards, standards *and* COSHH — a new, narrower flag may be cleaner than overloading it.
- **Empty/near-empty register render behaviour.** What Section 5 renders when only the 4 `Always` hazards match. Must not fall back to the old baseline.

### Deferred Ideas (OUT OF SCOPE)

- **Include-when authoring UI for custom hazards** — D-04 makes user-created hazards manual-only with a null condition. Letting engineers author their own include-when conditions is a separate capability; revisit only if the null-means-manual default proves limiting.
- **Structured job-scope capture** (building age, operative count, site type, strip-out flag) — would let D-05 tier 3 collapse into tier 2, making the whole register deterministic and reproducible and GATE-05 trivial. Rejected here as review-form scope creep; a strong candidate for its own phase if AI tiering proves unreliable.
- **Narrowing `RAMS_TIER1_DEFAULTS`** — the existing flag gates hazards, standards references *and* the COSHH baseline together. Splitting it per-concern is tidy-up, not Phase 26 work.

</user_constraints>

<phase_requirements>
## Phase Requirements

| ID | Description | Research Support |
|----|-------------|------------------|
| HAZ-01 | Port the 8 hazards present in the skill and absent from the app | `hazard-library.md` is the full 18-hazard source (read verbatim below); `HazardTemplateSeeder` is the existing upsert-by-name pattern to extend/replace; D-02's folding map tells the planner which of the app's current 13 seeded + 11 config-baseline names collapse into which of the 18 |
| HAZ-02 | Each hazard carries an include-when condition; register starts empty and is added to | All 5 injection paths identified and code-verified below with exact line numbers; `include_when` column design (D-04 nullable = manual-only) fits the existing `hazard_templates` schema without breaking `HazardTemplateController`/`HazardLibraryService::forUser()`/`forUserJson()` |
| HAZ-03 | Align scores to the skill's typical values, incl. Working at Height residual 1×4 | Full typical-score table extracted from `hazard-library.md` below; `config/rams_tier1.php:67-68`'s current wrong Working-at-Height residual (`2×3`, i.e. post_likelihood=2/post_severity=3) identified as the value to replace with `1×4` |
| HAZ-04 | Typical scores are editable defaults, never silently applied | Found that `quote-review.blade.php`'s hazard row UI is currently Low/Medium/High only (no L×S numeric inputs) — this is the actual touch-point gap the planner must close; `RamsBuilderService::riskLevelsFromString()` and `reviewedToRisk()` are the code paths that currently convert that string to numbers and must be extended to carry a reviewed/unreviewed marker |

</phase_requirements>

## Standard Stack

Not applicable in the conventional sense — this phase is pure application-layer data modelling and service logic within the existing Laravel app. No new third-party packages are required. See **Package Legitimacy Audit** below (empty — no packages to vet).

### Core (existing components this phase extends)

| Component | Location | Purpose | Why it's the right extension point |
|---|---|---|---|
| `hazard_templates` table + `HazardTemplate` model | `database/migrations/2026_03_09_000001_create_hazard_templates_table.php`, `app/Models/HazardTemplate.php` | Engineer-facing hazard library, `visibleTo()` global/personal scoping | D-01 names this as the runtime store; already has `pre_likelihood/pre_severity/post_likelihood/post_severity/controls/is_global` — only `include_when` is new |
| `HazardTemplateSeeder` | `database/seeders/HazardTemplateSeeder.php` | Seeds 13 global hazard rows, idempotent by name | Already upserts by `where('is_global', true)->where('name', ...)` — exactly the D-03 pattern, needs its hazard list replaced with the 18 |
| `HazardLibraryService` | `app/Core/Modules/KnowledgeLibrary/HazardLibraryService.php` | Fuzzy-matches AI/free-text hazard names to library rows; the only place `MANDATORY_KEYWORDS`/`mandatoryBaseline()`/`mergeWithMandatory()` live | Injection path #5 (see below) lives entirely inside this one class — `MANDATORY_KEYWORDS` (`:36-44`) and its two consumers must be removed, not worked around |
| `RiskTemplateResolverService` | `app/Services/RiskTemplateResolverService.php` | Builds hazard/PPE/access-equipment rows from activities + explicit hazard names; also has a fully deterministic `resolveFromProjectContext()` variant | `buildHazards()`'s `includeMandatory = empty($names)` is the actual trigger condition for path #5 in the auto/quote-upload pipeline — verify this, don't assume "always" literally |
| `Tier1RamsDefaultsService` | `app/Services/Rams/Tier1RamsDefaultsService.php` | Injection path #1 — injects `config('rams_tier1.baseline_hazards')` into `$data['hazards']` when empty | Also injects `standards_references` and (unconditionally) `coshh_baseline` — do not touch those two in this phase; only the hazards line (`:69-71`) |
| `RiskAssessmentComposer` | `app/Support/Rams/SectionComposers/RiskAssessmentComposer.php` | Injection path #4 (composer-path last resort) — **not currently live** (only used if `RAMS_UNIFIED_COMPOSER=true`, default `false`) | Already reads `$h['ref'] ?? ('RA' . str_pad($idx+1, ...))` — i.e. it already supports a stable, explicit stored `ref` if one is present; useful if the planner wants to stabilise refs going forward |
| `DocxBuilderService::buildRiskAssessment()` | `app/Services/DocxBuilderService.php:~1108-1230` | The **actually-live document generator** (DOCX is the primary output; `PdfService` only invokes `rams.blade.php`/`rams-v2.blade.php` for the parallel PDF) | Computes RA refs by raw array position (`:1230`, `'RA' . str_pad($idx+1, 2, '0', STR_PAD_LEFT)`), reads `$data['hazards']` directly — no defensive baseline-inject fallback found in this file (only in the Blade templates and `Tier1RamsDefaultsService`) |
| `resources/views/pdf/rams.blade.php` | `:315-317` | Injection path #2 — render-time fallback, **the live PDF template** (`RAMS_UNIFIED_COMPOSER` defaults `false` per `config/rams.php:43`) | `if (empty($hazards) && config('rams_tier1.enabled', true)) { $hazards = (array) config('rams_tier1.baseline_hazards', []); }` |
| `resources/views/pdf/rams-v2.blade.php` | `:371-372` (per CONTEXT.md citation) | Injection path #3 — render-time fallback, **not live** (gated behind `RAMS_UNIFIED_COMPOSER`) | Same pattern as path #2, mirrored for the composer pipeline |

### Alternatives Considered

| Instead of | Could use | Tradeoff |
|---|---|---|
| Extending `hazard_templates` with `include_when` | A brand-new `hazard_library` table decoupled from the engineer-facing template table | Rejected by D-01 explicitly — would require rewiring `HazardTemplateController`, the create-form checkbox list, and the AI resolver for no benefit; the existing table already has everything except one column |
| Overloading `RAMS_TIER1_DEFAULTS` for the new kill switch | A new, narrower env flag (e.g. `RAMS_HAZARD_LIBRARY_V2` or similar) | CONTEXT.md flags this as open (Claude's Discretion) — the existing flag also gates standards references and the always-on COSHH baseline, which are explicitly out of scope for this phase; reusing it risks an operator disabling COSHH/standards by accident when they only meant to roll back hazards |
| New AI call for tier-3 include-when judgement | Extend `HazardLibraryService::resolveFromSeeds()`'s existing fuzzy-match, or feed tier-3 trigger phrases into the method-statement-generation AI call that already runs | CONTEXT.md explicitly steers toward reuse — `resolveFromSeeds()` is consumed by both `RamsBuilderService:410` and `RiskTemplateResolverService:193` already, so tier-3 hazards can ride the same seed-string mechanism the AI extraction already produces |

**Installation:** None — no new packages.

**Version verification:** N/A — no third-party dependency changes in this phase.

## Package Legitimacy Audit

Not applicable — this phase introduces zero new external packages (Composer or otherwise). All work is against the existing Laravel/PHP application code, a schema migration, and a data seeder.

## Architecture Patterns

### System Architecture Diagram

```
                         ┌─────────────────────────────┐
                         │  hazard_templates (DB)       │
                         │  18 rows (is_global=true) +  │
                         │  N user rows (is_global=false)│
                         │  NEW: include_when column     │
                         └───────────────┬───────────────┘
                                         │ read by
                 ┌───────────────────────┼───────────────────────┐
                 │                       │                       │
   HazardTemplateController      HazardLibraryService     RiskTemplateResolverService
   (CRUD, unchanged D-01)        ::resolveFromSeeds()      ::buildHazards() /
                 │                (fuzzy-match, tier 3      ::resolveFromProjectContext()
                 │                 AI-seed plug-in point)   (tier 2 deterministic)
                 │                       │                       │
                 ▼                       ▼                       ▼
        rams/create.blade.php   RamsBuilderService::reviewedToRisk()
        (manual checkbox list,   / runPipeline() — resolves the           NEW: tier-1/2/3
         unchanged)              final hazard set BEFORE the AI          include-when
                                  method-statement call reads it          resolution engine
                                         │                                lives HERE
                                         ▼
                          reviewed_data['hazards'] / generated_data['hazards']
                          (denormalised array — hazard/controls/score/ref-marker)
                                         │
                    ┌────────────────────┼────────────────────┐
                    ▼                    ▼                    ▼
        DocxBuilderService      rams.blade.php (LIVE PDF)   rams-v2.blade.php
        ::buildRiskAssessment()  RA refs by array index      (NOT live, gated
        (LIVE DOCX, RA refs      — REMOVE injection          RAMS_UNIFIED_COMPOSER)
         by array index)         fallback :315-317            REMOVE injection :371-372
        — no fallback found
          in this file itself
```

The primary use-case trace: a job's scope (structured `form_data` fields + free-text scope narrative + AI-extracted activity/equipment classification) flows into the NEW include-when resolution engine (replacing today's `mandatoryBaseline()`/`Tier1RamsDefaultsService` fallback logic) which emits a hazard array with tier markers; that array is what both live renderers (`DocxBuilderService`, `rams.blade.php`) consume directly, with no render-time fallback remaining once paths #2/#3 are removed.

### Recommended Project Structure

No new directories needed. Touch points, in dependency order:

```
database/
├── migrations/
│   └── 2026_08_2X_XXXXXX_add_include_when_to_hazard_templates.php   # NEW
└── seeders/
    └── HazardTemplateSeeder.php                                     # REWRITE — 18-hazard list + include_when values

app/Core/Modules/KnowledgeLibrary/
└── HazardLibraryService.php          # REMOVE MANDATORY_KEYWORDS + mandatoryBaseline() + mergeWithMandatory();
                                       # ADD include-when resolution entry point (tier 1/2 deterministic;
                                       # tier 3 plugs into existing resolveFromSeeds())

app/Services/
├── RiskTemplateResolverService.php   # buildHazards()/resolveHazards() — replace "empty($names) → mandatory
│                                      # baseline" fallback with the new tiered resolver
└── RamsBuilderService.php            # reviewedToRisk() — the ACTUAL live path for the review→generate flow;
                                       # this is where tier-1/2 signals from reviewed_data must be read

app/Services/Rams/
└── Tier1RamsDefaultsService.php      # REMOVE the hazards branch (:69-71) only; leave standards_references
                                       # and coshh_baseline branches untouched

app/Support/Rams/SectionComposers/
└── RiskAssessmentComposer.php        # REMOVE the config('rams_tier1.baseline_hazards') fallback (:36-38)
                                       # — low priority, not live, but must not silently resurrect the 11
                                       # if RAMS_UNIFIED_COMPOSER is ever flipped on mid-milestone

resources/views/pdf/
├── rams.blade.php                    # REMOVE :315-317 fallback — THE LIVE TEMPLATE
└── rams-v2.blade.php                 # REMOVE :371-372 fallback — not live, fix anyway for parity

resources/views/rams/
└── quote-review.blade.php            # EXTEND hazard row — add L×S numeric inputs (or equivalent) +
                                       # unreviewed-marker UI (HAZ-04); add D-06 confirmation-flag UI

config/
└── rams_tier1.php                    # baseline_hazards array REMOVED entirely (D-01: "config/rams_tier1.php
                                       # drops out of the hazard business entirely"); standards_references
                                       # and coshh_products stay — those are Phase 31 scope, not this phase
```

### Pattern 1: Tiered include-when resolution (new)

**What:** A resolver that, given the job's captured signals (form_data fields, scope narrative, activity classification), evaluates each of the 18 hazard rows' `include_when` against three tiers and returns the matching subset, each tagged with how it matched (always / deterministic / ai / unconfirmed).

**When to use:** At the exact point `RiskTemplateResolverService::buildHazards()` and `RamsBuilderService::reviewedToRisk()` currently call `HazardLibraryService::resolveFromSeeds()`/`mergeWithMandatory()` — i.e., before the method-statement AI call, so the final register is fixed before anything cites `RA{NN}` refs against it (this ordering already exists in the codebase and must be preserved, not re-engineered).

**Example (illustrative shape, not literal code — the planner designs the exact resolver):**
```php
// Source: derived from app/Services/RiskTemplateResolverService.php buildHazards()
// and app/Core/Modules/KnowledgeLibrary/HazardLibraryService.php resolveFromSeeds()
// existing patterns — this is the NEW shape, not existing code.
class HazardIncludeWhenResolver
{
    public function resolve(Collection $library, array $signals): Collection
    {
        return $library->filter(function (HazardTemplate $h) use ($signals) {
            return match ($h->include_when_tier) {
                'always'        => true,
                'deterministic' => $this->deterministicMatch($h->include_when, $signals),
                'ai'            => null, // resolved separately via AI-seed fuzzy match
                default         => false, // null include_when = manual-only (D-04)
            };
        });
    }
}
```

### Pattern 2: Score default + unreviewed marker (HAZ-04)

**What:** Every hazard row entering `reviewed_data['hazards']` carries the library's typical `pre_likelihood`/`pre_severity`/`post_likelihood`/`post_severity` as a **default** plus a boolean/timestamp marker (e.g. `score_reviewed: false`) that a human edit or explicit model confirmation flips.

**When to use:** Anywhere a hazard row is first materialised into `reviewed_data` — currently that happens in `RamsExtractionDraftBuilderService` (Phase A extraction, feeding `extracted_data`) and is then copied/edited into `reviewed_data` via `quote-review.blade.php`'s form submit. The marker must survive `RamsReviewDataService::normaliseHazards()` (`:155-172`), which today only knows the shape `{activity_key, hazard, risk, control_measures}` — this normaliser needs its schema extended, matching the established pattern the phase's own CONTEXT.md flags ("dual key vocabulary" — `initial_l`/`pre_likelihood` etc.).

**Why this matters:** Without a marker, there is no way to distinguish "the engineer looked at this and it happens to still read 3×4" from "the app filled in 3×4 and nobody has looked yet" — which is exactly the failure mode HAZ-04 exists to prevent.

### Anti-Patterns to Avoid

- **Overloading `RAMS_TIER1_DEFAULTS` for the new kill switch:** it also gates standards references and the unconditional COSHH baseline (`Tier1RamsDefaultsService:82`, always-set regardless of flag state per that method's own docblock — re-verify, the `enabled` check wraps the whole method so COSHH IS actually gated too, contrary to its own inline comment; either way, disabling it disables three unrelated things at once).
- **Adding a new AI call for tier-3 judgement:** CONTEXT.md and the existing architecture both point at reusing `resolveFromSeeds()`'s fuzzy-match against AI-extracted seed strings, or extending the method-statement-generation prompt's existing context — not standing up a parallel AI round-trip.
- **Truncating and re-seeding `hazard_templates`:** D-03 is explicit — upsert by name on `is_global=true` rows only, never delete/truncate (would destroy user-created `is_global=false` rows irrecoverably; there is no cascade-safe way to know which `is_global=false` rows are "safe" to also touch).
- **Treating `RiskAssessmentComposer` as the live ref-assignment code:** it is not live (`RAMS_UNIFIED_COMPOSER` defaults `false`). The actual live RA-ref computation is duplicated independently in `DocxBuilderService::buildRiskAssessment()` and `rams.blade.php` — both must be checked/fixed, not just the composer.
- **Assuming `HazardLibraryService::MANDATORY_KEYWORDS` fires "regardless of engineer selection" in the literal sense stated in ROADMAP.md/REQUIREMENTS.md:** verified in code, it fires specifically when `RiskTemplateResolverService::buildHazards()` is called with `$hazardNames` empty (`includeMandatory = empty($names)`) — i.e. it is a fallback-for-empty-selection behaviour in the `runPipeline()`/`buildFromForm()`/`buildFromQuote()` code path, not a literal unconditional merge on every call. The **actual** live review→generate path (`RamsBuilderService::reviewedToRisk()`, used by `buildFromReview()`) calls `resolveFromSeeds($userId, [$name], false)` — `includeMandatory` explicitly `false` — so it does NOT currently invoke the mandatory-baseline merge at all. **This changes what "removal" means for path #5:** the planner must remove `MANDATORY_KEYWORDS`/`mandatoryBaseline()`/`mergeWithMandatory()` from `HazardLibraryService` (they are dead weight once removed, since no remaining live caller should depend on them), and separately replace the `RiskTemplateResolverService::buildHazards()` "empty names → mandatory baseline" behaviour with the new tiered resolver — these are two related but distinct edits, not one.

## Don't Hand-Roll

| Problem | Don't build | Use instead | Why |
|---|---|---|---|
| Fuzzy-matching AI-extracted hazard seed strings to library rows | A new matcher | `HazardLibraryService::fuzzyMatch()` (`:285-318`) — exact → substring → shared-word-count strategy already implemented and tested | Already handles the seed→template resolution this phase's tier-3 AI judgement needs to plug into |
| Idempotent global-row reseeding | A new upsert helper | `HazardTemplateSeeder`'s existing `where('is_global', true)->where('name', ...)->first()` + `update()`/`create()` pattern | Already proven safe to re-run on live (per its own docblock: "Safe to run multiple times") |
| Env-gated feature rollback for a production system under live validation | A feature-flag package | Plain `config()` + `env()`, following the exact `RAMS_TIER1_DEFAULTS` / `RAMS_UNIFIED_COMPOSER` precedent already in this codebase | Both existing flags are checked "at every render — no build-time constant, no container binding to invalidate" (per `PdfService.php:44-47`'s own docblock) — this is a deliberate, documented pattern for exactly this migration's risk profile |

**Key insight:** Nothing in this phase requires new infrastructure. Every mechanism it needs (conditional data resolution, safe reseeding, env-gated rollback) already exists elsewhere in this codebase in a directly analogous form. The work is almost entirely: (1) writing the 18-hazard content correctly, (2) wiring the *tier* logic into the existing resolution call sites, and (3) closing the score-editability gap in the review UI.

## Common Pitfalls

### Pitfall 1: Fixing only the two ROADMAP-named paths and missing the other three
**What goes wrong:** `Tier1RamsDefaultsService` (path #1) and `HazardLibraryService::MANDATORY_KEYWORDS` (path #5) are the two named in REQUIREMENTS.md/ROADMAP.md. But `rams.blade.php:315-317` (the **live PDF template**) independently re-injects the same `config('rams_tier1.baseline_hazards')` array at render time whenever `$hazards` is empty — completely bypassing whatever the generation-time services decided.
**Why it happens:** The render-time fallback was added defensively in quick task 260712-twi specifically to catch legacy records — it is easy to forget it exists because it's a Blade `@php` block, not a service class.
**How to avoid:** Grep for `rams_tier1.baseline_hazards` across the whole repo (not just `app/`) before considering the removal complete; confirm zero references remain, or that any remaining reference is intentionally load-bearing for a *different* config key (standards/COSHH) that is out of scope.
**Warning signs:** A regenerated RAMS with an intentionally near-empty hazard set (only the 4 Always hazards matched) still shows old baseline hazard names like "Manual Handling of AV Equipment" (the config-baseline wording) rather than "Manual handling" (the skill's wording) — that specific string difference is a reliable canary since the two vocabularies never overlap exactly.

### Pitfall 2: Breaking `Tier1BaselineHazardsRenderTest`'s three tests without replacing their intent
**What goes wrong:** `tests/Feature/Rams/Tier1BaselineHazardsRenderTest.php` currently asserts (1) empty hazards + flag on → baseline injects "Working at Height"/"Manual Handling of AV Equipment"/"Electrical Isolation"; (2) engineer-supplied hazards render verbatim and baseline is NOT injected; (3) flag off → "No hazards identified." renders, no baseline titles. Test (1) is a direct contradiction of Phase 26's goal and WILL fail once the fallback is removed — if left unfixed, it either blocks CI or gets silently skipped, both bad outcomes.
**Why it happens:** This test file was written to prove exactly the behaviour Phase 26 exists to remove.
**How to avoid:** Rewrite test (1) to assert the OPPOSITE — empty/near-empty structured signals → only the 4 Always hazards appear, config-baseline titles do NOT appear. Test (3) already demonstrates the exact target end-state for a fully-gated-off scenario and can likely be reused with minimal changes as a "flag off" regression guard for the new kill switch.
**Warning signs:** Full test suite goes from N passing to N-1 passing with a hazard-related failure — do not "fix" by deleting the assertion; rewrite it to assert the new contract.

### Pitfall 3: Treating `quote-review.blade.php`'s Low/Medium/High select as already satisfying HAZ-04
**What goes wrong:** It's tempting to conclude the review screen already has "editable defaults" because there's a `<select>` for risk level. But that select only ever produces one of three coarse buckets (`riskLevelsFromString`: High→[4,4], Medium→[3,3], Low→[2,2]) — it cannot represent the skill's actual typical scores (e.g. Working at Height `3×4`, Electrical `2×5`), and critically it provides no mechanism to distinguish "pre-filled default, unreviewed" from "engineer explicitly chose Medium."
**Why it happens:** The Low/Medium/High UI predates the skill-parity requirement; it was designed for the old 11-hazard config-baseline world where exact L×S values weren't user-facing.
**How to avoid:** Treat HAZ-04 as requiring UI work in this specific view, not just a data-layer change. Confirm with the planner whether the fix is (a) replacing the select with real L×S number inputs, or (b) keeping Low/Medium/High but adding a distinct "reviewed" checkbox/badge plus mapping the library's typical numeric score onto the nearest bucket for display — CONTEXT.md leaves the exact mechanism to Claude's Discretion, but the review form itself must change either way.
**Warning signs:** A plan that only touches `hazard_templates`/`HazardLibraryService`/`Tier1RamsDefaultsService` and never touches `quote-review.blade.php` or `RamsReviewDataService::normaliseHazards()` has not actually satisfied HAZ-04.

### Pitfall 4: Assuming `RiskAssessmentComposer` is where RA-ref stability logic lives
**What goes wrong:** `RiskAssessmentComposer` (`app/Support/Rams/SectionComposers/RiskAssessmentComposer.php`) looks like the natural place to reason about RA-ref stability — it even has a docblock explaining the 1-indexed scheme. But it is gated behind `RAMS_UNIFIED_COMPOSER` (default `false`) and is **not** the live code path. The actually-live ref computation is duplicated independently inside `DocxBuilderService::buildRiskAssessment()` (`:1230`, live DOCX) and `rams.blade.php` (live PDF) — both compute `'RA' . str_pad($idx+1, 2, '0', STR_PAD_LEFT)` inline from raw array position, with **no** `$h['ref']` override support (unlike the composer, which does support one).
**Why it happens:** The composer is clearly the "nicer," more recent code, so it reads as authoritative — but the migration to it (`RAMS_UNIFIED_COMPOSER=true`) has not happened in production.
**How to avoid:** Any change affecting hazard-array ordering/length must be verified against `DocxBuilderService::buildRiskAssessment()` and `rams.blade.php` directly, not just the composer. If the planner wants explicit stable refs going forward (optional, not required by this phase's success criteria), the composer already supports it but the two live renderers would need the same `$h['ref'] ?? computed` fallback added — that is additive scope beyond HAZ-01..04, flag it as a discretionary nice-to-have, not a requirement.
**Warning signs:** A plan or test that asserts "`RiskAssessmentComposer` output changes → live RAMS output changes" without confirming `RAMS_UNIFIED_COMPOSER=true` in the target environment.

### Pitfall 5: `RamsComplianceUpgradeService::fillMissingHazardControls()` silently duplicating or contradicting ported control text
**What goes wrong:** After hazard resolution, `RamsComplianceUpgradeService::upgrade()` runs `fillMissingHazardControls()` (`:523+`), which injects its OWN keyword-matched default control-measure bullets (e.g. a `'height'` keyword match injects 5 access-equipment bullets) into any hazard row with `empty($h['controls'])`. If the new include-when-resolved hazard rows arrive with `controls` already populated from the ported `hazard-library.md` text (which they should, per HAZ-01), this method should no-op for them — but it is worth an explicit check that it does not run BEFORE the new resolver populates `controls`, and that its own keyword vocabulary (still referencing the OLD hazard names/wording) doesn't produce a mismatch or duplicate bullet set for the newly-worded hazards.
**Why it happens:** This service runs unconditionally in the pipeline (`upgrade():29`) regardless of where the hazard rows came from; it was written against the old 11-hazard vocabulary and has its own independent keyword-matching logic that this phase does not otherwise touch.
**How to avoid:** Verify `controls` is non-empty for every include-when-matched hazard row by the time `RamsComplianceUpgradeService::upgrade()` runs (it should be, since the ported library rows carry full control lists per `hazard-library.md`), and spot-check `fillMissingHazardControls()`'s keyword list (`'height'`, `'manual handling'`, etc.) against the skill's exact hazard titles (e.g. "Working at height" lowercase vs the old "Working at Height") to confirm the keyword-substring match still behaves as a no-op, not an accidental double-fire.
**Warning signs:** A generated RAMS shows two overlapping sets of control bullets for the same hazard (one from the ported library, one from `fillMissingHazardControls()`'s fallback list) — this would be a visible content-quality regression, not a crash, so it needs a targeted assertion, not just "the pipeline didn't throw."

## Code Examples

Verified patterns from the actual codebase (all read directly, 2026-08-23):

### Existing idempotent global-row upsert pattern (to extend, not replace)
```php
// Source: database/seeders/HazardTemplateSeeder.php:22-39
foreach ($hazards as $hazard) {
    // Idempotent: update if a global template with this name already exists
    $existing = HazardTemplate::where('is_global', true)
        ->where('name', $hazard['name'])
        ->first();

    $payload = array_merge($hazard, [
        'user_id'   => null,   // null = global
        'is_global' => true,
    ]);

    if ($existing) {
        $existing->update($payload);
        continue;
    }

    HazardTemplate::create($payload);
}
```

### Existing fuzzy-match resolver (the tier-3 AI-judgement plug-in point)
```php
// Source: app/Core/Modules/KnowledgeLibrary/HazardLibraryService.php:77-113
public function resolveFromSeeds(int $userId, array $seeds, bool $includeMandatory = true): Collection
{
    $library = HazardTemplate::visibleTo($userId)->get();
    $matched = collect();
    $unmatched = [];

    foreach ($seeds as $seed) {
        $template = $this->fuzzyMatch($seed, $library);
        if ($template !== null) {
            if (! $matched->contains('id', $template->id)) {
                $matched->push($template);
            }
        } else {
            $unmatched[] = $seed;
        }
    }
    // ... mandatory-merge logic to be REMOVED per this phase
}
```

### The three render-time / build-time injection points to remove (verbatim)
```php
// Source: app/Services/Rams/Tier1RamsDefaultsService.php:69-71 — PATH #1
if (empty($data['hazards']) || ! is_array($data['hazards'])) {
    $data['hazards'] = (array) config('rams_tier1.baseline_hazards', []);
}
```
```blade
{{-- Source: resources/views/pdf/rams.blade.php:315-317 — PATH #2, THE LIVE PDF TEMPLATE --}}
@php
if (empty($hazards) && config('rams_tier1.enabled', true)) {
    $hazards = (array) config('rams_tier1.baseline_hazards', []);
}
@endphp
```
```php
// Source: app/Core/Modules/KnowledgeLibrary/HazardLibraryService.php:36-44, 195-249, 254-273 — PATH #5
private const MANDATORY_KEYWORDS = [
    'working at height', 'manual handling', 'electrical',
    'slips, trips and falls', 'noise and vibration',
    'working in occupied premises', 'confined spaces',
];
// mandatoryBaseline() and mergeWithMandatory() consume this constant.
// Trigger condition (verified): RiskTemplateResolverService::buildHazards()
// calls resolveFromSeeds() with includeMandatory = empty($hazardNames) —
// i.e. this fires when the caller supplies NO explicit hazard names, not
// unconditionally on every call.
```

### The dual RA-ref computation (both must be kept in sync if refs change)
```php
// Source: app/Services/DocxBuilderService.php:~1222-1230 — LIVE DOCX
foreach ($data['hazards'] ?? [] as $idx => $hazard) {
    // ...
    $refLabel = 'RA' . str_pad((string)($idx + 1), 2, '0', STR_PAD_LEFT);
}
```
```php
// Source: app/Support/Rams/SectionComposers/RiskAssessmentComposer.php:49 — NOT LIVE, but supports explicit ref
'ref' => (string) ($h['ref'] ?? ('RA' . str_pad((string) ($idx + 1), 2, '0', STR_PAD_LEFT))),
```

### The review-form hazard row shape that must be extended for HAZ-04
```blade
{{-- Source: resources/views/rams/quote-review.blade.php:775-807 --}}
@foreach ($reviewPayload['hazards'] as $i => $hazard)
    <input type="text" name="hazards[{{ $i }}][activity_key]" value="{{ old("hazards.{$i}.activity_key", $hazard['activity_key']) }}">
    <input type="text" name="hazards[{{ $i }}][hazard]" value="{{ old("hazards.{$i}.hazard", $hazard['hazard']) }}">
    <select name="hazards[{{ $i }}][risk]">
        {{-- Low / Medium / High only — NO numeric L×S input exists today --}}
    </select>
    <textarea name="hazards[{{ $i }}][control_measures]">{{ old("hazards.{$i}.control_measures", implode("\n", $hazard['control_measures'])) }}</textarea>
@endforeach
```
```php
// Source: app/Services/RamsBuilderService.php:655-661 — the Low/Medium/High → L×S mapping this phase must extend
private function riskLevelsFromString(string $risk): array
{
    return match (strtolower(trim($risk))) {
        'high'   => [4, 4],
        'low'    => [2, 2],
        default  => [3, 3],
    };
}
```

## State of the Art

| Old Approach | New Approach (per this phase) | Where | Impact |
|---|---|---|---|
| Fixed 11 `baseline_hazards` in `config/rams_tier1.php`, injected whenever `hazards` is empty | Config array removed entirely; `hazard_templates` table (18 rows + user rows) is sole source | `config/rams_tier1.php` | D-01 — config file "drops out of the hazard business entirely" |
| 7 `MANDATORY_KEYWORDS` always-fallback-merged when no explicit hazard names supplied | Removed; replaced by tiered include-when resolution (always/deterministic/AI) that runs regardless of whether names were explicitly supplied | `HazardLibraryService` | The old behaviour was itself a proto-"always" mechanism for exactly 7 of the 18 hazards — the new mechanism generalises and extends it to all 18, condition-gated |
| Coarse Low/Medium/High risk select in review form → hardcoded `[preL,preS]` pairs | Editable numeric L×S defaults + unreviewed marker (exact mechanism = Claude's Discretion) | `quote-review.blade.php`, `RamsBuilderService::riskLevelsFromString()`/`reviewedToRisk()` | HAZ-04 — closes the "silently applied score" gap |
| Working at Height residual scored `2×3` (`post_likelihood=2, post_severity=3`) | Residual scored `1×4` (severity held at initial, per skill's methodology: controls reduce likelihood, not severity) | `config/rams_tier1.php:67-68` (source of the wrong value) | HAZ-03, the single named checkable proof-point |
| RA{NN} refs stable only within one generation (array-position-derived, both DOCX + live PDF) | Unchanged by this phase — variable-length register makes refs shift ACROSS regenerations after an engineer edits included hazards, same as today's behaviour with a fixed 11 whose *order* could still change if an engineer removed one | `DocxBuilderService`, `rams.blade.php` | Not a regression this phase introduces — already true today; flagged so the planner doesn't over-scope a "fix" GATE-03 already covers within-generation consistency |

**Deprecated/outdated:**
- `config/rams_tier1.php`'s `baseline_hazards` array (52-232) — fully removed by this phase.
- `HazardLibraryService::MANDATORY_KEYWORDS` + `mandatoryBaseline()` + `mergeWithMandatory()` (`:36-44`, `:195-249`, `:254-273`) — fully removed by this phase.
- `HazardTemplateSeeder::standardHazards()`'s current 13-hazard list — replaced wholesale by the 18, per D-01/D-02's folding map.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | The suggested D-02 folding map (e.g. "Struck by Falling Objects → Working at height") is functionally reasonable but was authored during discuss-phase as a *suggestion*, not verified against every control-text sentence in the 6 folded-away app hazards vs the 18 skill hazards' control lists | User Constraints / D-02 | If a folded hazard's control wording doesn't actually fit the target hazard's scope (e.g. a Hidden-Services control about gas pipes bolted onto "Fixings into walls, ceilings and pillars"), the merged control list could read oddly; low safety risk (worst case is redundant/awkward wording, not a missing control), but worth a planner sanity-pass against the actual control bullet text, not just the hazard titles |
| A2 | `fillMissingHazardControls()`'s keyword-substring matching against the OLD hazard vocabulary will safely no-op once the new library's `controls` arrays are non-empty for every matched hazard | Common Pitfalls / Pitfall 5 | If any HAZ-01-ported hazard row somehow reaches this method with an empty `controls` array (e.g. a tier-3 AI-only match that doesn't carry the template's control list through), stale/mismatched fallback control text could get injected silently; this is a code-path risk to verify with a test, not something researched via reading alone |
| A3 | Reusing `resolveFromSeeds()`'s fuzzy-match as the tier-3 AI-judgement plug-in (rather than a new prompt/call) is sufficient to satisfy D-05 tier 3's five named triggers (asbestos, lone-working, road-risk, vehicle/plant, occupied-premises) | Standard Stack / Alternatives Considered | These five triggers need *reading* (e.g. "building pre-2000 or age unknown"), which the current fuzzy-match against a short seed string may not naturally produce unless the upstream AI extraction is already emitting seed strings that encode these judgements (e.g. an "Asbestos" seed string only appears if the AI already decided the building might be pre-2000). This is a genuine open design question for the planner — not something this research can settle by reading code, since it depends on prompt content this phase might also need to touch (which prompt currently classifies building age / operative count / site type, if any) |

## Open Questions

1. **Which AI prompt (if any) currently classifies building age, operative count, or site type — the raw signals D-05's tier-3 hazards depend on?**
   - What we know: `RamsController.php:359-369` validates `permits_required` and `material_handling_*` structured fields; CONTEXT.md's own "What the app does NOT capture" section (mirrored from `PORTING-NOTES.md`) states there is no building-age, operative-count, site-type, or strip-out-flag field anywhere in `form_data` today.
   - What's unclear: whether the AI method-statement generation prompt (`MethodStatementPrompt`) or the extraction prompt (whichever populates `extracted_data`) ever infers or surfaces these signals in a way tier-3 could consume, or whether tier-3 is starting from zero signal and must rely entirely on D-06's "include and flag for confirmation" fallback for most jobs.
   - Recommendation: the planner should grep `app/Core/AI/Prompts/*.php` for any existing building-age/operative-count/travel-distance signal extraction before designing tier-3's exact mechanism — if none exists (likely, per the "What the app does NOT capture" list), D-06's flag-and-include default will fire for the majority of tier-3 hazards on most jobs, which is an acceptable, explicitly-sanctioned outcome per CONTEXT.md, not a bug — but the planner should confirm this is the expected steady-state, not something to "fix" by inventing new form fields (which CONTEXT.md's Deferred Ideas explicitly rules out for this phase).

2. **Exact mechanism for HAZ-04's editable-default UI in `quote-review.blade.php`.**
   - What we know: the requirement is absolute (never silently apply scores) and the constraint is concrete (Working at Height residual must render `1×4`); the current UI is Low/Medium/High only.
   - What's unclear: whether the planner should add raw L×S number inputs (most direct, but a bigger form change) or keep Low/Medium/High with a separate reviewed-marker and a numeric default carried invisibly until touched (smaller UI change, but requires the "typical score" to map onto one of three buckets for display, which loses precision for skill scores that don't sit cleanly on a Low/Medium/High boundary — e.g. `2×5` is genuinely between "Medium" and "High" territory under the existing `riskLevelsFromString` bucket boundaries).
   - Recommendation: explicitly Claude's Discretion per CONTEXT.md — the planner should pick one and document the choice; given HAZ-04's "editable defaults" wording and the skill's precise per-hazard scores, raw L×S inputs are the more faithful choice, but either satisfies the literal requirement if paired with a working "not yet reviewed" marker.

## Environment Availability

Skipped — this phase has no external tool/service/runtime dependencies beyond the existing Laravel app, its already-configured MySQL/SQLite test database, and the existing DOCX (PhpWord) / PDF (Browsershot) rendering stack, all of which are already in continuous use by the live system this phase modifies. No new environment probing is needed.

## Validation Architecture

### Test Framework

| Property | Value |
|----------|-------|
| Framework | PHPUnit (via Laravel's testing layer — some tests may use Pest-style syntax; both run under `phpunit.xml`) |
| Config file | `phpunit.xml` (repo root) — `Unit` suite → `tests/Unit`, `Feature` suite → `tests/Feature` |
| Quick run command | `php artisan test --filter=Hazard` (or the specific new test class name once written) |
| Full suite command | `php artisan test` (baseline per most recent quick-task SUMMARY: 2132 passed / 2 pre-existing unrelated failures as of 2026-08-17) |

### Phase Requirements → Test Map

| Req ID | Behaviour | Test Type | Automated Command | File Exists? |
|--------|-----------|-----------|-------------------|-------------|
| HAZ-01 | All 18 hazards exist in `hazard_templates` after seeding, each with `include_when` set (or explicitly null for none — none are expected to be null among the 18) | unit/feature | `php artisan test --filter=HazardTemplateSeeder` | ❌ Wave 0 — new test needed |
| HAZ-02 | A fresh RAMS with no matching structured signals shows ONLY the 4 Always hazards (Slips/trips/falls, Low voltage AV connections, Fire and evacuation, COSHH substances) — never the old 11 config-baseline titles | feature | `php artisan test --filter=HazardIncludeWhen` (rewrite/replace `Tier1BaselineHazardsRenderTest`'s test 1) | ❌ Wave 0 — rewrite existing file, see Pitfall 2 |
| HAZ-02 | All 5 injection paths verified dead — grep-based structural guard (following the established pattern of `DeadPathRemovalGuardTest` from Phase 22.1) confirming `config('rams_tier1.baseline_hazards')` / `MANDATORY_KEYWORDS` no longer referenced by any live code path | unit (structural) | `php artisan test --filter=HazardInjectionPathsRemoved` | ❌ Wave 0 — new guard test, modelled on `tests/.../DeadPathRemovalGuardTest.php` pattern from Phase 22.1 (referenced in ROADMAP.md, exact path to confirm during planning) |
| HAZ-03 | Working at Height renders residual `1×4` (not `2×3`) in both DOCX and live PDF output | feature | `php artisan test --filter=WorkingAtHeightResidualScore` | ❌ Wave 0 — new test |
| HAZ-04 | A hazard row with an un-reviewed default score is visibly distinguishable from a reviewed one, and `generated_data` never receives an unreviewed score without the marker surviving alongside it | feature | `php artisan test --filter=HazardScoreEditableDefault` | ❌ Wave 0 — new test; depends on the UI mechanism chosen (Open Question 2) |
| HAZ-01..04 (Criterion 4) | Regenerating real project **21CQ30960** shows only hazards its actual scope supports | manual-only | N/A — requires live production data + human spot-check against the source quote, per CONTEXT.md's own framing ("cannot be validated against the old fixed 11/7-item lists, which by construction cannot contain the answer") | N/A — explicitly manual, not automatable |

### Sampling Rate

- **Per task commit:** `php artisan test --filter=Hazard` (fast, scoped to the new/modified hazard test classes)
- **Per wave merge:** `php artisan test` (full suite — this phase touches shared services like `RamsComplianceUpgradeService`'s call graph and `RamsBuilderService`, both consumed by many other test files; a full-suite run is warranted at least once per wave, not just per hazard-scoped test)
- **Phase gate:** Full suite green before `/gsd:verify-work`, PLUS the manual 21CQ30960 spot-check (Criterion 4) — this is a hard gate that cannot be satisfied by automated tests alone, consistent with the "validated on live against production data" constraint from CONTEXT.md

### Wave 0 Gaps

- [ ] Rewrite `tests/Feature/Rams/Tier1BaselineHazardsRenderTest.php` — test 1 currently asserts the exact behaviour this phase removes; tests 2 and 3 may be reusable largely as-is (test 3 already models the target "flag off → empty" end state)
- [ ] New test class covering `HazardTemplateSeeder`'s 18-hazard upsert-by-name idempotency (mirroring the seeder's own existing idempotency claim, currently untested per the file search performed during this research)
- [ ] New test class covering the tiered include-when resolver (unit-level, one case per tier: always / deterministic-match / deterministic-no-match / AI-judgement-matched / AI-judgement-flagged-unconfirmed per D-06)
- [ ] New structural guard test confirming zero live references to `config('rams_tier1.baseline_hazards')` and `HazardLibraryService::MANDATORY_KEYWORDS` remain (grep-based, following the `DeadPathRemovalGuardTest` precedent from Phase 22.1 — locate that file during planning to confirm its exact pattern/location before the plan cites it)
- [ ] New test asserting `RA{NN}` refs in a freshly-generated document with a variable-length (non-11) hazard register still resolve 1:1 against the method-statement's cited RA-IDs within the same generation (regression guard extending the existing `MethodStatementAssociatedRisksTest` pattern from quick task 260817-r5e, confirming that fix's guarantee still holds once register length is no longer fixed at 11)
- [ ] Framework install: none — PHPUnit/Laravel testing already fully configured, no new install needed

## Security Domain

`security_enforcement` config key not found in `.planning/config.json` during this research pass (absent = enabled per the default rule) — however this phase has essentially no security surface: it does not touch authentication, session management, access control, external input parsing/validation beyond existing form fields, or cryptography. The one borderline item is the `include_when` deterministic-match logic potentially parsing free-text scope narrative — this is server-side keyword/tag matching against already-validated/sanitised `form_data` and `reviewed_data` fields (both already subject to existing Laravel validation rules per `RamsController.php`), not new user input surface, so no new ASVS category is newly triggered by this phase.

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | No | Unchanged — this phase touches no auth code |
| V3 Session Management | No | Unchanged |
| V4 Access Control | No | `HazardTemplateController::authorizeTemplate()` unchanged — global vs owned template distinction (D-01/D-03) already enforced, not modified |
| V5 Input Validation | Marginal | `HazardTemplateController::validateTemplate()` may need a new `include_when` validation rule if this phase decides to expose it in the CRUD form (D-04 says "no new authoring UI this phase" — likely means this validation rule stays server/seeder-only and is NOT exposed to the `store`/`update` request validation, keeping the existing `hazard-templates.edit` form untouched) |
| V6 Cryptography | No | Unchanged |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Free-text `include_when` deterministic-match logic accidentally matching unintended scope-narrative substrings (false positive, not a security threat but a correctness one) | N/A (correctness, not security) | Keep matching scoped to a fixed, reviewed keyword/tag vocabulary (per D-05's named trigger list) rather than open-ended regex against arbitrary narrative text — this is the same discipline already used in `RiskTemplateResolverService::ACCESS_EQUIPMENT_MAP`/`PPE_ACTIVITY_MAP` |
| A hazard row's `include_when` column being exposed to mass-assignment via `HazardTemplate::$fillable` without a corresponding validation rule, allowing a non-admin user to set an arbitrary condition on their own `is_global=false` row that later gets confused with a global auto-populating hazard | Tampering | D-04 already rules this out by design (null = manual-only for user rows; only the seeder writes non-null `include_when` values onto `is_global=true` rows) — the planner should ensure `include_when` is either excluded from `HazardTemplateController`'s `$fillable`/validated request fields entirely, or explicitly forced null for non-admin/non-global submissions, mirroring how `is_global` itself is already gated by `auth()->user()->isAdmin()` in `store()`/`update()` |

## Sources

### Primary (HIGH confidence — all direct codebase reads, 2026-08-23)
- `.planning/phases/26-hazard-library-structural-inversion/26-CONTEXT.md` — locked decisions D-01..D-06, discretion areas, canonical refs
- `.planning/REQUIREMENTS.md` §"Group C — Hazard library reconciliation" (HAZ-01..04) and §"Why this milestone exists"
- `.planning/ROADMAP.md` §"Phase 26: Hazard Library Structural Inversion" and the v3.0 milestone framing
- `.planning/reference/21cav-rams-skill/references/hazard-library.md` — full 18-hazard text, typical scores, include-when column (verbatim, reproduced in relevant sections above)
- `.planning/reference/21cav-rams-skill/PORTING-NOTES.md` — the empty-register principle, "do not apply typical scores silently," the 12 validation gates
- `.planning/reference/21cav-rams-skill/references/house-rules.md` — settled positions (FFP3, two-operative lifts, "Restricted access and ceiling void working" naming, CDM/A&E positions)
- `app/Core/Modules/KnowledgeLibrary/HazardLibraryService.php` (full read) — `MANDATORY_KEYWORDS`, `mandatoryBaseline()`, `mergeWithMandatory()`, `resolveFromSeeds()`, `fuzzyMatch()`
- `config/rams_tier1.php` (full read) — `baseline_hazards`, `coshh_products`, `standards_references`, `enabled` kill switch
- `app/Services/Rams/Tier1RamsDefaultsService.php` (full read)
- `database/seeders/HazardTemplateSeeder.php` (full read) — current 13-hazard content
- `app/Models/HazardTemplate.php`, `app/Http/Controllers/HazardTemplateController.php` (full reads)
- `app/Support/Rams/SectionComposers/RiskAssessmentComposer.php` (full read)
- `database/migrations/2026_03_09_000001_create_hazard_templates_table.php`, `2026_03_09_000002_...`, `2026_03_14_000010_...` (all read) — confirmed the first is authoritative, other two are neutralised no-ops
- `app/Services/RamsBuilderService.php` (large portions read) — `buildFromReview()`, `runPipeline()`, `reviewedToRisk()`, `riskLevelsFromString()`, injection call sites at both entry points
- `app/Services/RiskTemplateResolverService.php` (full read) — `buildHazards()`, `resolveHazards()`, `resolveFromProjectContext()`
- `app/Services/RamsReviewDataService.php` (partial read) — `normaliseHazards()` current schema
- `app/Http/Controllers/RamsController.php` (partial reads) — `create()` hazard-checkbox prefill, validated `form_data` fields (`permits_required`, `material_handling_*`)
- `resources/views/rams/quote-review.blade.php` (grep + targeted reads) — the actual editable hazard-row form and its Low/Medium/High-only score UI
- `resources/views/rams/review.blade.php` (targeted read) — confirmed this is a post-generation READ-ONLY diff view, not the editable intake form
- `resources/views/pdf/rams.blade.php:305-317` (read) — the live render-time fallback, verbatim
- `app/Services/DocxBuilderService.php` (grep + targeted read) — confirmed DOCX is the live primary render path; `buildRiskAssessment()` RA-ref computation
- `app/Services/PdfService.php` (partial read) — `RAMS_UNIFIED_COMPOSER` routing between `rams.blade.php` (live) and `rams-v2.blade.php` (not live)
- `config/rams.php` (grep) — confirmed `unified_composer` defaults `false`
- `app/Services/Rams/RamsComplianceUpgradeService.php` (grep + targeted read) — `upgrade()` pipeline order, `fillMissingHazardControls()`
- `.planning/quick/20260817-rams-generator-defects/SUMMARY.md` (full read) — confirmed live-template identity, index-based RA-ref derivation in both `DocxBuilderService.php:1221` and `rams-v2.blade.php:1393`, GATE-03's dangling-reference fix
- `tests/Feature/Rams/Tier1BaselineHazardsRenderTest.php` (full read) — three existing tests, one of which directly contradicts this phase's target behaviour
- `tests/Unit/Services/RamsBuilderServiceTest.php`, `tests/Feature/Rams/RamsRenderRegressionTest.php`, `tests/Unit/Services/Rams/MethodStatementAssociatedRisksTest.php`, `tests/Unit/Support/Rams/Sections/RiskAssessmentSectionDtoTest.php` (existence + grep confirmed) — existing test surface inventory
- `phpunit.xml` (grep) — test suite structure (`Unit`/`Feature`)

### Secondary (MEDIUM confidence)
- None — this research required no external web sources; the entire domain is internal application code and the vendored skill reference documents, both read directly and verified against the live codebase.

### Tertiary (LOW confidence)
- None.

## Metadata

**Confidence breakdown:**
- Standard stack / architecture: HIGH — every claim traced to a specific file and line range, read directly in this session; no training-data assumptions about this codebase were used
- Hazard content (18-hazard scores/controls): HIGH — transcribed directly from the vendored `hazard-library.md`, the phase's own designated source of truth
- Pitfalls: HIGH — each pitfall is grounded in a specific, cited code location or existing test file, not a generic pattern
- Open Questions (AI-prompt signal availability, exact HAZ-04 UI mechanism): MEDIUM/LOW by design — these are genuinely open per CONTEXT.md's own "Claude's Discretion" framing, not gaps in this research; the "what exists today" half of each question is HIGH confidence, the "what to build" half is deliberately left to planning

**Research date:** 2026-08-23
**Valid until:** Short shelf-life recommended — 7-14 days. This phase's plan will edit several of the exact files this research reads (`HazardLibraryService.php`, `config/rams_tier1.php`, `HazardTemplateSeeder.php`, `RamsBuilderService.php`), so this research becomes partially stale the moment Phase 26 lands its first commit. If planning is deferred past that point, re-verify the "current state" claims (especially line numbers) before relying on them.
