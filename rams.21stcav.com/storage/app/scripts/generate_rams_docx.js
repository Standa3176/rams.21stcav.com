#!/usr/bin/env node
/**
 * generate_rams_docx.js
 *
 * Renders a CDM-compliant RAMS Word document from a RAMS JSON file.
 * Called by Laravel's RamsDocxBuilderService via shell_exec / proc_open.
 *
 * Usage:
 *   node generate_rams_docx.js <input.json> <output.docx>
 *
 * 21st Century AV brand colours:
 *   TEAL   : 007B8A
 *   WHITE  : FFFFFF
 *   DARK   : 1F2937
 *   LIGHT  : F3F4F6  (alternating row fill)
 */

'use strict';

const fs   = require('fs');
const path = require('path');

const {
    Document, Packer, Paragraph, TextRun, Table, TableRow, TableCell,
    Header, Footer, AlignmentType, HeadingLevel, BorderStyle, WidthType,
    ShadingType, VerticalAlign, PageBreak, LevelFormat,
    TabStopType, TabStopPosition, PageNumberElement, PageNumberType,
} = require('docx');

// ── Brand constants ────────────────────────────────────────────────────────────
const TEAL      = '007B8A';
const WHITE     = 'FFFFFF';
const DARK      = '1F2937';
const LIGHT_ROW = 'EBF6F7';   // very light teal tint for alternating rows
const HEADER_BG = '007B8A';
const TEXT_DARK = '1F2937';

// A4 in DXA (1440 DXA = 1 inch).  Margins: 0.8" L/R, 0.9" T/B
const PAGE_W    = 11906;
const PAGE_H    = 16838;
const MARGIN_LR = 1152;   // 0.8"
const MARGIN_TB = 1296;   // 0.9"
const CONTENT_W = PAGE_W - MARGIN_LR * 2;  // 9602 DXA

// ── CLI ────────────────────────────────────────────────────────────────────────
const [,, inputFile, outputFile] = process.argv;
if (!inputFile || !outputFile) {
    console.error('Usage: node generate_rams_docx.js <input.json> <output.docx>');
    process.exit(1);
}

const rams = JSON.parse(fs.readFileSync(inputFile, 'utf8'));
const p    = rams.project || {};

// ── Helpers ────────────────────────────────────────────────────────────────────

function cellBorder(color = 'C5D9DB') {
    const b = { style: BorderStyle.SINGLE, size: 1, color };
    return { top: b, bottom: b, left: b, right: b };
}

function headerCell(text, widthDxa, opts = {}) {
    return new TableCell({
        width:   { size: widthDxa, type: WidthType.DXA },
        borders: cellBorder('007B8A'),
        shading: { fill: HEADER_BG, type: ShadingType.CLEAR },
        margins: { top: 80, bottom: 80, left: 120, right: 120 },
        verticalAlign: VerticalAlign.CENTER,
        children: [new Paragraph({
            children: [new TextRun({ text, bold: true, color: WHITE, size: 18, font: 'Arial' })],
            alignment: opts.center ? AlignmentType.CENTER : AlignmentType.LEFT,
        })],
    });
}

function dataCell(text, widthDxa, opts = {}) {
    const fill = opts.alt ? LIGHT_ROW : WHITE;
    return new TableCell({
        width:   { size: widthDxa, type: WidthType.DXA },
        borders: cellBorder(),
        shading: { fill, type: ShadingType.CLEAR },
        margins: { top: 80, bottom: 80, left: 120, right: 120 },
        verticalAlign: VerticalAlign.TOP,
        children: [new Paragraph({
            children: [new TextRun({ text: String(text ?? ''), size: 18, font: 'Arial', color: TEXT_DARK })],
            alignment: opts.center ? AlignmentType.CENTER : AlignmentType.LEFT,
        })],
    });
}

function riskColor(score) {
    if (score >= 15) return 'C0392B';  // red
    if (score >= 8)  return 'E67E22';  // amber
    return '27AE60';                   // green
}

