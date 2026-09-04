# Skill re-sync 2026-08-26 — findings against REQUIREMENTS.md

**What happened.** `.planning/reference/21cav-rams-skill/` is milestone v3.0's declared
source of truth. It was vendored once, on 2026-08-23, from a zip — and never synced. On
2026-08-26 it was compared against the live skill in the Service Contractor Creator repo
(`service-contractor-creator/resources/rams-skill/`) and found to be a materially
truncated, older snapshot.

| File | Vendored 08-23 | Live | Gap |
|---|---|---|---|
| `references/house-rules.md` | 121 lines, 13 sections | 298 lines, 21 sections | **8 sections missing** |
| `references/standards-and-legislation.md` | absent | 47 lines | **missing entirely** |
| `data/standards-library.json` | absent | present | **missing entirely** |
| `references/hazard-library.md` | 217 lines | 224 lines | control text materially rewritten |
| `references/project-schema.md` | 108 | 146 | |
| `references/quote-extraction.md` | 96 | 133 | |
| `assets/renault-golden-project.json` | absent | present | second worked example |

Phases 26 and 27 were planned, executed and deployed against the truncated copy. This
document records what the recovered material changes.

**Guard added:** `tests/Feature/Rams/VendoredSkillDriftGuardTest.php` + `MANIFEST.sha256`.
The vendored files can no longer be edited, added or removed without regenerating the
manifest in the same commit. It cannot detect upstream drift (the skill lives in another
repo) — only in-place editing. **Re-comparing against SCC remains a manual step; do it
before planning each remaining phase.**

---

## A. Already fixed in this pass

### A-1. Unconditional strip-out language — LIVE DEFECT, FIXED
`database/seeders/HazardTemplateSeeder.php` listed `DisplayLiftPolicy::wallMountRemovalStatement()`
as a static control on the *Manual handling* hazard, whose `include_when` is
`signal:display_mount_or_rack` — so it fired on **installation-only** jobs.

Both recovered sources forbid this:
- `hazard-library.md`, Manual handling control 3: *"(Removal jobs only — omit entirely on an installation-only job.)"*
- `house-rules.md` §Manual handling: *"Removal / strip-out language is removal-only… On an installation-only job, leave all of that out."*

Shipped to production 2026-08-26 by Phase 27, then removed the same day. The statement is
still emitted **conditionally** by `RamsComplianceUpgradeService::deriveMaterialHandling()`'s
`scope_items.decommission` scan (`:1442`), which is the correct behaviour.
`RamsComplianceUpgradeServiceDisplayLiftTest` was rewritten — it had pinned the defect
(asserted the statement was present, and a control count of 7).

**Verified already correct:** the *Decommissioning and WEEE* hazard is gated on
`signal:strip_out_or_decommission`, satisfying the same rule for the hazard row itself.

---

## B. Blocker answered — Phase 28 can proceed

### B-1. RULE-11's open human decision is settled by the skill
`REQUIREMENTS.md` RULE-11 carries: *"**Needs a human decision on the actual approved
product/system before implementation.**"*

The recovered `house-rules.md` §"Fire-stopping — one consistent position" answers it, and
the answer is that there is no 21CAV product:

> Standard 21CAV position: **fire-stopping is excluded** — any penetration of a fire-rated
> element is sealed by others / referred to the client with a specified fire-stopping
> detail before proceeding. Do not simultaneously exclude fire-stopping and then claim in
> a hazard row or QA that 21CAV fire-stops penetrations "to the original rating". Only
> state that 21CAV fire-stops if the quote scope explicitly includes an approved
> fire-stopping system.

`hazard-library.md` now carries the matching control text on both the *Fixings* and
*Cable pulling* hazards. **RULE-11's requirement text should be restated** — it currently
implies 21CAV seals penetrations "with the client-specified system restoring the original
compartment rating", which is the claim the skill says not to make. Confirm with the user
before editing, since it is a settled-position change.

---

## C. Contradicts shipped code — needs a decision

