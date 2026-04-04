<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

class CreateDocxTemplates extends Command
{
    protected $signature   = 'docx:create-templates';
    protected $description = 'Generate branded .docx template files in resources/templates/';

    // ── Brand ─────────────────────────────────────────────────────────────────
    private const TEAL      = '007B8A';
    private const DARK_GREY = '333333';
    private const MID_GREY  = '666666';
    private const ROW_ALT   = 'F0FBFC';
    private const WHITE     = 'FFFFFF';
    // ── Geometry (twips) ──────────────────────────────────────────────────────
    private const M_PORT = 1020;
    private const W_PORT = 9866;

    public function handle(): int
    {
        $dir = resource_path('templates');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $this->buildRams($dir);
        $this->buildOmManual($dir);
        $this->buildSiteSurvey($dir);

        $this->info('Templates written to resources/templates/');
        return self::SUCCESS;
    }

    // =========================================================================
    // RAMS TEMPLATE
    // =========================================================================

    private function buildRams(string $dir): void
    {
        $phpWord = $this->newDoc();
        $section = $phpWord->addSection($this->portraitStyle());

        $company = config('rams.company_name', 'Company Name');

        // ── Title block ───────────────────────────────────────────────────────
        $section->addText($company,
            $this->font(24, bold: true, colour: self::TEAL),
            ['alignment' => Jc::LEFT]);

        $section->addText('RISK ASSESSMENT & METHOD STATEMENT',
            $this->font(17, bold: true, colour: self::DARK_GREY),
            ['alignment' => Jc::LEFT, 'borderBottomSize' => 12, 'borderBottomColor' => self::TEAL, 'borderBottomSpace' => 4]);

        $section->addText('{{project_name}}  |  {{client_name}}',
            $this->font(11, colour: self::MID_GREY),
            ['alignment' => Jc::LEFT, 'spacing' => ['before' => 60, 'after' => 200]]);

        // ── Project details table ─────────────────────────────────────────────
        $this->sectionHeading($section, 'Project Details');
        $table = $section->addTable($this->tableStyle());
        $lf = $this->font(9, bold: true);
        $vf = $this->font(9);
        $lc = ['bgColor' => self::ROW_ALT];
        $vc = ['bgColor' => self::WHITE];

        $rows = [
            ['Project Reference', '{{project_ref}}'],
            ['Project Name',      '{{project_name}}'],
            ['Client',            '{{client_name}}'],
            ['Site Address',      '{{site_address}}'],
            ['Contractor',        '{{contractor}}'],
            ['Works Description', '{{works_description}}'],
            ['Start Date',        '{{start_date}}'],
            ['Expected Duration', '{{expected_duration}}'],
            ['Document Status',   '{{document_status}}'],
            ['Date',              '{{date}}'],
        ];
        foreach ($rows as [$label, $value]) {
            $row = $table->addRow(400);
            $row->addCell(2800, $lc)->addText($label, $lf);
            $row->addCell(7066, $vc)->addText($value, $vf);
        }

        $section->addTextBreak(1);

        // ── Document authorisation table ──────────────────────────────────────
        $this->sectionHeading($section, 'Document Authorisation');
        $table = $section->addTable($this->tableStyle());
        $this->tealHeader($table, ['Role', 'Name', 'Title', 'Signature', 'Date'], [2100, 2100, 1800, 2200, 1666]);

        foreach (['Document Author', 'Authorised By', 'Authorised By (Client)'] as $role) {
            $row = $table->addRow(500);
            $row->addCell(2100, $vc)->addText($role, $vf);
            $row->addCell(2100, $vc)->addText($role === 'Document Author' ? '{{doc_author}}' : '', $vf);
            $row->addCell(1800, $vc)->addText('', $vf);
            $row->addCell(2200, $vc)->addText('', $vf);
            $row->addCell(1666, $vc)->addText('', $vf);
        }

        $section->addTextBreak(1);

        // ── UK Legislation table ──────────────────────────────────────────────
        $this->sectionHeading($section, 'Applicable UK Legislation & Regulations');
        $legislation = [
            ['Health & Safety at Work Act 1974',     'Management of H&S at Work Regs 1999'],
            ['Manual Handling Operations Regs 1992', 'Work at Height Regulations 2005'],
            ['PUWER 1998',                           'COSHH 2002'],
            ['Control of Noise at Work Regs 2005',   'PPE at Work Regulations 2022'],
            ['CDM Regulations 2015',                 'Electricity at Work Regulations 1989'],
            ['Control of Asbestos Regulations 2012', 'RIDDOR 2013'],
        ];

        $table = $section->addTable($this->tableStyle());
        $row   = $table->addRow(400);
        $hCell = $row->addCell(self::W_PORT, ['bgColor' => self::TEAL, 'gridSpan' => 2]);
        $hCell->addText('Applicable UK Legislation', $this->font(10, bold: true, colour: self::WHITE), ['alignment' => Jc::CENTER]);

        foreach ($legislation as $i => [$left, $right]) {
            $bg  = ($i % 2 === 0) ? self::WHITE : self::ROW_ALT;
            $row = $table->addRow(380);
            $row->addCell(4933, ['bgColor' => $bg])->addText($left,  $this->font(9));
            $row->addCell(4933, ['bgColor' => $bg])->addText($right, $this->font(9));
        }

        $section->addTextBreak(1);

        // ── Risk Rating matrix ────────────────────────────────────────────────
        $this->sectionHeading($section, 'Risk Rating System');
        $table = $section->addTable($this->tableStyle());
        $hRow  = $table->addRow(380);
        foreach (['Likelihood' => 2400, 'Score' => 700, 'Severity' => 2400, 'Score' => 700, 'Risk = Likelihood × Severity' => 3666] as $label => $w) {
            $hRow->addCell($w, ['bgColor' => self::TEAL])
                 ->addText($label, $this->font(9, bold: true, colour: self::WHITE), ['alignment' => Jc::CENTER]);
        }

        $matrix = [
            ['Highly Unlikely', '1', 'Trivial',            '1', 'No Action Required (1)',       'D4EDDA'],
            ['Unlikely',        '2', 'Minor Injury',        '2', 'Low Priority (2–6)',            'D4EDDA'],
            ['Possible',        '3', 'Over 3-Day Injury',   '3', 'Medium Priority (7–9)',         'FFF3CD'],
            ['Probable',        '4', 'Major Injury',        '4', 'High Priority (10–14)',         'FFD0A0'],
            ['Certain',         '5', 'Incapacity or Death', '5', 'Urgent Action Required (≥15)', 'FFDEDE'],
        ];
        foreach ($matrix as [$l, $ls, $s, $ss, $r, $rc]) {
            $row = $table->addRow(360);
            $row->addCell(2400, ['bgColor' => self::WHITE])->addText($l,  $this->font(9));
            $row->addCell(700,  ['bgColor' => self::WHITE])->addText($ls, $this->font(9, bold: true), ['alignment' => Jc::CENTER]);
            $row->addCell(2400, ['bgColor' => self::WHITE])->addText($s,  $this->font(9));
            $row->addCell(700,  ['bgColor' => self::WHITE])->addText($ss, $this->font(9, bold: true), ['alignment' => Jc::CENTER]);
            $row->addCell(3666, ['bgColor' => $rc])        ->addText($r,  $this->font(9, bold: true));
        }

        $this->save($phpWord, $dir . '/rams.docx');
        $this->line('  ✓ rams.docx');
    }

