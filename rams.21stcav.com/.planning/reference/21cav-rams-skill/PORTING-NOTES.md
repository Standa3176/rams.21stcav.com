# 21cav-rams — handover notes for porting into the AV project delivery app

These notes are written for Claude Code. The rest of this folder is the skill
exactly as it runs today in Claude.ai. Nothing here has been edited for the port.

## What the skill actually is

A two-layer system, and the split matters more than anything else in the port:

**Layer 1 — judgement (currently the model).** Read a QuoteWerks quote or survey
notes, work out which hazards the job genuinely presents, score them honestly,
sequence the method steps in delivery order, and write method bullets specific
enough that an engineer can work to them. This layer is not deterministic and
should not be. It is the part that produces a RAMS rather than a template.

**Layer 2 — assembly (deterministic).** `scripts/build_rams.js` takes a JSON
document and emits a branded .docx. The script owns all layout, pagination,
fonts, colour, risk-band colouring and section numbering. It has no opinions
about safety content.

The JSON file in the middle is the contract between the two layers. If you port
nothing else cleanly, port that boundary.

## File map

| File | Role |
|---|---|
| `SKILL.md` | Orchestration — the workflow the model follows |
| `references/quote-extraction.md` | How to read a QuoteWerks PDF into RAMS content |
| `references/house-rules.md` | Settled 21CAV safety positions. Non-negotiable |
| `references/hazard-library.md` | Hazard bank with typical L×S scores and controls |
| `references/project-schema.md` | The JSON contract |
| `assets/example-project.json` | Complete worked example (VW Blakelands) |
| `scripts/build_rams.js` | JSON → .docx. Three sections: portrait front matter, landscape hazard register, portrait method statement |
| `scripts/brand.js` | Brand tokens and layout helpers (teal #01889F, gold #D4AF37, Verdana headings, Poppins body) |

`build_rams.js` depends only on the `docx` npm package. Invocation:

```bash
node scripts/build_rams.js project.json "OUT.docx"
```

## What maps to what in an app

- **`project.json` → database records.** The schema in `references/project-schema.md`
  is already close to a relational model: a project, a set of hazards, a set of
  method steps, and a handful of list/table sections. Hazards and method steps
  are the two that want proper tables with foreign keys; most of the rest is
  effectively structured text.
- **`hazard-library.md` → a seeded hazard table.** Controls are ordered lists
  belonging to a hazard. Typical initial and residual scores are defaults that a
  user or the model then adjusts per job. Do not let the app apply the typical
  scores silently — see the scoring note below.
- **`house-rules.md` → validation rules, not prose.** Most of these are
  mechanically checkable. See "validation gates" below.
- **`build_rams.js` → a document generation service.** Keep it as-is if the app
  is Node; port carefully if not, because the layout tuning in it is the result
  of real review feedback (column widths, page breaks, orphan headers).

## Validation gates worth implementing in code

These are the recurring defects in generated RAMS output. They are all
deterministic checks and would be far more reliable as code than as instructions
to a model:

1. **Orphan controls.** Every method step or hazard control that references a
   document, permit or hold point must have a matching hazard row *and* a
   matching `clientReqs` entry. The classic failure is "review the asbestos
   register" with no asbestos hazard behind it.
2. **Every area has at least one method step.**
3. **Every method step has exactly one `risks` line**, and every RA reference in
   it resolves to a hazard that exists.
4. **Residual score ≤ initial score** on every hazard, and residual severity is
   normally unchanged (controls reduce likelihood, not severity — a falling
   display is still fatal, you just make it less likely). Flag any hazard where
   `s2 < s1` for human review rather than accepting it.
5. **Uniform scoring detection.** If most hazards share the same initial score,
   the register was assembled from the library rather than the job. Warn.
6. **FFP2 anywhere → error.** House rule is FFP3 with face-fit testing.
7. **"Confined space" applied to a ceiling void, comms room or riser → error.**
   These are not confined spaces under the 1997 Regulations.
8. **Access equipment contradiction.** Something listed as excluded in one
   section and required as a control in another. Podium steps are the usual one.
9. **Display lift specified as anything other than two-operative → error.**
10. **COSHH and standards padding.** Cross-check every COSHH substance and every
    cited standard against the activity list. Soldering flux with no soldering
    activity, laser safety on a Teams Rooms job, BS EN 60849 / BS 8492 / HSG 47
    where none apply.
11. **CDM duty holder table left as "[To be confirmed]"** on an occupied-premises
    job. There is a settled position for this — see house-rules.
12. **Named A&E must be a real A&E.** This has bitten us: a subcontractor RAMS
    named a hospital whose A&E closed in 2014. Consider a lookup rather than
    free text.

## Things the quote never contains

The app should model these as explicit unknowns with a state, not as blank
fields that quietly render empty:

- Building age and asbestos register status
- Ceiling or mounting height (decides tower vs MEWP)
- Substrate type at each mounting position
- Confirmed weights of displays and client-supplied equipment
- Named engineers
- Whether an area is trafficked

Each of these is a pre-start hold point in practice. A hold point wants to be a
first-class object in the app — with an owner, a state (open / released /
failed), and a rule that a failed hold point blocks the works — rather than a
bullet in a list. That is the single biggest upgrade the app can make over the
skill.

## Known weaknesses in the current skill

Worth fixing rather than porting faithfully:

- **Revision handling is manual.** `revisions` is a free-form array. The app
  should own revision letters, supersede prior issues, and diff between
  revisions so a reviewer can see what changed.
- **No reuse across projects.** Every RAMS starts from the library. Site-level
  facts (asbestos register, building access, welfare, nearest A&E, loading bay
  arrangements) should be stored against the site and inherited by every job at
  that address.
- **No review workflow.** Client and principal contractor review comments come
  back and are currently addressed by hand. Comments want to be attached to
  hazard rows and method steps.
- **Section cross-references are hand-written** (`§6.4`, `§6.6`) and break when
  optional sections are omitted, because the script numbers subsections
  dynamically. The generator should resolve these itself.
- **Toolbox talk record is an empty appendix table.** In an app it should be a
  capture surface with signatures.
- **No output of the source data.** The JSON is currently a throwaway
  intermediate. Persist it — it is the audit trail for how the document was
  produced.
- **`itIntegration` and some other sections are Teams-Rooms-shaped** and read as
  padding on jobs that have no network integration. Should be conditional on
  activity.

## What not to lose in the port

The skill produces good RAMS mainly because of the restraint written into
`house-rules.md` and `hazard-library.md` — include only what the job involves,
score honestly, no orphan controls, no padded standards tables. An app makes it
very easy to regress on all four, because a form with every field visible invites
filling every field in. Whatever the UI, the default should be an empty register
that the user adds to, never a full register the user prunes.