function heading1(text) {
    return new Paragraph({
        heading: HeadingLevel.HEADING_1,
        spacing: { before: 280, after: 140 },
        border:  { bottom: { style: BorderStyle.SINGLE, size: 6, color: TEAL, space: 6 } },
        children: [new TextRun({ text, bold: true, size: 28, font: 'Arial', color: TEAL })],
    });
}

function heading2(text) {
    return new Paragraph({
        heading: HeadingLevel.HEADING_2,
        spacing: { before: 200, after: 100 },
        children: [new TextRun({ text, bold: true, size: 22, font: 'Arial', color: DARK })],
    });
}

function bodyPara(text, opts = {}) {
    return new Paragraph({
        spacing: { before: 60, after: 60 },
        children: [new TextRun({ text, size: 18, font: 'Arial', color: TEXT_DARK, bold: opts.bold || false })],
    });
}

function bulletItem(text, ref = 'bullets') {
    return new Paragraph({
        numbering: { reference: ref, level: 0 },
        spacing:   { before: 40, after: 40 },
        children:  [new TextRun({ text, size: 18, font: 'Arial', color: TEXT_DARK })],
    });
}

function spacer(before = 160) {
    return new Paragraph({ spacing: { before, after: 0 }, children: [new TextRun('')] });
}

// ── Cover page ─────────────────────────────────────────────────────────────────
function buildCoverPage() {
    const ref  = p.ref  || 'RAMS-001';
    const name = p.name || 'AV Installation';

    return [
        // Big teal title block
        new Paragraph({
            spacing: { before: 2400, after: 200 },
            alignment: AlignmentType.CENTER,
            children: [new TextRun({ text: 'RISK ASSESSMENT &', bold: true, size: 64, font: 'Arial', color: TEAL })],
        }),
        new Paragraph({
            spacing: { before: 0, after: 400 },
            alignment: AlignmentType.CENTER,
            children: [new TextRun({ text: 'METHOD STATEMENT', bold: true, size: 64, font: 'Arial', color: TEAL })],
        }),
        // Divider rule
        new Paragraph({
            spacing: { before: 0, after: 400 },
            border: { bottom: { style: BorderStyle.SINGLE, size: 12, color: TEAL } },
            children: [new TextRun('')],
        }),
        new Paragraph({
            spacing: { before: 200, after: 120 },
            alignment: AlignmentType.CENTER,
            children: [new TextRun({ text: name.toUpperCase(), bold: true, size: 36, font: 'Arial', color: DARK })],
        }),
        new Paragraph({
            spacing: { before: 80, after: 80 },
            alignment: AlignmentType.CENTER,
            children: [new TextRun({ text: p.subtitle || '', size: 22, font: 'Arial', color: '6B7280' })],
        }),
        spacer(400),
        // Info table
        new Table({
            width: { size: CONTENT_W, type: WidthType.DXA },
            columnWidths: [2400, CONTENT_W - 2400],
            rows: [
                ['Client',          p.client       || ''],
                ['Site Address',    p.site_address || ''],
                ['Document Ref',    ref],
                ['Document Status', p.document_status || 'For Construction'],
                ['Prepared By',     '21st Century AV Ltd'],
                ['Date',            new Date().toLocaleDateString('en-GB', { day:'2-digit', month:'long', year:'numeric' })],
            ].map(([label, value], i) => new TableRow({
                children: [
                    headerCell(label, 2400),
                    dataCell(value, CONTENT_W - 2400, { alt: i % 2 === 1 }),
                ],
            })),
        }),
        spacer(600),
        new Paragraph({
            alignment: AlignmentType.CENTER,
            spacing: { before: 0, after: 40 },
            children: [new TextRun({ text: '21st Century AV Ltd', bold: true, size: 22, font: 'Arial', color: TEAL })],
        }),
        new Paragraph({
            alignment: AlignmentType.CENTER,
            children: [new TextRun({ text: 'Thames Court, 2 Richfield Avenue, Reading RG1 8EQ', size: 18, font: 'Arial', color: '6B7280' })],
        }),
        new Paragraph({
            alignment: AlignmentType.CENTER,
            children: [new TextRun({ text: 'Tel: 0118 977 7770  |  info@21stcenturyav.com', size: 18, font: 'Arial', color: '6B7280' })],
        }),
        // Page break to end cover
        new Paragraph({ children: [new PageBreak()] }),
    ];
}

