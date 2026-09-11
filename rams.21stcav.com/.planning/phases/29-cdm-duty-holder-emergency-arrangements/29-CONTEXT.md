# Phase 29: CDM Duty-Holder & Emergency Arrangements - Context

**Gathered:** 2026-09-11
**Status:** Ready for planning

<domain>
## Phase Boundary

Kill the two placeholders that can currently ship on a live RAMS — the CDM duty-holder
table's unconditional `[To be confirmed]` and the hardcoded "Nearest hospital A&E to be
identified at site induction" — and ship GATE-11/GATE-12 so neither can return.

**In scope:** RULE-07 (anticipated sole-Contractor CDM wording), RULE-08 (**restated this
phase — see D-06**), GATE-11, GATE-12. Four requirements, two gates — that is the whole
phase.

**Out of scope:** Site-level A&E/asbestos/welfare inheritance (v3.1 deferral; this phase
works with the existing per-RAMS field + per-project carry-forward instead). Structural
gates GATE-01/02/04/13/14 (Phase 30). Standards/COSHH scoping and RULE-11 fire-stopping
(Phase 31). The hazard include-when library (Phase 26, shipped). FFP3/confined-space
content (Phase 28, shipped).

</domain>

<decisions>
## Implementation Decisions

Eight decisions were taken across two areas. The CDM **wording** area was deliberately
left to the planner as constrained discretion (see Claude's Discretion) because
`standards-and-legislation.md:23-41` prescribes it almost verbatim.

### A&E arrangements — what the document says

- **D-05: When no verified 24/7 A&E is recorded, the document prints the house-rule
  hold-point line — it never guesses a hospital name.**

  `house-rules.md` §"Emergency arrangements" is explicit and **contradicts ROADMAP
  criterion 2 as written**:

  > *"Do not assert a specific hospital as the nearest A&E unless it is a verified 24/7
  > Emergency Department. Urgent-care centres and minor-injury units are not A&E, and
  > hospitals downgrade — a wrong A&E is a genuine safety defect. If the nearest 24/7 ED
  > is not verified for the site, record 'Nearest A&E — to be confirmed at induction (must
  > be a 24/7 Emergency Department)' as a hold point rather than guessing a name. Where it
  > is verified, give the hospital, full address and postcode, and add that route and
  > travel time are confirmed at induction."*

  ROADMAP criterion 2 says the line is "replaced by a named A&E with address **by
  default**". Auto-naming an A&E by default would ship precisely the defect GATE-12 exists
  to catch. **This is the same class of drift as RULE-07**, which was restated on
  2026-08-26 after the skill file was recovered (`.planning/reference/SKILL-RESYNC-2026-08-26.md`
  §C-2) — Phase 29 as originally specified would have shipped the forbidden assertion.

  **Planner action:** restate ROADMAP Phase 29 criterion 2 to match D-06 before planning
  against it. Do not plan to the criterion as currently written.

- **D-06: RULE-08 is restated to a two-branch requirement.** New wording:

  > *Where a 24/7 Emergency Department is verified for the site, it is named with full
  > address and postcode, and route/travel time are confirmed at induction. Where it is
  > not verified, the document states the hold point and the 24/7 ED requirement. The
  > passive string "to be identified at site induction" never appears in either branch.*

  Keeps RULE-08 a hard, testable requirement and keeps the banned string banned. Update
  `.planning/REQUIREMENTS.md:88` with a **RESTATED 2026-09-11** note in the same style as
  RULE-07's restatement at `:87`.

- **D-07: Section 7.0 is the single source of A&E truth. The Welfare First Aid bullet
  defers to it.**

  A&E is currently stated in two disconnected places that can already contradict each
  other today: the Welfare First Aid bullet (hardcoded prose) and the section 7.0
  Emergency table row (data-driven, `'TBC'` fallback). The Welfare bullet keeps its
  first-aid certificate and kit content but **drops the A&E sentence**, replacing it with
  a pointer ("Nearest A&E — see Section 7.0"). Section 7.0 then renders either the
  verified name + address + postcode or the D-05 hold-point line.

  Makes contradiction structurally impossible rather than relying on two sites staying in
  sync. **The `'TBC'` fallback at `rams.blade.php:1976` is removed** — `'TBC'` is not one
  of the two permitted branches.

