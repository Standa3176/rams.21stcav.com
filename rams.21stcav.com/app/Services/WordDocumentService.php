<?php

namespace App\Services;

use App\Models\RamsDocument;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Generates a branded RAMS DOCX that matches the 21st Century AV sample document.
 *
 * Document structure (all portrait A4):
 *   Cover page      — title, subtitle, project info table
 *   §1 Scope        — works description, working hours note
 *   §2 Company      — company details, engineering team
 *   §3 Legislation  — bullet list of applicable regulations
 *   §4 Risk Assess. — risk rating key + 9-column hazard register
 *   §5 Method Stmt. — AI-generated phases with step bullet lists
 *   §6 PPE          — 3-column PPE table
 *   §7 Emergency    — first aid, fire, incident reporting subsections
 *   §8 Sign-off     — acknowledgement table + document control
 *
 * All content is built in a single PhpWord section (portrait A4) to avoid
 * header/footer repetition across section breaks. Page breaks are inserted
 * between major sections using addPageBreak().
 */
class WordDocumentService
{
    // ── Brand colours ─────────────────────────────────────────────────────────
    private const TEAL       = '00788A';
    private const GOLD       = 'C9A84C';
    private const LABEL_BG   = 'E8F4F6';
    private const MID_GREY   = '666666';
    private const DARK_GREY  = '333333';
    private const WHITE      = 'FFFFFF';
    private const ROW_ALT    = 'F0FBFC';

    // ── Risk band colours ─────────────────────────────────────────────────────
    private const RISK_GREEN  = 'D4EDDA';
    private const RISK_AMBER  = 'FFF3CD';
    private const RISK_ORANGE = 'FFD0A0';
    private const RISK_RED    = 'FFDEDE';

    // ── Page geometry (twips: 1 cm ≈ 567 twips) ──────────────────────────────
    // A4 portrait (11906 wide) minus 2 × 1.8 cm margins (1020 each) = 9866
    private const M_PORT  = 1020;
    private const W_PAGE  = 9866;

    // ── Hazard table column widths (must sum to W_PAGE = 9866) ───────────────
    // Hazard(1900) + Who(850) + PreL(540) + PreS(540) + PreRisk(680)
    // + Controls(3596) + PostL(540) + PostS(540) + PostRisk(680) = 9866
    private const HT_HAZARD    = 1900;
    private const HT_WHO       = 850;
    private const HT_PRE_L     = 540;
    private const HT_PRE_S     = 540;
    private const HT_PRE_RISK  = 680;
    private const HT_CONTROLS  = 3596;
    private const HT_POST_L    = 540;
    private const HT_POST_S    = 540;
    private const HT_POST_RISK = 680;

    // =========================================================================
    // PUBLIC ENTRY POINT
    // =========================================================================

    /**
     * Build and save a RAMS DOCX, update $record->filename, return the file path.
     *
     * @param  array        $data    Assembled RAMS data from RamsBuilderService
     * @param  RamsDocument $record  Persisted record (id used for filename)
     * @return string                Absolute path to the saved DOCX
     */
    public function build(array $data, RamsDocument $record): string
    {
        $storageDir = storage_path('app/rams');
        if (! is_dir($storageDir)) {
            mkdir($storageDir, 0755, true);
        }

        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(10);

        // Single portrait section — header/footer applies to all pages
        $section = $phpWord->addSection($this->portraitStyle());
        $this->attachHeader($section, $data['project'] ?? []);
        $this->attachFooter($section);

        // ── Build all document sections ───────────────────────────────────────
        $this->buildCoverPage($section, $data, $record);

        $section->addPageBreak();
        $this->buildScopeSection($section, $data);

        $section->addPageBreak();
        $this->buildCompanySection($section, $data);

        $section->addPageBreak();
        $this->buildLegislationSection($section);

        $section->addPageBreak();
        $this->buildRiskAssessmentSection($section, $data);

        $section->addPageBreak();
        $this->buildMethodStatementSection($section, $data);

        $section->addPageBreak();
        $this->buildPpeSection($section, $data);

        $section->addPageBreak();
        $this->buildEmergencySection($section);

        $section->addPageBreak();
        $this->buildSignOffSection($section, $data);

        // ── Save and persist filename ─────────────────────────────────────────
        $filename = 'rams_' . $record->id . '_' . now()->format('Ymd') . '.docx';
        $filePath = $storageDir . '/' . $filename;

        IOFactory::createWriter($phpWord, 'Word2007')->save($filePath);

        $record->filename = $filename;
        $record->save();

        return $filePath;
    }