// ── Section 1: Project Information ────────────────────────────────────────────
function buildProjectInfo() {
    return [
        heading1('1. Project Information'),
        new Table({
            width: { size: CONTENT_W, type: WidthType.DXA },
            columnWidths: [2800, CONTENT_W - 2800],
            rows: [
                ['Project Reference', p.ref  || ''],
                ['Project Name',      p.name || ''],
                ['Client',            p.client || ''],
                ['Site Address',      p.site_address || ''],
                ['Document Status',   p.document_status || 'For Construction'],
                ['Scope',             p.works_description || ''],
            ].map(([label, value], i) => new TableRow({
                children: [
                    headerCell(label, 2800),
                    dataCell(value, CONTENT_W - 2800, { alt: i % 2 === 1 }),
                ],
            })),
        }),
        spacer(),
    ];
}

// ── Section 2: Scope of Works ──────────────────────────────────────────────────
function buildScopeOfWorks() {
    const ms     = rams.method_statement || {};
    const scopes = ms.scope_of_works || [];
    const cols   = [2200, 1600, CONTENT_W - 3800];

    const rows = [
        new TableRow({ children: [headerCell('Room', 2200), headerCell('Drawing Ref', 1600), headerCell('Equipment / Works', CONTENT_W - 3800)] }),
        ...scopes.map((s, i) => new TableRow({
            children: [
                dataCell(s.room || '', 2200, { alt: i % 2 === 1 }),
                dataCell(s.drawing_ref || 'N/A', 1600, { alt: i % 2 === 1, center: true }),
                dataCell(s.equipment || '', CONTENT_W - 3800, { alt: i % 2 === 1 }),
            ],
        })),
    ];

    return [
        heading1('2. Scope of Works'),
        bodyPara(ms.introduction || ''),
        spacer(120),
        scopes.length ? new Table({ width: { size: CONTENT_W, type: WidthType.DXA }, columnWidths: cols, rows })
                      : bodyPara('See project documentation.'),
        spacer(),
    ];
}

// ── Section 3: Persons at Risk ─────────────────────────────────────────────────
function buildPersonsAtRisk() {
    const persons = rams.persons_at_risk || [];
    return [
        heading1('3. Persons at Risk'),
        ...persons.map(p => bulletItem(p)),
        spacer(),
    ];
}

