#!/usr/bin/env node
/**
 * 21CAV RAMS builder.
 *
 * Usage:  node build_rams.js <project.json> <output.docx>
 *
 * Reads a project JSON file (see references/project-schema.md) and emits a
 * branded, three-section A4 Word document: portrait front matter, landscape
 * hazard register, portrait method statement.
 *
 * Every section is optional. If a key is absent from the JSON, that section is
 * silently skipped — so a small job can produce a short document without
 * editing this script.
 */

const fs = require("fs");
const path = require("path");
const {
  Document, Packer, Paragraph, TextRun, AlignmentType, PageBreak, PageOrientation,
  Table, TableRow, TableCell, WidthType, ShadingType, VerticalAlign,
} = require("docx");
const B = require(path.join(__dirname, "brand.js"));

const {
  TEAL, TEAL_DARK, TEAL_LIGHT, GOLD, GOLD_LIGHT, WHITE, TEXT, TEXT_LIGHT,
  LOW_FILL, MED_FILL, HIGH_FILL, VHIGH_FILL, HEAD_FONT, BODY_FONT,
  A4, MARGIN, CONTENT_W, LAND_CONTENT_W, cellBorders, cellMargins, noBorder,
  p, bullet, sp, pageBreakBefore, qtyCell, sectionHeader, subHeader, calloutBox,
  fieldTable, dataTable, coverBlock, runningHeader, runningFooter, internalFooter, numbering,
} = B;
const { TableLayoutType } = require("docx");

const [, , jsonPath, outPath] = process.argv;
if (!jsonPath || !outPath) {
  console.error("Usage: node build_rams.js <project.json> <output.docx>");
  process.exit(1);
}
const D = JSON.parse(fs.readFileSync(jsonPath, "utf8"));
const P = D.project || {};

const REV = P.rev || "Rev A";
const HDR = `21st Century AV Ltd  |  Risk Assessment & Method Statement  |  ${P.quoteRef || ""}  |  ${REV}`;
const FTR = `${P.docRef || P.quoteRef || ""} ${REV}  |  © 21st Century AV Ltd — Confidential`;
// A forced page break, reserved for genuine top-level document divisions only
// (cover, risk assessment, COSHH, emergency, sign-off). Implemented with the
// safe pageBreakBefore paragraph property — see brand.js — never a PageBreak run.
const pb = () => pageBreakBefore();

const bandFill = v => (v >= 17 ? VHIGH_FILL : v >= 10 ? HIGH_FILL : v >= 5 ? MED_FILL : LOW_FILL);
const bandName = v => (v >= 10 ? "HIGH" : v >= 5 ? "MED" : "LOW");

/* ========================= SECTION A — front matter ========================= */
const A = [];

A.push(...coverBlock(
  ["Risk Assessment", "& Method Statement"],
  P.subtitle || "AV INSTALLATION & COMMISSIONING",
));
A.push(sp(240));
A.push(fieldTable([
  ["Client", P.client || ""],
  ["Site", P.site || ""],
  ["Project reference", P.quoteRef || ""],
  ["Rooms", P.rooms || ""],
  ["Date", P.date || ""],
  ["Revision", REV],
  ["Status", P.status || "For Issue"],
]));
A.push(sp(160));
A.push(fieldTable([
  ["Prepared by", P.preparedBy || ""],
  ["Telephone", "01189 977770"],
  ["Client contact", P.clientContact || ""],
  ["Project Manager", P.pm || ""],
  ["Lead Engineer", P.lead || ""],
  ["Engineers", P.engineers || ""],
]));
// quick-260903-nocover — the gold cover callout was removed: it duplicated the
// Section 4 "Client responsibilities and pre-start hold points" (clientReqs) and
// the cover Status field. Hold points now live only in Section 4; the cover keeps
// its Status field. coverCallout is no longer rendered even if present in the JSON.