    // =========================================================================
    // COVER PAGE
    // =========================================================================

    private function buildCoverPage(Section $section, array $data, RamsDocument $record): void
    {
        $project = $data['project'] ?? [];
        $company = config('rams.company_name', '21st Century AV Ltd');

        // Main title
        $section->addText(
            'RISK ASSESSMENT & METHOD STATEMENT',
            $this->font(22, bold: true, colour: self::TEAL),
            ['alignment' => Jc::LEFT, 'spacing' => ['before' => 0, 'after' => 100]],
        );

        // Subtitle line with gold bottom border
        $subtitle = implode('  |  ', array_filter([
            $project['name']   ?? '',
            $project['client'] ?? '',
        ]));
        $section->addText(
            $subtitle ?: 'Risk Assessment & Method Statement',
            $this->font(11, colour: self::DARK_GREY),
            [
                'alignment'         => Jc::LEFT,
                'borderBottomSize'  => 12,
                'borderBottomColor' => self::GOLD,
                'borderBottomSpace' => 4,
                'spacing'           => ['after' => 200],
            ],
        );

        $section->addTextBreak(1);

        // Project information table
        $formData   = $record->form_data ?? [];
        $opsContact = implode('  |  ', array_filter([
            config('rams.company_email', 'operations@21stcenturyav.com'),
            config('rams.company_tel',   '0118 977 771'),
        ]));

        $table = $section->addTable($this->tableStyle());
        $infoRows = [
            ['Project Reference',  $project['ref']          ?? ''],
            ['Client',             $project['client']       ?? ''],
            ['Site Address',       $project['site_address'] ?? ''],
            ['Site Contact',       $formData['site_contact'] ?? ($formData['client_contact'] ?? '')],
            ['Prepared by',        $company],
            ['Operations Contact', $opsContact],
            ['Date of Issue',      $project['date'] ?? now()->format('F Y')],
            ['Document Version',   'v1.0'],
            ['Review Date',        'Prior to each site visit / phase commencement'],
        ];

        foreach ($infoRows as [$label, $value]) {
            $row = $table->addRow(420);
            $row->addCell(2800, ['bgColor' => self::LABEL_BG])
                ->addText($label, $this->font(9, bold: true));
            $row->addCell(7066, ['bgColor' => self::WHITE])
                ->addText((string) $value, $this->font(9));
        }

        // Optional: equipment summary from quote PDF
        if (! empty($data['quote']['line_items'])) {
            $section->addTextBreak(1);
            $this->buildQuoteSummary($section, $data['quote']);
        }
    }

    // =========================================================================
    // §1 SCOPE OF WORKS
    // =========================================================================

    private function buildScopeSection(Section $section, array $data): void
    {
        $project = $data['project'] ?? [];

        $this->sectionHeading($section, '1. Scope of Works');

        $scope = $project['works_description'] ?? 'AV installation works as specified in the project quotation.';
        $section->addText(
            $scope,
            $this->font(10),
            ['spacing' => ['after' => 120]],
        );

        $section->addText(
            'Working Hours: Monday to Friday, 07:00–18:00, unless otherwise agreed in writing with '
            . 'the client. Any out-of-hours working will be subject to a separate method statement addendum.',
            $this->font(9, italic: true, colour: self::MID_GREY),
            ['spacing' => ['before' => 80, 'after' => 80]],
        );
    }

    // =========================================================================
    // §2 COMPANY & KEY PERSONNEL
    // =========================================================================

