const {
  Paragraph, TextRun, Table, TableRow, TableCell, AlignmentType, WidthType,
  ShadingType, VerticalAlign, BorderStyle, LevelFormat, Header, Footer,
  PageNumber, TableLayoutType, convertInchesToTwip
} = require("docx");

// ---- Brand tokens (21cav-brand skill) ----
const TEAL = "01889F";
const TEAL_DARK = "016E82";
const TEAL_LIGHT = "E6F4F7";
const GOLD = "D4AF37";
const GOLD_LIGHT = "F5EDD6";
const WHITE = "FFFFFF";
const TEXT = "1A1A1A";
const TEXT_MID = "444444";
const TEXT_LIGHT = "767676";
const BG_LIGHT = "F8F8F8";
const BORDER = "E0E0E0";

// Risk band fills
const LOW_FILL = "C8E6C9";
const MED_FILL = "FFE0A3";
const HIGH_FILL = "FFCCBC";
const VHIGH_FILL = "F5B7B1";

const HEAD_FONT = "Verdana";
const BODY_FONT = "Poppins";

const A4 = { width: 11906, height: 16838 };
const MARGIN = { top: 1180, right: 1080, bottom: 1080, left: 1080 };
const CONTENT_W = 9746;          // portrait content width
const LAND_CONTENT_W = 14678;    // landscape content width

const cellBorders = {
  top:    { style: BorderStyle.SINGLE, size: 4, color: BORDER },
  bottom: { style: BorderStyle.SINGLE, size: 4, color: BORDER },
  left:   { style: BorderStyle.SINGLE, size: 4, color: BORDER },
  right:  { style: BorderStyle.SINGLE, size: 4, color: BORDER },
};
const cellMargins = { top: 70, bottom: 70, left: 110, right: 110 };
const noBorder = {
  top:    { style: BorderStyle.NONE, size: 0, color: "FFFFFF" },
  bottom: { style: BorderStyle.NONE, size: 0, color: "FFFFFF" },
  left:   { style: BorderStyle.NONE, size: 0, color: "FFFFFF" },
  right:  { style: BorderStyle.NONE, size: 0, color: "FFFFFF" },
};

// ---- Text helpers ----

// Convert model-supplied text into TextRuns: **bold** markers become real Word
// bold, and null/undefined become "" so a missing value never prints the literal
// word "undefined". Everything that renders author-supplied strings goes through
// this so the two defects never reach the page.
function mdRuns(text, base = {}) {
  const s = text === null || text === undefined ? "" : String(text);
  const parts = s.split(/(\*\*[^*]+\*\*)/g).filter((x) => x !== "");
  if (parts.length === 0) parts.push("");
  return parts.map((part) => {
    const isBold = /^\*\*[^*]+\*\*$/.test(part);
    return new TextRun({
      text: isBold ? part.slice(2, -2) : part,
      font: base.font ?? BODY_FONT,
      size: base.size ?? 18,
      italics: base.italics,
      color: base.color ?? TEXT,
      bold: base.bold || isBold,
    });
  });
}

function p(text, o = {}) {
  return new Paragraph({
    alignment: o.align,
    spacing: { before: o.before ?? 0, after: o.after ?? 120, line: o.line ?? 260 },
    indent: o.indent,
    children: mdRuns(text, {
      font: o.font ?? BODY_FONT,
      size: o.size ?? 18,
      bold: o.bold,
      italics: o.italics,
      color: o.color ?? TEXT,
    }),
  });
}

function bullet(text, o = {}) {
  return new Paragraph({
    numbering: { reference: "bullets", level: o.level ?? 0 },
    spacing: { after: o.after ?? 70, line: 250 },
    children: mdRuns(text, { font: BODY_FONT, size: o.size ?? 18, color: TEXT, bold: o.bold }),
  });
}

function numItem(text, o = {}) {
  return new Paragraph({
    numbering: { reference: "numbers", level: o.level ?? 0 },
    spacing: { after: o.after ?? 70, line: 250 },
    children: [new TextRun({ text, font: BODY_FONT, size: o.size ?? 18, color: TEXT })],
  });
}

function sp(after = 120) {
  return new Paragraph({ spacing: { after }, children: [new TextRun({ text: "", size: 2 })] });
}

// quick-260830-ramsqa (Commit 4) — a SAFE forced page break. Uses the paragraph
// `pageBreakBefore` property rather than a PageBreak run inside its own empty
// paragraph. A trailing PageBreak run can double-break (page break + the
// paragraph mark rolling to a further page), which was the historical source of
// blank pages; pageBreakBefore simply starts the next content on a fresh page
// and never manufactures a blank page when already at the top of one.
function pageBreakBefore() {
  return new Paragraph({ pageBreakBefore: true, spacing: { after: 0 }, children: [new TextRun({ text: "", size: 2 })] });
}