// ── Section 4: Hazard & Risk Assessment Table ──────────────────────────────────
function buildHazardTable() {
    const hazards = rams.hazards || [];

    // Column widths summing exactly to CONTENT_W (9602 DXA for A4 with 0.8" margins)
    // ID | Hazard | Pre-L | Pre-S | Pre-Score | Controls | Post-L | Post-S | Post-Score
    const C = [600, 2102, 620, 620, 760, 2600, 620, 620, 760];
    // Verify: 600+2102+620+620+760+2600+620+620+760 = 9302; pad controls col
    const sumC = C.reduce((a,b)=>a+b, 0);
    C[5] += (CONTENT_W - sumC); // add remainder to controls column

    const hdr = new TableRow({ children: [
        headerCell('ID',         C[0], { center: true }),
        headerCell('Hazard',     C[1]),
        headerCell('Pre\nL',     C[2], { center: true }),
        headerCell('Pre\nS',     C[3], { center: true }),
        headerCell('Pre\nScore', C[4], { center: true }),
        headerCell('Control Measures', C[5]),
        headerCell('Post\nL',    C[6], { center: true }),
        headerCell('Post\nS',    C[7], { center: true }),
        headerCell('Post\nScore',C[8], { center: true }),
    ]});

    const dataRows = hazards.map((h, i) => {
        const preScore  = (h.pre_likelihood  || 0) * (h.pre_severity  || 0);
        const postScore = (h.post_likelihood || 0) * (h.post_severity || 0);
        const controls  = Array.isArray(h.controls) ? h.controls.join('\n• ') : (h.controls || '');
        const alt       = i % 2 === 1;
        const preColor  = riskColor(preScore);
        const postColor = riskColor(postScore);

        // Consequences + controls merged for readability
        const consequences = Array.isArray(h.consequences) ? h.consequences.join('; ') : '';
        const hazardText   = `${h.hazard || ''}\n${consequences}`.trim();
        const controlText  = controls ? `• ${controls}` : '';

        function scoreCell(score, color, w) {
            return new TableCell({
                width: { size: w, type: WidthType.DXA },
                borders: cellBorder(),
                shading: { fill: alt ? LIGHT_ROW : WHITE, type: ShadingType.CLEAR },
                margins: { top: 80, bottom: 80, left: 60, right: 60 },
                verticalAlign: VerticalAlign.CENTER,
                children: [new Paragraph({
                    alignment: AlignmentType.CENTER,
                    children: [new TextRun({ text: String(score), bold: true, size: 18, font: 'Arial', color })],
                })],
            });
        }

        return new TableRow({ children: [
            dataCell(String(h.id || i + 1), C[0], { alt, center: true }),
            new TableCell({
                width: { size: C[1], type: WidthType.DXA },
                borders: cellBorder(),
                shading: { fill: alt ? LIGHT_ROW : WHITE, type: ShadingType.CLEAR },
                margins: { top: 80, bottom: 80, left: 120, right: 120 },
                children: [
                    new Paragraph({ children: [new TextRun({ text: h.hazard || '', bold: true, size: 18, font: 'Arial', color: TEXT_DARK })] }),
                    ...(consequences ? [new Paragraph({ children: [new TextRun({ text: consequences, size: 16, font: 'Arial', color: '6B7280', italics: true })] })] : []),
                ],
            }),
            dataCell(String(h.pre_likelihood  || ''), C[2], { alt, center: true }),
            dataCell(String(h.pre_severity    || ''), C[3], { alt, center: true }),
            scoreCell(preScore, preColor, C[4]),
            new TableCell({
                width: { size: C[5], type: WidthType.DXA },
                borders: cellBorder(),
                shading: { fill: alt ? LIGHT_ROW : WHITE, type: ShadingType.CLEAR },
                margins: { top: 80, bottom: 80, left: 120, right: 120 },
                children: [new Paragraph({ children: [new TextRun({ text: controlText, size: 17, font: 'Arial', color: TEXT_DARK })] })],
            }),
            dataCell(String(h.post_likelihood || ''), C[6], { alt, center: true }),
            dataCell(String(h.post_severity   || ''), C[7], { alt, center: true }),
            scoreCell(postScore, postColor, C[8]),
        ]});
    });

    // Risk matrix key
    const matrixKey = new Table({
        width: { size: CONTENT_W, type: WidthType.DXA },
        columnWidths: [CONTENT_W / 3, CONTENT_W / 3, CONTENT_W - Math.round(CONTENT_W * 2 / 3)],
        rows: [
            new TableRow({ children: [
                headerCell('Score',          Math.round(CONTENT_W / 3)),
                headerCell('Rating',         Math.round(CONTENT_W / 3)),
                headerCell('Action Required',CONTENT_W - Math.round(CONTENT_W * 2 / 3)),
            ]}),
            ...([
                ['15–25', 'HIGH — Red',    'Stop work. Immediate corrective action required.'],
                ['8–14',  'MEDIUM — Amber','Review controls. Supervision required.'],
                ['1–7',   'LOW — Green',   'Acceptable. Monitor and maintain controls.'],
            ].map(([score, rating, action], i) => new TableRow({ children: [
                dataCell(score,  Math.round(CONTENT_W / 3),  { alt: i%2===1, center: true }),
                dataCell(rating, Math.round(CONTENT_W / 3),  { alt: i%2===1 }),
                dataCell(action, CONTENT_W - Math.round(CONTENT_W*2/3), { alt: i%2===1 }),
            ]}))),
        ],
    });

    return [
        heading1('4. Hazard & Risk Assessment'),
        bodyPara('Risk Score = Likelihood × Severity. L = Likelihood (1–5), S = Severity (1–5).', { bold: false }),
        spacer(80),
        matrixKey,
        spacer(120),
        hazards.length ? new Table({ width: { size: CONTENT_W, type: WidthType.DXA }, columnWidths: C, rows: [hdr, ...dataRows] })
                       : bodyPara('No hazards recorded.'),
        spacer(),
    ];
}