    private function buildCompanySection(Section $section, array $data): void
    {
        $project = $data['project'] ?? [];
        $team    = $data['team']    ?? [];
        $company = config('rams.company_name',    '21st Century AV Ltd');
        $address = config('rams.company_address', 'Thames Court, 2 Richfield Ave, Reading, RG1 8EQ');
        $tel     = config('rams.company_tel',     '0118 977 771');

        $this->sectionHeading($section, '2. Company & Key Personnel');

        // Find lead engineer and supervisor from team
        $leadEngineer = 'To be confirmed prior to works';
        $supervisor   = 'To be confirmed prior to works';
        foreach ($team as $m) {
            $role = strtolower($m['role'] ?? '');
            if (str_contains($role, 'lead') || $role === 'engineer') {
                $leadEngineer = $m['name'] ?? $leadEngineer;
            }
            if (str_contains($role, 'supervisor') || str_contains($role, 'manager')) {
                $supervisor = $m['name'] ?? $supervisor;
            }
        }

        $opsContact = implode('  |  ', array_filter([$tel, config('rams.company_email', 'operations@21stcenturyav.com')]));

        $table = $section->addTable($this->tableStyle());
        foreach ([
            ['Principal Contractor', $company],
            ['Registered Address',   $address],
            ['Company Reg No.',      config('rams.company_reg', 'Available on request')],
            ['H&S Accreditation',    config('rams.hs_accreditation', 'SafeContractor accredited')],
            ['Lead Engineer',        $leadEngineer],
            ['Supervisor',           $supervisor],
            ['Emergency Contact',    $opsContact],
        ] as [$label, $value]) {
            $row = $table->addRow(380);
            $row->addCell(2800, ['bgColor' => self::LABEL_BG])->addText($label,          $this->font(9, bold: true));
            $row->addCell(7066, ['bgColor' => self::WHITE])   ->addText((string) $value, $this->font(9));
        }

        $section->addTextBreak(1);
        $section->addText(
            'All operatives hold relevant CSCS/ECS cards, manufacturer certifications, and have completed '
            . 'induction training covering manual handling, working at height, and electrical awareness.',
            $this->font(9, italic: true, colour: self::MID_GREY),
            ['spacing' => ['after' => 80]],
        );

        if (! empty($team)) {
            $section->addTextBreak(1);
            $this->subHeading($section, 'Engineering Team');
            $tbl = $section->addTable($this->tableStyle());
            $this->tealHeader($tbl, ['Role', 'Name', 'Mobile'], [3000, 3500, 3366]);
            foreach ($team as $member) {
                $row = $tbl->addRow(380);
                $row->addCell(3000, ['bgColor' => self::WHITE])->addText((string) ($member['role']   ?? ''), $this->font(9));
                $row->addCell(3500, ['bgColor' => self::WHITE])->addText((string) ($member['name']   ?? ''), $this->font(9));
                $row->addCell(3366, ['bgColor' => self::WHITE])->addText((string) ($member['mobile'] ?? ''), $this->font(9));
            }
        }
    }

    // =========================================================================
    // §3 RELEVANT LEGISLATION
    // =========================================================================

    private function buildLegislationSection(Section $section): void
    {
        $this->sectionHeading($section, '3. Relevant Legislation & Regulations');

        $acts = [
            'Health & Safety at Work Act 1974',
            'Management of Health & Safety at Work Regulations 1999',
            'Manual Handling Operations Regulations 1992',
            'Work at Height Regulations 2005',
            'Provision and Use of Work Equipment Regulations (PUWER) 1998',
            'Control of Substances Hazardous to Health (COSHH) Regulations 2002',
            'Electricity at Work Regulations 1989',
            'Construction (Design & Management) Regulations 2015 (where applicable)',
            'Personal Protective Equipment at Work Regulations 2022',
            'Reporting of Injuries, Diseases and Dangerous Occurrences Regulations (RIDDOR) 2013',
            'Control of Noise at Work Regulations 2005',
            'Control of Asbestos Regulations 2012',
        ];

        foreach ($acts as $act) {
            $section->addListItem($act, 0, $this->font(10), null, ['spacing' => ['after' => 60]]);
        }
    }

    // =========================================================================
    // §4 RISK ASSESSMENT
    // =========================================================================