### C-1. The 20 kg lifting threshold is now explicitly banned — 6 LIVE SITES
`hazard-library.md` Manual handling control 1 was rewritten to:

> Mechanical aids … are used where the load's weight, dimensions, shape, route or the
> task-specific manual-handling assessment indicates they are required. **(There is no
> fixed "safe" lifting weight in UK law — do not state a kg threshold such as "over 20 kg".)**

Every one of these is live and reaches generated documents:

| Site | Text |
|---|---|
| `HazardTemplateSeeder.php:119` | "Use mechanical aids … for items over 20 kg." |
| `RamsComplianceUpgradeService.php:558` | "team lift for items over 20 kg" |
| `RamsComplianceUpgradeService.php:713` | "Team lift for items over 20 kg; mechanical aids used where available" |
| `RamsComplianceUpgradeService.php:1494` | "Team lifts (minimum 2 persons) are required for items over 20 kg." |
| `RamsComplianceUpgradeService.php:1619` | "Single person lift acceptable if under 20 kg." |
| `RamsComplianceUpgradeService.php:1646` | "Assess weight before lifting. Team lift for items over 20 kg." |

Not fixed here: it is safety-content wording across six sites and warrants a decision on
replacement text, not a unilateral rewrite. **Candidate: RULE-13.** Note `:1619` and
`:1646` are non-display fallbacks, so `DisplayLiftPolicy` does not currently own them.

### C-2. CDM sole-contractor wording contradicts RULE-07 / Phase 29
`REQUIREMENTS.md` RULE-07 and ROADMAP Phase 29 both require *"the settled sole-Contractor
position"*. The recovered `standards-and-legislation.md` says the opposite:

> **Do not state unequivocally that "21CAV is the sole contractor".** At preliminary stage
> the contractor make-up is usually unconfirmed, so word it: *"21CAV is currently
> anticipated to be the sole contractor for the AV works…"*

**Phase 29 should not be planned until RULE-07 is restated.** As written it would ship the
exact assertion the skill forbids.

### C-3. Electrical hazard controls materially rewritten — Phase 28 RULE-09
The *Electrical* hazard's controls were rewritten around a socket-outlet-only framing:
21CAV terminates at existing socket outlets; lock-off/test-dead controls apply only to
genuine fixed-installation work; *"do not present plugging equipment into an existing
socket as if it were fixed-installation work"*; BS 7671 cited as *"the current amendment
of BS 7671:2018"*. The currently-seeded controls assert generic lock-off and blanket
BS 7671 compliance. Phase 28 must port the new text, not the vendored-08-23 text.

### C-4. Fixings controls rewritten — BS 8539, no blanket safety factor
*"Select the fixing after the substrate is verified, in accordance with the mount and
fixing manufacturer's published design/load data — and, for post-installed anchors in
concrete or masonry, in accordance with BS 8539:2012+A1:2021… Do not assume an anchor
diameter, drill size or embedment… and do not mandate a blanket safety factor in place of
the manufacturer's design data."* Also: do not promise a blanket pull-test of every fixing.
The seeded controls currently do both (4:1 safety factor, pull-test every fixing).
**Unmapped — candidate RULE-14.**

---

## D. New requirements with no current coverage