// ── Section 5: PPE ────────────────────────────────────────────────────────────
function buildPpe() {
    const ppe = rams.ppe || [];
    return [
        heading1('5. PPE Requirements'),
        ...ppe.map(item => bulletItem(item)),
        spacer(),
    ];
}

// ── Section 6: Tools & Equipment ──────────────────────────────────────────────
function buildToolsAndEquipment() {
    const tools = rams.tools_and_equipment || [];
    return [
        heading1('6. Tools & Equipment'),
        ...(tools.length
            ? tools.map(t => bulletItem(t))
            : [bodyPara('Standard AV installation hand tools and power tools.')]),
        spacer(),
    ];
}

// ── Section 7: Environmental Controls ─────────────────────────────────────────
function buildEnvironmentalControls() {
    const envControls = rams.environmental_controls || [];
    return [
        heading1('7. Environmental Controls'),
        ...(envControls.length
            ? envControls.map(e => bulletItem(e))
            : [bodyPara('Standard site environmental controls apply.')]),
        spacer(),
    ];
}

// ── Section 8: Method Statement ────────────────────────────────────────────────
function buildMethodStatement() {
    const ms      = rams.method_statement || {};
    const phases  = ms.phases  || [];
    const general = ms.general_procedures || [];
    const quality = ms.quality_checks     || [];
    const excl    = ms.exclusions         || [];

    const elements = [heading1('8. Method Statement')];

    if (general.length) {
        elements.push(heading2('8.1 General Procedures'), ...general.map(g => bulletItem(g)), spacer(80));
    }

    phases.forEach((phase, i) => {
        elements.push(heading2(`8.${i + 2} ${phase.name || `Phase ${i + 1}`}`));
        if (phase.description) elements.push(bodyPara(phase.description));
        (phase.procedures || []).forEach((proc, j) => {
            elements.push(new Paragraph({
                numbering: { reference: 'numbers', level: 0 },
                spacing: { before: 40, after: 40 },
                children: [new TextRun({ text: proc, size: 18, font: 'Arial', color: TEXT_DARK })],
            }));
        });
        elements.push(spacer(80));
    });

    if (quality.length) {
        elements.push(heading2(`8.${phases.length + 2} Quality & Completion Checks`), ...quality.map(q => bulletItem(q)), spacer(80));
    }

    if (excl.length) {
        elements.push(heading2('Exclusions'));
        const cols = [2400, 2000, CONTENT_W - 4400];
        const rows = [
            new TableRow({ children: [headerCell('Item', 2400), headerCell('Responsible Party', 2000), headerCell('Description', CONTENT_W - 4400)] }),
            ...excl.map((e, i) => new TableRow({ children: [
                dataCell(e.item || '', 2400, { alt: i%2===1 }),
                dataCell(e.responsible_party || '', 2000, { alt: i%2===1 }),
                dataCell(e.description || '', CONTENT_W - 4400, { alt: i%2===1 }),
            ]})),
        ];
        elements.push(new Table({ width: { size: CONTENT_W, type: WidthType.DXA }, columnWidths: cols, rows }));
    }

    elements.push(spacer());
    return elements;
}