- **D-08: GATE-12 is a plausibility check. No UK A&E dataset is introduced.**

  This closes the scoping call ROADMAP criterion 3 explicitly required be made in
  planning. GATE-12 errors when:

  1. the banned passive string appears anywhere in generated output; **or**
  2. a named A&E is given but matches urgent-care / minor-injury / walk-in / UTC keywords
     — the exact categories `house-rules.md` says are not A&E; **or**
  3. a named A&E is given with no address or postcode.

  The D-05 hold-point line **passes clean** — it is legitimate output, not a defect.

  **Constraint (inherited, load-bearing):** GATE-12 takes the Phase 28 D-01
  conservative-by-construction rule verbatim — a value the detector cannot confidently
  classify is **CLEAN**. A false positive silently overwrites an engineer's deliberate
  wording on a live safety document; a false negative merely leaves today's behaviour
  unchanged.

  A curated static list of verified EDs was offered and **declined** — it is a maintained
  dataset with no owner and fires false positives the first time 21CAV works in a new
  region. Banned-string-check-only was also declined — it does not check that a named A&E
  is real, which is GATE-12's entire purpose and the incident it was written for.

### Fix site & live production rows

- **D-01: The A&E line becomes data-driven, and ALL THREE legacy sites plus the composer
  layer are fixed.**

  The A&E sentence is a hardcoded literal in three separate templates, entirely
  disconnected from the `site_emergency.nearest_hospital` data that section 7.0 already
  renders:

  | File | Line |
  |---|---|
  | `resources/views/pdf/rams.blade.php` | `:1960` (+ `'TBC'` fallback `:1976`) |
  | `resources/views/pdf/rams-v2.blade.php` | `:2021` |
  | `app/Services/DocxBuilderService.php` | `:2149` |

  All three read the resolved value through one shared helper, **and** the same resolution
  lands in `WelfareComposer` / `EmergencyComposer` / `EmergencySectionDto`.

  **Why both layers:** `config/rams.php:43` defaults `RAMS_UNIFIED_COMPOSER` to **false**.
  A composer-only fix changes nothing in live output unless that flag is on in prod; a
  legacy-only fix leaves a latent defect that reappears the day it is flipped. This
  milestone has been reopened by reintroduction twice. **Planning must verify which
  setting prod actually runs** — read it via `config()`, not by grepping `.env`.

  Two test fixtures carry the old string and will need regenerating:
  `tests/Fixtures/rams/tilda-21cq29531/expected-html-v1.html:1278`, `expected-html-v2.html:1313`,
  plus `expected-docx-v1.xml.norm` / `expected-docx-v2.xml.norm`.
  `resources/views.backup-260430/` is a backup tree — **do not edit**.

- **D-02: Live production rows get a measure-first checkpoint + an idempotent backfill
  migration — the Phase 28-07 shape.**

  A code-only fix does **not** reach existing documents. There are two CDM sources and the
  persisted one wins: engineer-editable `reviewed_data['cdm']` (`cdmRows`) and the
  upgrade-service default `cdm_duty_holders`, and the blades merge the default **only when
  `cdmRows` is empty** (`rams.blade.php:1824`, `rams-v2.blade.php:1885`).

  This is exactly the Phase 27 Blocker-1 shape — a library fix that never reached documents
  regenerated from existing reviewed data, requiring the 438-row backfill on 2026-08-26.

  The migration patches `reviewed_data['cdm']` and `generated_data['cdm_duty_holders']`
  **only where the value is still the `[To be confirmed]` placeholder** — never a row where
  an engineer typed a real name. Count the affected production rows at a checkpoint before
  writing the migration.

  **A&E needs no backfill** — it is template text, never persisted, so the D-01 code fix
  reaches every existing document on its next render. The asymmetry is deliberate; do not
  write a backfill for it.

- **D-03: GATE-11/GATE-12 ship DISARMED. The flag is flipped in `.env` after the backfill
  and a live regeneration verify clean.**

  New dedicated flag (e.g. `RAMS_CDM_AE_GATE`) defaults **false** in `config/rams_tier1.php`.
  Deploy code → run backfill → verify a live regeneration → flip true as a separate
  one-line `.env` change.

  Applies the Phase 28 deploy-order lesson directly: a content gate defaulting ON is a
  deploy-order trap — arming before the migration ran broke Save Review corpus-wide. Also
  matches this project's standing pattern of gating risky changes behind an env flag so a
  bad result is one `.env` edit from off.

  **Constraint (Phase 28 D-03 isolation rule):** GATE-11/GATE-12 get their OWN flag. Never
  reuse or share `RAMS_DISPLAY_LIFT_GATE` (GATE-09) or `RAMS_PPE_CEILING_ELECTRICAL_GATE`
  (GATE-06/07), so one gate's rollback can never accidentally disarm another's.