    private function buildRiskAssessmentSection(Section $section, array $data): void
    {
        $this->sectionHeading($section, '4. Risk Assessment');

        // Risk rating key
        $this->subHeading($section, 'Risk Rating Key');
        $keyTable = $section->addTable($this->tableStyle());
        foreach ([
            ['Low (Score 1–3)',      self::RISK_GREEN,  'Risk is acceptable. Proceed with standard precautions.'],
            ['Medium (Score 4–6)',   self::RISK_AMBER,  'Risk requires attention. Implement additional controls before proceeding.'],
            ['High (Score 7–12)',    self::RISK_ORANGE, 'Significant risk. Management review required before proceeding.'],
            ['Critical (Score >12)', self::RISK_RED,    'Unacceptable risk. Work must not proceed until risk is reduced.'],
        ] as [$rating, $colour, $action]) {
            $row = $keyTable->addRow(380);
            $row->addCell(2200, ['bgColor' => $colour])->addText($rating, $this->font(9, bold: true), ['alignment' => Jc::CENTER]);
            $row->addCell(7666, ['bgColor' => self::WHITE])->addText($action, $this->font(9));
        }

        $section->addTextBreak(1);

        // Hazard register
        $this->subHeading($section, 'Hazard Register');
        $this->buildHazardTable($section, $data['hazards'] ?? []);
    }

    private function buildHazardTable(Section $section, array $hazards): void
    {
        if (empty($hazards)) {
            $section->addText('No hazards identified.', $this->font(9, italic: true));
            return;
        }

        $teal      = ['bgColor' => self::TEAL];
        $whFont    = $this->font(8, bold: true, colour: self::WHITE);
        $bodyFont  = $this->font(8);
        $boldFont  = $this->font(8, bold: true);
        $centred   = ['alignment' => Jc::CENTER];

        $table = $section->addTable($this->tableStyle());

        // ── Double header row ─────────────────────────────────────────────────

        // Row 1: group labels (Hazard | Who | Initial Rating (colspan 3) | Controls | Residual Rating (colspan 3))
        $r1 = $table->addRow(380);
        $r1->addCell(self::HT_HAZARD,  array_merge($teal, ['vMerge' => 'restart', 'valign' => 'center']))->addText('Hazard',          $whFont, $centred);
        $r1->addCell(self::HT_WHO,     array_merge($teal, ['vMerge' => 'restart', 'valign' => 'center']))->addText('Who Affected',     $whFont, $centred);
        $r1->addCell(self::HT_PRE_L + self::HT_PRE_S + self::HT_PRE_RISK, array_merge($teal, ['gridSpan' => 3]))->addText('Initial Rating',   $whFont, $centred);
        $r1->addCell(self::HT_CONTROLS, array_merge($teal, ['vMerge' => 'restart', 'valign' => 'center']))->addText('Control Measures', $whFont, $centred);
        $r1->addCell(self::HT_POST_L + self::HT_POST_S + self::HT_POST_RISK, array_merge($teal, ['gridSpan' => 3]))->addText('Residual Rating', $whFont, $centred);

        // Row 2: L / S / Risk sub-labels
        $r2 = $table->addRow(300);
        $r2->addCell(self::HT_HAZARD,   array_merge($teal, ['vMerge' => 'continue']))->addText('');
        $r2->addCell(self::HT_WHO,      array_merge($teal, ['vMerge' => 'continue']))->addText('');
        $r2->addCell(self::HT_PRE_L,    $teal)->addText('L',    $whFont, $centred);
        $r2->addCell(self::HT_PRE_S,    $teal)->addText('S',    $whFont, $centred);
        $r2->addCell(self::HT_PRE_RISK, $teal)->addText('Risk', $whFont, $centred);
        $r2->addCell(self::HT_CONTROLS, array_merge($teal, ['vMerge' => 'continue']))->addText('');
        $r2->addCell(self::HT_POST_L,    $teal)->addText('L',    $whFont, $centred);
        $r2->addCell(self::HT_POST_S,    $teal)->addText('S',    $whFont, $centred);
        $r2->addCell(self::HT_POST_RISK, $teal)->addText('Risk', $whFont, $centred);

        // ── Data rows ─────────────────────────────────────────────────────────
        foreach ($hazards as $idx => $hazard) {
            $rowBg    = ($idx % 2 === 0) ? self::WHITE : self::ROW_ALT;
            $preScore = (int) ($hazard['pre_likelihood']  ?? 1) * (int) ($hazard['pre_severity']  ?? 1);
            $postScore= (int) ($hazard['post_likelihood'] ?? 1) * (int) ($hazard['post_severity'] ?? 1);

            $dr = $table->addRow();  // null height → auto-height in Word XML

            $dr->addCell(self::HT_HAZARD, ['bgColor' => $rowBg, 'valign' => 'top'])
               ->addText($hazard['hazard'] ?? '', $boldFont);

            $whoCell = $dr->addCell(self::HT_WHO, ['bgColor' => $rowBg, 'valign' => 'top']);
            foreach ($hazard['persons_at_risk'] ?? [] as $person) {
                $whoCell->addText($person, $bodyFont);
            }

            $dr->addCell(self::HT_PRE_L,    ['bgColor' => $rowBg,                       'valign' => 'top'])->addText((string) ($hazard['pre_likelihood'] ?? ''), $bodyFont, $centred);
            $dr->addCell(self::HT_PRE_S,    ['bgColor' => $rowBg,                       'valign' => 'top'])->addText((string) ($hazard['pre_severity']   ?? ''), $bodyFont, $centred);
            $dr->addCell(self::HT_PRE_RISK, ['bgColor' => $this->riskColour($preScore), 'valign' => 'top'])->addText((string) $preScore, $boldFont, $centred);

            $ctrlCell = $dr->addCell(self::HT_CONTROLS, ['bgColor' => $rowBg, 'valign' => 'top']);
            foreach ($hazard['controls'] ?? [] as $j => $ctrl) {
                $ctrlCell->addText(($j + 1) . '. ' . $ctrl, $bodyFont);
            }

            $dr->addCell(self::HT_POST_L,    ['bgColor' => $rowBg,                        'valign' => 'top'])->addText((string) ($hazard['post_likelihood'] ?? ''), $bodyFont, $centred);
            $dr->addCell(self::HT_POST_S,    ['bgColor' => $rowBg,                        'valign' => 'top'])->addText((string) ($hazard['post_severity']   ?? ''), $bodyFont, $centred);
            $dr->addCell(self::HT_POST_RISK, ['bgColor' => $this->riskColour($postScore), 'valign' => 'top'])->addText((string) $postScore, $boldFont, $centred);
        }
    }

