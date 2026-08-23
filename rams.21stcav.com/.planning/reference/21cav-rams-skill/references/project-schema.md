# project.json schema

Input to `scripts/build_rams.js`. Every key except `project` is optional — omit a
key and that section is skipped. Tables are arrays of arrays (row → cells);
lists are arrays of strings.

Use `assets/example-project.json` as a working starting point.

## Top level

```jsonc
{
  "project": { ... },              // required
  "coverCallout": ["line", ...],   // gold box on cover — pre-start hold points
  "revisions": [[rev, date, author, description, status]],
  "policy": ["paragraph", ...],
  "standards": [[ref, title, appliesToOnThisProject]],
  "activities": ["works activity", ...],
  "areas": [[areaName, type, worksDescription]],
  "equipment": { "Area name": [[item, qty, source]] },
  "exclusions": ["...", ...],
  "clientReqs": ["...", ...],
  "hazards": [ { ... } ],
  "team": [[role, qty, requirements]],
  "teamCallout": "gold box under the team table",
  "tools": ["...", ...],
  "ppe": [[task, ["ppe item", ...]]],
  "accessEquipment": ["...", ...],
  "accessRequirements": ["...", ...],
  "methodSteps": [ { "title": "", "bullets": [], "risks": "RA01, RA04" } ],
  "materialHandling": [[qty, item, handlingMethod]],
  "materialHandlingNote": "paragraph under the table",
  "permits": ["...", ...],
  "fixings": ["...", ...],
  "qa": ["...", ...],
  "coordination": { "intro": "", "points": [] },
  "itIntegration": { "intro": "", "points": [], "closing": "" },
  "coshh": [[substanceOrProcess, controls]],
  "coshhNote": "callout under the COSHH table",
  "waste": ["...", ...],
  "noiseDust": ["...", ...],
  "welfare": [[label, value]],
  "cdm": [[role, name]],
  "cdmNote": "gold callout under the CDM table",
  "emergencyContacts": [[contact, number]]
}
```

## project

```jsonc
{
  "quoteRef": "21CQ30960-OPS",
  "docRef": "21CQ30960-RAMS",
  "rev": "Rev A",
  "date": "17/08/2026",
  "status": "For Issue",
  "subtitle": "AV DECOMMISSION, INSTALLATION & COMMISSIONING",  // gold band on cover
  "client": "Volkswagen Group",
  "clientContact": "Nick Chapman",
  "site": "Yeomans Drive, Blakelands, Milton Keynes, MK14 5AN",
  "rooms": "Willen Decommission, GND & Nadin Installation, ...",
  "preparedBy": "Sonny Tanda",
  "pm": "Sonny Tanda",
  "lead": "Goldy Singh",
  "engineers": "Jay Singh",
  "hours": "Monday–Friday, 09:00–17:30",
  "duration": "1 day first fix, 4 days installation and decommission"
}
```

Company details (address, phone, company number, VAT, accreditation) are
hard-coded in the script — do not put them in the JSON.

## hazards

```jsonc
{
  "ref": "RA01",
  "hazard": "Working at Height",
  "persons": ["21CAV Staff", "Client Staff", "Others"],
  "l1": 3, "s1": 4,                    // initial, no controls
  "controls": ["control 1", "control 2"],
  "l2": 1, "s2": 4                     // residual, all controls applied
}
```

The script computes L×S, colours the cell by band (1–4 LOW green, 5–9 MED amber,
10+ HIGH red) and renders the controls as a numbered list. Do not pre-number the
control strings.

## Table cell formatting

Any cell in `standards`, `areas`, `equipment`, `team`, `materialHandling`,
`coshh` or `revisions` can be a plain string, or an object for control over
appearance — but in practice plain strings are right and the script applies the
house formatting (bold first column, zebra striping, reuse flagged in red)
automatically. Keep the JSON plain.

## Output

```bash
node scripts/build_rams.js project.json "OUT.docx"
```

Three sections: portrait front matter, landscape hazard register (skipped if
`hazards` is absent or empty), portrait method statement. A4 throughout, teal
section bars, gold accents, running header and footer with page numbers.
