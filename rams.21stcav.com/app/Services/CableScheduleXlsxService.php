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

    private const TEAL      = '007B8A';
    private const WHITE     = 'FFFFFF';
    private const ROW_ALT   = 'F0FBFC';
    private const MID_GREY  = '666666';

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
            'font'      => ['bold' => true, 'size' => 14, 'color' => ['argb' => 'FF' . self::TEAL]],
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
            'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF' . self::TEAL]],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFCCCCCC']]],
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
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFCCCCCC']]],
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
                'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFCCCCCC']]],
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
        $filename     = 'cable_schedule_' . $schedule->id . '_' . now()->format('Ymd') . '.xlsx';
        $absolutePath = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_CABLE, $filename);

        (new Xlsx($spreadsheet))->save($absolutePath);

        // Persist filename via source_filename (always exists on table)
        $schedule->update(['source_filename' => $filename]);

        return $absolutePath;
    }
}