    // =========================================================================
    // §5 METHOD STATEMENT
    // =========================================================================

    private function buildMethodStatementSection(Section $section, array $data): void
    {
        $this->sectionHeading($section, '5. Method Statement');

        $methodData = $data['method_statement'] ?? [];

        // Support both {'phases': [...]} and flat array fallback
        $phases = $methodData['phases'] ?? (is_array($methodData) && isset($methodData[0]['title']) ? $methodData : []);

        if (empty($phases)) {
            $section->addText('Method statement not available.', $this->font(9, italic: true));
            return;
        }

        foreach ($phases as $i => $phase) {
            if (! is_array($phase)) {
                continue;
            }

            $this->subHeading($section, ($phase['title'] ?? 'Phase ' . ($i + 1)));

            foreach ($phase['steps'] ?? [] as $step) {
                $section->addListItem(
                    (string) $step,
                    0,
                    $this->font(10),
                    null,
                    ['spacing' => ['after' => 60]],
                );
            }

            $section->addTextBreak(1);
        }
    }

    // =========================================================================
    // §6 PPE
    // =========================================================================

    private function buildPpeSection(Section $section, array $data): void
    {
        $this->sectionHeading($section, '6. Personal Protective Equipment (PPE)');

        $ppeItems = $data['ppe'] ?? [];

        if (empty($ppeItems)) {
            $section->addText('PPE requirements to be confirmed by the project manager.', $this->font(9, italic: true));
            return;
        }

        // Lookup table: PPE item → [required_when, standard]
        $ppeDetails = [
            'Safety Boots (steel toe cap)' => ['All times on site',               'EN ISO 20345:2011'],
            'Hi-Visibility Vest'           => ['During delivery/unloading operations', 'EN ISO 20471 Class 2'],
            'Safety Glasses'               => ['Drilling, cutting, overhead work', 'EN 166'],
            'Hard Hat'                     => ['Overhead works & exclusion zone',  'EN 397'],
            'Latex / Nitrile Gloves'       => ['Cable handling, sharp edges',      'EN 374'],
            'Dust Mask (FFP2)'             => ['Drilling, grinding, cutting',       'EN 149 FFP2'],
            'Hearing Protection'           => ['Sustained power tool use',          'EN 352'],
        ];

        $table = $section->addTable($this->tableStyle());
        $this->tealHeader($table, ['PPE Item', 'Required When', 'Standard / Specification'], [3000, 3500, 3366]);

        foreach ($ppeItems as $i => $item) {
            $bg      = ($i % 2 === 0) ? self::WHITE : self::ROW_ALT;
            $details = $ppeDetails[$item] ?? ['As required by risk assessment', '—'];
            $row     = $table->addRow(380);
            $row->addCell(3000, ['bgColor' => $bg])->addText($item,        $this->font(9));
            $row->addCell(3500, ['bgColor' => $bg])->addText($details[0],  $this->font(9));
            $row->addCell(3366, ['bgColor' => $bg])->addText($details[1],  $this->font(9));
        }
    }