- **D-04: The CDM carry-forward copies only non-placeholder values.**

  `RamsDisplayPatchService.php:417-430` auto-carries `cdm` and `site_emergency` forward
  from a prior completed RAMS on the same project — so one document's `[To be confirmed]`
  reseeds itself onto every later job on that project indefinitely.

  Keep the carry-forward (it is genuinely useful and is why these fields get reused across
  a project's documents) but **skip any field whose prior value is still the placeholder**,
  letting the fixed default fill in instead. Same treatment for
  `site_emergency.nearest_hospital` — a blank or placeholder A&E must not propagate.

### Claude's Discretion

- **CDM duty-holder wording (RULE-07) — constrained, not open.** The skill prescribes it
  almost verbatim; the planner applies it without re-asking:
  - Use the anticipated wording **exactly**: *"21CAV is currently anticipated to be the
    sole contractor for the AV installation scope. The client shall confirm whether the
    overall project involves, or is likely to involve, more than one contractor before
    works commence."* (`standards-and-legislation.md:23-28`). **Never** an unequivocal
    assertion that 21CAV *is* the sole contractor — that is the forbidden form RULE-07 was
    restated on 2026-08-26 to prevent.
  - The CDM **Client row uses the known client's name**, never "To be confirmed" alongside
    it. Leave "Accepted by (Client)" blank for the client to sign — never auto-fill from
    the site contact (`:29-31`).
  - The **Contractor / 21CAV row is conditional**: *"If sole contractor: prepares and
    implements the Construction Phase Plan. If multiple contractors: works to the Principal
    Contractor's Construction Phase Plan and site arrangements."* (`:32-34`).
  - **Four forbidden statements** (`:35-41`) — must not appear: that the client "retains
    Principal Designer responsibilities"; that 21CAV "discharges its duties under
    Regulations 4 or 5" (contractor duties sit under **Regulation 15**); that "the
    Principal Contractor must notify HSE" (the F10 duty is the **Client's**); or any
    implication that an F10 is always needed (most single-visit AV installs are not
    notifiable — say so, and judge notifiability on the whole project, not 21CAV's one day).
  - Undecided and left to the planner: whether the position is applied unconditionally or
    only on occupied-premises jobs; whether engineers get a review-form input to override
    the CDM rows; whether the PD/PC rows carry the full sentence or a short form with the
    full text elsewhere in the section.
- Test-fixture regeneration strategy for the four `tilda-21cq29531` expected-output files.
- Whether GATE-11/GATE-12 share one detector class or take separate entries — subject to
  the `ControlTextRuleViolations` single-choke-point rule below.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### House rules — the authority for both requirements
- `.planning/reference/21cav-rams-skill/references/house-rules.md` §"Emergency
  arrangements" (`:265-273`) — the verified-24/7-ED rule and the exact hold-point wording.
  **Overrides ROADMAP criterion 2.**
- `.planning/reference/21cav-rams-skill/references/standards-and-legislation.md` §"CDM 2015
  — how to word the duty holders" (`:17-41`) — the verbatim anticipated-sole-contractor
  sentence, the conditional Contractor row, and the four forbidden statements.
- `.planning/reference/SKILL-RESYNC-2026-08-26.md` §C-2 — why RULE-07 was restated; the
  precedent this phase follows for RULE-08.

### Prior-phase decisions this phase inherits
- `.planning/phases/28-ppe-ceiling-electrical-boundary-house-rules/28-CONTEXT.md` — D-01
  (conservative-by-construction detectors, never flag the app's own corrected text), D-03
  (gate pairing: auto-correct + independent throwing re-check; per-gate flag isolation),
  28-07 (measure-first + idempotent backfill).
- `.planning/phases/27-manual-handling-display-lift-house-rules/27-CONTEXT.md` — the
  RULE-13 + GATE-09 pairing shape that Phase 28 mirrored and this phase mirrors again.

### Requirements & roadmap
- `.planning/REQUIREMENTS.md:87-88` (RULE-07 restated, RULE-08 to be restated per D-06),
  `:72-73` (GATE-11/GATE-12), `:143` (site-level inheritance deferral), `:164-165`,
  `:174-175` (traceability rows to flip).
- `.planning/ROADMAP.md` §"Phase 29" — **criterion 2 requires restating per D-05/D-06.**

</canonical_refs>

<code_context>
## Existing Code Insights

### Reusable Assets
- **Per-RAMS A&E capture already exists** — `RamsController.php:412-413` validates
  `site_emergency_nearest_hospital` / `site_emergency_hospital_address`, writes them to
  `reviewed_data['site_emergency']` (`:564-576`) and mirrors to `generated_data` (`:584`).
  GATE-12 does **not** need to invent storage; the roadmap's "no per-site A&E dataset"
  flag is only half true.
- **Per-project carry-forward exists** — `RamsDisplayPatchService.php:417-430` carries
  `site_emergency` and `cdm` from a prior completed RAMS on the same project. Useful; also
  the placeholder-propagation vector D-04 closes.
- **`ControlTextRuleViolations`** — the established single choke point for text detectors.
  Its docblock names each phase's extension as "a new `DETECTORS` entry plus its own
  `detectXxx()` method". Do **not** modify `RamsBuilderService::reviewedToRisk()` to add a
  detector, and do not duplicate a detector's logic anywhere else.
- **`EmergencySectionDto`** — already declares nine canonical `site_emergency` keys
  including `nearest_hospital` (`:16`, `:86`); `EmergencyComposer:29` already resolves
  `generated_data` → `reviewed_data` → fallback.

### Established Patterns
- **Gate = auto-correct + independent throwing re-check** (Phase 27 RULE-13/GATE-09, Phase
  28 D-03). The throwing half lives in `RamsComplianceUpgradeService::upgrade()`
  (GATE-09 shape at `:1104-1213`), surfaced at `RamsController.php:597-604`.
- **Per-gate env kill-switch** in `config/rams_tier1.php` — `display_lift_gate_enabled`
  (`:74`), `ffp2_confined_space_gate_enabled` (`:98`). Deliberately never shared.
- **Measure-first checkpoint before any backfill migration** (28-07).

### Integration Points
- `app/Services/Rams/RamsComplianceUpgradeService.php:1093-1111` — `addCdmDutyHolders()`,
  the single origin of the `[To be confirmed]` CDM defaults (`:1099-1100`).
- `app/Services/DocxBuilderService.php:1706-1707` — a **second** `'[To be confirmed]'`
  fallback in the DOCX CDM table; must be fixed with the first or it re-introduces the
  placeholder on the DOCX path alone.
- `resources/views/pdf/rams.blade.php:1822-1826` and `rams-v2.blade.php:1883-1887` — the
  `cdmRows`-wins-over-`cdm_duty_holders` merge that makes D-02's backfill necessary.
- `app/Support/Rams/RamsDocumentComposer.php` — order-of-operations invariant: it MUST run
  after `RamsDisplayPatchService::patch()`; detection via the `_display_patched_at` marker.

</code_context>

<specifics>
## Specific Ideas

- The failure GATE-12 was written for: *"A subcontractor RAMS once named a hospital whose
  A&E closed in 2014."* (`REQUIREMENTS.md:73`). Naming a plausible-but-wrong hospital is
  worse than stating the hold point — that asymmetry drove D-05 and D-08.
- Verification must be against **production data, not just a fixture** (ROADMAP criterion
  4), consistent with this project's practice of validating RAMS changes on the live VPS
  against real project data.

</specifics>

<deferred>
## Deferred Ideas

- **Site-level A&E / asbestos-register / access / welfare inheritance** — a real per-site
  dataset would let GATE-12 verify against a maintained record rather than a plausibility
  check. Explicitly out of scope for v3.1 (`REQUIREMENTS.md:143`); D-08 scopes around its
  absence.
- **Curated static list of verified 24/7 EDs near 21CAV's regular sites** — offered and
  declined for D-08 (no owner, false positives in new regions). Revisit if site-level
  inheritance lands, which would give it a home.
- **Escalating GATE-12 to block generation on a blank A&E** — considered under D-05 and
  declined for now; would become viable once the corpus is clean and a site-level A&E
  source exists.

</deferred>

---

*Phase: 29-CDM Duty-Holder & Emergency Arrangements*
*Context gathered: 2026-09-11*