| # | Source section | Substance | Suggested |
|---|---|---|---|
| D-1 | §Document status | Default status to *"Draft — Preliminary (subject to technical survey)"* whenever rooms, equipment, weights, heights, substrates, access method, engineers or site contact are unknown. Never "For Issue" while the text says the survey is outstanding. Always populate the `revisions` table. | **GATE-15 + RULE-15** — a self-contradiction gate of the same class as the shipped GATE-08 |
| D-2 | §Drilling near fire detection | Coordinate with Facilities to identify detectors near the work, arrange temporary isolation through the client's authorised person, reinstate and confirm after drilling. Do **not** invent a fixed no-drilling distance. | **RULE-16** — pairs with Phase 28's drilling/dust work |
| D-3 | §Don't invent figures | No invented numerics: noise figures, anchor sizes, weights, no-drilling distances, access-height thresholds, travel times. Office number **01189 977770** must always be populated, never "to be confirmed". Qty columns hold a number or "TBC" only — never a phrase. | **GATE-16 + RULE-17** — highly checkable deterministically |
| D-4 | §Exposure limits and RIDDOR | RCS controlled below its **WEL (0.1 mg/m³, 8-hr TWA)** — never called an "EAV". No asbestos-awareness training in the RCS control. RIDDOR **0345 300 9923**; never invent an "HSE Incident Hotline". | **GATE-17** — string checks; extends Phase 28's RULE-01 |
| D-5 | §Residual risk and the matrix | Where residual remains MEDIUM, state explicitly it is *reduced ALARP and accepted with the listed controls in place*. Residual must never exceed initial. | Second half is **GATE-04** (Phase 30) — already covered. ALARP statement is **new, RULE-18** |
| D-6 | §Team, competence and consistency | Named lead must appear on cover **and** team table. Do not assert CSCS/PASMA/Asbestos-Awareness as fact for every operative. Client name in the CDM Client row, never "To be confirmed" beside the client's own name. Leave "Accepted by (Client)" blank. | **RULE-19** |
| D-7 | §Team, competence (COSHH para) | **The COSHH table holds health-hazard substances only.** Never electrical, manual-handling or working-at-height entries — *"'Electrical hazards' in a COSHH assessment is a standard review reject."* Keep COSHH consistent with the tools/method. | Sharpens **RULE-05 / GATE-10** (Phase 31) — currently framed only as anti-padding, misses the wrong-category rule |
| D-8 | §Write the actual job | Populate rooms, equipment, weights, heights, access method, engineers, site contact from the quote; where genuinely unknown make them **explicit pre-start hold points**, never silent blanks and never invented specifics. | Thematic; underpins D-1 and D-3. No standalone requirement proposed |

---

## E. Structural observation — SCC has already built the validator layer

`data/standards-library.json`'s own header describes itself as *"the SINGLE source of truth
for the RAMS authoring prompt (`RamsAuthorService`) and the deterministic validator
(`RamsProjectValidator`)"*, with version/transition logic modelled explicitly and obsolete
references separated from conditional guidance.

That is the same concept as this milestone's GATE-01..17: a deterministic check of authored
output against a controlled library. SCC has it working, driven by a machine-readable JSON
library rather than prose.

**Worth considering before Phase 31** (standards/COSHH scoping, GATE-10): port
`standards-library.json` as *data* rather than re-deriving a standards list into PHP. It is
already structured (`code`, `reference`, `title`, `amendment`, `category`, `kind`, `status`,
`applies_when`, `url`), already models supersession, and is maintained upstream. Re-typing it
into a config array would recreate exactly the drift problem this document exists to record.

Not a recommendation to adopt SCC's AI-authoring architecture — that conflicts with
`CLAUDE.md:12` (AI never invents scope) and with this app's auditability requirement. The
recommendation is narrower: **share the controlled data, keep the deterministic engine.**

---

## F. What to do next, in order

1. **Confirm B-1** with the user, then restate RULE-11. Phase 28 is blocked on it today and
   the blocker is answerable.
2. **Decide C-1** (the 20 kg threshold). Live on every RAMS, explicitly banned, six sites.
3. **Restate RULE-07 before Phase 29 is planned** (C-2), or the phase ships the forbidden
   assertion.
4. **Re-scope Phase 28** against the recovered electrical, fixings and dust control text
   (C-3, C-4) — its current plan targets the 08-23 wording.
5. **Add D-1..D-7** to REQUIREMENTS.md as new IDs, and decide which land in v3.0 versus a
   follow-on milestone. Seven new requirements is a milestone-scope decision, not a
   planning detail.
6. **Re-compare against SCC before each remaining phase.** The drift guard catches local
   edits only.

---

*Produced 2026-08-26 during Phase 27 close-out, after the vendored skill was re-synced from*
*`service-contractor-creator/resources/rams-skill/`.*
