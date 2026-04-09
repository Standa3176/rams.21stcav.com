<?php

namespace App\Services;

use App\Models\RamsDocument;
use App\Services\DocumentTemplateService;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\TemplateProcessor;

class DocxBuilderService
{
    public function __construct(
        private readonly DocumentTemplateService $templates,
    ) {}

    // ─── Brand colours ────────────────────────────────────────────────────────
    private const TEAL        = '007B8A';
    private const DARK_GREY   = '333333';
    private const MID_GREY    = '666666';
    private const ROW_ALT     = 'F0FBFC';
    private const WHITE       = 'FFFFFF';
    private const RISK_GREEN  = 'D4EDDA';
    private const RISK_AMBER  = 'FFF3CD';
    private const RISK_ORANGE = 'FFD0A0';
    private const RISK_RED    = 'FFDEDE';

    // ─── Page geometry (twips: 1 cm ≈ 567 twips) ─────────────────────────────
    // Portrait A4 (11906 wide) minus 2 × 1.8 cm margins (1020 each) = 9866
    private const M_PORT  = 1020;
    private const W_PORT  = 9866;

    // Landscape A4 (16838 wide) minus 2 × 1.5 cm margins (850 each) = 15138
    private const M_LAND  = 850;
    private const W_LAND  = 15138;

    // ─── Landscape hazard-table column widths (sum = 15138) ──────────────────
    private const COL_HAZARD   = 2650;
    private const COL_CONSEQ   = 2920;
    private const COL_P        = 665;
    private const COL_S        = 665;
    private const COL_RISK     = 800;
    private const COL_CONTROLS = 5308;   // remainder after all other cols

    // =========================================================================
    // PUBLIC ENTRY POINT
    // =========================================================================