// ── Section 9: Emergency Procedures ──────────────────────────────────────────
function buildEmergencyProcedures() {
    const ep = rams.emergency_procedures || {};
    const pairs = [
        ['Fire Evacuation',    ep.fire              || 'Follow building fire evacuation procedure.'],
        ['First Aid',          ep.first_aid         || 'Contact site first aider immediately.'],
        ['Incident Reporting', ep.incident_reporting || 'Report to site manager immediately.'],
        ['Emergency Contact',  ep.emergency_contact  || '21st Century AV Ltd: 0118 977 7770'],
    ];

    const cols = [2400, CONTENT_W - 2400];
    const rows = [
        new TableRow({ children: [headerCell('Procedure', 2400), headerCell('Action', CONTENT_W - 2400)] }),
        ...pairs.map(([label, value], i) => new TableRow({ children: [
            headerCell(label, 2400),
            dataCell(value, CONTENT_W - 2400, { alt: i % 2 === 1 }),
        ]})),
    ];

    return [
        heading1('9. Emergency Procedures'),
        new Table({ width: { size: CONTENT_W, type: WidthType.DXA }, columnWidths: cols, rows }),
        spacer(),
    ];
}

// ── Section 10: Training Requirements ────────────────────────────────────────
function buildTrainingRequirements() {
    const training = rams.training_requirements || [];
    return [
        heading1('10. Training Requirements'),
        ...(training.length
            ? training.map(t => bulletItem(t))
            : [bodyPara('All operatives to hold valid CSCS cards and relevant training certifications.')]),
        spacer(),
    ];
}

// ── Section 11: Regulations & Standards ──────────────────────────────────────
function buildRegulations() {
    const regs = rams.regulations || [];
    return [
        heading1('11. Regulations & Standards'),
        ...regs.map(r => bulletItem(r)),
        spacer(),
    ];
}

// ── Section 12: Sign-off ──────────────────────────────────────────────────────
function buildSignOff() {
    const cols = [CONTENT_W / 2, CONTENT_W / 2];
    const signRows = (label) => [
        new TableRow({ children: [
            headerCell(label, cols[0]),
            headerCell('Signature / Date', cols[1]),
        ]}),
        new TableRow({ children: [
            dataCell('Name:', cols[0], { alt: false }),
            dataCell('', cols[1], { alt: false }),
        ]}),
        new TableRow({ children: [
            dataCell('Position:', cols[0], { alt: true }),
            dataCell('', cols[1], { alt: true }),
        ]}),
        new TableRow({ children: [
            dataCell('Date:', cols[0], { alt: false }),
            dataCell('', cols[0], { alt: false }),
        ]}),
    ];

    return [
        heading1('12. Acknowledgement & Sign-off'),
        bodyPara('By signing below, you confirm that you have read, understood, and agree to comply with this RAMS document.'),
        spacer(120),
        heading2('Prepared By — 21st Century AV Ltd'),
        new Table({ width: { size: CONTENT_W, type: WidthType.DXA }, columnWidths: [cols[0], cols[1]], rows: signRows('Prepared By') }),
        spacer(200),
        heading2('Reviewed / Accepted By — Client'),
        new Table({ width: { size: CONTENT_W, type: WidthType.DXA }, columnWidths: [cols[0], cols[1]], rows: signRows('Accepted By') }),
        spacer(),
    ];
}

