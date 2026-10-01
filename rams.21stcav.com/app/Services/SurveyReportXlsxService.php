<?php

namespace App\Services;

use App\Models\SiteSurvey;
use App\Models\SurveyReportSection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Phase 46.6, Plan 04 — the completed-survey report (D-05/D-07) as a real
 * `.xlsx` workbook, following `CableScheduleXlsxService`'s existing
 * PhpSpreadsheet writer pattern (D-07: "XLS" means a real .xlsx; no new
 * dependency — phpoffice/phpspreadsheet ^5.8 is already installed and
 * already wired into app/Services/*).
 *
 * Same five sections as SurveyPdfService::buildCompletedReport()'s PDF:
 * Rooms Summary (derived from the survey's own rooms, no new capture) plus
 * the four office-annotated sections read via
 * SurveyReportSection::latestFor().
 *
 * ── FORMULA-INJECTION GUARD ─────────────────────────────────────────────
 * Office/engineer-origin text is untrusted free text. A cell value
 * beginning with `=`, `+`, `-`, or `@` is a formula-injection risk when the
 * workbook is later opened/edited in Excel (OWASP CSV/Excel-formula
 * injection). Every free-text cell this service writes goes through
 * guardFormulaInjection() first, which prefixes a literal `'` on any such
 * leading character — this is a gap the existing variationsCsv()/
 * CableScheduleXlsxService precedent also carries; fixed here rather than
 * copied forward (46.6-CONTEXT.md / 46.6-04-PLAN.md standing constraint).
 *
 * @see app/Services/CableScheduleXlsxService.php (the writer pattern copied by shape)
 * @see app/Services/SurveyPdfService.php::buildCompletedReport() (the PDF sibling)
 * @see .planning/phases/46.6-survey-review-completed-report/46.6-CONTEXT.md (D-05, D-07)
 */
class SurveyReportXlsxService
{
    public function __construct(
        private readonly DocumentArtifactStorage $artifacts = new DocumentArtifactStorage(),
    ) {}

    // Same palette VALUES as CableScheduleXlsxService — not necessarily the
    // same class, but the same hex values — so every workbook this app
    // produces reads as one product family when opened in Excel (plan
    // interface note: this is an internal office document, not the
    // client-facing branded survey link, so it uses the XLSX family's own
    // palette rather than the web teal/gold).
    private const NAVY     = '0B2440';
    private const WHITE    = 'FFFFFF';
    private const ROW_ALT  = 'F7F9FC';
    private const MID_GREY = '64748B';
    private const HAIRLINE = 'E2E8F0';

    /**
     * Build the completed-survey report as a real .xlsx and return its
     * absolute path.
     */
    public function build(SiteSurvey $survey): string
    {
        $survey->loadMissing(['rooms']);

        $sectionHeadings = [
            'points_raised'         => 'Points Raised / Possible Issues',
            'items_agreed'          => 'Items Agreed With Client',
            'client_items_required' => "Client's Items Required",
            'additional_comments'   => 'Additional Comments',
        ];

        $workTypeLabels = [
            'new_install' => 'New install',
            'upgrade'     => 'Upgrade',
            'retrofit'    => 'Retrofit',
            'fault'       => 'Fault',
        ];

        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Completed Survey Report');

        // ── Title row ────────────────────────────────────────────────────────
        $sheet->mergeCells('A1:D1');
        $sheet->setCellValue('A1', '21st Century AV Ltd — Completed Survey Report');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FF' . self::NAVY]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);

        // ── Project info row ─────────────────────────────────────────────────
        $sheet->mergeCells('A2:D2');
        $info = implode('  |  ', array_filter([
            $survey->project_name,
            $survey->project_ref ? 'Ref: ' . $survey->project_ref : null,
            $survey->client_name ? 'Client: ' . $survey->client_name : null,
            'Generated: ' . now()->format('d/m/Y'),
        ]));
        $sheet->setCellValue('A2', $this->guardFormulaInjection((string) $info));
        $sheet->getStyle('A2')->applyFromArray([
            'font' => ['size' => 9, 'color' => ['argb' => 'FF' . self::MID_GREY]],
        ]);

        $headerStyle = [
            'font'      => ['bold' => true, 'color' => ['argb' => 'FF' . self::WHITE]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . self::NAVY]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF' . self::HAIRLINE]]],
        ];

        // ── Rooms Summary (row 4 header, rows 5+ data) ──────────────────────────
        $sheet->getRowDimension(3)->setRowHeight(4); // spacer

        $headers = ['A' => 'Room', 'B' => 'Type', 'C' => 'Work Type', 'D' => 'Notes'];
        foreach ($headers as $col => $label) {
            $sheet->setCellValue($col . '4', $label);
        }
        $sheet->getStyle('A4:D4')->applyFromArray($headerStyle);
        $sheet->getRowDimension(4)->setRowHeight(20);

        $rowNum = 5;
        foreach ($survey->rooms as $i => $room) {
            $bg = ($i % 2 === 0) ? 'FFFFFFFF' : 'FF' . self::ROW_ALT;

            $digest        = trim((string) ($room->notes ?? ''));
            $digest        = $digest !== '' ? \Illuminate\Support\Str::limit($digest, 200) : '—';
            $workTypeKey   = strtolower((string) ($room->work_type ?? ''));
            $workTypeLabel = $workTypeLabels[$workTypeKey] ?? ($room->work_type ?: '—');

            $cells = [
                'A' => (string) ($room->room_name ?? ''),
                'B' => (string) ($room->space_type ?? '—'),
                'C' => $workTypeLabel,
                'D' => $digest,
            ];

            foreach ($cells as $col => $value) {
                $sheet->setCellValue($col . $rowNum, $this->guardFormulaInjection($value));
            }

            $sheet->getStyle("A{$rowNum}:D{$rowNum}")->applyFromArray([
                'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF' . self::HAIRLINE]]],
                'font'      => ['size' => 9],
                'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_TOP],
            ]);

            $rowNum++;
        }

        // ── Column widths ─────────────────────────────────────────────────────
        $widths = ['A' => 24, 'B' => 16, 'C' => 16, 'D' => 50];
        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        // ── The four office-annotated sections — one label row + one wrapped
        //    body row each, separated by a blank spacer row ─────────────────────
        $rowNum++; // spacer before the first section block

        foreach (SurveyReportSection::SECTIONS as $sectionKey) {
            $sheet->mergeCells("A{$rowNum}:D{$rowNum}");
            $sheet->setCellValue("A{$rowNum}", $sectionHeadings[$sectionKey]);
            $sheet->getStyle("A{$rowNum}")->applyFromArray($headerStyle);
            $sheet->getRowDimension($rowNum)->setRowHeight(20);
            $rowNum++;

            $body = SurveyReportSection::latestFor($survey->id, $sectionKey)?->body;
            $text = filled($body) ? $body : 'Not yet reviewed.';

            $sheet->mergeCells("A{$rowNum}:D{$rowNum}");
            $sheet->setCellValue("A{$rowNum}", $this->guardFormulaInjection((string) $text));
            $sheet->getStyle("A{$rowNum}")->applyFromArray([
                'alignment' => ['wrapText' => true, 'vertical' => Alignment::VERTICAL_TOP],
                'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF' . self::HAIRLINE]]],
                'font'      => ['size' => 9],
            ]);
            $sheet->getRowDimension($rowNum)->setRowHeight(60);
            $rowNum++;

            $rowNum++; // spacer between sections
        }

        // ── Footer row ────────────────────────────────────────────────────────
        $sheet->mergeCells("A{$rowNum}:D{$rowNum}");
        $sheet->setCellValue("A{$rowNum}", '21st Century AV Ltd — Confidential');
        $sheet->getStyle("A{$rowNum}")->applyFromArray([
            'font'      => ['size' => 8, 'italic' => true, 'color' => ['argb' => 'FF' . self::MID_GREY]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // ── Save ──────────────────────────────────────────────────────────────
        $slug      = \Illuminate\Support\Str::slug($survey->project_name ?: ('survey-' . $survey->id));
        $filename  = "completed-report-{$survey->id}-{$slug}-" . now()->format('Ymd_His_u') . '.xlsx';
        $absolutePath = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_SURVEY, $filename);

        (new Xlsx($spreadsheet))->save($absolutePath);

        return $absolutePath;
    }

    /**
     * Neutralise a formula-injection payload before it is written to any
     * cell. A leading `=`, `+`, `-`, or `@` is prefixed with a literal `'`
     * so the value is stored as plain text, never as an Excel formula —
     * independently testable, per the standing constraint that this guard
     * must not be inlined.
     */
    private function guardFormulaInjection(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        return in_array($value[0], ['=', '+', '-', '@'], true)
            ? "'" . $value
            : $value;
    }
}