    public function build(array $data, RamsDocument $record): string
    {
        $storageDir = storage_path('app/rams');
        if (! is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $formData = $record->form_data ?? [];
        $project  = $data['project'] ?? [];

        if ($this->templates->exists('rams')) {
            // ── Template path: load branded cover, append programmatic sections ──
            $phpWord = $this->loadTemplate('rams', [
                'project_ref'          => $project['ref']               ?? ($formData['project_ref']          ?? '—'),
                'project_name'         => $project['name']              ?? ($formData['project_name']         ?? '—'),
                'client_name'          => $project['client']            ?? ($formData['client_name']          ?? '—'),
                'site_address'         => $project['site_address']      ?? ($formData['site_address']         ?? '—'),
                'contractor'           => config('rams.company_name'),
                'works_description'    => $project['works_description'] ?? ($formData['works_description']    ?? '—'),
                'start_date'           => $formData['start_date']          ?? 'TBC',
                'expected_duration'    => $formData['expected_duration']   ?? 'TBC',
                'document_status'      => 'For Review',
                'date'                 => now()->format('F Y'),
                'doc_author'           => $formData['doc_author']          ?? '',
                // Personnel — populated from reviewed_data['programme'] via mergeReviewedIntoFormData()
                'project_manager'      => $formData['project_manager']     ?? '',
                'lead_engineer'        => $formData['lead_engineer']       ?? '',
                'additional_engineers' => $formData['additional_engineers']?? '',
                'programmer'           => $formData['programmer']          ?? '',
                'site_contact'         => $formData['site_contact']        ?? '',
            ]);

            // Append dynamic tables to the last section of the loaded template
            $sections = $phpWord->getSections();
            $section  = end($sections);

            if (! empty($data['quote'])) {
                $this->buildQuoteSummary($section, $data['quote']);
                $section->addTextBreak(1);
            }

            $this->addTeamTable($section, $data['team'] ?? []);
            $this->addEmergencyTable($section, $formData);
            $this->addPpeTable($section, $data['ppe'] ?? []);
            $this->addPersonsTable($section, $data['persons_at_risk'] ?? []);
        } else {
            // ── Fallback: fully programmatic ─────────────────────────────────────
            $phpWord = new PhpWord();
            $phpWord->setDefaultFontName('Arial');
            $phpWord->setDefaultFontSize(10);
            $this->buildSection1($phpWord, $data, $formData, $record);
        }

        $this->buildSection2($phpWord, $data);
        $this->buildMethodStatement($phpWord, $data);
        $this->buildSection3($phpWord);

        // Write file
        $filename = 'rams_' . $record->id . '_' . now()->format('Ymd_His') . '.docx';
        $filePath = $storageDir . '/' . $filename;

        IOFactory::createWriter($phpWord, 'Word2007')->save($filePath);

        // Persist filename
        $record->filename = $filename;
        $record->save();

        return $filePath;
    }

    // =========================================================================
    // TEMPLATE LOADER
    // =========================================================================

    /**
     * Process a .docx template via TemplateProcessor, substitute {{placeholders}},
     * save to a temp file, and return a mutable PhpWord object for section appending.
     */
    private function loadTemplate(string $name, array $values): PhpWord
    {
        $processor = new TemplateProcessor($this->templates->path($name));
        $processor->setMacroOpeningChars('{{');
        $processor->setMacroClosingChars('}}');

        foreach ($values as $key => $value) {
            $processor->setValue((string) $key, htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8'));
        }

        $tmp = tempnam(sys_get_temp_dir(), 'rams_tpl_') . '.docx';
        $processor->saveAs($tmp);

        $phpWord = IOFactory::load($tmp);
        @unlink($tmp);

        return $phpWord;
    }

    // =========================================================================
    // SECTION 1 — Portrait cover / project info
    // =========================================================================

    private function buildSection1(
        PhpWord      $phpWord,
        array        $data,
        array        $formData,
        RamsDocument $record,
    ): void {
        $section = $phpWord->addSection($this->portraitStyle());
        $this->attachFooter($section);

        $project   = $data['project'] ?? [];
        $labelFont = $this->font(9, bold: true);
        $valueFont = $this->font(9);
        $labelCell = ['bgColor' => self::ROW_ALT];
        $valueCell = ['bgColor' => self::WHITE];

        // ── Title block (matching Southwark sample) ───────────────────────────
        $section->addText(
            config('rams.company_name'),
            $this->font(24, bold: true, colour: self::TEAL),
            ['alignment' => Jc::LEFT],
        );
        $section->addText(
            'RISK ASSESSMENT & METHOD STATEMENT',
            $this->font(17, bold: true, colour: self::DARK_GREY),
            [
                'alignment'         => Jc::LEFT,
                'borderBottomSize'  => 12,
                'borderBottomColor' => self::TEAL,
                'borderBottomSpace' => 4,
            ],
        );
        // Project subtitle line (name | client | scope)
        $subtitleParts = array_filter([
            $project['name']   ?? ($formData['project_name'] ?? null),
            $project['client'] ?? ($formData['client_name']  ?? null),
        ]);
        $section->addText(
            implode('  |  ', $subtitleParts),
            $this->font(11, colour: self::MID_GREY),
            ['alignment' => Jc::LEFT, 'spacing' => ['before' => 60, 'after' => 200]],
        );

        // ── Project details (2-col label / value) ─────────────────────────────
        $this->sectionHeading($section, 'Project Details');
        $table = $section->addTable($this->tableStyle());

        $detailRows = [
            ['Project Reference', $project['ref']               ?? ($formData['project_ref']       ?? '—')],
            ['Project Name',      $project['name']              ?? ($formData['project_name']      ?? '—')],
            ['Client',            $project['client']            ?? ($formData['client_name']       ?? '—')],
            ['Site Address',      $project['site_address']      ?? ($formData['site_address']      ?? '—')],
            ['Contractor',        config('rams.company_name')],
            ['Works Description', $project['works_description'] ?? ($formData['works_description'] ?? '—')],
            ['Start Date',        $formData['start_date']       ?? 'TBC'],
            ['Expected Duration', $formData['expected_duration']?? 'TBC'],
            ['Document Status',   'For Review'],
            ['Date',              now()->format('F Y')],
        ];

        foreach ($detailRows as [$label, $value]) {
            $row = $table->addRow(400);
            $row->addCell(2800, $labelCell)->addText($label,         $labelFont);
            $row->addCell(7066, $valueCell)->addText((string)$value, $valueFont);
        }

        $section->addTextBreak(1);

        // ── Quoted works summary (quote-upload sourced RAMS only) ─────────────
        if (! empty($data['quote'])) {
            $this->buildQuoteSummary($section, $data['quote']);
            $section->addTextBreak(1);
        }

        // ── Document authorisation table ──────────────────────────────────────
        $this->sectionHeading($section, 'Document Authorisation');
        $table = $section->addTable($this->tableStyle());
        $this->tealHeader($table, ['Role', 'Name', 'Title', 'Signature', 'Date'], [2100, 2100, 1800, 2200, 1666]);

        foreach (['Document Author', 'Authorised By', 'Authorised By (Client)'] as $role) {
            $row = $table->addRow(500);
            $row->addCell(2100, $valueCell)->addText($role, $valueFont);
            $row->addCell(2100, $valueCell)->addText(
                $role === 'Document Author' ? ($formData['doc_author'] ?? '') : '',
                $valueFont
            );
            $row->addCell(1800, $valueCell)->addText('', $valueFont);
            $row->addCell(2200, $valueCell)->addText('', $valueFont);
            $row->addCell(1666, $valueCell)->addText('', $valueFont);
        }

        $section->addTextBreak(1);

        // ── Engineering team table (only if team members provided) ────────────
        $team = $data['team'] ?? [];
        if (! empty($team)) {
            $this->sectionHeading($section, 'Engineering Team');
            $table = $section->addTable($this->tableStyle());
            $this->tealHeader($table, ['Role', 'Name', 'Mobile'], [3000, 3500, 3366]);

            foreach ($team as $member) {
                $row = $table->addRow(400);
                $row->addCell(3000, $valueCell)->addText((string)($member['role']   ?? ''), $valueFont);
                $row->addCell(3500, $valueCell)->addText((string)($member['name']   ?? ''), $valueFont);
                $row->addCell(3366, $valueCell)->addText((string)($member['mobile'] ?? ''), $valueFont);
            }

            $section->addTextBreak(1);
        }

        // ── Emergency contacts table ──────────────────────────────────────────
        $this->sectionHeading($section, 'Emergency Contacts');
        $table = $section->addTable($this->tableStyle());
        $this->tealHeader($table, ['Contact', 'Tel', 'Mobile', 'Role'], [2466, 2400, 2400, 2600]);

        $row = $table->addRow(500);
        $row->addCell(2466, $valueCell)->addText($formData['emergency_contact'] ?? '', $valueFont);
        $row->addCell(2400, $valueCell)->addText($formData['emergency_tel']     ?? '', $valueFont);
        $row->addCell(2400, $valueCell)->addText('', $valueFont);
        $row->addCell(2600, $valueCell)->addText('', $valueFont);

        $row = $table->addRow(500);
        $row->addCell(2466, $valueCell)->addText('', $valueFont);
        $row->addCell(2400, $valueCell)->addText('', $valueFont);
        $row->addCell(2400, $valueCell)->addText('', $valueFont);
        $row->addCell(2600, $valueCell)->addText('', $valueFont);

        $section->addTextBreak(1);

        // ── UK Legislation table (2-column grid) ──────────────────────────────
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

        // Spanning header
        $row   = $table->addRow(400);
        $hCell = $row->addCell(self::W_PORT, ['bgColor' => self::TEAL, 'gridSpan' => 2]);
        $hCell->addText(
            'Applicable UK Legislation',
            $this->font(10, bold: true, colour: self::WHITE),
            ['alignment' => Jc::CENTER],
        );

        foreach ($legislation as $i => [$left, $right]) {
            $bg  = ($i % 2 === 0) ? self::WHITE : self::ROW_ALT;
            $row = $table->addRow(380);
            $row->addCell(4933, ['bgColor' => $bg])->addText($left,  $this->font(9));
            $row->addCell(4933, ['bgColor' => $bg])->addText($right, $this->font(9));
        }

        $section->addTextBreak(1);

        // ── Risk rating system — Southwark 5-row L × S matrix ────────────────
        $this->sectionHeading($section, 'Risk Rating System');
        $table = $section->addTable($this->tableStyle());

        // Header row
        $hRow = $table->addRow(380);
        foreach (['Likelihood' => 2400, 'Score' => 700, 'Severity' => 2400, 'Score' => 700, 'Risk = Likelihood × Severity' => 3666] as $label => $w) {
            $hRow->addCell($w, ['bgColor' => self::TEAL])
                 ->addText($label, $this->font(9, bold: true, colour: self::WHITE), ['alignment' => Jc::CENTER]);
        }

        $riskMatrix = [
            ['Highly Unlikely', '1', 'Trivial',              '1', 'No Action Required (1)',      self::RISK_GREEN],
            ['Unlikely',        '2', 'Minor Injury',          '2', 'Low Priority (2–6)',           self::RISK_GREEN],
            ['Possible',        '3', 'Over 3-Day Injury',     '3', 'Medium Priority (7–9)',        self::RISK_AMBER],
            ['Probable',        '4', 'Major Injury',          '4', 'High Priority (10–14)',        self::RISK_ORANGE],
            ['Certain',         '5', 'Incapacity or Death',   '5', 'Urgent Action Required (≥15)', self::RISK_RED],
        ];

        foreach ($riskMatrix as [$likelihood, $lScore, $severity, $sScore, $riskLabel, $riskColour]) {
            $row = $table->addRow(360);
            $row->addCell(2400, ['bgColor' => self::WHITE]) ->addText($likelihood, $this->font(9));
            $row->addCell(700,  ['bgColor' => self::WHITE]) ->addText($lScore,     $this->font(9, bold: true), ['alignment' => Jc::CENTER]);
            $row->addCell(2400, ['bgColor' => self::WHITE]) ->addText($severity,   $this->font(9));
            $row->addCell(700,  ['bgColor' => self::WHITE]) ->addText($sScore,     $this->font(9, bold: true), ['alignment' => Jc::CENTER]);
            $row->addCell(3666, ['bgColor' => $riskColour]) ->addText($riskLabel,  $this->font(9, bold: true));
        }

        $section->addTextBreak(1);

        // ── PPE table ─────────────────────────────────────────────────────────
        // Cap at 5 columns per row so text isn't crushed when there are many items.
        // Overflow items wrap to additional rows; short final rows are padded with
        // empty cells to keep the Word table column count consistent.
        $ppeItems = $data['ppe'] ?? [];
        if (! empty($ppeItems)) {
            $colsPerRow = min(count($ppeItems), 5);
            $colWidth   = (int) round(self::W_PORT / $colsPerRow);
            $chunks     = array_chunk($ppeItems, $colsPerRow);

            $table = $section->addTable($this->tableStyle());

            $row   = $table->addRow(400);
            $hCell = $row->addCell(self::W_PORT, ['bgColor' => self::TEAL, 'gridSpan' => $colsPerRow]);
            $hCell->addText(
                'PPE Required for this Project',
                $this->font(10, bold: true, colour: self::WHITE),
                ['alignment' => Jc::CENTER],
            );

            foreach ($chunks as $chunk) {
                $row = $table->addRow(500);
                foreach ($chunk as $item) {
                    $row->addCell($colWidth, ['bgColor' => self::ROW_ALT])
                        ->addText($item, $this->font(8), ['alignment' => Jc::CENTER]);
                }
                // Pad the last (possibly short) row so column count stays consistent
                for ($i = count($chunk); $i < $colsPerRow; $i++) {
                    $row->addCell($colWidth, ['bgColor' => self::ROW_ALT])->addText('');
                }
            }

            $section->addTextBreak(1);
        }

        // ── Persons at risk table ─────────────────────────────────────────────
        $persons = $data['persons_at_risk'] ?? [];
        if (! empty($persons)) {
            $colsPerRow = min(count($persons), 5);
            $colWidth   = (int) round(self::W_PORT / $colsPerRow);
            $chunks     = array_chunk($persons, $colsPerRow);

            $table = $section->addTable($this->tableStyle());

            $row   = $table->addRow(400);
            $hCell = $row->addCell(self::W_PORT, ['bgColor' => self::TEAL, 'gridSpan' => $colsPerRow]);
            $hCell->addText(
                'Persons at Risk',
                $this->font(10, bold: true, colour: self::WHITE),
                ['alignment' => Jc::CENTER],
            );

            foreach ($chunks as $chunk) {
                $row = $table->addRow(500);
                foreach ($chunk as $person) {
                    $row->addCell($colWidth, ['bgColor' => self::ROW_ALT])
                        ->addText('✓  ' . $person, $this->font(9), ['alignment' => Jc::CENTER]);
                }
                for ($i = count($chunk); $i < $colsPerRow; $i++) {
                    $row->addCell($colWidth, ['bgColor' => self::ROW_ALT])->addText('');
                }
            }
        }
    }

    // =========================================================================
    // EXTRACTED TABLE HELPERS — used by both template and programmatic paths
    // =========================================================================

    private function addTeamTable(Section $section, array $team): void
    {
        if (empty($team)) {
            return;
        }
        $vc = ['bgColor' => self::WHITE];
        $vf = $this->font(9);
        $this->sectionHeading($section, 'Engineering Team');
        $table = $section->addTable($this->tableStyle());
        $this->tealHeader($table, ['Role', 'Name', 'Mobile'], [3000, 3500, 3366]);
        foreach ($team as $member) {
            $row = $table->addRow(400);
            $row->addCell(3000, $vc)->addText((string)($member['role']   ?? ''), $vf);
            $row->addCell(3500, $vc)->addText((string)($member['name']   ?? ''), $vf);
            $row->addCell(3366, $vc)->addText((string)($member['mobile'] ?? ''), $vf);
        }
        $section->addTextBreak(1);
    }

    private function addEmergencyTable(Section $section, array $formData): void
    {
        $vc = ['bgColor' => self::WHITE];
        $vf = $this->font(9);
        $this->sectionHeading($section, 'Emergency Contacts');
        $table = $section->addTable($this->tableStyle());
        $this->tealHeader($table, ['Contact', 'Tel', 'Mobile', 'Role'], [2466, 2400, 2400, 2600]);
        $row = $table->addRow(500);
        $row->addCell(2466, $vc)->addText($formData['emergency_contact'] ?? '', $vf);
        $row->addCell(2400, $vc)->addText($formData['emergency_tel']     ?? '', $vf);
        $row->addCell(2400, $vc)->addText('', $vf);
        $row->addCell(2600, $vc)->addText('', $vf);
        $row = $table->addRow(500);
        $row->addCell(2466, $vc)->addText('', $vf);
        $row->addCell(2400, $vc)->addText('', $vf);
        $row->addCell(2400, $vc)->addText('', $vf);
        $row->addCell(2600, $vc)->addText('', $vf);
        $section->addTextBreak(1);
    }

    private function addPpeTable(Section $section, array $ppeItems): void
    {
        if (empty($ppeItems)) {
            return;
        }
        $colsPerRow = min(count($ppeItems), 5);
        $colWidth   = (int) round(self::W_PORT / $colsPerRow);
        $chunks     = array_chunk($ppeItems, $colsPerRow);
        $table      = $section->addTable($this->tableStyle());
        $row        = $table->addRow(400);
        $hCell      = $row->addCell(self::W_PORT, ['bgColor' => self::TEAL, 'gridSpan' => $colsPerRow]);
        $hCell->addText('PPE Required for this Project', $this->font(10, bold: true, colour: self::WHITE), ['alignment' => Jc::CENTER]);
        foreach ($chunks as $chunk) {
            $row = $table->addRow(500);
            foreach ($chunk as $item) {
                $row->addCell($colWidth, ['bgColor' => self::ROW_ALT])
                    ->addText($item, $this->font(8), ['alignment' => Jc::CENTER]);
            }
            for ($i = count($chunk); $i < $colsPerRow; $i++) {
                $row->addCell($colWidth, ['bgColor' => self::ROW_ALT])->addText('');
            }
        }
        $section->addTextBreak(1);
    }

    private function addPersonsTable(Section $section, array $persons): void
    {
        if (empty($persons)) {
            return;
        }
        $colsPerRow = min(count($persons), 5);
        $colWidth   = (int) round(self::W_PORT / $colsPerRow);
        $chunks     = array_chunk($persons, $colsPerRow);
        $table      = $section->addTable($this->tableStyle());
        $row        = $table->addRow(400);
        $hCell      = $row->addCell(self::W_PORT, ['bgColor' => self::TEAL, 'gridSpan' => $colsPerRow]);
        $hCell->addText('Persons at Risk', $this->font(10, bold: true, colour: self::WHITE), ['alignment' => Jc::CENTER]);
        foreach ($chunks as $chunk) {
            $row = $table->addRow(500);
            foreach ($chunk as $person) {
                $row->addCell($colWidth, ['bgColor' => self::ROW_ALT])
                    ->addText('✓  ' . $person, $this->font(9), ['alignment' => Jc::CENTER]);
            }
            for ($i = count($chunk); $i < $colsPerRow; $i++) {
                $row->addCell($colWidth, ['bgColor' => self::ROW_ALT])->addText('');
            }
        }
    }

    // =========================================================================
    // QUOTE SUMMARY — rendered inside Section 1 when source is a PDF upload
    // =========================================================================

    /**
     * Render two tables into Section 1 when a QuoteWerks PDF was the source:
     *   1. Line items  — SKU | Qty | Description
     *   2. Room summaries — Room / Area | Solution Summary
     *
     * This section is skipped entirely for manually-created RAMS documents
     * (i.e. when $data['quote'] is absent).
     */
    private function buildQuoteSummary(Section $section, array $quote): void
    {
        $valueFont  = $this->font(9);
        $headerFont = $this->font(9, bold: true, colour: self::WHITE);
        $valueCell  = ['bgColor' => self::WHITE];
        $altCell    = ['bgColor' => self::ROW_ALT];

        // ── Section heading ───────────────────────────────────────────────────
        $ref   = $quote['qw_number'] ?? $quote['quote_ref'] ?? '';
        $qwRef = $ref !== '' ? '  —  ' . $ref : '';
        $row   = null;

        $table = $section->addTable($this->tableStyle());
        $hRow  = $table->addRow(420);
        $hCell = $hRow->addCell(self::W_PORT, ['bgColor' => self::TEAL]);
        $hCell->addText(
            'Quoted Works Summary' . $qwRef,
            $this->font(10, bold: true, colour: self::WHITE),
            ['alignment' => Jc::LEFT],
        );

        // ── Hardware list (grouped by room) ──────────────────────────────────
        $hardwareByRoom = $quote['hardware_by_room'] ?? [];
        $lineItems      = $quote['line_items'] ?? [];

        if (! empty($hardwareByRoom)) {
            foreach ($hardwareByRoom as $group) {
                $room  = (string) ($group['room'] ?? 'General');
                $items = $group['items'] ?? [];
                if (empty($items)) {
                    continue;
                }

                $section->addText($room, $this->font(9, bold: true, colour: self::DARK_GREY));

                $table = $section->addTable($this->tableStyle());
                $hRow  = $table->addRow(380);
                $hRow->addCell(600,  ['bgColor' => self::DARK_GREY])
                     ->addText('Qty', $headerFont, ['alignment' => Jc::CENTER]);
                $hRow->addCell(9266, ['bgColor' => self::DARK_GREY])
                     ->addText('Hardware Item', $headerFont);

                foreach ($items as $i => $item) {
                    $bg  = ($i % 2 === 0) ? $valueCell : $altCell;
                    $row = $table->addRow(380);
                    $row->addCell(600,  $bg)->addText((string)($item['qty'] ?? ''), $valueFont, ['alignment' => Jc::CENTER]);
                    $row->addCell(9266, $bg)->addText($this->t((string)($item['description'] ?? '')), $valueFont);
                }

                $section->addTextBreak(1);
            }
        } elseif (! empty($lineItems)) {
            // Fallback: ungrouped hardware list
            $table = $section->addTable($this->tableStyle());
            $hRow  = $table->addRow(380);
            $hRow->addCell(600,  ['bgColor' => self::DARK_GREY])
                 ->addText('Qty', $headerFont, ['alignment' => Jc::CENTER]);
            $hRow->addCell(9266, ['bgColor' => self::DARK_GREY])
                 ->addText('Hardware Item', $headerFont);

            foreach ($lineItems as $i => $item) {
                $bg  = ($i % 2 === 0) ? $valueCell : $altCell;
                $row = $table->addRow(380);
                $row->addCell(600,  $bg)->addText((string)($item['qty'] ?? ''), $valueFont, ['alignment' => Jc::CENTER]);
                $row->addCell(9266, $bg)->addText($this->t((string)($item['description'] ?? '')), $valueFont);
            }

            $section->addTextBreak(1);
        }

        // ── Room / solution summaries table ───────────────────────────────────
        $roomSummaries = $quote['room_summaries'] ?? [];
        if (! empty($roomSummaries)) {
            // Column widths: Room: 2800 | Summary: 7066
            $table = $section->addTable($this->tableStyle());

            $hRow = $table->addRow(380);
            $hRow->addCell(2800, ['bgColor' => self::DARK_GREY])
                 ->addText('Room / Area', $headerFont);
            $hRow->addCell(7066, ['bgColor' => self::DARK_GREY])
                 ->addText('AV Solution Summary', $headerFont);

            foreach ($roomSummaries as $i => $entry) {
                $bg  = ($i % 2 === 0) ? $valueCell : $altCell;
                $row = $table->addRow(400);
                $row->addCell(2800, $bg)->addText((string)($entry['room']    ?? ''), $valueFont);
                $row->addCell(7066, $bg)->addText((string)($entry['summary'] ?? ''), $valueFont);
            }
        }
    }

    // =========================================================================
    // SECTION 2 — Landscape hazard register
    // =========================================================================

    private function buildSection2(PhpWord $phpWord, array $data): void
    {
        $section = $phpWord->addSection($this->landscapeStyle() + ['breakType' => 'nextPage']);
        $this->attachFooter($section);

        // Page header
        $projectName = $data['project']['name'] ?? 'RAMS Document';
        $hdr         = $section->addHeader();
        $hdr->addText(
            'RISK ASSESSMENT  |  ' . strtoupper($projectName),
            $this->font(11, bold: true, colour: self::TEAL),
            [
                'alignment'         => Jc::LEFT,
                'borderBottomSize'  => 8,
                'borderBottomColor' => self::TEAL,
                'borderBottomSpace' => 3,
            ],
        );

        // Column widths (sum = W_LAND = 15138)
        $wH  = self::COL_HAZARD;    // 2650
        $wC  = self::COL_CONSEQ;    // 2920
        $wP  = self::COL_P;         // 665
        $wS  = self::COL_S;         // 665
        $wR  = self::COL_RISK;      // 800
        $wCt = self::COL_CONTROLS;  // 5308

        $teal      = ['bgColor' => self::TEAL];
        $whiteFont = $this->font(9, bold: true, colour: self::WHITE);
        $bodyFont  = $this->font(8);
        $boldFont  = $this->font(8, bold: true);
        $centred   = ['alignment' => Jc::CENTER];

        $table = $section->addTable($this->tableStyle());

        // ── Single header row (safe — no vMerge/gridSpan combo) ──────────────
        // Using combined "Pre L/S/Risk" labels avoids the multi-row merge that
        // produces malformed OOXML and causes Word to reject the file.
        $hRow = $table->addRow(420);
        $hRow->addCell($wH,  $teal)->addText('Hazard',             $whiteFont);
        $hRow->addCell($wC,  $teal)->addText('Persons at Risk',    $whiteFont);
        $hRow->addCell($wP,  $teal)->addText('L',                  $whiteFont, $centred);
        $hRow->addCell($wS,  $teal)->addText('S',                  $whiteFont, $centred);
        $hRow->addCell($wR,  $teal)->addText('Risk',               $whiteFont, $centred);
        $hRow->addCell($wCt, $teal)->addText('Control Measures',   $whiteFont);
        $hRow->addCell($wP,  $teal)->addText('L',                  $whiteFont, $centred);
        $hRow->addCell($wS,  $teal)->addText('S',                  $whiteFont, $centred);
        $hRow->addCell($wR,  $teal)->addText('Risk',               $whiteFont, $centred);

        // ── Data rows — one per hazard ─────────────────────────────────────────
        foreach ($data['hazards'] ?? [] as $idx => $hazard) {
            $rowBg    = ($idx % 2 === 0) ? self::WHITE : self::ROW_ALT;
            $preScore = (int)($hazard['pre_likelihood']  ?? 1) * (int)($hazard['pre_severity']  ?? 1);
            $postScore= (int)($hazard['post_likelihood'] ?? 1) * (int)($hazard['post_severity'] ?? 1);

            $dr = $table->addRow(0);  // auto height

            // Hazard name (bold)
            $dr->addCell($wH, ['bgColor' => $rowBg, 'valign' => 'top'])
               ->addText($this->t((string)($hazard['hazard'] ?? '')), $boldFont);

            // Persons at risk (replaces 'consequences' — not present in normalised data)
            $personsCell = $dr->addCell($wC, ['bgColor' => $rowBg, 'valign' => 'top']);
            $persons = $hazard['persons_at_risk'] ?? [];
            if (is_array($persons) && ! empty($persons)) {
                foreach ($persons as $person) {
                    $personsCell->addText('•  ' . (string)$person, $bodyFont);
                }
            } else {
                $personsCell->addText('', $bodyFont);
            }

            // Pre L / S / Risk
            $dr->addCell($wP, ['bgColor' => $rowBg, 'valign' => 'top'])
               ->addText((string)($hazard['pre_likelihood'] ?? ''), $bodyFont, $centred);
            $dr->addCell($wS, ['bgColor' => $rowBg, 'valign' => 'top'])
               ->addText((string)($hazard['pre_severity']   ?? ''), $bodyFont, $centred);
            $dr->addCell($wR, ['bgColor' => $this->riskColour($preScore), 'valign' => 'top'])
               ->addText((string)$preScore, $boldFont, $centred);

            // Control measures — numbered list
            $ctrlCell = $dr->addCell($wCt, ['bgColor' => $rowBg, 'valign' => 'top']);
            foreach ($hazard['controls'] ?? [] as $j => $ctrl) {
                $ctrlCell->addText(($j + 1) . '.  ' . $this->t((string)$ctrl), $bodyFont);
            }

            // Post L / S / Risk
            $dr->addCell($wP, ['bgColor' => $rowBg, 'valign' => 'top'])
               ->addText((string)($hazard['post_likelihood'] ?? ''), $bodyFont, $centred);
            $dr->addCell($wS, ['bgColor' => $rowBg, 'valign' => 'top'])
               ->addText((string)($hazard['post_severity']   ?? ''), $bodyFont, $centred);
            $dr->addCell($wR, ['bgColor' => $this->riskColour($postScore), 'valign' => 'top'])
               ->addText((string)$postScore, $boldFont, $centred);
        }
    }

    // =========================================================================
    // METHOD STATEMENT — Portrait numbered steps
    // =========================================================================

    private function buildMethodStatement(PhpWord $phpWord, array $data): void
    {
        $phases = $data['method_statement']['phases'] ?? [];
        if (empty($phases)) {
            return;
        }

        $section    = $phpWord->addSection($this->portraitStyle() + ['breakType' => 'nextPage']);
        $this->attachFooter($section);

        $projectName = $data['project']['name'] ?? 'RAMS Document';
        $hdr         = $section->addHeader();
        $hdr->addText(
            'METHOD STATEMENT  |  ' . strtoupper($projectName),
            $this->font(11, bold: true, colour: self::TEAL),
            [
                'alignment'         => Jc::LEFT,
                'borderBottomSize'  => 8,
                'borderBottomColor' => self::TEAL,
                'borderBottomSpace' => 3,
            ],
        );

        $this->sectionHeading($section, 'Method Statement — Sequence of Works');

        foreach ($phases as $i => $phase) {
            $section->addText(
                ($i + 1) . '.  ' . $this->t((string) ($phase['title'] ?? '')),
                $this->font(10, bold: true, colour: self::TEAL),
                ['spacing' => ['before' => 80, 'after' => 80]],
            );

            foreach (($phase['steps'] ?? []) as $step) {
                $section->addText(
                    '    •  ' . $this->t((string) $step),
                    $this->font(9),
                    ['spacing' => ['before' => 40, 'after' => 40]],
                );
            }
        }
    }

    // =========================================================================
    // SECTION 3 — Portrait operative sign-off
    // =========================================================================

    private function buildSection3(PhpWord $phpWord): void
    {
        $section   = $phpWord->addSection($this->portraitStyle());
        $this->attachFooter($section);

        $valueFont = $this->font(9);
        $valueCell = ['bgColor' => self::WHITE];
        $altCell   = ['bgColor' => self::ROW_ALT];

        // ── Operative sign-off ────────────────────────────────────────────────
        $table = $section->addTable($this->tableStyle());

        // Spanning header
        $row   = $table->addRow(400);
        $hCell = $row->addCell(self::W_PORT, ['bgColor' => self::TEAL, 'gridSpan' => 3]);
        $hCell->addText(
            'Operative Sign-Off',
            $this->font(11, bold: true, colour: self::WHITE),
            ['alignment' => Jc::CENTER],
        );

        // Instruction
        $row   = $table->addRow(500);
        $iCell = $row->addCell(self::W_PORT, array_merge($valueCell, ['gridSpan' => 3]));
        $iCell->addText(
            'I have read and understood this Risk Assessment and Method Statement '
            . 'and agree to comply with its requirements.',
            $this->font(9, italic: true, colour: self::MID_GREY),
        );

        // Column headers
        $row = $table->addRow(380);
        $row->addCell(3500, $altCell)->addText('Print Name', $this->font(9, bold: true));
        $row->addCell(3500, $altCell)->addText('Signature',  $this->font(9, bold: true));
        $row->addCell(2866, $altCell)->addText('Date',       $this->font(9, bold: true));

        // Six blank rows for operatives
        for ($i = 0; $i < 6; $i++) {
            $row = $table->addRow(600);
            $row->addCell(3500, $valueCell)->addText('', $valueFont);
            $row->addCell(3500, $valueCell)->addText('', $valueFont);
            $row->addCell(2866, $valueCell)->addText('', $valueFont);
        }

        $section->addTextBreak(2);

        // ── Document control ──────────────────────────────────────────────────
        $table = $section->addTable($this->tableStyle());

        $row   = $table->addRow(400);
        $hCell = $row->addCell(self::W_PORT, ['bgColor' => self::TEAL, 'gridSpan' => 5]);
        $hCell->addText(
            'Document Control',
            $this->font(10, bold: true, colour: self::WHITE),
            ['alignment' => Jc::CENTER],
        );

        // Column headers
        $dcHeaders = ['Rev' => 900, 'Date' => 2200, 'Prepared By' => 2400, 'Checked By' => 2200, 'Description' => 2166];
        $row = $table->addRow(360);
        foreach ($dcHeaders as $label => $w) {
            $row->addCell($w, $altCell)->addText($label, $this->font(9, bold: true));
        }

        // Rev 01 row
        $row = $table->addRow(400);
        $row->addCell(900,  $valueCell)->addText('01',                   $valueFont);
        $row->addCell(2200, $valueCell)->addText(now()->format('d/m/Y'), $valueFont);
        $row->addCell(2400, $valueCell)->addText(config('rams.company_name'),  $valueFont);
        $row->addCell(2200, $valueCell)->addText('—',                    $valueFont);
        $row->addCell(2166, $valueCell)->addText('Initial Issue',        $valueFont);
    }

    // =========================================================================
    // PRIVATE HELPERS
    // =========================================================================

    /** Attach a '{company_name} | Page X' footer to a section. */
    private function attachFooter(Section $section): void
    {
        $section->addFooter()->addPreserveText(
            config('rams.company_name') . '  |  Page {PAGE}',
            $this->font(8, colour: self::MID_GREY),
            ['alignment' => Jc::CENTER],
        );
    }

    /**
     * Render a bold teal section heading with a teal bottom border.
     * Matches the Southwark sample heading style.
     */
    private function sectionHeading(Section $section, string $text): void
    {
        $section->addText(
            strtoupper($text),
            $this->font(11, bold: true, colour: self::TEAL),
            [
                'alignment'         => Jc::LEFT,
                'borderBottomSize'  => 8,
                'borderBottomColor' => self::TEAL,
                'borderBottomSpace' => 3,
                'spacing'           => ['before' => 120, 'after' => 80],
            ],
        );
    }

    /** Add a single TEAL header row to a table. */
    private function tealHeader(Table $table, array $labels, array $widths): void
    {
        $row = $table->addRow(380);
        foreach ($labels as $i => $label) {
            $row->addCell($widths[$i], ['bgColor' => self::TEAL])
                ->addText(
                    $label,
                    $this->font(9, bold: true, colour: self::WHITE),
                    ['alignment' => Jc::CENTER],
                );
        }
    }

    /**
     * Strip XML 1.0 control characters that cause PhpWord / libxml to throw.
     * Keeps tabs (0x09), newlines (0x0A) and carriage returns (0x0D).
     */
    private function t(string $text): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    }

    /** Return the risk-score background colour. */
    private function riskColour(int $score): string
    {
        return match (true) {
            $score <= 6  => self::RISK_GREEN,
            $score <= 9  => self::RISK_AMBER,
            $score <= 14 => self::RISK_ORANGE,
            default      => self::RISK_RED,
        };
    }

    /** Build a font style array. */
    private function font(
        int     $size    = 10,
        bool    $bold    = false,
        bool    $italic  = false,
        string  $colour  = self::DARK_GREY,
    ): array {
        return array_filter([
            'name'   => 'Arial',
            'size'   => $size,
            'bold'   => $bold   ?: null,
            'italic' => $italic ?: null,
            'color'  => $colour,
        ]);
    }

    /** Standard thin-border table style. */
    private function tableStyle(): array
    {
        return [
            'borderSize'       => 4,
            'borderColor'      => 'CCCCCC',
            'cellMarginLeft'   => 80,
            'cellMarginRight'  => 80,
            'cellMarginTop'    => 60,
            'cellMarginBottom' => 60,
        ];
    }

    /** Portrait A4 section style with 1.8 cm margins. */
    private function portraitStyle(): array
    {
        return [
            'orientation'  => 'portrait',
            'marginTop'    => self::M_PORT,
            'marginBottom' => self::M_PORT,
            'marginLeft'   => self::M_PORT,
            'marginRight'  => self::M_PORT,
            'headerHeight' => 500,
            'footerHeight' => 400,
        ];
    }

    /** Landscape A4 section style with 1.5 cm margins. */
    private function landscapeStyle(): array
    {
        return [
            'orientation'  => 'landscape',
            'marginTop'    => self::M_LAND,
            'marginBottom' => self::M_LAND,
            'marginLeft'   => self::M_LAND,
            'marginRight'  => self::M_LAND,
            'headerHeight' => 500,
            'footerHeight' => 400,
        ];
    }
}
