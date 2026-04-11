<?php

namespace App\Services;

use App\Models\Worksheet;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * WorksheetDocxService — builds the Worksheet DOCX from generated_data.
 *
 * Document structure (four sections per room, per D-03):
 *   Cover header (project name, client, ref, date)
 *   Per room:
 *     A. Equipment — table with Item | Qty columns
 *     B. Install Steps — narrative paragraph from AI-generated steps
 *     C. Cable Routes — paragraph from survey cable_route_desc
 *     D. Power & Network — field table (outlets, additional power, ports, cabling)
 *
 * Storage:
 *   Files are written to Storage::disk('local')->path('worksheets/') so that
 *   WorksheetController::download() can resolve them via the same disk.
 *
 * @see WorksheetGeneratorService — produces generated_data consumed here
 * @see BuildWorksheetJob         — calls build() after generation
 */
class WorksheetDocxService
{
    // ── Brand colours ─────────────────────────────────────────────────────────
    private const TEAL  = '178A95';
    private const WHITE = 'FFFFFF';
    private const DARK  = '0B3C45';
    private const GREY  = 'F3F6F7';
    private const MID   = 'E5E7EB';

    // ── Public entry point ────────────────────────────────────────────────────

    /**
     * Build the Worksheet DOCX, save it to disk, and update $worksheet->filename.
     *
     * @param  array     $generatedData  Data from WorksheetGeneratorService::generateContent()
     * @param  Worksheet $worksheet      Model record — filename will be updated after save
     * @return void
     */
    public function build(array $generatedData, Worksheet $worksheet): void
    {
        $phpWord = new PhpWord();
        $this->configureStyles($phpWord);

        $project = $generatedData['project'] ?? [];
        $rooms   = $generatedData['rooms']   ?? [];

        // ── Cover / header section ────────────────────────────────────────────
        $coverSection = $phpWord->addSection($this->sectionProps());
        $this->buildCoverHeader($coverSection, $project, $worksheet);

        // ── One section per room ──────────────────────────────────────────────
        foreach ($rooms as $room) {
            $section = $phpWord->addSection($this->sectionProps());
            $this->buildRoom($section, $room);
        }

        // ── Persist to disk ───────────────────────────────────────────────────
        $filename  = 'worksheet_' . $worksheet->id . '_' . now()->format('Ymd_His') . '.docx';
        $directory = Storage::disk('local')->path('worksheets');

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        $fullPath = $directory . DIRECTORY_SEPARATOR . $filename;

        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($fullPath);

        $worksheet->update(['filename' => $filename]);

        Log::info('WorksheetDocxService: DOCX saved', [
            'worksheet_id' => $worksheet->id,
            'filename'     => $filename,
        ]);
    }

    // ── Cover header ──────────────────────────────────────────────────────────

    private function buildCoverHeader(
        \PhpOffice\PhpWord\Element\Section $section,
        array $project,
        Worksheet $worksheet
    ): void {
        $projectName = $project['name'] ?? $worksheet->project_name;
        $clientName  = $project['client_name'] ?? $worksheet->client_name ?? '';
        $ref         = $project['quote_reference'] ?? $worksheet->project_ref ?? '';
        $siteAddress = $project['site_address'] ?? $worksheet->site_address ?? '';

        // Title
        $section->addText(
            'Installation Worksheet',
            ['name' => 'Arial', 'size' => 22, 'bold' => true, 'color' => self::TEAL],
            ['alignment' => Jc::START]
        );

        $section->addText(
            $projectName,
            ['name' => 'Arial', 'size' => 16, 'bold' => true, 'color' => self::DARK],
            ['alignment' => Jc::START]
        );

        $section->addTextBreak(1);

        // Meta table
        $table = $section->addTable(['borderSize' => 0, 'borderColor' => self::MID, 'cellMarginLeft' => 100, 'cellMarginRight' => 100]);

        $metaRows = [
            ['Client',    $clientName],
            ['Site',      $siteAddress],
            ['Reference', $ref],
            ['Date',      now()->format('d F Y')],
        ];

        foreach ($metaRows as [$label, $value]) {
            $row = $table->addRow();

            $cell1 = $row->addCell(2000);
            $cell1->addText($label, ['bold' => true, 'size' => 10, 'color' => self::TEAL]);

            $cell2 = $row->addCell(7000);
            $cell2->addText((string) $value, ['size' => 10, 'color' => self::DARK]);
        }

        $section->addTextBreak(1);
        $section->addLine(['weight' => 1, 'color' => self::TEAL, 'width' => 9000, 'height' => 0]);
        $section->addTextBreak(1);
    }

    // ── Room section ──────────────────────────────────────────────────────────