    // =========================================================================
    // §7 EMERGENCY PROCEDURES
    // =========================================================================

    private function buildEmergencySection(Section $section): void
    {
        $this->sectionHeading($section, '7. Emergency Procedures');

        // 7.1 First Aid
        $this->subHeading($section, '7.1 First Aid');
        foreach ([
            'A first aid kit must be available and accessible on site at all times.',
            'All operatives must be briefed on the location of the first aid kit at induction.',
            'The appointed first aider for the project is to be identified at the pre-works briefing.',
            'In the event of serious injury: call 999. Administer first aid as trained and do not move the casualty unless in immediate danger.',
            'All injuries, however minor, and all near-misses must be recorded in the site accident book.',
        ] as $item) {
            $section->addListItem($item, 0, $this->font(10), null, ['spacing' => ['after' => 60]]);
        }

        $section->addTextBreak(1);

        // 7.2 Fire
        $this->subHeading($section, '7.2 Fire & Evacuation');
        foreach ([
            'All operatives must be briefed on the site fire evacuation procedure during the site induction.',
            'Fire exits must be kept clear at all times — never block with equipment, cable drums or packaging.',
            'In the event of fire: raise the alarm immediately using the nearest call point.',
            'Evacuate via the nearest fire exit and assemble at the designated muster point.',
            'Do not use lifts or re-enter the building until the all-clear is given by the responsible person.',
        ] as $item) {
            $section->addListItem($item, 0, $this->font(10), null, ['spacing' => ['after' => 60]]);
        }

        $section->addTextBreak(1);

        // 7.3 Incident Reporting
        $this->subHeading($section, '7.3 Incident & Near-Miss Reporting');
        foreach ([
            'All accidents, incidents and near-misses must be reported to the 21st Century AV project manager immediately.',
            'Complete a company incident report form within 24 hours of any incident.',
            'RIDDOR-reportable incidents must be notified to the HSE by the responsible person.',
            'Preserve the scene of any serious incident for investigation purposes.',
            'Co-operate fully with any investigation by the HSE, client or insurers.',
        ] as $item) {
            $section->addListItem($item, 0, $this->font(10), null, ['spacing' => ['after' => 60]]);
        }
    }

    // =========================================================================
    // §8 SIGN-OFF
    // =========================================================================