// 1. Document control
A.push(pb());
A.push(sectionHeader("1. Document control"));
A.push(sp(140));
A.push(dataTable(
  ["Rev", "Date", "Author", "Description", "Status"],
  (D.revisions || []).concat([["", "", "", "", ""], ["", "", "", "", ""]]),
  [900, 1300, 1500, 4446, 1600],
));
A.push(sp(200));
A.push(subHeader("Authorisation"));
A.push(dataTable(
  ["Role", "Name", "Signature", "Date"],
  [
    ["Prepared by", P.preparedBy || "", "", ""],
    ["Reviewed by", "", "", ""],
    ["Approved by", "", "", ""],
    ["Accepted by (Client)", "", "", ""],
  ],
  [3000, 2746, 2400, 1600],
  { boldFirstCol: true },
));

// 2. Company information
A.push(sp(220));
A.push(sectionHeader("2. Company information"));
A.push(sp(140));
A.push(fieldTable([
  ["Company name", "21st Century AV Ltd"],
  ["Address", "Thames Court, 2 Richfield Avenue, Reading, Berkshire, RG1 8EQ"],
  ["Telephone", "01189 977770"],
  ["Website", "www.21stcenturyav.com"],
  ["Email", "info@21stcenturyav.com"],
  ["Company number", "03700669"],
  ["VAT number", "GB728816895"],
  ["Accreditation", "SafeContractor accredited. Employers' and Public Liability insurance certificates available on request."],
  ["Prepared by", P.preparedBy || ""],
]));

// 3. Policy + standards
if (D.policy || D.standards) {
  A.push(sp(240));   // flow naturally after §2; keepNext holds the heading with its content
  A.push(sectionHeader("3. Health & safety policy statement"));
  A.push(sp(140));
  (D.policy || []).forEach(t => A.push(p(t)));
  if (D.standards) {
    A.push(sp(160));
    A.push(subHeader("Standards & guidance applicable to these works"));
    A.push(dataTable(
      ["Reference", "Title", "Applies to (on this project)"],
      D.standards.map(([r, t, ap]) => [{ t: r, bold: true, color: TEAL_DARK }, t, ap]),
      [2100, 3200, 4446],
      { zebra: true },
    ));
  }
}

// 4. Scope of works
A.push(sp(260));
A.push(sectionHeader("4. Scope of works"));
A.push(sp(140));
if (D.activities) {
  A.push(subHeader("Works activities"));
  D.activities.forEach(t => A.push(bullet(t)));
  A.push(sp(160));
}
A.push(fieldTable([
  ["Client", P.client || ""],
  ["Site", P.site || ""],
  ["Rooms", P.rooms || ""],
  ["Working hours", P.hours || "Monday–Friday, 09:00–17:30"],
  ["Duration", P.duration || ""],
]));

if (D.areas) {
  A.push(sp(200));
  A.push(subHeader("Areas and works"));
  A.push(dataTable(
    ["Area", "Type", "Works"],
    D.areas.map(([a, t, w]) => [
      { t: a, bold: true },
      { t, bold: true, color: /decom/i.test(t) ? "B03A2E" : TEAL_DARK },
      w,
    ]),
    [1900, 1400, 6446],
    { zebra: true },
  ));
}

if (D.equipment) {
  A.push(sp(200));
  A.push(subHeader("Equipment schedule"));
  // Only mention reuse/decommission when the schedule actually contains reused
  // items — otherwise this note contaminates an installation-only RAMS.
  const hasReused = Object.values(D.equipment).some(items =>
    (items || []).some(row => /\b(reuse|reused|recovered|salvaged|decommission)\b/i.test(String((row || [])[2] || ""))));
  A.push(p(hasReused
    ? "Allocated by installation area. Items marked as reused are recovered during the decommission phases and are not new supply."
    : "Allocated by installation area.",
    { italics: true, size: 16, color: TEXT_LIGHT }));
  Object.entries(D.equipment).forEach(([area, items], i) => {
    A.push(sp(i === 0 ? 60 : 140));
    A.push(new Paragraph({
      spacing: { after: 80 },
      children: [new TextRun({ text: area, font: HEAD_FONT, size: 17, bold: true, color: TEAL_DARK })],
    }));
    A.push(dataTable(
      ["Item", "Qty", "Source"],
      items.map(([item, qty, src]) => [
        item,
        qtyCell(qty),
        { t: src, color: /reuse/i.test(src) ? "B03A2E" : TEAL_DARK },
      ]),
      [5646, 900, 3200],
      { zebra: true },
    ));
  });
}

