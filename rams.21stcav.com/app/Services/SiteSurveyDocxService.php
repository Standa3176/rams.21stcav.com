<?php

namespace App\Services;

use App\Models\SiteSurvey;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Builds a branded .docx Site Survey report.
 *
 * Structure:
 *   Cover page   (template if available, else programmatic)
 *   Project details + general notes
 *   Per-room tables (dimensions, infrastructure, AV requirements)
 */
class SiteSurveyDocxService
{
    // ── Brand ─────────────────────────────────────────────────────────────────
    private const TEAL      = '007B8A';
    private const DARK_GREY = '333333';
    private const MID_GREY  = '666666';
    private const ROW_ALT   = 'F0FBFC';
    private const WHITE     = 'FFFFFF';

    // ── Geometry ──────────────────────────────────────────────────────────────
    private const M_PORT = 1020;
    private const W_PORT = 9866;

    public function __construct(
        private readonly DocumentTemplateService $templates,
    ) {}

    /**
     * Build the .docx, save to storage/app/site-surveys/, update the model
     * filename column, and return the absolute path.
     */
    public function build(SiteSurvey $survey): string
    {
        $survey->loadMissing('rooms.photos');

        $storageDir = storage_path('app/site-surveys');
        if (! is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $dateStr = $survey->survey_date ? $survey->survey_date->format('d/m/Y') : '—';

        if ($this->templates->exists('site-survey')) {
            $phpWord = $this->templates->load('site-survey', [
                'project_name'  => $survey->project_name             ?? '—',
                'client_name'   => $survey->client_name              ?? '—',
                'site_address'  => $survey->site_address             ?? '—',
                'surveyor_name' => $survey->surveyor_name            ?? '—',
                'survey_date'   => $dateStr,
                'general_notes' => $survey->general_notes            ?? '',
                'hazard_table'  => '',   // placeholder — built programmatically below
                'equipment_table' => '', // placeholder — built programmatically below
            ]);
            $this->configure($phpWord);

            // Append a new portrait section for room-by-room detail
            $section = $phpWord->addSection($this->portraitStyle());
        } else {
            $phpWord = new PhpWord();
            $this->configure($phpWord);

            // Programmatic cover / summary section
            $section = $phpWord->addSection($this->portraitStyle());
            $this->buildCover($section, $survey, $dateStr);
        }

        // ── Per-room tables ───────────────────────────────────────────────────
        foreach ($survey->rooms->sortBy('sort_order') as $room) {
            $this->buildRoomBlock($section, $room);
        }

        // ── Save ──────────────────────────────────────────────────────────────
        $filename = 'site_survey_'
            . $survey->id . '_'
            . now()->format('Ymd_His') . '.docx';
        $filePath = $storageDir . '/' . $filename;

        IOFactory::createWriter($phpWord, 'Word2007')->save($filePath);

        $survey->update(['filename' => $filename]);

        return $filePath;
    }

    // =========================================================================
    // PROGRAMMATIC COVER (fallback)
    // =========================================================================

    private function buildCover(\PhpOffice\PhpWord\Element\Section $section, SiteSurvey $survey, string $dateStr): void
    {
        $company = config('rams.company_name', 'Company Name');

        $section->addText($company,
            $this->font(24, bold: true, colour: self::TEAL),
            ['alignment' => Jc::LEFT]);

        $section->addText('SITE SURVEY REPORT',
            $this->font(17, bold: true, colour: self::DARK_GREY),
            ['alignment' => Jc::LEFT,
             'borderBottomSize' => 12,
             'borderBottomColor' => self::TEAL,
             'borderBottomSpace' => 4]);

        $section->addText(
            ($survey->project_name ?? '') . '  |  ' . ($survey->client_name ?? ''),
            $this->font(11, colour: self::MID_GREY),
            ['alignment' => Jc::LEFT, 'spacing' => ['before' => 60, 'after' => 200]]);

        $this->sectionHeading($section, 'Survey Details');
        $table = $section->addTable($this->tableStyle());
        $lc = ['bgColor' => self::ROW_ALT];
        $vc = ['bgColor' => self::WHITE];
        $lf = $this->font(9, bold: true);
        $vf = $this->font(9);

        foreach ([
            ['Project Name',  $survey->project_name  ?? '—'],
            ['Client',        $survey->client_name   ?? '—'],
            ['Site Address',  $survey->site_address  ?? '—'],
            ['Surveyor',      $survey->surveyor_name ?? '—'],
            ['Survey Date',   $dateStr],
            ['General Notes', $survey->general_notes ?? ''],
        ] as [$label, $value]) {
            $row = $table->addRow(400);
            $row->addCell(2800, $lc)->addText($label, $lf);
            $row->addCell(7066, $vc)->addText((string) $value, $vf);
        }

        $section->addTextBreak(1);
    }

    // =========================================================================
    // PER-ROOM BLOCK
    // =========================================================================

    private function buildRoomBlock(\PhpOffice\PhpWord\Element\Section $section, object $room): void
    {
        $this->sectionHeading($section,
            $room->room_name . ($room->floor ? ' — Floor ' . $room->floor : ''));

        $table = $section->addTable($this->tableStyle());
        $lc = ['bgColor' => self::ROW_ALT];
        $vc = ['bgColor' => self::WHITE];
        $lf = $this->font(9, bold: true);
        $vf = $this->font(9);

        // Dimensions
        $dims = array_filter([
            $room->room_width_m  ? $room->room_width_m  . ' m'  : null,
            $room->room_depth_m  ? $room->room_depth_m  . ' m'  : null,
            $room->room_height_m ? $room->room_height_m . ' m'  : null,
        ]);

        $rows = [
            ['Room Ref',            $room->room_ref              ?? '—'],
            ['Dimensions (W×D×H)',  empty($dims) ? '—' : implode(' × ', $dims)],
            ['Ceiling Type',        $room->ceiling_type          ?? '—'],
            ['Ceiling Height',      $room->ceiling_height_m ? $room->ceiling_height_m . ' m' : '—'],
            ['Wall Material',       $room->wall_material         ?? '—'],
            ['Floor Type',          $room->floor_type            ?? '—'],
            ['Power Outlets',       (string) ($room->power_outlet_count ?? 0)],
            ['Network Ports',       (string) ($room->network_port_count ?? 0)],
            ['Has Power',           $room->has_power    ? 'Yes' : 'No'],
            ['Has Network',         $room->has_network  ? 'Yes' : 'No'],
            ['Req. Additional Power', $room->requires_additional_power ? 'Yes' : 'No'],
            ['Existing Cabling',    $room->existing_cabling      ?? '—'],
            ['AV Requirements',     $room->av_requirements       ?? '—'],
            ['Existing AV Equipment', $room->av_equipment_list   ?? '—'],
            ['Access Notes',        $room->access_notes          ?? '—'],
            ['Notes',               $room->notes                 ?? '—'],
        ];

        foreach ($rows as [$label, $value]) {
            $row = $table->addRow(400);
            $row->addCell(2800, $lc)->addText($label,          $lf);
            $row->addCell(7066, $vc)->addText((string) $value, $vf);
        }

        $section->addTextBreak(1);
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function configure(PhpWord $phpWord): void
    {
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(10);
    }

    private function portraitStyle(): array
    {
        return [
            'marginTop'    => self::M_PORT,
            'marginBottom' => self::M_PORT,
            'marginLeft'   => self::M_PORT,
            'marginRight'  => self::M_PORT,
        ];
    }

    private function tableStyle(): array
    {
        return ['borderSize' => 4, 'borderColor' => 'CCCCCC', 'cellMargin' => 80];
    }

    private function font(int $size, bool $bold = false, ?string $colour = null): array
    {
        $f = ['name' => 'Arial', 'size' => $size, 'bold' => $bold];
        if ($colour !== null) {
            $f['color'] = $colour;
        }
        return $f;
    }

    private function sectionHeading(object $section, string $text): void
    {
        $section->addText($text,
            $this->font(11, bold: true, colour: self::TEAL),
            ['alignment' => Jc::LEFT, 'spacing' => ['before' => 120, 'after' => 60]]);
    }
}