    // =========================================================================
    // O&M MANUAL TEMPLATE
    // =========================================================================

    private function buildOmManual(string $dir): void
    {
        $phpWord = $this->newDoc();
        $section = $phpWord->addSection($this->portraitStyle());

        $company = config('rams.company_name', 'Company Name');

        $section->addText($company,
            $this->font(24, bold: true, colour: self::TEAL),
            ['alignment' => Jc::LEFT]);

        $section->addText('OPERATION & MAINTENANCE MANUAL',
            $this->font(17, bold: true, colour: self::DARK_GREY),
            ['alignment' => Jc::LEFT, 'borderBottomSize' => 12, 'borderBottomColor' => self::TEAL, 'borderBottomSpace' => 4]);

        $section->addText('{{project_name}}  |  {{client_name}}',
            $this->font(11, colour: self::MID_GREY),
            ['alignment' => Jc::LEFT, 'spacing' => ['before' => 60, 'after' => 200]]);

        $this->sectionHeading($section, 'Document Details');
        $table = $section->addTable($this->tableStyle());
        $lf = $this->font(9, bold: true);
        $vf = $this->font(9);
        $lc = ['bgColor' => self::ROW_ALT];
        $vc = ['bgColor' => self::WHITE];

        foreach ([
            ['Project Reference', '{{project_ref}}'],
            ['Project Name',      '{{project_name}}'],
            ['Client',            '{{client_name}}'],
            ['Site Address',      '{{site_address}}'],
            ['Date',              '{{date}}'],
            ['Status',            '{{status}}'],
        ] as [$label, $value]) {
            $row = $table->addRow(400);
            $row->addCell(2800, $lc)->addText($label, $lf);
            $row->addCell(7066, $vc)->addText($value, $vf);
        }

        $section->addTextBreak(2);

        $this->sectionHeading($section, 'Equipment Schedule');
        $section->addText('{{equipment_table}}', $this->font(9, colour: self::MID_GREY));

        $this->save($phpWord, $dir . '/om-manual.docx');
        $this->line('  ✓ om-manual.docx');
    }

