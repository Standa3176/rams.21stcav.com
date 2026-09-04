# 21CAV house rules for RAMS

Standing positions. These are settled decisions — apply them without asking
unless the quote or the user says otherwise for a specific job.

## Write the actual job, not the safety theory

The most common failing on review is a RAMS that is elaborate on generic controls
(resin anchors, chemical anchors, PoE budgets, tower ranges) while still saying
"Rooms — to be confirmed" with no real equipment list. A client wants to read
*their* job: arrive at reception → sign in / induction → deliver equipment to the
defined work area → establish exclusion zone → install the specified item in Room
X → panel weighs ~X kg and is moved by two engineers using Y → bracket fixed to
the verified substrate using the manufacturer's approved fixing → maximum working
height ~X m using podium/tower → drilling controlled for noise, dust and nearby
smoke detectors → commission → clean → hand over.

Populate rooms, equipment, weights, heights, access method, engineers and site
contact from the quote and the user wherever they are known. Where they are
genuinely unknown, make them **explicit pre-start hold points** — never silent
blank fields, and never invented specifics.

## Document status — do not over-claim "For Issue"

Default `project.status` to **"Draft — Preliminary (subject to technical survey)"**
whenever any of these are still unknown or "to be confirmed": room names, the
actual equipment schedule, item weights, mounting heights, substrates, the
selected access method, named engineers, or the site contact. Only use **"For
Issue"** once those are populated. Never mark a document "For Issue" while its own
text says the survey, equipment schedule or labour allocation are still
outstanding — that contradiction fails review on its own.

Always populate the document-control (`revisions`) table — e.g. `["Rev A",
"<date>", "<preparedBy>", "Initial issue", "<status>"]`. A blank revision/approver
block on a document stamped "For Issue" is an audit finding.

## Manual handling

**All displays are two-operative team lifts**, regardless of panel size. Do not
specify four-operative lifts or make the lift team conditional on screen size.
Mechanical aids (trolley, panel lifter) are used in addition where available, not
instead of the second person.