    private function buildSignOffSection(Section $section, array $data): void
    {
        $this->sectionHeading($section, '8. RAMS Acknowledgement & Sign-Off');

        $section->addText(
            'All personnel attending site must sign below to confirm they have read, '
            . 'understood and agree to comply with this RAMS prior to commencing work.',
            $this->font(9, italic: true, colour: self::MID_GREY),
            ['spacing' => ['after' => 120]],
        );

        // Sign-off table
        $signTable = $section->addTable($this->tableStyle());
        $this->tealHeader($signTable, ['Name (Print)', 'Role', 'Signature', 'Date'], [2800, 2400, 2400, 2266]);
        for ($i = 0; $i < 5; $i++) {
            $bg  = ($i % 2 === 0) ? self::WHITE : self::ROW_ALT;
            $row = $signTable->addRow(600);
            $row->addCell(2800, ['bgColor' => $bg])->addText('', $this->font(9));
            $row->addCell(2400, ['bgColor' => $bg])->addText('', $this->font(9));
            $row->addCell(2400, ['bgColor' => $bg])->addText('', $this->font(9));
            $row->addCell(2266, ['bgColor' => $bg])->addText('', $this->font(9));
        }

        $section->addTextBreak(1);

        $section->addText(
            'By signing above, each operative confirms they have read and understood the contents of this '
            . 'Risk Assessment & Method Statement and will comply with all stated control measures.',
            $this->font(9, colour: self::MID_GREY),
            ['spacing' => ['after' => 80]],
        );
    }

    // =========================================================================
    // OPTIONAL: QUOTE SUMMARY (COVER PAGE INSERT)
    // =========================================================================

    private function buildQuoteSummary(Section $section, array $quote): void
    {
        $headerFont = $this->font(9, bold: true, colour: self::WHITE);
        $bodyFont   = $this->font(9);
        $vc         = ['bgColor' => self::WHITE];
        $ac         = ['bgColor' => self::ROW_ALT];

        if (! empty($quote['hardware_by_room'])) {
            $this->subHeading($section, 'Quoted Hardware Schedule');

            foreach ($quote['hardware_by_room'] as $group) {
                $room  = (string) ($group['room'] ?? 'General');
                $items = $group['items'] ?? [];
                if (empty($items)) {
                    continue;
                }

                $section->addText($room, $this->font(9, bold: true));

                $t   = $section->addTable($this->tableStyle());
                $hdr = $t->addRow(380);
                $hdr->addCell(600,  ['bgColor' => self::DARK_GREY])->addText('Qty',          $headerFont, ['alignment' => Jc::CENTER]);
                $hdr->addCell(9266, ['bgColor' => self::DARK_GREY])->addText('Hardware Item', $headerFont);

                foreach ($items as $i => $item) {
                    $bg  = ($i % 2 === 0) ? $vc : $ac;
                    $row = $t->addRow(360);
                    $row->addCell(600,  $bg)->addText((string) ($item['qty']         ?? ''), $bodyFont, ['alignment' => Jc::CENTER]);
                    $row->addCell(9266, $bg)->addText((string) ($item['description'] ?? ''), $bodyFont);
                }

                $section->addTextBreak(1);
            }
        } elseif (! empty($quote['line_items'])) {
            $this->subHeading($section, 'Quoted Hardware Schedule');

            $t   = $section->addTable($this->tableStyle());
            $hdr = $t->addRow(380);
            $hdr->addCell(600,  ['bgColor' => self::DARK_GREY])->addText('Qty',          $headerFont, ['alignment' => Jc::CENTER]);
            $hdr->addCell(9266, ['bgColor' => self::DARK_GREY])->addText('Hardware Item', $headerFont);

            foreach ($quote['line_items'] as $i => $item) {
                $bg  = ($i % 2 === 0) ? $vc : $ac;
                $row = $t->addRow(360);
                $row->addCell(600,  $bg)->addText((string) ($item['qty']         ?? ''), $bodyFont, ['alignment' => Jc::CENTER]);
                $row->addCell(9266, $bg)->addText((string) ($item['description'] ?? ''), $bodyFont);
            }
        }

        if (! empty($quote['room_summaries'])) {
            $section->addTextBreak(1);
            $this->subHeading($section, 'Areas / Rooms');

            $t   = $section->addTable($this->tableStyle());
            $hdr = $t->addRow(380);
            $hdr->addCell(self::W_PAGE, ['bgColor' => self::TEAL])->addText('Room / Area', $headerFont);

            foreach ($quote['room_summaries'] as $i => $entry) {
                $bg  = ($i % 2 === 0) ? $vc : $ac;
                $row = $t->addRow(360);
                $row->addCell(self::W_PAGE, $bg)->addText((string) ($entry['room'] ?? ''), $bodyFont);
            }
        }
    }