    // =========================================================================
    // SITE SURVEY TEMPLATE
    // =========================================================================

    private function buildSiteSurvey(string $dir): void
    {
        $phpWord = $this->newDoc();
        $section = $phpWord->addSection($this->portraitStyle());

        $company = config('rams.company_name', 'Company Name');

        $section->addText($company,
            $this->font(24, bold: true, colour: self::TEAL),
            ['alignment' => Jc::LEFT]);

        $section->addText('SITE SURVEY REPORT',
            $this->font(17, bold: true, colour: self::DARK_GREY),
            ['alignment' => Jc::LEFT, 'borderBottomSize' => 12, 'borderBottomColor' => self::TEAL, 'borderBottomSpace' => 4]);

        $lf = $this->font(9, bold: true);
        $vf = $this->font(9);
        $lc = ['bgColor' => self::ROW_ALT];
        $vc = ['bgColor' => self::WHITE];

        $this->sectionHeading($section, 'Survey Details');
        $table = $section->addTable($this->tableStyle());
        foreach ([
            ['Project Name',   '{{project_name}}'],
            ['Client',         '{{client_name}}'],
            ['Site Address',   '{{site_address}}'],
            ['Surveyor',       '{{surveyor_name}}'],
            ['Survey Date',    '{{survey_date}}'],
            ['General Notes',  '{{general_notes}}'],
        ] as [$label, $value]) {
            $row = $table->addRow(400);
            $row->addCell(2800, $lc)->addText($label, $lf);
            $row->addCell(7066, $vc)->addText($value, $vf);
        }

        $section->addTextBreak(1);

        $this->sectionHeading($section, 'Site Hazards');
        $section->addText('{{hazard_table}}', $this->font(9, colour: self::MID_GREY));

        $section->addTextBreak(1);

        $this->sectionHeading($section, 'Equipment / Infrastructure');
        $section->addText('{{equipment_table}}', $this->font(9, colour: self::MID_GREY));

        $this->save($phpWord, $dir . '/site-survey.docx');
        $this->line('  ✓ site-survey.docx');
    }

    // =========================================================================
    // HELPERS
    // =========================================================================

    private function newDoc(): PhpWord
    {
        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(10);
        return $phpWord;
    }

    private function save(PhpWord $phpWord, string $path): void
    {
        IOFactory::createWriter($phpWord, 'Word2007')->save($path);
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

    private function tealHeader(object $table, array $labels, array $widths): void
    {
        $row = $table->addRow(400);
        foreach ($labels as $i => $label) {
            $row->addCell($widths[$i], ['bgColor' => self::TEAL])
                ->addText($label, $this->font(9, bold: true, colour: self::WHITE));
        }
    }
}
