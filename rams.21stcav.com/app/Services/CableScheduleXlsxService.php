<?php

namespace App\Services;

use App\Models\CableSchedule;
use App\Services\DocumentArtifactStorage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Color;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class CableScheduleXlsxService
{
    public function __construct(
        private readonly DocumentArtifactStorage $artifacts = new DocumentArtifactStorage(),
    ) {}

    // Cable-schedule re-audit — palette retuned from tier-one teal
    // (007B8A) + pale-teal alt row (F0FBFC) + generic mid-grey to
    // the Jetbuilt-clean navy/accent/slate stack. The XLSX now
    // reads as one product surface with the rest of the app when
    // opened in Excel.
    private const NAVY      = '0B2440';  // header fill
    private const ACCENT    = '2E7BFF';  // title text
    private const WHITE     = 'FFFFFF';
    private const ROW_ALT   = 'F7F9FC';  // paper canvas — matches --paper
    private const MID_GREY  = '64748B';  // matches --ink-500
    private const HAIRLINE  = 'E2E8F0';  // matches --ink-200

    /**
     * Build a formatted .xlsx cable schedule and return its absolute path.
     */
    public function build(CableSchedule $schedule): string
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Cable Schedule');

        // ── Title row ────────────────────────────────────────────────────────
        $sheet->mergeCells('A1:H1');
        $sheet->setCellValue('A1', '21st Century AV Ltd — Cable Schedule');
        $sheet->getStyle('A1')->applyFromArray([
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FF' . self::ACCENT]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_LEFT],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);

        // ── Project info row ─────────────────────────────────────────────────
        $sheet->mergeCells('A2:H2');
        $info = implode('  |  ', array_filter([
            $schedule->project_name,
            $schedule->project_ref  ? 'Ref: ' . $schedule->project_ref  : null,
            $schedule->client_name  ? 'Client: ' . $schedule->client_name : null,
            'Generated: ' . now()->format('d/m/Y'),
        ]));
        $sheet->setCellValue('A2', $info);
        $sheet->getStyle('A2')->applyFromArray([
            'font'      => ['size' => 9, 'color' => ['argb' => 'FF' . self::MID_GREY]],
        ]);

        // ── Column headers (row 4) ────────────────────────────────────────────
        $headers = [
            'A' => 'Cable ID',
            'B' => 'From Location',
            'C' => 'To Location',
            'D' => 'Cable Type',
            'E' => 'Cores',
            'F' => 'Length (m)',
            'G' => 'Notes',
            'H' => 'Status',
        ];

        $sheet->getRowDimension(3)->setRowHeight(4); // spacer

        foreach ($headers as $col => $label) {
            $sheet->setCellValue($col . '4', $label);
        }

        $headerStyle = [
            'font'      => ['bold' => true, 'color' => ['argb' => 'FF' . self::WHITE]],
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . self::NAVY]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF' . self::HAIRLINE]]],
        ];
        $sheet->getStyle('A4:H4')->applyFromArray($headerStyle);
        $sheet->getRowDimension(4)->setRowHeight(20);

        // ── Data rows ─────────────────────────────────────────────────────────
        $rowNum = 5;
        foreach ($schedule->items as $i => $item) {
            $bg    = ($i % 2 === 0) ? 'FFFFFFFF' : 'FF' . self::ROW_ALT;
            $cells = [
                'A' => $item->cable_id         ?? '',
                'B' => $item->from_location,
                'C' => $item->to_location,
                'D' => $item->cable_type,
                'E' => $item->cores            ?? '',
                'F' => $item->approx_length_m  ?? '',
                'G' => $item->notes            ?? '',
                'H' => '',  // Status — for manual completion
            ];

            foreach ($cells as $col => $value) {
                $sheet->setCellValue($col . $rowNum, $value);
            }

            $sheet->getStyle("A{$rowNum}:H{$rowNum}")->applyFromArray([
                'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => $bg]],
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF' . self::HAIRLINE]]],
                'font'    => ['size' => 9],
            ]);

            $rowNum++;
        }

        // Add a few blank rows for manual entries
        for ($b = 0; $b < 5; $b++) {
            foreach (array_keys($headers) as $col) {
                $sheet->setCellValue($col . $rowNum, '');
            }
            $sheet->getStyle("A{$rowNum}:H{$rowNum}")->applyFromArray([
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FF' . self::HAIRLINE]]],
                'font'    => ['size' => 9],
            ]);
            $rowNum++;
        }

        // ── Column widths ─────────────────────────────────────────────────────
        $widths = ['A' => 12, 'B' => 22, 'C' => 22, 'D' => 18, 'E' => 10, 'F' => 12, 'G' => 30, 'H' => 14];
        foreach ($widths as $col => $width) {
            $sheet->getColumnDimension($col)->setWidth($width);
        }

        // ── Footer row ────────────────────────────────────────────────────────
        $rowNum++;
        $sheet->mergeCells("A{$rowNum}:H{$rowNum}");
        $sheet->setCellValue("A{$rowNum}", '21st Century AV Ltd — Confidential');
        $sheet->getStyle("A{$rowNum}")->applyFromArray([
            'font'      => ['size' => 8, 'italic' => true, 'color' => ['argb' => 'FF' . self::MID_GREY]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER],
        ]);

        // ── Save ──────────────────────────────────────────────────────────────
        // Cable-schedule re-audit — the M-09 pass missed this file. Bump
        // to Ymd_His_u so concurrent retries within the same wall-clock
        // second don't overwrite a successful earlier build mid-download.
        $filename     = 'cable_schedule_' . $schedule->id . '_' . now()->format('Ymd_His_u') . '.xlsx';
        $absolutePath = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_CABLE, $filename);

        (new Xlsx($spreadsheet))->save($absolutePath);

        // Persist filename via source_filename (always exists on table)
        $schedule->update(['source_filename' => $filename]);

        return $absolutePath;
    }
}