if (D.exclusions) {
  A.push(sp(200));
  A.push(subHeader("Exclusions"));
  D.exclusions.forEach(t => A.push(bullet(t)));
}

if (D.clientReqs) {
  A.push(sp(200));
  A.push(subHeader("Client responsibilities and pre-start hold points"));
  A.push(p("Work will not commence until the following have been confirmed by the client. These are hold points, not advisory notes."));
  A.push(sp(60));
  D.clientReqs.forEach(t => A.push(bullet(t)));
  A.push(sp(120));
  A.push(calloutBox("Failure to have power, network and licensing requirements in place prior to installation may result in a failed installation and a chargeable return visit, as stated in the quotation.", { gold: true, bold: true }));
}

// 5. Risk methodology + matrix
A.push(pb());
A.push(sectionHeader("5. Risk assessment"));
A.push(sp(140));
A.push(p("The risk scoring matrix below is used throughout this assessment. Likelihood (L) × Severity (S) = Risk Score (R). Each hazard is scored twice: an initial score with no controls in place, and a residual score assuming every listed control is correctly implemented."));
A.push(sp(120));
(function matrix() {
  const w = 1624;
  const widths = [w, w, w, w, w, w];
  const sevHead = ["", "S1 Minor", "S2 Moderate", "S3 Serious", "S4 Major", "S5 Fatal"];
  const likLabels = ["L1 Unlikely", "L2 Possible", "L3 Likely", "L4 Probable", "L5 Almost certain"];
  const head = new TableRow({
    tableHeader: true,
    children: sevHead.map((h, i) => new TableCell({
      width: { size: widths[i], type: WidthType.DXA },
      shading: { fill: TEAL, type: ShadingType.CLEAR },
      margins: cellMargins, borders: cellBorders, verticalAlign: VerticalAlign.CENTER,
      children: [new Paragraph({ spacing: { after: 0 }, alignment: AlignmentType.CENTER,
        children: [new TextRun({ text: h, font: HEAD_FONT, size: 13, bold: true, color: WHITE })] })],
    })),
  });
  const rows = [];
  for (let l = 1; l <= 5; l++) {
    const cells = [new TableCell({
      width: { size: w, type: WidthType.DXA },
      shading: { fill: TEAL_LIGHT, type: ShadingType.CLEAR },
      margins: cellMargins, borders: cellBorders, verticalAlign: VerticalAlign.CENTER,
      children: [new Paragraph({ spacing: { after: 0 }, alignment: AlignmentType.CENTER,
        children: [new TextRun({ text: likLabels[l - 1], font: HEAD_FONT, size: 13, bold: true, color: TEAL_DARK })] })],
    })];
    for (let s = 1; s <= 5; s++) {
      const v = l * s;
      cells.push(new TableCell({
        width: { size: w, type: WidthType.DXA },
        shading: { fill: bandFill(v), type: ShadingType.CLEAR },
        margins: cellMargins, borders: cellBorders, verticalAlign: VerticalAlign.CENTER,
        children: [new Paragraph({ spacing: { after: 0 }, alignment: AlignmentType.CENTER,
          children: [new TextRun({ text: String(v), font: BODY_FONT, size: 17, bold: true, color: TEXT })] })],
      }));
    }
    rows.push(new TableRow({ children: cells }));
  }
  A.push(new Table({ width: { size: w * 6, type: WidthType.DXA }, columnWidths: widths, layout: TableLayoutType.FIXED, rows: [head, ...rows] }));
})();
A.push(sp(160));
A.push(dataTable(
  ["Band", "Score", "Action required"],
  [
    [{ t: "LOW", fill: LOW_FILL, bold: true }, { t: "1 – 4", fill: LOW_FILL, align: AlignmentType.CENTER }, "Acceptable. Monitor and maintain controls."],
    [{ t: "MEDIUM", fill: MED_FILL, bold: true }, { t: "5 – 9", fill: MED_FILL, align: AlignmentType.CENTER }, "Further reduction required where reasonably practicable. Work may proceed once the listed controls are implemented and the residual risk is accepted by the Lead Engineer."],
    [{ t: "HIGH", fill: HIGH_FILL, bold: true }, { t: "10+", fill: HIGH_FILL, align: AlignmentType.CENTER }, "Work must not proceed until the listed controls are implemented and verified by the Lead Engineer."],
  ],
  [2200, 1600, 5946],
));

