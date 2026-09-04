# Reading a QuoteWerks quote for RAMS purposes

21CAV quotes come out of QuoteWerks as a PDF, usually named
`21CQ<number>-OPS<something>.pdf`. The internal (OPS) version shows buy, sell and
supplier columns; the client version doesn't. Either works — the RAMS only needs
the descriptive content and the line items.

## Structure

The quote is organised into **named sections**, each with a heading, a paragraph
or two of narrative, then line items and a subtotal. The section headings are the
work areas. A typical strip-out-and-reuse job looks like:

```
Willen Decommission          ← area, decommission
Nadin Decommission           ← area, decommission
GND & Nadin Installation     ← area, install (often covers two rooms)
HR Offices Installation      ← area, install (often covers two rooms)
Commercial Vehicles Stand Up ← area, install
Professional Services        ← boilerplate conditions
Services                     ← labour lines
```

## What each part gives you

**Section narrative** — the richest source. It describes what is being installed
where, how it is mounted, and often flags site constraints directly. Read it
closely: phrases like *"mounted on the right-hand pillar, positioned just below
the ceiling recess"*, *"Client to ensure wall is sufficiently reinforced if
mounting wall is Gypsum"* or *"two new ceiling microphones will be installed in
the ceiling above"* each drive specific hazards and method steps.

**Line items** — the equipment schedule. Note:
- `CLIENTSUPPLIED` in the part number and supplier columns means client-supplied
  or reused kit, not 21CAV supply. The sub-description usually says where it came
  from or where it's going (`From Willen`, `To CV Standup`, `To GND & Nadin`).
- Nested sub-descriptions list system components (e.g. a Rally Plus expands to
  camera, display hub, table hub, speakers, mic pods, remote, PSUs). Capture the
  components — they are what actually gets handled and mounted.
- Watch item colour and variant. Graphite and white mic pods are different lines
  for different rooms; getting this wrong sends an engineer to site with the
  wrong part.

**Services section** — the labour lines size the job:
- `FIRST FIX` qty = first fix days
- `INSTALL2` qty = installation and decommission days
- `CONFIGURATION` = configuration effort, and the note about needing client IT access
- `Travel1` qty = travel days, and `Outside M25` tells you occupational road risk applies
- `SSVOTHER` = site survey purchased, so survey-confirmed hold points are legitimate
- `ELEVATION`, `RAMS` = drawing and documentation deliverables

**Professional Services boilerplate** — reusable conditions. The standard block
covers technical site survey, in-hours working 09:00–17:30, rubbish removal being
the client's responsibility, and the warning that missing power/network/licences
may cause a failed installation and chargeable return visit. Quote these back into
exclusions and client responsibilities rather than paraphrasing — they are already
contractual.

## Turning it into RAMS content

| Quote element | Feeds |
|---|---|
| Section headings | `areas`, `project.rooms` |
| Section narrative | `areas` works description, `methodSteps` bullets |
| Line items | `equipment`, `materialHandling` |
| CLIENTSUPPLIED + "From X" | equipment `source` = "Reused — from X" |
| Mounting descriptions | working at height, fixings hazards; access equipment |
| Ceiling / pillar mounting | ceiling void hazard, soffit support rule, MEWP trigger |
| Display sizes | `materialHandling` rows |
| Gypsum / reinforcement notes | `clientReqs` hold point, fixings hazard |
| Power / PoE prerequisites | `clientReqs` hold point |
| Travel days, outside M25 | occupational road risk hazard |
| Working hours | `project.hours` |
| Labour day counts | `project.duration`, team sizing |

## Fields the user provides — use them, don't leave them as hold points

The app passes user-completed project fields alongside the quote. When present,
map them into `project` and use them as fact (not "to be confirmed"):

| Provided field | Maps to |
|---|---|
| `planned_start_date`, `planned_end_date` | `project.date` (start) and `project.duration` (derive the span, e.g. "start–end") |
| `lead_engineer` | `project.lead` |
| `engineers` | `project.engineers` |
| `site_contact_name` + `site_contact_phone` | `project.clientContact` where no client contact is given, the Site contact row in `emergencyContacts`, and welfare/coordination |
| `client_contact` | `project.clientContact` |
| `project_manager` | `project.pm` |
| `working_hours` | `project.hours` |
| `rooms` | `project.rooms`, and seed `areas`/method steps |
| `access_notes` | access requirements, asbestos/coordination hold points as appropriate |

Only the equipment weights, mounting heights and substrates remain survey hold
points once these are supplied — so a RAMS with all fields provided should read as
genuinely project-specific, not generic.

## Things the quote won't tell you

Flag these rather than inventing them:

- **Building age and asbestos status** — always a client hold point
- **Ceiling height** where ceiling-mounted devices are specified — decides tower
  vs MEWP, so make it a survey-confirmed item
- **Substrate type** at each mounting position
- **Exact weights** of client-supplied displays — give an indicative figure and
  mark it as confirm-at-survey rather than stating it as fact
- **Named engineers** — leave blank or as supplied by the user
- **Whether the area is trafficked** — an area name like "Commercial Vehicles"
  strongly implies FLT and vehicle movement, but confirm rather than assume

## Build a real, room-by-room equipment schedule

The quote's line items are the equipment schedule — use them. Populate
`equipment` room-by-room with the actual makes/models, quantities and location
from the quote (not "displays, mounts, speakers"). In `materialHandling`, identify
the **heaviest items** and give their lift method; where a weight isn't in the
quote, give an indicative figure marked confirm-at-survey rather than omitting it.
A method statement that walks the actual kit into the actual rooms is what makes
the RAMS project-specific rather than generic safety theory.

**Don't invent scope.** Only name equipment types in the scope/activities that the
quote line items actually contain. If the quote doesn't specify speaker arrays,
ceiling microphones or wall-mounted displays, keep the scope generic ("AV equipment
per the quoted schedule") until the survey confirms them — do not let the scope be
more specific than the known equipment schedule.

## Sanity checks before building

- Every area in the quote has at least one method step
- Every reused item has a stated origin and destination
- Item quantities across decommission and reuse sections reconcile
- Any hazard control referencing a document (asbestos register, drawings)
  has a matching entry in `clientReqs`