// Qty is a display cell: a short token only. Long prose in a narrow Qty column
// wraps vertically and looks broken, so it is rejected at assembly time (the
// validator gates it upstream; this is the deterministic Layer-2 backstop).
const QTY_MAX_LEN = 6;
function isValidQty(v) {
  const s = (v === null || v === undefined ? "" : String(v)).trim();
  if (s === "") return true;                       // blank is allowed (sign-off/blank rows)
  if (/^\d{1,3}$/.test(s)) return true;            // 1..999
  return /^(?:TBC|N\/?A|NA|See schedule|Various|As req(?:'d|uired)?)$/i.test(s);
}
function qtyCell(v, o = {}) {
  if (!isValidQty(v)) {
    throw new Error(
      `Invalid Qty value for the RAMS document: "${v}". Qty must be a short token ` +
      `(a number, TBC, N/A, "See schedule"). Put descriptive text in the Item/Notes column, not the Qty column.`
    );
  }
  return { t: v === null || v === undefined ? "" : String(v), align: AlignmentType.CENTER, bold: o.bold !== false };
}

// Teal section header bar
function sectionHeader(text, width) {
  return new Table({
    width: { size: width ?? CONTENT_W, type: WidthType.DXA },
    columnWidths: [width ?? CONTENT_W],
    layout: TableLayoutType.FIXED,
    rows: [new TableRow({
      cantSplit: true,
      children: [new TableCell({
        width: { size: width ?? CONTENT_W, type: WidthType.DXA },
        shading: { fill: TEAL, type: ShadingType.CLEAR },
        margins: { top: 90, bottom: 90, left: 140, right: 140 },
        borders: noBorder,
        children: [new Paragraph({
          spacing: { after: 0 },
          children: [new TextRun({ text: text.toUpperCase(), font: HEAD_FONT, size: 20, bold: true, color: WHITE })],
        })],
      })],
    })],
  });
}

function subHeader(text) {
  return new Paragraph({
    spacing: { before: 160, after: 90 },
    border: { bottom: { style: BorderStyle.SINGLE, size: 6, color: GOLD, space: 3 } },
    keepNext: true,   // don't strand a heading at the bottom of a page
    keepLines: true,
    children: [new TextRun({ text, font: HEAD_FONT, size: 19, bold: true, color: TEAL_DARK })],
  });
}

// Gold / teal callout box
function calloutBox(text, o = {}) {
  const fill = o.gold ? GOLD_LIGHT : TEAL_LIGHT;
  const edge = o.gold ? GOLD : TEAL;
  const w = o.width ?? CONTENT_W;
  return new Table({
    width: { size: w, type: WidthType.DXA },
    columnWidths: [w],
    layout: TableLayoutType.FIXED,
    rows: [new TableRow({
      cantSplit: true,
      children: [new TableCell({
        width: { size: w, type: WidthType.DXA },
        shading: { fill, type: ShadingType.CLEAR },
        margins: { top: 130, bottom: 130, left: 160, right: 160 },
        borders: {
          top:    { style: BorderStyle.NONE, size: 0, color: fill },
          bottom: { style: BorderStyle.NONE, size: 0, color: fill },
          right:  { style: BorderStyle.NONE, size: 0, color: fill },
          left:   { style: BorderStyle.SINGLE, size: 24, color: edge },
        },
        children: (Array.isArray(text) ? text : [text]).map((t, i) => new Paragraph({
          spacing: { after: i === (Array.isArray(text) ? text.length - 1 : 0) ? 0 : 90, line: 250 },
          children: mdRuns(t, { font: BODY_FONT, size: 18, bold: o.bold, color: TEXT }),
        })),
      })],
    })],
  });
}

// Two-column label/value table
function fieldTable(rows, o = {}) {
  const w = o.width ?? CONTENT_W;
  const lw = o.labelWidth ?? 2900;
  const vw = w - lw;
  return new Table({
    width: { size: w, type: WidthType.DXA },
    columnWidths: [lw, vw],
    layout: TableLayoutType.FIXED,
    rows: rows.map(([k, v]) => new TableRow({
      cantSplit: true,
      children: [
        new TableCell({
          width: { size: lw, type: WidthType.DXA },
          shading: { fill: TEAL_LIGHT, type: ShadingType.CLEAR },
          margins: cellMargins, borders: cellBorders, verticalAlign: VerticalAlign.CENTER,
          children: [new Paragraph({ spacing: { after: 0 }, children: [new TextRun({ text: k, font: BODY_FONT, size: 17, bold: true, color: TEAL_DARK })] })],
        }),
        new TableCell({
          width: { size: vw, type: WidthType.DXA },
          margins: cellMargins, borders: cellBorders, verticalAlign: VerticalAlign.CENTER,
          children: (Array.isArray(v) ? v : [v]).map((t, i, a) => new Paragraph({
            spacing: { after: i === a.length - 1 ? 0 : 60, line: 250 },
            children: mdRuns(t, { font: BODY_FONT, size: 17, color: TEXT }),
          })),
        }),
      ],
    })),
  });
}

// Generic data table.
//   o.cantSplitRows (default true) — keep a body row intact across a page break
//     so a row's cells never tear mid-content. Set false for tall registers
//     (e.g. the hazard register) whose single rows may legitimately exceed a
//     page; the header still repeats via tableHeader.
function dataTable(headers, rows, widths, o = {}) {
  const total = widths.reduce((a, b) => a + b, 0);
  const cantSplitRows = o.cantSplitRows !== false;
  const head = new TableRow({
    tableHeader: true,
    cantSplit: true,
    children: headers.map((h, i) => new TableCell({
      width: { size: widths[i], type: WidthType.DXA },
      shading: { fill: o.headFill ?? TEAL, type: ShadingType.CLEAR },
      margins: cellMargins, borders: cellBorders, verticalAlign: VerticalAlign.CENTER,
      children: [new Paragraph({ spacing: { after: 0 }, alignment: o.headAlign,
        children: [new TextRun({ text: h, font: HEAD_FONT, size: o.headSize ?? 15, bold: true, color: WHITE })] })],
    })),
  });
  const body = rows.map((r, ri) => new TableRow({
    cantSplit: cantSplitRows,
    children: r.map((c, i) => {
      const isObj = c !== null && typeof c === "object" && !Array.isArray(c);
      const val = isObj ? c.t : c;
      const fill = isObj && c.fill ? c.fill : (o.zebra && ri % 2 === 1 ? BG_LIGHT : undefined);
      return new TableCell({
        width: { size: widths[i], type: WidthType.DXA },
        margins: cellMargins, borders: cellBorders, verticalAlign: VerticalAlign.TOP,
        shading: fill ? { fill, type: ShadingType.CLEAR } : undefined,
        children: (Array.isArray(val) ? val : [val]).map((t, j, a) => new Paragraph({
          spacing: { after: j === a.length - 1 ? 0 : 50, line: 240 },
          alignment: (isObj && c.align) || (o.colAlign && o.colAlign[i]) || undefined,
          children: mdRuns(t, {
            font: BODY_FONT,
            size: (isObj && c.size) || o.size || 16,
            bold: (isObj && c.bold) || (o.boldFirstCol && i === 0),
            color: (isObj && c.color) || TEXT,
          }),
        })),
      });
    }),
  }));
  return new Table({ width: { size: total, type: WidthType.DXA }, columnWidths: widths, layout: TableLayoutType.FIXED, rows: [head, ...body] });
}

// Risk score chip
function scoreCell(score) {
  let fill = LOW_FILL;
  if (score >= 17) fill = VHIGH_FILL;
  else if (score >= 10) fill = HIGH_FILL;
  else if (score >= 5) fill = MED_FILL;
  return { t: String(score), fill, bold: true, align: AlignmentType.CENTER };
}

function bandOf(score) {
  if (score >= 17) return "Unacceptable";
  if (score >= 10) return "High";
  if (score >= 5) return "Medium";
  return "Low";
}

// Cover block
function coverBlock(titleLines, subtitle) {
  const out = [];
  out.push(new Table({
    width: { size: CONTENT_W, type: WidthType.DXA },
    columnWidths: [CONTENT_W],
    layout: TableLayoutType.FIXED,
    rows: [
      new TableRow({ cantSplit: true, children: [new TableCell({
        width: { size: CONTENT_W, type: WidthType.DXA },
        shading: { fill: TEAL, type: ShadingType.CLEAR },
        margins: { top: 420, bottom: 300, left: 300, right: 300 },
        borders: noBorder,
        children: [
          new Paragraph({ spacing: { after: 60 }, children: [new TextRun({ text: "21ST CENTURY AV LTD", font: HEAD_FONT, size: 26, bold: true, color: WHITE })] }),
          new Paragraph({ spacing: { after: 260 }, children: [new TextRun({ text: "Your Audio Visual Partner", font: BODY_FONT, size: 17, italics: true, color: "CFEAF0" })] }),
          ...titleLines.map(t => new Paragraph({ spacing: { after: 40 }, children: [new TextRun({ text: t.toUpperCase(), font: HEAD_FONT, size: 40, bold: true, color: WHITE })] })),
        ],
      })] }),
      new TableRow({ children: [new TableCell({
        width: { size: CONTENT_W, type: WidthType.DXA },
        shading: { fill: GOLD, type: ShadingType.CLEAR },
        margins: { top: 90, bottom: 90, left: 300, right: 300 },
        borders: noBorder,
        children: [new Paragraph({ spacing: { after: 0 }, children: [new TextRun({ text: subtitle, font: HEAD_FONT, size: 18, bold: true, color: "3A2E00" })] })],
      })] }),
    ],
  }));
  return out;
}

function runningHeader(text) {
  return new Header({
    children: [new Paragraph({
      spacing: { after: 60 },
      border: { bottom: { style: BorderStyle.SINGLE, size: 6, color: TEAL, space: 3 } },
      children: [new TextRun({ text, font: BODY_FONT, size: 14, color: TEXT_LIGHT })],
    })],
  });
}

function runningFooter(text, opts = {}) {
  const runs = [
    new TextRun({ text: text + "   |   Page ", font: BODY_FONT, size: 14, color: TEXT_LIGHT }),
    new TextRun({ children: [PageNumber.CURRENT], font: BODY_FONT, size: 14, color: TEXT_LIGHT }),
  ];
  // A removable internal page-0 makes a whole-document "of N" total wrong for the
  // client: it counts the internal page, and renderers miscount NUMPAGES once
  // page numbering restarts (LibreOffice over-counts). So the total is omitted
  // while an internal page is present — the client pages still read Page 1, 2,
  // 3 … from the cover, which is always correct. The final-issue document (no
  // internal page) keeps the full "Page X of Y".
  if (!opts.hideTotal) {
    runs.push(new TextRun({ text: " of ", font: BODY_FONT, size: 14, color: TEXT_LIGHT }));
    runs.push(new TextRun({ children: [PageNumber.TOTAL_PAGES], font: BODY_FONT, size: 14, color: TEXT_LIGHT }));
  }

  return new Footer({
    children: [new Paragraph({
      alignment: AlignmentType.CENTER,
      border: { top: { style: BorderStyle.SINGLE, size: 6, color: GOLD, space: 4 } },
      spacing: { before: 60 },
      children: runs,
    })],
  });
}

// Footer for the removable internal page-0: a clear "not for issue" banner and,
// deliberately, NO page-number field — the page sits outside the client
// numbering, which restarts at 1 on the client cover.
function internalFooter(text) {
  return new Footer({
    children: [new Paragraph({
      alignment: AlignmentType.CENTER,
      border: { top: { style: BorderStyle.SINGLE, size: 6, color: GOLD, space: 4 } },
      spacing: { before: 60 },
      children: [new TextRun({ text, font: HEAD_FONT, size: 14, bold: true, color: "B00020" })],
    })],
  });
}

const numbering = {
  config: [
    {
      reference: "bullets",
      levels: [
        { level: 0, format: LevelFormat.BULLET, text: "\u2022", alignment: AlignmentType.LEFT,
          style: { paragraph: { indent: { left: 360, hanging: 220 } }, run: { color: TEAL, font: BODY_FONT } } },
        { level: 1, format: LevelFormat.BULLET, text: "\u25E6", alignment: AlignmentType.LEFT,
          style: { paragraph: { indent: { left: 740, hanging: 220 } }, run: { color: GOLD, font: BODY_FONT } } },
      ],
    },
    {
      reference: "numbers",
      levels: [
        { level: 0, format: LevelFormat.DECIMAL, text: "%1.", alignment: AlignmentType.LEFT,
          style: { paragraph: { indent: { left: 400, hanging: 260 } }, run: { color: TEAL_DARK, bold: true, font: BODY_FONT } } },
      ],
    },
  ],
};

module.exports = {
  TEAL, TEAL_DARK, TEAL_LIGHT, GOLD, GOLD_LIGHT, WHITE, TEXT, TEXT_MID, TEXT_LIGHT, BG_LIGHT, BORDER,
  LOW_FILL, MED_FILL, HIGH_FILL, VHIGH_FILL, HEAD_FONT, BODY_FONT,
  A4, MARGIN, CONTENT_W, LAND_CONTENT_W, cellBorders, cellMargins, noBorder,
  p, bullet, numItem, sp, pageBreakBefore, qtyCell, isValidQty,
  sectionHeader, subHeader, calloutBox, fieldTable, dataTable,
  scoreCell, bandOf, coverBlock, runningHeader, runningFooter, internalFooter, numbering,
};