/* ========================= SECTION B — hazard register ========================= */
const LB = [];
if (D.hazards && D.hazards.length) {
  LB.push(sectionHeader("5.1 Hazard register", LAND_CONTENT_W));
  LB.push(sp(140));
  LB.push(p("Initial score assumes no controls. Residual score assumes all listed controls are correctly implemented."));
  LB.push(sp(100));
  const widths = [700, 2200, 1400, 400, 400, 720, 7458, 400, 400, 720];
  widths[6] += LAND_CONTENT_W - widths.reduce((a, b) => a + b, 0);
  const rows = D.hazards.map(h => {
    const r1 = h.l1 * h.s1, r2 = h.l2 * h.s2;
    return [
      { t: h.ref, bold: true, color: TEAL_DARK },
      { t: h.hazard, bold: true },
      (h.persons || []).map(x => "• " + x),
      { t: String(h.l1), align: AlignmentType.CENTER },
      { t: String(h.s1), align: AlignmentType.CENTER },
      { t: [`${h.l1}×${h.s1}=${r1}`, bandName(r1)], fill: bandFill(r1), bold: true, align: AlignmentType.CENTER, size: 14 },
      (h.controls || []).map((c, i) => `${i + 1}. ${c}`),
      { t: String(h.l2), align: AlignmentType.CENTER },
      { t: String(h.s2), align: AlignmentType.CENTER },
      { t: [`${h.l2}×${h.s2}=${r2}`, bandName(r2)], fill: bandFill(r2), bold: true, align: AlignmentType.CENTER, size: 14 },
    ];
  });
  LB.push(dataTable(
    ["Ref", "Hazard", "Persons at risk", "L", "S", "Risk", "Control measures", "L", "S", "Risk"],
    rows, widths,
    // A single hazard's control list can legitimately be taller than a page, so
    // allow rows to split here — the header still repeats on the continuation.
    { size: 15, headSize: 14, cantSplitRows: false },
  ));
}

/* ========================= SECTION C — method statement ========================= */
const M = [];
M.push(sectionHeader("6. Method statement"));
M.push(sp(160));

if (D.team) {
  M.push(subHeader("6.1 Team requirements"));
  M.push(dataTable(
    ["Role", "Qty", "Requirements"],
    D.team.map(([r, q, req]) => [r, qtyCell(q, { bold: false }), req]),
    [2900, 1000, 5846],
    { boldFirstCol: true },
  ));
  if (D.teamCallout) {
    M.push(sp(120));
    M.push(calloutBox(D.teamCallout, { gold: true, bold: true }));
  }
}

if (D.tools) {
  M.push(sp(200));
  M.push(subHeader("6.2 Tools & equipment"));
  D.tools.forEach(t => M.push(bullet(t)));
}