// ── Header / Footer ────────────────────────────────────────────────────────────
function buildHeader() {
    const ref  = p.ref  || 'RAMS';
    const name = p.name || 'AV Installation';
    return {
        default: new Header({
            children: [
                new Paragraph({
                    border: { bottom: { style: BorderStyle.SINGLE, size: 6, color: TEAL } },
                    tabStops: [{ type: TabStopType.RIGHT, position: CONTENT_W }],
                    children: [
                        new TextRun({ text: `21st Century AV Ltd — RAMS  |  ${name}`, bold: true, size: 18, font: 'Arial', color: TEAL }),
                        new TextRun({ text: '\t', size: 18 }),
                        new TextRun({ text: `Ref: ${ref}`, size: 18, font: 'Arial', color: '6B7280' }),
                    ],
                }),
            ],
        }),
    };
}

function buildFooter() {
    return {
        default: new Footer({
            children: [
                new Paragraph({
                    border: { top: { style: BorderStyle.SINGLE, size: 6, color: TEAL } },
                    tabStops: [{ type: TabStopType.RIGHT, position: CONTENT_W }],
                    children: [
                        new TextRun({ text: 'CONFIDENTIAL — For construction use only', size: 16, font: 'Arial', color: '9CA3AF' }),
                        new TextRun({ text: '\t', size: 16 }),
                        new TextRun({ text: 'Page ', size: 16, font: 'Arial', color: '9CA3AF' }),
                        new TextRun({ children: [new PageNumberElement()], size: 16, font: 'Arial', color: '9CA3AF' }),
                    ],
                }),
            ],
        }),
    };
}

// ── Assemble document ──────────────────────────────────────────────────────────
const children = [
    ...buildCoverPage(),
    ...buildProjectInfo(),
    ...buildScopeOfWorks(),
    ...buildPersonsAtRisk(),
    ...buildHazardTable(),
    ...buildPpe(),
    ...buildToolsAndEquipment(),
    ...buildEnvironmentalControls(),
    ...buildMethodStatement(),
    ...buildEmergencyProcedures(),
    ...buildTrainingRequirements(),
    ...buildRegulations(),
    ...buildSignOff(),
];

const doc = new Document({
    creator:     '21st Century AV Ltd',
    description: `RAMS — ${p.name || 'AV Installation'} — ${p.ref || ''}`,
    title:       `RAMS — ${p.name || 'AV Installation'}`,

    numbering: {
        config: [
            {
                reference: 'bullets',
                levels: [{ level: 0, format: LevelFormat.BULLET, text: '•', alignment: AlignmentType.LEFT,
                    style: { paragraph: { indent: { left: 560, hanging: 280 } } } }],
            },
            {
                reference: 'numbers',
                levels: [{ level: 0, format: LevelFormat.DECIMAL, text: '%1.', alignment: AlignmentType.LEFT,
                    style: { paragraph: { indent: { left: 560, hanging: 280 } } } }],
            },
        ],
    },

    styles: {
        default: {
            document: { run: { font: 'Arial', size: 20, color: TEXT_DARK } },
        },
        paragraphStyles: [
            { id: 'Heading1', name: 'Heading 1', basedOn: 'Normal', next: 'Normal', quickFormat: true,
              run: { size: 28, bold: true, font: 'Arial', color: TEAL },
              paragraph: { spacing: { before: 280, after: 140 }, outlineLevel: 0 } },
            { id: 'Heading2', name: 'Heading 2', basedOn: 'Normal', next: 'Normal', quickFormat: true,
              run: { size: 22, bold: true, font: 'Arial', color: DARK },
              paragraph: { spacing: { before: 200, after: 100 }, outlineLevel: 1 } },
        ],
    },

    sections: [{
        properties: {
            page: {
                size:   { width: PAGE_W, height: PAGE_H },
                margin: { top: MARGIN_TB, right: MARGIN_LR, bottom: MARGIN_TB, left: MARGIN_LR },
            },
        },
        headers: buildHeader(),
        footers: buildFooter(),
        children,
    }],
});

Packer.toBuffer(doc).then(buf => {
    fs.writeFileSync(outputFile, buf);
    console.log(`OK: ${outputFile}`);
}).catch(err => {
    console.error('ERROR:', err.message);
    process.exit(1);
});