Give the heaviest items and their weights (indicative, marked confirm-at-survey
where the quote doesn't state them) and the lift method for each — not just
"displays, mounts, speakers".

**Removal / strip-out language is removal-only.** Only describe taking a display
off an existing mount, "the highest-risk lift on a strip-out", decommissioning or
release "during removal" when the quote scope actually includes removing existing
equipment. On an installation-only job, leave all of that out and omit the
Decommissioning/WEEE hazard row entirely.

## Respiratory protection

**FFP3, not FFP2** (and never the typo "FFE3"). Drilling into masonry and concrete
generates respirable crystalline silica. Specify face-fit testing.

## Electrical scope boundary

21CAV works terminate at the existing socket outlet or client data outlet. **No
alteration to the fixed electrical installation, ever. No live working under any
circumstances** — there is no AV task that justifies it.

Because the normal scope is *connecting to an existing socket*, do **not** dress
that up as work on the fixed installation: no circuit isolation, lock-off,
test-dead or "compliance with BS 7671" language for plugging equipment in. Reserve
lock-off / test-dead / BS 7671 wording for genuine work on the fixed installation,
and state that such work is carried out by the **client's competent electrician**
unless it is expressly within 21CAV's scope. Where a hardwired supply must be
isolated, it is isolated by the client's authorised person with site lock-off.

**Never produce a live-working PPE row** (e.g. insulated gloves "for live
working"). It directly contradicts "no live working" and always gets picked up.

**Cabling terminology:** never call it "first-fix power" or "power cabling" — in
construction terms that reads as fixed mains installation, which 21CAV excludes.
Call it **"first-fix AV signal/data/ELV cabling"**. Equipment power is limited to
the manufacturer-supplied flexes plugged into existing socket outlets.

## Fixings — selection criteria, not a fixing recipe

**Never auto-specify an anchor diameter, drill size, embedment depth or fixing
type from the substrate word alone.** "Masonry" or "concrete" in the quote does
not tell you the anchor. State that the fixing is **selected after the substrate
is verified on site, in accordance with the mount and fixing manufacturer's
published design/load data** — and, for post-installed anchors in concrete or
masonry, **in accordance with BS 8539:2012+A1:2021** — with structural-engineering
advice where the load, edge distance or substrate is marginal. Do not print "M8
resin anchor, 10 mm hole, 50 mm embedment" (or any specific figures) as an assumed
spec.

Do **not** mandate a blanket "minimum 4:1 safety factor" as if it were a universal
rule — the anchor's suitability comes from the manufacturer's design data and BS
8539, not an invented factor. Any proof/pull test is carried out **only where, and
to the value, specified by the fixing manufacturer's procedure** — do not promise
a blanket pull-test of *every* fixing to an invented value (e.g. 1.5× weight),
which commits engineers to a test regime with no defined method. Photograph every
completed fixing for the handover file.

Where the substrate is gypsum/plasterboard, the client's written confirmation of
reinforcement is obtained before drilling — normally already a condition in the
quotation, so quote it back.

## Fire-stopping — one consistent position

Take a single position across exclusions, the hazard register and QA. Standard
21CAV position: **fire-stopping is excluded** — any penetration of a fire-rated
element is sealed by others / referred to the client with a specified fire-stopping
detail before proceeding. Do not simultaneously exclude fire-stopping and then
claim in a hazard row or QA that 21CAV fire-stops penetrations "to the original
rating". Only state that 21CAV fire-stops if the quote scope explicitly includes
an approved fire-stopping system.

## Access equipment

Podium steps or a mobile access tower are the working platform. Step ladders are
for short-duration light-duty tasks only and are never a working platform for
extended or two-handed work. Where the client restricts step ladders under a
permit, state that explicitly rather than listing them as freely available.

State the actual mounting/fixing heights, the expected duration at height and the
selected access method per task where known; where unknown, make MEWP vs tower a
survey-confirmed trigger rather than an assumption either way.

Never write "podium steps excluded" alongside a hazard row that lists them as a
control — that contradiction has appeared in generated output before.

**Raising items to a platform:** small tools and components are raised/lowered in a
secured tool bag or by hand line; equipment is positioned using the planned
mechanical/manual-handling method appropriate to its weight and dimensions. Never
write that a trolley delivers equipment to a tower platform (it can't), and never
pass large AV equipment up in a tool bag.

## Ceiling work

All AV load is supported from the structural soffit or a purpose-designed ceiling
mount kit. Never from the suspended ceiling grid, pipework, sprinkler pipe or
other services. State this wherever ceiling-mounted devices appear.

Ceiling voids and comms rooms are **not confined spaces** under the Confined
Spaces Regulations 1997. Title the hazard "Restricted access and ceiling void
working". Claiming confined space invites a permit regime the job doesn't need,
and do not cite the confined-spaces ACOP (L101) for this work.

## Drilling near fire detection

Before drilling in occupied premises, coordinate with Facilities to **identify
smoke/heat detectors near the work**, arrange any permitted temporary isolation or
detector protection through the client's authorised person, and **remove
covers / reinstate detection immediately after drilling**, confirming the system
is back in service. A drilled dust cloud under a live smoke head causes an
evacuation — say how it is prevented.

## Asbestos

The client asbestos register is a pre-start hold point. **Where the client has
already confirmed an asbestos survey exists and records no ACM for the work area,
record that as "register received and reviewed — no ACM identified for the work
area"**, and keep the stop-work-on-suspect-material procedure. Only say the
register "must be provided" when it genuinely has not been. If the method
statement asks for register sign-off, the hazard register must contain an asbestos
row — a control with no hazard behind it is an audit finding. 21CAV never disturbs
suspected ACM under any circumstances.

## Standards and legislation

Cite standards and legal instruments **only** from `standards-and-legislation.md`,
by the exact designation there. Cite only what the job actually involves — do not
pad the table, and never invent a code, an edition or an ACOP number. Same
principle for COSHH: list only substances actually carried (soldering flux and UPS
battery acid do not belong on a Teams Rooms install).

**Any standard you rely on in the body** (QA, method, notes) **must also appear in
the Section 3 standards table.** If the job includes AV signal/data cabling, that
means BS 6701:2016+A1:2017 and BS EN 50174-2:2018 belong in the table, not only in
a QA bullet.

## Team, competence and consistency

Use the provided team fields consistently. If a lead engineer / engineers are
given, name them on the cover **and** in the team table — never leave the team
table saying "Named at survey" when a lead is already supplied.

Do not assert specific certifications (CSCS, PASMA, Asbestos Awareness) for every
operative as fact unless you know it is true. Safer wording: "Relevant competence
and certification confirmed for the tasks allocated; PASMA-trained operatives used
where mobile access towers are erected, altered or dismantled."

The client is known from the context — put the client's name in the CDM "Client"
row and in client fields, never "To be confirmed" alongside the client's own name.
Leave "Accepted by (Client)" blank for the client to sign; do not auto-fill it with
the site contact.

**The COSHH table holds substances/processes with a health hazard only** —
respirable dust, adhesives, sealants, cleaning agents. Never put electrical,
manual-handling, working-at-height or any other physical-safety hazard in the
COSHH table; those belong in the risk register (RA rows). "Electrical hazards" in
a COSHH assessment is a standard review reject.

**Keep COSHH consistent with the tools/method.** If the tools or method mention a
substance (cable lubricant, adhesive, sealant, cleaner), either assess it in the
COSHH table or don't claim "no other COSHH substances are carried". A safe closing
line is: "No other hazardous substances are planned; any additional product
introduced will be subject to SDS/COSHH review before use."

## CDM 2015

Follow the CDM section of `standards-and-legislation.md`. In short: the PD/PC
appointment trigger is **more than one contractor on the project**, not 21CAV's
view of itself; a sole-contractor 21CAV prepares a **construction phase plan**;
contractor duties sit under **Regulation 15**. Never write that the client
"retains Principal Designer responsibilities" or that 21CAV discharges its duties
under Regulations 4 or 5. Put the position in `cdmNote` rather than leaving "[To be
confirmed]" in the duty-holder table.

## Scope boundaries to state explicitly

These recur on almost every job and are worth stating even when obvious:

- Making good of walls, floors and ceilings after works is the responsibility of others
- Rubbish removal is the client's responsibility unless previously agreed in writing
- No IT network provision, IP addressing, VLAN configuration or licensing
- No structural works or reinforcement
- No hot works. If hot work becomes necessary, work stops and a permit is obtained
- No asbestos survey, sampling or removal
- No fire-stopping (unless expressly in scope)

## Inductions and briefings

The mandatory **site-specific induction is conducted by the client / site
management**, not by 21CAV's Project Manager — do not assign it to the PM. 21CAV
conducts its **own toolbox talk / RAMS briefing** (Appendix A) to its engineers
before work starts. Keep the two distinct.

## Don't invent figures

Do not state specific numeric values the quote/tools don't give you — e.g. a
drilling noise figure like "80–95 dB(A)", an anchor size, an equipment weight, a
no-drilling distance like "within 1 m of a detector", an access-height switching
threshold like "tower for work above 2.5 m", or a fixed travel time like "< 60
minutes from Reading". Select access equipment (podium / tower / MEWP) from the
actual task, working height, the tower manufacturer's configuration and the ground
conditions — not a generic height threshold. Say the activity is noise-generating
and that exposure is assessed from the actual tool sound data and site conditions;
give weights as indicative, confirm-at-survey.

Conversely, **do populate the facts you already have.** The 21CAV office /
operations number is **01189 977770** — always fill it in, never "to be confirmed".

**Qty columns hold quantities only.** In the team and material-handling tables the
Qty cell takes a number or "TBC" — never a long phrase (e.g. do not put "Equipment
schedule and weights" or "To be confirmed at survey" in the Qty column; those go in
the Item / Requirements column). A long string in a narrow Qty cell wraps one
character per line and looks broken on the page.

## Exposure limits and RIDDOR

Use the correct terms (see `standards-and-legislation.md`): respirable crystalline
silica is controlled below its **WEL (0.1 mg/m³, 8-hour TWA)** — never an "EAV",
which belongs to noise/vibration only — and don't put asbestos-awareness training
in the RCS control. RIDDOR reporting is **0345 300 9923** (fatal/specified
injuries) or online; never invent an "HSE Incident Hotline" number.

## Emergency arrangements

**Do not assert a specific hospital as the nearest A&E unless it is a verified
24/7 Emergency Department.** Urgent-care centres and minor-injury units are not
A&E, and hospitals downgrade — a wrong A&E is a genuine safety defect. If the
nearest 24/7 ED is not verified for the site, record "Nearest A&E — to be
confirmed at induction (must be a 24/7 Emergency Department)" as a hold point
rather than guessing a name. Where it is verified, give the hospital, full address
and postcode, and add that route and travel time are confirmed at induction.

## Residual risk and the matrix

Score honestly. Where a residual score remains **MEDIUM**, state explicitly that
it has been **reduced ALARP and is accepted with the listed controls in place** —
do not leave a medium residual sitting against matrix wording that says medium
"requires further action to reduce risk". Residual must never exceed the initial
score.

## Method statement structure

One "Associated risks" line per step, listing the RA references that actually
apply to that step. Never two lines, and never a generic list repeated across
every step.

Number the steps in delivery order. For an installation job: induction → delivery
and set-down → exclusion zone set-up → first fix (one step per area) → mounting
and second fix (one step per area) → configuration and commissioning → clean and
handover. Add decommission/strip-out steps only where removal is in scope.

## Tone

Precise and practical. Short sentences. Imperative for instructions to engineers.
No hedging — a RAMS that says work "should" be done a certain way is weaker on
review than one that says it "is".