if (D.ppe) {
  M.push(sp(200));
  M.push(subHeader("6.3 Personal protective equipment"));
  M.push(dataTable(
    ["Task", "PPE required"],
    D.ppe.map(([task, items]) => [{ t: task, bold: true }, items.map(x => "• " + x)]),
    [3200, 6546],
    { zebra: true },
  ));
}

if (D.accessEquipment) {
  M.push(sp(260));
  M.push(subHeader("6.4 Access equipment"));
  D.accessEquipment.forEach(t => M.push(bullet(t)));
  if (D.accessRequirements) {
    M.push(sp(120));
    M.push(p("Requirements:", { bold: true, after: 80 }));
    D.accessRequirements.forEach(t => M.push(bullet(t)));
  }
}

if (D.methodSteps) {
  M.push(sp(220));   // subsection — flow naturally; step titles carry keepNext
  M.push(subHeader("6.5 Method of works"));
  M.push(sp(60));
  D.methodSteps.forEach((step, i) => {
    if (i > 0) M.push(sp(200));
    M.push(new Paragraph({
      spacing: { before: 60, after: 100 },
      keepNext: true,   // keep a step title with the first lines of the step
      keepLines: true,
      children: [new TextRun({ text: step.title, font: HEAD_FONT, size: 18, bold: true, color: TEAL })],
    }));
    (step.bullets || []).forEach(t => M.push(bullet(t)));
    if (step.risks) {
      M.push(sp(60));
      M.push(new Table({
        width: { size: CONTENT_W, type: WidthType.DXA },
        columnWidths: [CONTENT_W],
        layout: TableLayoutType.FIXED,
        rows: [new TableRow({ cantSplit: true, children: [new TableCell({
          width: { size: CONTENT_W, type: WidthType.DXA },
          shading: { fill: GOLD_LIGHT, type: ShadingType.CLEAR },
          margins: { top: 80, bottom: 80, left: 140, right: 140 },
          borders: noBorder,
          children: [new Paragraph({ spacing: { after: 0 }, children: [
            new TextRun({ text: "Associated risks:  ", font: HEAD_FONT, size: 15, bold: true, color: "6B5400" }),
            new TextRun({ text: step.risks, font: BODY_FONT, size: 16, color: TEXT }),
          ] })],
        })] })],
      }));
    }
  });
}

if (D.materialHandling) {
  M.push(sp(220));   // subsection — flow naturally
  M.push(subHeader("6.6 Material handling"));
  M.push(dataTable(
    ["Qty", "Item", "Handling method / controls"],
    D.materialHandling.map(([q, i, m]) => [qtyCell(q), i, m]),
    [900, 4650, 4196],
    { zebra: true },
  ));
  M.push(sp(120));
  M.push(p(D.materialHandlingNote || "This installation includes heavy and bulky AV equipment requiring manual handling controls. All displays are two-operative team lifts. Mechanical aids (trolley, panel lifter) must be used where available. Correct lifting technique must be adopted at all times, and no display is lifted above shoulder height."));
}

const simpleLists = [
  ["6.7 Permit & isolation requirements", "permits"],
  ["6.8 Fixings & installation control", "fixings"],
  ["6.9 Supervision & quality assurance", "qa"],
];
simpleLists.forEach(([title, key]) => {
  if (!D[key]) return;
  M.push(sp(200));
  M.push(subHeader(title));
  D[key].forEach(t => M.push(bullet(t)));
});

if (D.coordination) {
  M.push(sp(200));
  M.push(subHeader("6.10 Coordination with other parties"));
  if (D.coordination.intro) M.push(p(D.coordination.intro));
  M.push(sp(60));
  (D.coordination.points || []).forEach(t => M.push(bullet(t)));
}

if (D.itIntegration) {
  M.push(sp(200));
  M.push(subHeader("6.11 IT / network integration safety"));
  if (D.itIntegration.intro) M.push(p(D.itIntegration.intro));
  M.push(sp(60));
  (D.itIntegration.points || []).forEach(t => M.push(bullet(t)));
  if (D.itIntegration.closing) { M.push(sp(100)); M.push(p(D.itIntegration.closing)); }
}