    // =========================================================================
    // HEADER & FOOTER
    // =========================================================================

    private function attachHeader(Section $section, array $project): void
    {
        $company = config('rams.company_name', '21st Century AV Ltd');
        $name    = $project['name'] ?? '';
        $ref     = $project['ref']  ?? '';

        $hdr = $section->addHeader();

        // Left side: company | RAMS | project name
        $hdr->addText(
            $company . '  |  RAMS' . ($name ? '  |  ' . $name : ''),
            $this->font(9, bold: true, colour: self::TEAL),
            [
                'alignment'         => Jc::LEFT,
                'borderBottomSize'  => 6,
                'borderBottomColor' => self::TEAL,
                'borderBottomSpace' => 4,
                'spacing'           => ['after' => 80],
            ],
        );

        // Ref number on a separate right-aligned line (displayed in header area)
        if ($ref) {
            $hdr->addText(
                'Ref: ' . $ref,
                $this->font(9, colour: self::MID_GREY),
                ['alignment' => Jc::RIGHT, 'spacing' => ['before' => 0, 'after' => 0]],
            );
        }
    }

    private function attachFooter(Section $section): void
    {
        $company = config('rams.company_name',    '21st Century AV Ltd');
        $address = config('rams.company_address', 'Thames Court, 2 Richfield Ave, Reading, RG1 8EQ');
        $tel     = config('rams.company_tel',     '0118 977 771');

        $section->addFooter()->addPreserveText(
            $company . '  |  ' . $address . '  |  ' . $tel . '  —  Page {PAGE} of {NUMPAGES}',
            $this->font(8, colour: self::MID_GREY),
            [
                'alignment'      => Jc::LEFT,
                'borderTopSize'  => 6,
                'borderTopColor' => self::TEAL,
                'borderTopSpace' => 4,
                'spacing'        => ['before' => 80],
            ],
        );
    }

    // =========================================================================
    // PRIVATE STYLE HELPERS
    // =========================================================================

    private function sectionHeading(Section $section, string $text): void
    {
        $section->addText(
            $text,
            $this->font(13, bold: true, colour: self::TEAL),
            [
                'alignment'         => Jc::LEFT,
                'borderBottomSize'  => 8,
                'borderBottomColor' => self::TEAL,
                'borderBottomSpace' => 3,
                'spacing'           => ['before' => 120, 'after' => 100],
            ],
        );
    }

    private function subHeading(Section $section, string $text): void
    {
        $section->addText(
            $text,
            $this->font(11, bold: true, colour: self::DARK_GREY),
            ['spacing' => ['before' => 100, 'after' => 60]],
        );
    }

    private function tealHeader(Table $table, array $labels, array $widths): void
    {
        $row = $table->addRow(380);
        foreach ($labels as $i => $label) {
            $row->addCell($widths[$i], ['bgColor' => self::TEAL])
                ->addText($label, $this->font(9, bold: true, colour: self::WHITE), ['alignment' => Jc::CENTER]);
        }
    }

    private function riskColour(int $score): string
    {
        return match (true) {
            $score <= 3  => self::RISK_GREEN,
            $score <= 6  => self::RISK_AMBER,
            $score <= 12 => self::RISK_ORANGE,
            default      => self::RISK_RED,
        };
    }

    private function font(
        int    $size   = 10,
        bool   $bold   = false,
        bool   $italic = false,
        string $colour = self::DARK_GREY,
    ): array {
        return array_filter([
            'name'   => 'Arial',
            'size'   => $size,
            'bold'   => $bold   ?: null,
            'italic' => $italic ?: null,
            'color'  => $colour,
        ]);
    }

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
}
