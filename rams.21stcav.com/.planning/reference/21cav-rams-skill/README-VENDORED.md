# Vendored: the 21cav-rams Claude skill

Copied verbatim from `21cav-rams-skill.zip`, supplied by the user 2026-08-23.
**Nothing here has been edited.** This is the skill exactly as it runs in Claude.ai.

## Why it is in the repo

It is the **source of truth** for milestone v3.0 (RAMS Skill Parity). Where the
application and these documents disagree about safety content, structure or
scoring, **these documents win** unless the user says otherwise for a specific job.

## What to read, and when

| File | Read it for |
|---|---|
| `PORTING-NOTES.md` | Written *for* this port. The 12 validation gates, the two-layer split, and what not to lose. Read first. |
| `references/house-rules.md` | Settled 21CAV positions. Non-negotiable. FFP3, two-operative lifts, standards/COSHH padding, confined-space naming, CDM, A&E. |
| `references/hazard-library.md` | 18 hazards with typical L×S scores, controls, and an explicit "Include when" per hazard. |
| `references/project-schema.md` | The JSON contract between judgement and assembly layers. |
| `references/quote-extraction.md` | How a QuoteWerks PDF becomes RAMS content. |
| `assets/example-project.json` | Complete worked example — VW Blakelands, the same job as the reviewed RAMS. |
| `scripts/build_rams.js` | JSON → branded .docx. Layout tuning here is the result of real review feedback. |

## The rule that shapes the whole milestone

From `PORTING-NOTES.md`:

> *"The default should be an empty register that the user adds to, never a full
> register the user prunes."*

`config/rams_tier1.php` currently does the opposite — 11 fixed `baseline_hazards`
injected into every RAMS. Individual line fixes do not address that.

## Already actioned before this milestone opened

Quick task `260817-r5e` independently fixed two of the twelve gates before this
skill was supplied — GATE-03 (one risks line per step, references must resolve) and
GATE-08 (access-equipment contradiction). Both were derived from a reviewer's
critique of a real document; both are named in `PORTING-NOTES.md`. Two independent
routes to the same defects.

---

## Re-vendored 2026-08-26

The 2026-08-23 vendoring above was a **truncated, older snapshot**. Re-synced from the
live skill in `service-contractor-creator/resources/rams-skill/`.

What the 08-23 copy was missing: 8 of `house-rules.md`'s 21 sections (121 lines vs 298),
`references/standards-and-legislation.md` and `data/standards-library.json` entirely, and
materially older control text throughout `hazard-library.md`. Phases 26 and 27 were planned
and shipped against that subset — one live defect resulted (unconditional strip-out language
on installation-only jobs).

**Read `.planning/reference/SKILL-RESYNC-2026-08-26.md` before planning any further phase.**
It maps the recovered material against REQUIREMENTS.md: one open blocker answered (RULE-11),
two shipped positions contradicted (the 20 kg threshold; CDM sole-contractor wording), and
seven candidate new requirements.

`MANIFEST.sha256` + `tests/Feature/Rams/VendoredSkillDriftGuardTest.php` now guard this
directory against in-place edits. They cannot detect upstream drift — **re-compare against
SCC manually before each remaining phase.**