if (D.coshh) {
  M.push(pb());
  M.push(sectionHeader("COSHH assessment"));
  M.push(sp(140));
  M.push(p("These works involve the following substances or processes that may present a health hazard under the Control of Substances Hazardous to Health Regulations 2002."));
  M.push(sp(60));
  M.push(dataTable(
    ["Substance / process", "Controls"],
    D.coshh.map(([s, c]) => [{ t: s, bold: true }, c]),
    [3200, 6546],
    { zebra: true },
  ));
  if (D.coshhNote) { M.push(sp(140)); M.push(calloutBox(D.coshhNote)); }
}

if (D.waste || D.noiseDust) {
  M.push(sp(220));
  M.push(sectionHeader("Environmental management"));
  M.push(sp(140));
  if (D.waste) {
    M.push(subHeader("Waste disposal"));
    D.waste.forEach(t => M.push(bullet(t)));
  }
  if (D.noiseDust) {
    M.push(sp(160));
    M.push(subHeader("Noise, dust & vibration"));
    D.noiseDust.forEach(t => M.push(bullet(t)));
  }
}

if (D.welfare) {
  M.push(sp(240));   // flow naturally after the preceding block
  M.push(sectionHeader("Welfare arrangements"));
  M.push(sp(140));
  M.push(fieldTable(D.welfare));
}

if (D.cdm) {
  M.push(sp(220));
  M.push(sectionHeader("CDM 2015 — duty holders"));
  M.push(sp(140));
  M.push(fieldTable(D.cdm));
  if (D.cdmNote) { M.push(sp(140)); M.push(calloutBox(D.cdmNote, { gold: true })); }
}

// Emergency procedures
M.push(pb());
M.push(sectionHeader("7. Emergency procedures"));
M.push(sp(140));
M.push(subHeader("7.1 Emergency contact numbers"));
M.push(dataTable(
  ["Contact", "Number"],
  D.emergencyContacts || [
    ["Emergency Services (Fire, Police, Ambulance)", "999"],
    ["Non-Emergency Police", "101"],
    ["NHS non-emergency medical", "111"],
    ["Site contact", P.clientContact || ""],
    ["21CAV Operations", "01189 977770"],
    ["HSE Incident Contact Centre (RIDDOR)", "0345 300 9923"],
  ],
  [5646, 4100],
  { zebra: true, boldFirstCol: true },
));
M.push(sp(180));
M.push(subHeader("7.2 Accident / injury"));
[
  "Stop all work. Call 999 if life-threatening.",
  "Administer first aid if qualified.",
  "Do not move a person with suspected spinal injury.",
  "Contact 21CAV operations.",
  "Preserve the scene.",
  "Complete incident report within 24 hours.",
  "Report to client site manager.",
  "RIDDOR reportable incidents must be reported within required timescales.",
].forEach(t => M.push(bullet(t)));
M.push(sp(180));
M.push(subHeader("7.3 Fire procedure"));
[
  "Raise the alarm using the nearest fire alarm call point.",
  "Evacuate by the nearest fire exit. Do not use lifts.",
  "Proceed to the designated assembly point.",
  "Do not re-enter until instructed.",
  "Inform the site manager that 21CAV engineers are on site.",
].forEach(t => M.push(bullet(t)));

// Sign-off
M.push(pb());
M.push(sectionHeader("8. Document sign-off"));
M.push(sp(140));
M.push(dataTable(
  ["", "21st Century AV Ltd", "Client acceptance"],
  [["Name", "", ""], ["Position", "", ""], ["Date", "", ""], ["Signature", "", ""]],
  [2000, 3873, 3873],
  { boldFirstCol: true },
));