    private function buildRoom(
        \PhpOffice\PhpWord\Element\Section $section,
        array $room
    ): void {
        $roomName   = $room['name'] ?? 'Unknown Room';
        $isSurveyed = $room['is_surveyed'] ?? false;

        // Room heading
        $section->addText(
            $roomName,
            ['name' => 'Arial', 'size' => 14, 'bold' => true, 'color' => self::DARK],
            ['alignment' => Jc::START]
        );

        // Surveyed badge
        $badgeText  = $isSurveyed ? 'Surveyed' : 'Not Surveyed';
        $badgeColor = $isSurveyed ? '065F46' : '6B7280';
        $section->addText($badgeText, ['size' => 9, 'color' => $badgeColor, 'italic' => true]);
        $section->addTextBreak(1);

        // ── A. Equipment ──────────────────────────────────────────────────────
        $this->addSectionHeading($section, 'A. Equipment');
        $this->buildEquipmentTable($section, $room['equipment'] ?? []);

        // ── B. Install Steps ──────────────────────────────────────────────────
        $this->addSectionHeading($section, 'B. Install Steps');
        $steps = $room['install_steps'] ?? null;
        if ($steps) {
            $section->addText(
                $steps,
                ['size' => 10, 'color' => self::DARK],
                ['lineHeight' => 1.6]
            );
        } else {
            $section->addText(
                'Install steps not available.',
                ['size' => 10, 'color' => '9CA3AF', 'italic' => true]
            );
        }
        $section->addTextBreak(1);

        // ── C. Cable Routes ───────────────────────────────────────────────────
        $this->addSectionHeading($section, 'C. Cable Routes');
        $cableRoute = $room['cable_route_desc'] ?? null;
        if ($cableRoute) {
            $section->addText((string) $cableRoute, ['size' => 10, 'color' => self::DARK]);
        } else {
            $section->addText(
                'Not surveyed',
                ['size' => 10, 'color' => '9CA3AF', 'italic' => true]
            );
        }
        $section->addTextBreak(1);

        // ── D. Power & Network ────────────────────────────────────────────────
        $this->addSectionHeading($section, 'D. Power & Network');
        $this->buildPowerNetworkTable($section, $room);

        $section->addTextBreak(1);
    }

    // ── Equipment table ───────────────────────────────────────────────────────

    private function buildEquipmentTable(
        \PhpOffice\PhpWord\Element\Section $section,
        array $equipment
    ): void {
        if (empty($equipment)) {
            $section->addText(
                'No equipment listed for this room.',
                ['size' => 10, 'color' => '9CA3AF', 'italic' => true]
            );
            $section->addTextBreak(1);
            return;
        }

        $tableStyle = [
            'borderSize'  => 6,
            'borderColor' => self::MID,
            'cellMargin'  => 80,
        ];

        $table = $section->addTable($tableStyle);

        // Header row
        $header = $table->addRow();
        $hCell1 = $header->addCell(6500, ['bgColor' => self::TEAL]);
        $hCell1->addText('Item', ['bold' => true, 'color' => self::WHITE, 'size' => 9]);
        $hCell2 = $header->addCell(2500, ['bgColor' => self::TEAL]);
        $hCell2->addText('Qty', ['bold' => true, 'color' => self::WHITE, 'size' => 9]);

        // Data rows
        foreach ($equipment as $idx => $item) {
            $bgColor = ($idx % 2 === 0) ? self::WHITE : self::GREY;
            $row     = $table->addRow();

            $cell1 = $row->addCell(6500, ['bgColor' => $bgColor]);
            $cell1->addText(
                $item['name'] ?? $item['description'] ?? '—',
                ['size' => 10, 'color' => self::DARK]
            );

            $cell2 = $row->addCell(2500, ['bgColor' => $bgColor]);
            $cell2->addText(
                (string) ($item['quantity'] ?? 1),
                ['size' => 10, 'color' => self::DARK]
            );
        }

        $section->addTextBreak(1);
    }

    // ── Power & Network table ─────────────────────────────────────────────────

    private function buildPowerNetworkTable(
        \PhpOffice\PhpWord\Element\Section $section,
        array $room
    ): void {
        $notSurveyed = 'Not surveyed';

        $rows = [
            ['Power outlets',              $room['power_outlet_count'] ?? null],
            ['Additional power required',  $room['requires_additional_power'] ?? null],
            ['Network ports',              $room['network_port_count'] ?? null],
            ['Existing cabling',           $room['existing_cabling'] ?? null],
        ];

        $tableStyle = [
            'borderSize'  => 6,
            'borderColor' => self::MID,
            'cellMargin'  => 80,
        ];

        $table = $section->addTable($tableStyle);

        foreach ($rows as [$label, $value]) {
            $row = $table->addRow();

            $cell1 = $row->addCell(3500, ['bgColor' => self::GREY]);
            $cell1->addText($label, ['bold' => true, 'size' => 9, 'color' => '4B5563']);

            $cell2 = $row->addCell(5500);
            if ($value !== null && $value !== '') {
                $cell2->addText((string) $value, ['size' => 10, 'color' => self::DARK]);
            } else {
                $cell2->addText($notSurveyed, ['size' => 10, 'color' => '9CA3AF', 'italic' => true]);
            }
        }

        $section->addTextBreak(1);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function addSectionHeading(
        \PhpOffice\PhpWord\Element\Section $section,
        string $text
    ): void {
        $section->addText(
            $text,
            ['bold' => true, 'size' => 11, 'color' => self::TEAL, 'allCaps' => true],
            ['spaceAfter' => 80]
        );
    }

    private function sectionProps(): array
    {
        return [
            'marginLeft'   => 1080, // 1.5cm
            'marginRight'  => 1080,
            'marginTop'    => 1080,
            'marginBottom' => 1080,
        ];
    }

    private function configureStyles(PhpWord $phpWord): void
    {
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(10);

        $phpWord->addTitleStyle(1, [
            'bold'  => true,
            'size'  => 14,
            'color' => self::DARK,
        ]);

        $phpWord->addTitleStyle(2, [
            'bold'  => true,
            'size'  => 12,
            'color' => self::TEAL,
        ]);
    }
}
