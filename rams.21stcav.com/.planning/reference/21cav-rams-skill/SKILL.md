---
name: 21cav-rams
description: Produce a full 21st Century AV Risk Assessment & Method Statement (RAMS) as a branded Word document from a QuoteWerks quotation PDF, survey notes or a scope description. Use this whenever the user mentions RAMS, a risk assessment, a method statement, a hazard register, a toolbox talk record, safe systems of work for an AV install, or uploads a 21CAV quote reference like 21CQ... and asks for site documentation. Also use when asked to review, correct, re-score or rebrand an existing RAMS, when a client or principal contractor has returned RAMS review comments to be addressed, or when asked what hazards or controls apply to an AV installation, decommission or strip-out.
---

# 21CAV RAMS

Builds a single combined Risk Assessment & Method Statement in 21CAV house style
from a QuoteWerks quotation. Output is one A4 Word document — portrait front
matter, a landscape hazard register, then the method statement — plus a PDF.

The document is assembled by a script from a JSON file. Your job is to read the
quote properly, make the safety judgements, and write good JSON. Do not
hand-build the Word document; the script owns layout so every issue looks the
same and the brand stays consistent.

## Workflow

### 1. Read the quote

Read `references/quote-extraction.md` first. It explains QuoteWerks section
structure, what `CLIENTSUPPLIED` means, how to read reuse flags, and which quote
elements feed which parts of the RAMS.

Extract: work areas, the narrative for each, the equipment schedule with reuse
origins, labour day counts, working hours, and the standard conditions in the
Professional Services block.

### 2. Decide the safety content

Read `references/house-rules.md` — these are settled 21CAV positions (two-person
display lifts, FFP3, ceiling loads from the soffit, scope boundaries, what not to
cite). Apply them without asking. Read `references/standards-and-legislation.md`
for the canonical standards/legal designations — cite standards and CDM duties
**only** from there, verbatim; never invent a code, an edition or an ACOP number.

Then read `references/hazard-library.md` and select the hazards the job actually
involves. Include a row for every hazard the work presents, and no rows for
hazards it doesn't. Two failure modes to avoid, both of which have shown up in
real output:

- **Padding** — citing laser safety, voice alarm or PA standards on a Teams Rooms
  install, or listing soldering flux under COSHH when nothing is soldered. It
  invites a reviewer to test compliance with things that aren't in scope.
- **Orphan controls** — a method step that says "review the asbestos register"
  with no asbestos hazard in the register behind it. Every control that references
  a document, permit or hold point needs a matching hazard row and a matching
  entry in `clientReqs`.

Score honestly. A ceiling-mounted microphone in an industrial space is not the
same working-at-height risk as a display at 1.2 m, and scoring them identically
is the tell that a register was assembled from a library rather than the job.

### 3. Write the JSON

Read `references/project-schema.md` for the full structure and
`assets/example-project.json` for a complete worked example (the VW Blakelands
job — strip-out, reuse across five spaces, ceiling mics in a commercial vehicle
area).

Sequence method steps in delivery order. Where there's a strip-out, that's:
induction → decommission → retained equipment inspection → first fix → second fix
(one step per area type) → configuration → handover. Give each step exactly one
`risks` line listing the RA references that genuinely apply to that step.

Write method bullets so an engineer can work to them. Name the room, name the
equipment, say what connects to what. "Install the display" is useless; "Within
the GND room, confirm wall reinforcement is in place and a mains outlet and PoE
point are present at the display position before commencing any fixing" is a step
someone can follow and a client can audit.

### 4. Build and check

```bash
cd <skill-dir>
node scripts/build_rams.js /path/to/project.json "/mnt/user-data/outputs/<QUOTEREF> - <Client> <Site> - RAMS <Rev>.docx"
```

`docx` is preinstalled — don't npm install. Then render and actually look at it:

```bash
python3 /mnt/skills/public/docx/scripts/office/validate.py <out.docx>
python3 /mnt/skills/public/docx/scripts/office/soffice.py --headless --convert-to pdf <out.docx>
pdftoppm -jpeg -r 80 <out.pdf> page
```

View the cover, the hazard register and one method statement page at minimum.
Watch for narrow table columns wrapping letter-by-letter, and orphaned section
headers at page bottoms — fix by adjusting column widths or adding a page break.

Deliver both the .docx and the .pdf.

### 5. Report the gaps

Close by naming what still needs filling before issue — this is the most useful
part of the response and shouldn't be buried. Typically: named engineers,
confirmed display weights, ceiling height where it decides tower vs MEWP,
substrate type per mounting position, and asbestos register status.

If the quote implies a resourcing or sequencing problem, say so rather than
quietly papering over it.

## Reviewing an existing RAMS

Same references apply. Convert with `pandoc -t markdown` and read it all before
commenting.

The recurring defects in generated RAMS output, worth checking specifically:

- Duplicate or contradictory "Associated Risks" lines on method steps
- Equipment schedules with duplicated rows, everything flagged as new supply, or
  no room allocation
- Standards tables padded with out-of-scope references
- Internal contradictions — access equipment excluded in one section and required
  in another is the classic
- FFP2 where FFP3 is needed
- "Confined spaces" applied to comms rooms and ceiling voids
- Missing asbestos and vehicle-movement hazards
- CDM duty holder tables left as "[To be confirmed]" on occupied-premises jobs

Fix mechanical and factual errors directly and list what you changed. Flag
judgement calls — resourcing, scoring, commercial scope — rather than deciding
them silently.

## Files

- `scripts/build_rams.js` — JSON → branded .docx
- `scripts/brand.js` — brand tokens and layout helpers (teal #01889F, gold #D4AF37, Verdana headings, Poppins body)
- `references/quote-extraction.md` — reading a QuoteWerks PDF
- `references/house-rules.md` — settled 21CAV safety positions
- `references/standards-and-legislation.md` — canonical standards/legal codes + CDM duties
- `references/hazard-library.md` — hazard bank with typical scores and controls
- `references/project-schema.md` — JSON structure
- `assets/example-project.json` — complete worked example