M.push(sp(240));
M.push(sectionHeader("Appendix A — toolbox talk record"));
M.push(sp(140));
M.push(p("Prior to commencement of works, the Lead Engineer or Project Manager must conduct a toolbox talk covering the key risks, controls and procedures in this RAMS document. All attending personnel must sign below to confirm attendance and understanding."));
M.push(sp(100));
M.push(fieldTable([["Date of toolbox talk", ""], ["Conducted by", ""], ["Location", ""]]));
M.push(sp(140));
M.push(dataTable(
  ["Name", "Company", "Date", "Signature"],
  Array.from({ length: 8 }, () => ["", "", "", ""]),
  [2900, 2646, 1600, 2600],
));
M.push(sp(240));
M.push(calloutBox([
  "21st Century AV Ltd  |  Your Audio Visual Partner",
  "Thames Court, 2 Richfield Avenue, Reading, Berkshire, RG1 8EQ",
  "01189 977770  |  info@21stcenturyav.com  |  www.21stcenturyav.com  |  Company No. 03700669",
], { gold: true, bold: true }));

/* ===================== internal page-0 (removable) =====================
 * All 21CAV-internal working notes go on ONE detachable page at the very front.
 * It is page 0: the client front matter below restarts numbering at 1, so the
 * client document begins at the cover (page 1) and this page can be deleted for
 * issue without disturbing the client page numbers. Client-facing maturity
 * caveats (e.g. "Draft — Preliminary") stay on the cover, NOT here. Absent
 * `internalNotes` => no internal page and numbering is unchanged. */
const I = [];
const internalNotes = Array.isArray(D.internalNotes) ? D.internalNotes : [];
if (internalNotes.length) {
  I.push(calloutBox(["INTERNAL WORKING NOTES — NOT FOR ISSUE TO CLIENT"], { gold: true, bold: true }));
  I.push(sp(140));
  I.push(p("For 21st Century AV internal use only. This is page 0 and is excluded from the client page numbering — the client document begins at page 1 (the cover). Delete this page before issuing the RAMS to the client."));
  I.push(sp(160));
  I.push(sectionHeader("Pre-issue / survey checklist"));
  I.push(sp(120));
  internalNotes.forEach(t => I.push(bullet(t)));
}
const hasInternal = I.length > 0;

/* ========================= assemble ========================= */
const portraitProps = { page: { size: { width: A4.width, height: A4.height }, margin: MARGIN } };
const landscapeProps = {
  page: {
    size: { width: A4.width, height: A4.height, orientation: PageOrientation.LANDSCAPE },
    margin: { top: 1080, right: 1080, bottom: 1080, left: 1080 },
  },
};
// With a removable page-0 present, drop the "of N" total (see runningFooter): the
// client pages read Page 1, 2, 3 … from the cover, always correct in any renderer.
const hf = { headers: { default: runningHeader(HDR) }, footers: { default: runningFooter(FTR, { hideTotal: hasInternal }) } };
// When a removable page-0 precedes it, the client front matter restarts at page 1.
const clientFirstProps = hasInternal
  ? { page: { ...portraitProps.page, pageNumbers: { start: 1 } } }
  : portraitProps;

const sections = [];
if (hasInternal) {
  const internalHf = {
    headers: { default: runningHeader("INTERNAL WORKING COPY — NOT FOR ISSUE") },
    footers: { default: internalFooter("INTERNAL — NOT FOR ISSUE  ·  remove before sending to client  ·  excluded from client page numbering") },
  };
  sections.push({ properties: portraitProps, ...internalHf, children: I });
}
sections.push({ properties: clientFirstProps, ...hf, children: A });
if (LB.length) sections.push({ properties: landscapeProps, ...hf, children: LB });
sections.push({ properties: portraitProps, ...hf, children: M });

const doc = new Document({
  numbering,
  styles: { default: { document: { run: { font: BODY_FONT, size: 18, color: TEXT } } } },
  sections,
});

Packer.toBuffer(doc).then(buf => {
  fs.writeFileSync(outPath, buf);
  console.log(`RAMS written: ${outPath} (${buf.length} bytes)`);
});
