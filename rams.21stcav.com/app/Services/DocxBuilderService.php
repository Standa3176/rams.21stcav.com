<?php

namespace App\Services;

use App\Models\RamsDocument;
use App\Services\DocumentArtifactStorage;
use App\Services\DocumentTemplateService;
use App\Services\Rams\RamsDisplayPatchService;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\Element\Table;
use PhpOffice\PhpWord\TemplateProcessor;

/**
 * Builds the RAMS DOCX document from the assembled data array.
 *
 * Produces a 9-section document matching the reference PDF format (21CQ30017-02-OPS):
 *
 *   Cover Page   — Two-table cover with CLIENT, SITE, PROJECT REFERENCE, ROOMS, DATE
 *                  plus PREPARED BY, TELEPHONE, CLIENT CONTACT, REVISION, STATUS
 *   1. Document Control — Revision history table
 *   2. Company Information — Contact details table
 *   3. Health & Safety Policy Statement — Boilerplate paragraphs
 *   4. Scope of Works — Equipment schedule table grouped by DECOMMISSION / RETAINED / NEW INSTALLATION
 *   5. Risk Assessment — Landscape hazard register with Ref (RA01…), L×S=R notation
 *   6. Method Statement — Team requirements, tools, client responsibilities, numbered steps
 *   7. Emergency Procedures — Contact table, accident/fire boilerplate
 *   8. Document Sign-Off — Two-column 21CAV | Client sign-off table
 *
 * All content must trace to data[] or config('rams.*'). No invented values.
 */
class DocxBuilderService
{
    public function __construct(
        private readonly DocumentTemplateService $templates,
        private readonly DocumentArtifactStorage $artifacts = new DocumentArtifactStorage(),
        private readonly RamsDisplayPatchService $patchService = new RamsDisplayPatchService(),
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
    private const COL_REF     = 600;
    private const COL_HAZARD  = 2650;
    private const COL_CONSEQ  = 2920;
    private const COL_P       = 665;
    private const COL_S       = 665;
    private const COL_RISK    = 800;
    private const COL_CONTROLS = 4708;  // reduced by 600 to accommodate COL_REF

    // =========================================================================
    // PUBLIC ENTRY POINT
    // =========================================================================

    public function build(array $data, RamsDocument $record): string
    {
        // Ensure PHPWord escapes &, <, > in text content (off by default).
        Settings::setOutputEscapingEnabled(true);

        // ── Sidebar fix 2026-05-14 — docx-builder-pdf-parity (D1, D3, D5) ─────
        // Apply the same display patches the PDF download path applies. This
        // overwrites stale/leaked doc_author (client email leak), resolves the
        // personnel chain from programme → reviewed_data → owner, normalises
        // rooms_text + scope_items, and infers client_contact_name. Mutation
        // is transient (no $record->save() inside the patch service) — the
        // patched generated_data is read back below and supersedes the
        // caller-provided $data so cover/scope/contact rows match the PDF.
        $this->patchService->patch($record);
        $data = $record->generated_data ?? $data;

        $formData = $record->form_data ?? [];

        $phpWord = new PhpWord();
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(10);

        // Build all sections in order
        $this->buildCoverPage($phpWord, $data, $formData, $record);
        $this->buildDocumentControl($phpWord, $data, $record);
        $this->buildCompanyInformation($phpWord, $data);
        $this->buildHealthSafetyPolicy($phpWord, $data);
        $this->buildCdmSection($phpWord, $data);
        $this->buildScopeOfWorks($phpWord, $data, $formData, $record);
        $this->buildEngineerFindingsByRoom($phpWord, $data);
        $this->buildRiskAssessment($phpWord, $data);
        $this->buildMethodStatement($phpWord, $data);
        $this->buildEmergencyProcedures($phpWord, $data, $formData);
        $this->buildDocumentSignOff($phpWord, $data);

        // Write file to the unified documents disk (H-07).
        $filename = 'rams_' . $record->id . '_' . now()->format('Ymd_His') . '.docx';
        $filePath = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_RAMS, $filename);

        IOFactory::createWriter($phpWord, 'Word2007')->save($filePath);

        // Persist filename
        $record->filename = $filename;
        $record->save();

        return $filePath;
    }

    // =========================================================================
    // COVER PAGE — Portrait, two info tables
    // =========================================================================

    private function buildCoverPage(PhpWord $phpWord, array $data, array $formData, ?RamsDocument $record = null): void
    {
        $section = $phpWord->addSection($this->portraitStyle());
        $this->attachFooter($section);

        $project = $data['project'] ?? [];

        // ── Rooms resolution chain (D3 — match PDF rams.blade.php:324-345) ────
        // Priority: reviewed_data['rooms'] → reviewed_data['room_overviews'][n]['room']
        //           → $project['rooms_text']. Non-physical-space entries
        //           (cabling/services/warranty etc) are filtered out.
        $roomsList = $this->resolveRoomsList($data, $record);
        $roomsDisplay = ! empty($roomsList) ? implode(', ', $roomsList) : ($project['rooms_text'] ?? '');

        // ── Document date (D2 — match PDF rams.blade.php:347-349) ─────────────
        // PDF uses $record->created_at->format('d/m/Y') — NOT the stale
        // "F Y" placeholder written into $project['date'] at build time.
        $docDate = $record?->created_at?->format('d/m/Y') ?: now()->format('d/m/Y');

        // ── Company name + RAMS title ─────────────────────────────────────────
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
                'spaceAfter'        => 200,
            ],
        );

        $tealCell  = ['bgColor' => self::TEAL];
        $whiteCell = ['bgColor' => self::WHITE];
        $labelFont = $this->font(10, bold: true, colour: self::WHITE);
        $valueFont = $this->font(10);
        $colW      = (int) (self::W_PORT / 2); // 4933

        // ── First table: CLIENT, SITE, PROJECT REFERENCE, ROOMS, DATE ─────────
        $table = $section->addTable($this->tableStyle());
        $rows1 = [
            ['CLIENT',            $project['client']       ?? ''],
            ['SITE',              $project['site_address'] ?? ''],
            ['PROJECT REFERENCE', $project['ref']          ?? ''],
            ['ROOMS',             $roomsDisplay],
            ['DATE',              $docDate],
        ];
        foreach ($rows1 as [$label, $value]) {
            $row = $table->addRow(420);
            $row->addCell($colW, $tealCell) ->addText($label,              $labelFont);
            $row->addCell($colW, $whiteCell)->addText($this->t($value),    $valueFont);
        }

        $section->addTextBreak(1);

        // ── Second table: PREPARED BY, TELEPHONE, CLIENT CONTACT, REVISION, STATUS
        $table2 = $section->addTable($this->tableStyle());

        $clientContact = trim(
            ($project['client_contact_name'] ?? '') .
            (($project['client_contact_email'] ?? '') !== '' ? "\n" . $project['client_contact_email'] : '')
        );
        // Match PDF: fall back to "TBC at site induction" when no client contact.
        $clientContactDisplay = $clientContact !== '' ? $clientContact : 'TBC at site induction';

        $rows2 = [
            ['PREPARED BY',    $project['doc_author']      ?? ''],
            ['TELEPHONE',      config('rams.company_phone')],
            ['CLIENT CONTACT', $clientContactDisplay],
            ['REVISION',       $project['revision']        ?? 'Rev 1.0'],
            ['STATUS',         $project['document_status'] ?? 'For Issue'],
        ];
        foreach ($rows2 as [$label, $value]) {
            $row = $table2->addRow(420);
            $row->addCell($colW, $tealCell) ->addText($label,           $labelFont);
            $row->addCell($colW, $whiteCell)->addText($this->t($value), $valueFont);
        }

        $section->addTextBreak(1);

        // ── Third table: PROJECT MANAGER, LEAD ENGINEER, ENGINEERS, PROGRAMMER,
        //                 VEHICLE REGS (D4 expansion — match PDF lines 586-611)
        $table3 = $section->addTable($this->tableStyle());

        $docAuthor = (string) ($project['doc_author'] ?? '');
        $leadEng   = (string) ($project['lead_engineer'] ?? '');
        $addEngs   = (string) ($project['additional_engineers'] ?? '');
        $programmer = (string) ($project['programmer'] ?? '');

        $vehSrc = $project['site_vehicles'] ?? ($data['site_vehicles'] ?? null);
        if (is_string($vehSrc)) {
            $vehSrc = preg_split('/\r?\n/', $vehSrc) ?: [];
        }
        $coverVehiclesList = array_values(array_filter(
            array_map('trim', (array) ($vehSrc ?? [])),
            fn (string $v) => $v !== '',
        ));
        $coverVehiclesDisplay = ! empty($coverVehiclesList) ? implode(', ', $coverVehiclesList) : '—';

        $rows3 = [
            ['PROJECT MANAGER', $docAuthor !== '' ? $docAuthor : '—'],
            ['LEAD ENGINEER',   $leadEng   !== '' ? $leadEng   : '—'],
            ['ENGINEERS',       $addEngs   !== '' ? $addEngs   : '—'],
            ['PROGRAMMER',      $programmer !== '' ? $programmer : '—'],
            ['VEHICLE REGS',    $coverVehiclesDisplay],
        ];
        foreach ($rows3 as [$label, $value]) {
            $row = $table3->addRow(420);
            $row->addCell($colW, $tealCell) ->addText($label,           $labelFont);
            $row->addCell($colW, $whiteCell)->addText($this->t($value), $valueFont);
        }
    }

    /**
     * Resolve the rooms list for cover + scope-of-works rendering.
     *
     * Mirrors the PDF priority chain in rams.blade.php:324-345 so the DOCX
     * cover ROOMS field is populated even when the legacy $project['rooms']
     * array is empty:
     *   1. $record->reviewed_data['rooms']            (curated names)
     *   2. $record->reviewed_data['room_overviews'][n]['room']  (PM-edited)
     *   3. $data['project']['rooms']                  (legacy)
     *   4. $data['project']['rooms_text']             (parser fallback)
     *
     * Non-physical-space entries (cabling, services, warranty, etc.) are
     * filtered out using the same regex as the PDF blade.
     *
     * @return array<int, string> ordered list of unique room names.
     */
    private function resolveRoomsList(array $data, ?RamsDocument $record): array
    {
        $excludeRe = '/\b(licen[cs]|cabling|cables?|wiring|network|software|service|warranty|support|delivery|carriage)\b/i';
        $filter = fn ($r) => is_string($r) && $r !== '' && ! preg_match($excludeRe, $r);

        $list = [];

        // 1. reviewed_data['rooms'] (curated names list — highest priority).
        $reviewedRooms = $record?->reviewed_data['rooms'] ?? [];
        if (is_array($reviewedRooms) && ! empty($reviewedRooms)) {
            foreach ($reviewedRooms as $r) {
                if (is_array($r)) {
                    $name = (string) ($r['name'] ?? ($r['room_name'] ?? ''));
                } else {
                    $name = (string) $r;
                }
                if ($filter($name)) {
                    $list[] = $name;
                }
            }
        }

        // 2. reviewed_data['room_overviews'][n]['room'] (PM-edited overviews).
        if (empty($list)) {
            $roomOverviews = $record?->reviewed_data['room_overviews'] ?? [];
            if (is_array($roomOverviews)) {
                foreach ($roomOverviews as $ro) {
                    if (! is_array($ro)) {
                        continue;
                    }
                    $name = (string) ($ro['room'] ?? ($ro['room_name'] ?? ($ro['name'] ?? '')));
                    if ($filter($name)) {
                        $list[] = $name;
                    }
                }
            }
        }

        // 3. $project['rooms'] (legacy generated_data array).
        if (empty($list)) {
            $projectRooms = $data['project']['rooms'] ?? [];
            if (is_array($projectRooms)) {
                foreach ($projectRooms as $r) {
                    $name = (string) $r;
                    if ($filter($name)) {
                        $list[] = $name;
                    }
                }
            }
        }

        // 4. $project['rooms_text'] (parser fallback, comma/newline separated).
        if (empty($list)) {
            $roomsText = (string) ($data['project']['rooms_text'] ?? '');
            if ($roomsText !== '') {
                $parts = array_filter(array_map('trim', preg_split('/[,\n]+/', $roomsText) ?: []));
                foreach ($parts as $name) {
                    if ($filter($name)) {
                        $list[] = $name;
                    }
                }
            }
        }

        return array_values(array_unique($list));
    }

    // =========================================================================
    // SECTION 1 — Document Control
    // =========================================================================

    private function buildDocumentControl(PhpWord $phpWord, array $data, ?RamsDocument $record = null): void
    {
        $section = $phpWord->addSection($this->portraitStyle() + ['breakType' => 'nextPage']);
        $this->attachFooter($section);

        $project = $data['project'] ?? [];

        $this->sectionHeading($section, '1. Document Control');

        $table = $section->addTable($this->tableStyle());
        $this->tealHeader($table, ['Rev', 'Date', 'Author', 'Description', 'Status'], [800, 1800, 2500, 3500, 1266]);

        $altCell   = ['bgColor' => self::ROW_ALT];
        $whiteCell = ['bgColor' => self::WHITE];
        $vf        = $this->font(9);

        // D2 — use document creation date (dd/mm/yyyy) not the "F Y" placeholder.
        $docDate = $record?->created_at?->format('d/m/Y') ?: now()->format('d/m/Y');

        // Pre-filled row
        $row = $table->addRow(400);
        $row->addCell(800,  $altCell)  ->addText($this->t($project['revision']        ?? 'Rev 1.0'),     $vf);
        $row->addCell(1800, $whiteCell)->addText($this->t($docDate), $vf);
        $row->addCell(2500, $whiteCell)->addText($this->t($project['doc_author']       ?? ''),           $vf);
        $row->addCell(3500, $whiteCell)->addText('Initial Issue',                                        $vf);
        $row->addCell(1266, $whiteCell)->addText($this->t($project['document_status']  ?? 'For Issue'),  $vf);

        // Three blank rows for future revisions
        for ($i = 0; $i < 3; $i++) {
            $row = $table->addRow(400);
            $row->addCell(800,  $whiteCell)->addText('', $vf);
            $row->addCell(1800, $whiteCell)->addText('', $vf);
            $row->addCell(2500, $whiteCell)->addText('', $vf);
            $row->addCell(3500, $whiteCell)->addText('', $vf);
            $row->addCell(1266, $whiteCell)->addText('', $vf);
        }
    }

    // =========================================================================
    // SECTION 2 — Company Information
    // =========================================================================

    private function buildCompanyInformation(PhpWord $phpWord, array $data): void
    {
        $section = $phpWord->addSection($this->portraitStyle() + ['breakType' => 'nextPage']);
        $this->attachFooter($section);

        $project = $data['project'] ?? [];

        $this->sectionHeading($section, '2. Company Information');

        $table    = $section->addTable($this->tableStyle());
        $altCell  = ['bgColor' => self::ROW_ALT];
        $valCell  = ['bgColor' => self::WHITE];
        $labelFont = $this->font(9, bold: true);
        $valueFont = $this->font(9);

        $infoRows = [
            ['Company Name', config('rams.company_name')],
            ['Address',      config('rams.company_address')],
            ['Telephone',    config('rams.company_phone')],
            ['Website',      config('rams.company_website')],
            ['Email',        config('rams.company_email')],
            ['Prepared by',  $project['doc_author'] ?? ''],
        ];

        foreach ($infoRows as $i => [$label, $value]) {
            $bg  = ($i % 2 === 0) ? $altCell : $valCell;
            $row = $table->addRow(400);
            $row->addCell(2800, $bg)->addText($label,              $labelFont);
            $row->addCell(7066, $bg)->addText($this->t($value),    $valueFont);
        }
    }

    // =========================================================================
    // SECTION 3 — Health & Safety Policy Statement
    // =========================================================================

    private function buildHealthSafetyPolicy(PhpWord $phpWord, array $data): void
    {
        $section = $phpWord->addSection($this->portraitStyle() + ['breakType' => 'nextPage']);
        $this->attachFooter($section);

        $this->sectionHeading($section, '3. Health & Safety Policy Statement');

        $bodyFont  = $this->font(9, colour: self::DARK_GREY);
        $paraStyle = ['spaceBefore' => 60, 'spaceAfter' => 120, 'alignment' => Jc::BOTH];

        $section->addText(
            '21st Century AV Ltd is committed to ensuring the health, safety and welfare of all its employees, '
            . 'subcontractors, clients and members of the public who may be affected by our activities. We comply '
            . 'fully with the Health and Safety at Work etc. Act 1974 and all relevant statutory provisions, '
            . 'including the Management of Health and Safety at Work Regulations 1999, the Provision and Use of '
            . 'Work Equipment Regulations 1998 (PUWER), the Manual Handling Operations Regulations 1992, and the '
            . 'Electricity at Work Regulations 1989.',
            $bodyFont,
            $paraStyle,
        );

        $section->addText(
            'All engineers operating on behalf of 21st Century AV Ltd are briefed on site-specific risks prior to '
            . 'commencement of works and are required to adhere to this Risk Assessment and Method Statement at all '
            . 'times. Engineers will not commence work until they are satisfied that it is safe to do so. Any near '
            . 'misses, accidents, or unsafe conditions must be reported to the site manager and to the 21st Century '
            . 'AV operations team immediately. This document must be read, understood, and complied with by all '
            . 'persons carrying out the works described herein. It should be retained on site for the duration of '
            . 'the works.',
            $bodyFont,
            $paraStyle,
        );
    }

    // =========================================================================
    // SECTION 4 — Scope of Works
    // =========================================================================

    private function buildScopeOfWorks(PhpWord $phpWord, array $data, array $formData, ?RamsDocument $record = null): void
    {
        $section = $phpWord->addSection($this->portraitStyle() + ['breakType' => 'nextPage']);
        $this->attachFooter($section);

        $project = $data['project'] ?? [];

        // ── Rooms resolution chain (D3 — match PDF rams.blade.php:324-345) ────
        $roomsList = $this->resolveRoomsList($data, $record);
        $roomsDisplay = ! empty($roomsList) ? implode(', ', $roomsList) : ($project['rooms_text'] ?? '');

        $this->sectionHeading($section, '4. Scope of Works');

        // ── Scope of Works bullets (Tier 1 upgrade) ──────────────────────────
        $scopeBullets = $data['scope_of_works_bullets'] ?? [];
        if (! empty($scopeBullets)) {
            $section->addText('Works Activities', $this->font(10, bold: true, colour: self::TEAL), ['spaceBefore' => 80, 'spaceAfter' => 60]);
            foreach ($scopeBullets as $bullet) {
                $section->addText(
                    '•  ' . $this->t((string) $bullet),
                    $this->font(9),
                    ['spaceBefore' => 40, 'spaceAfter' => 40],
                );
            }
            $section->addTextBreak(1);
        }

        // ── Summary header block ──────────────────────────────────────────────
        $table    = $section->addTable($this->tableStyle());
        $altCell  = ['bgColor' => self::ROW_ALT];
        $valCell  = ['bgColor' => self::WHITE];
        $lf       = $this->font(9, bold: true);
        $vf       = $this->font(9);

        $headerRows = [
            ['Client',        $project['client']        ?? ''],
            ['Site',          $project['site_address']  ?? ''],
            ['Rooms',         $roomsDisplay],
            ['Working Hours', $project['working_hours'] ?? 'Monday–Friday, 09:00–17:30'],
        ];
        foreach ($headerRows as $i => [$label, $value]) {
            $bg  = ($i % 2 === 0) ? $altCell : $valCell;
            $row = $table->addRow(400);
            $row->addCell(2800, $bg)->addText($label,           $lf);
            $row->addCell(7066, $bg)->addText($this->t($value), $vf);
        }

        $section->addTextBreak(1);

        // ── Site Logistics & Access (mirrors PDF — quick task 260503-tfb closure
        //    via 260504-gho). Pure additive: empty/missing $data['site_logistics']
        //    ⇒ this block adds NOTHING to the document, so legacy RAMS DOCX output
        //    is byte-identical to pre-change. Same shape as rams.blade.php 714-734.
        $siteLog = $data['site_logistics'] ?? [];
        $hasSiteLog = is_array($siteLog) && (
            ! empty($siteLog['comms_room_access_status']) ||
            ! empty($siteLog['comms_room_access_notes']) ||
            ! empty($siteLog['parking_restraints']) ||
            ! empty($siteLog['distance_from_base_miles']) ||
            ! empty($siteLog['distance_from_base_notes']) ||
            ! empty($siteLog['site_access_notes']) ||
            ! empty($siteLog['delivery_routes'])
        );
        if ($hasSiteLog) {
            $section->addText(
                'Site Logistics & Access (from site survey)',
                $this->font(10, bold: true, colour: self::TEAL),
                ['spaceBefore' => 80, 'spaceAfter' => 60],
            );

            $commsLabels = [
                'yes' => 'Permission required', 'no' => 'Free access',
                'outsourced' => 'Outsourced facilities team', 'unknown' => 'Status unknown',
            ];

            $logTable = $section->addTable($this->tableStyle());
            $rowsLog = [];
            if (! empty($siteLog['parking_restraints'])) {
                $rowsLog[] = ['Parking arrangements', $siteLog['parking_restraints']];
            }
            if (! empty($siteLog['site_access_notes'])) {
                $rowsLog[] = ['Site access notes', $siteLog['site_access_notes']];
            }
            if (! empty($siteLog['delivery_routes'])) {
                $rowsLog[] = ['Delivery routes', $siteLog['delivery_routes']];
            }
            if (! empty($siteLog['comms_room_access_status']) || ! empty($siteLog['comms_room_access_notes'])) {
                $statusLabel = $commsLabels[$siteLog['comms_room_access_status'] ?? ''] ?? '';
                $parts = array_filter([$statusLabel, $siteLog['comms_room_access_notes'] ?? '']);
                $rowsLog[] = ['Comms room access', implode(' — ', $parts)];
            }
            if (! empty($siteLog['distance_from_base_miles']) || ! empty($siteLog['distance_from_base_notes'])) {
                $parts = array_filter([
                    ! empty($siteLog['distance_from_base_miles'])
                        ? $siteLog['distance_from_base_miles'] . ' miles from depot' : '',
                    $siteLog['distance_from_base_notes'] ?? '',
                ]);
                $rowsLog[] = ['Distance from depot', implode(' — ', $parts)];
            }

            $altCellLog   = ['bgColor' => self::ROW_ALT];
            $whiteCellLog = ['bgColor' => self::WHITE];
            foreach ($rowsLog as $i => [$labelLog, $valueLog]) {
                $bg = ($i % 2 === 0) ? $altCellLog : $whiteCellLog;
                $rowLog = $logTable->addRow(380);
                $rowLog->addCell(3000, $bg)->addText($labelLog, $this->font(9, bold: true));
                $rowLog->addCell(6866, $bg)->addText($this->t((string) $valueLog), $this->font(9));
            }

            $section->addTextBreak(1);
        }

        // ── Equipment schedule table ──────────────────────────────────────────
        // Columns: Activity | Item | Qty per Room | Notes  (total = W_PORT = 9866)
        $wAct  = 2600;
        $wItem = 4200;
        $wQty  = 800;
        $wNote = 9866 - $wAct - $wItem - $wQty; // 1266

        $scopeItems = $data['scope_items'] ?? [];
        $hasDecomm  = ! empty($scopeItems['decommission'] ?? []);
        $hasRetain  = ! empty($scopeItems['retained']     ?? []);
        $hasNew     = ! empty($scopeItems['new_install']  ?? []);

        // Fall back to quote line items when scope_items is entirely empty
        $hasAnyScopeItems = $hasDecomm || $hasRetain || $hasNew;

        $eqTable  = $section->addTable($this->tableStyle());
        $tealCell = ['bgColor' => self::TEAL];
        $darkCell = ['bgColor' => self::DARK_GREY];
        $wf       = $this->font(10, bold: true, colour: self::WHITE);
        $hf       = $this->font(9,  bold: true, colour: self::WHITE);
        $bf       = $this->font(9);

        // Spanning header: "Equipment Schedule"
        $hRow  = $eqTable->addRow(420);
        $hCell = $hRow->addCell(self::W_PORT, array_merge($tealCell, ['gridSpan' => 4]));
        $hCell->addText('Equipment Schedule', $this->font(10, bold: true, colour: self::WHITE), ['alignment' => Jc::CENTER]);

        // Column sub-headers
        $shRow = $eqTable->addRow(360);
        $shRow->addCell($wAct,  $darkCell)->addText('Activity',     $hf);
        $shRow->addCell($wItem, $darkCell)->addText('Item',         $hf);
        $shRow->addCell($wQty,  $darkCell)->addText('Qty / Room',   $hf, ['alignment' => Jc::CENTER]);
        $shRow->addCell($wNote, $darkCell)->addText('Notes',        $hf);

        if ($hasAnyScopeItems) {
            $rowIdx = 0;

            // ── DECOMMISSION & HANDBACK ───────────────────────────────────────
            if ($hasDecomm) {
                $subRow  = $eqTable->addRow(380);
                $subCell = $subRow->addCell(self::W_PORT, array_merge($darkCell, ['gridSpan' => 4]));
                $subCell->addText('DECOMMISSION & HANDBACK', $this->font(9, bold: true, colour: self::WHITE));

                foreach ($scopeItems['decommission'] as $item) {
                    $bg  = ($rowIdx % 2 === 0) ? ['bgColor' => self::WHITE] : ['bgColor' => self::ROW_ALT];
                    $dr  = $eqTable->addRow(380);
                    $dr->addCell($wAct,  $bg)->addText('Decommission',                            $bf);
                    $dr->addCell($wItem, $bg)->addText($this->t((string)($item['item_name'] ?? '')), $bf);
                    $dr->addCell($wQty,  $bg)->addText($this->t((string)($item['qty']       ?? '')), $bf, ['alignment' => Jc::CENTER]);
                    $dr->addCell($wNote, $bg)->addText($this->t((string)($item['notes']     ?? '')), $bf);
                    $rowIdx++;
                }
            }

            // ── EXISTING — RETAINED ───────────────────────────────────────────
            if ($hasRetain) {
                $subRow  = $eqTable->addRow(380);
                $subCell = $subRow->addCell(self::W_PORT, array_merge($darkCell, ['gridSpan' => 4]));
                $subCell->addText('EXISTING — RETAINED', $this->font(9, bold: true, colour: self::WHITE));

                foreach ($scopeItems['retained'] as $item) {
                    $bg  = ($rowIdx % 2 === 0) ? ['bgColor' => self::WHITE] : ['bgColor' => self::ROW_ALT];
                    $dr  = $eqTable->addRow(380);
                    $dr->addCell($wAct,  $bg)->addText('Retained',                                $bf);
                    $dr->addCell($wItem, $bg)->addText($this->t((string)($item['item_name'] ?? '')), $bf);
                    $dr->addCell($wQty,  $bg)->addText($this->t((string)($item['qty']       ?? '')), $bf, ['alignment' => Jc::CENTER]);
                    $dr->addCell($wNote, $bg)->addText($this->t((string)($item['notes']     ?? '')), $bf);
                    $rowIdx++;
                }
            }

            // ── NEW INSTALLATION ──────────────────────────────────────────────
            if ($hasNew) {
                $subRow  = $eqTable->addRow(380);
                $subCell = $subRow->addCell(self::W_PORT, array_merge($darkCell, ['gridSpan' => 4]));
                $subCell->addText('NEW INSTALLATION', $this->font(9, bold: true, colour: self::WHITE));

                foreach ($scopeItems['new_install'] as $item) {
                    $bg  = ($rowIdx % 2 === 0) ? ['bgColor' => self::WHITE] : ['bgColor' => self::ROW_ALT];
                    $dr  = $eqTable->addRow(380);
                    $dr->addCell($wAct,  $bg)->addText('New Installation',                        $bf);
                    $dr->addCell($wItem, $bg)->addText($this->t((string)($item['item_name'] ?? '')), $bf);
                    $dr->addCell($wQty,  $bg)->addText($this->t((string)($item['qty']       ?? '')), $bf, ['alignment' => Jc::CENTER]);
                    $dr->addCell($wNote, $bg)->addText($this->t((string)($item['notes']     ?? '')), $bf);
                    $rowIdx++;
                }
            }
        } else {
            // ── Backward compat: fall back to quote line items ────────────────
            $lineItems = $data['quote']['line_items'] ?? [];
            if (! empty($lineItems)) {
                $subRow  = $eqTable->addRow(380);
                $subCell = $subRow->addCell(self::W_PORT, array_merge($darkCell, ['gridSpan' => 4]));
                $subCell->addText('NEW INSTALLATION', $this->font(9, bold: true, colour: self::WHITE));

                foreach ($lineItems as $i => $item) {
                    $bg = ($i % 2 === 0) ? ['bgColor' => self::WHITE] : ['bgColor' => self::ROW_ALT];
                    $dr = $eqTable->addRow(380);
                    $dr->addCell($wAct,  $bg)->addText('New Installation',                              $bf);
                    $dr->addCell($wItem, $bg)->addText($this->t((string)($item['description'] ?? '')),  $bf);
                    $dr->addCell($wQty,  $bg)->addText($this->t((string)($item['qty']         ?? '')),  $bf, ['alignment' => Jc::CENTER]);
                    $dr->addCell($wNote, $bg)->addText($this->t((string)($item['room']        ?? '')),  $bf);
                }
            } else {
                // Empty placeholder row
                $dr = $eqTable->addRow(380);
                $dr->addCell($wAct,  ['bgColor' => self::WHITE])->addText('', $bf);
                $dr->addCell($wItem, ['bgColor' => self::WHITE])->addText('No items listed', $bf);
                $dr->addCell($wQty,  ['bgColor' => self::WHITE])->addText('', $bf);
                $dr->addCell($wNote, ['bgColor' => self::WHITE])->addText('', $bf);
            }
        }
    }

    // =========================================================================
    // SECTION 4.5 — Engineer Survey Findings (per room)
    //
    // Mirrors resources/views/pdf/rams.blade.php lines 781-1033 from quick task
    // 260503-tfb (PDF) — added to DOCX in 260504-gho. Reads
    // $data['rooms'][n]['engineer_feedback'] populated by ProjectContextBuilder.
    // The whole section is suppressed when no rooms have any populated
    // engineer_feedback fields — pre-260503 RAMS DOCX byte output is
    // regression-safe.
    // =========================================================================

    private function buildEngineerFindingsByRoom(PhpWord $phpWord, array $data): void
    {
        $rooms = (array) ($data['rooms'] ?? []);
        if (empty($rooms)) return;

        // Pre-flight: any room with non-empty engineer_feedback?
        $anyEf = false;
        foreach ($rooms as $room) {
            $ef = (array) ($room['engineer_feedback'] ?? []);
            if (! empty($ef) && (
                ! empty($ef['mounting_heights']) ||
                ! empty($ef['work_at_height_methods']) ||
                ! empty($ef['cable_routes']) ||
                ! empty($ef['wall_construction']) ||
                ! empty($ef['wall_needs_reinforcement']) ||
                ! empty($ef['wall_needs_chase_out']) ||
                ! empty($ef['wall_needs_conduit']) ||
                ! empty($ef['brackets_required']) ||
                ! empty($ef['table_info']) ||
                ! empty($ef['floor_box_info'])
            )) {
                $anyEf = true; break;
            }
        }
        if (! $anyEf) return;

        $section = $phpWord->addSection($this->portraitStyle() + ['breakType' => 'nextPage']);
        $this->attachFooter($section);
        $this->sectionHeading($section, 'Engineer Survey Findings');

        $methodLabels = [
            'ladder' => 'Ladder', 'podium' => 'Podium steps', 'tower' => 'Access tower',
            'mewp' => 'MEWP', 'scaffold' => 'Scaffold', 'na' => 'Not required',
        ];
        $wallConstructionLabels = [
            'ply_lined' => 'Ply-lined', 'solid' => 'Solid wall', 'plasterboard' => 'Plasterboard',
            'masonry' => 'Masonry / brick', 'metal_stud' => 'Metal stud', 'concrete' => 'Concrete',
        ];
        $cableCategoryLabels = [
            'ceiling_speakers' => 'Ceiling speakers', 'desk_cables' => 'Desk cables',
            'mic_cables' => 'Microphone cables', 'booking_panel_cables' => 'Booking panel cables',
            'screen_cables' => 'Screen / display cables', 'rack_to_room' => 'Rack to room',
            'other' => 'Other',
        ];

        $vf = $this->font(9);
        $bf = $this->font(9, bold: true);

        foreach ($rooms as $room) {
            $ef = (array) ($room['engineer_feedback'] ?? []);
            $hasEF = ! empty($ef) && (
                ! empty($ef['mounting_heights']) ||
                ! empty($ef['work_at_height_methods']) ||
                ! empty($ef['cable_routes']) ||
                ! empty($ef['wall_construction']) ||
                ! empty($ef['wall_needs_reinforcement']) ||
                ! empty($ef['wall_needs_chase_out']) ||
                ! empty($ef['wall_needs_conduit']) ||
                ! empty($ef['brackets_required']) ||
                ! empty($ef['table_info']) ||
                ! empty($ef['floor_box_info'])
            );
            if (! $hasEF) continue;

            $roomName = (string) ($room['name'] ?? 'Room');
            $section->addText(
                'Engineer Survey Findings — ' . $this->t($roomName),
                $this->font(10, bold: true, colour: self::TEAL),
                ['spaceBefore' => 100, 'spaceAfter' => 60],
            );

            // ── Mounting heights ─────────────────────────────────────────────
            $mh = (array) ($ef['mounting_heights'] ?? []);
            $heightRows = [];
            foreach ([
                'screen_h_m' => 'Screen', 'camera_h_m' => 'Camera',
                'booking_panel_h_m' => 'Booking panel', 'speaker_h_m' => 'Speaker',
            ] as $k => $lbl) {
                if (! empty($mh[$k])) $heightRows[] = $lbl . ': ' . $mh[$k] . ' m';
            }
            foreach ((array) ($mh['other'] ?? []) as $other) {
                $oLbl = trim((string) ($other['label'] ?? ''));
                $oH = $other['h_m'] ?? null;
                if ($oLbl !== '' && $oH !== null && $oH !== '') {
                    $heightRows[] = $oLbl . ': ' . $oH . ' m';
                }
            }
            if (! empty($heightRows)) {
                $section->addText('Installation heights: ', $bf, ['spaceBefore' => 40]);
                $section->addText($this->t(implode(' • ', $heightRows)), $vf, ['spaceAfter' => 40]);
            }

            // ── Working at height methods ────────────────────────────────────
            $wahLabels = array_values(array_filter(array_map(
                fn ($m) => $methodLabels[strtolower((string) $m)] ?? ucfirst((string) $m),
                (array) ($ef['work_at_height_methods'] ?? [])
            )));
            if (! empty($wahLabels)) {
                $section->addText('Working at height — methods on site: ', $bf, ['spaceBefore' => 40]);
                $section->addText($this->t(implode(', ', $wahLabels)), $vf, ['spaceAfter' => 40]);
            }

            // ── Cable routes ─────────────────────────────────────────────────
            $cableRoutes = (array) ($ef['cable_routes'] ?? []);
            if (! empty($cableRoutes)) {
                $section->addText('Cable routes planned:', $bf, ['spaceBefore' => 40, 'spaceAfter' => 40]);
                foreach ($cableRoutes as $cr) {
                    $catKey = (string) ($cr['category'] ?? '');
                    $cat = $cableCategoryLabels[$catKey] ?? ucwords(str_replace('_', ' ', $catKey));
                    $len = ! empty($cr['length_m']) ? ($cr['length_m'] . ' m') : '';
                    $from = trim((string) ($cr['from'] ?? ''));
                    $to = trim((string) ($cr['to'] ?? ''));
                    $route = ($from && $to) ? ($from . ' → ' . $to) : ($from ?: $to);
                    $note = trim((string) ($cr['notes'] ?? ''));
                    $parts = array_filter([$cat, $route, $len, $note]);
                    if (! empty($parts)) {
                        $section->addText('•  ' . $this->t(implode(' — ', $parts)), $vf, ['spaceBefore' => 20, 'spaceAfter' => 20]);
                    }
                }
            }

            // ── Wall construction & prep ─────────────────────────────────────
            $wcLabels = array_values(array_filter(array_map(
                fn ($w) => $wallConstructionLabels[strtolower((string) $w)] ?? ucwords(str_replace('_', ' ', (string) $w)),
                (array) ($ef['wall_construction'] ?? [])
            )));
            $prepFlags = [];
            if (! empty($ef['wall_needs_reinforcement'])) $prepFlags[] = 'Reinforcement required';
            if (! empty($ef['wall_needs_chase_out']))     $prepFlags[] = 'Chase-out required';
            if (! empty($ef['wall_needs_conduit']))       $prepFlags[] = 'Conduit installation required';
            if (! empty($wcLabels) || ! empty($prepFlags)) {
                $section->addText('Wall construction: ', $bf, ['spaceBefore' => 40]);
                $section->addText(! empty($wcLabels) ? $this->t(implode(', ', $wcLabels)) : '—', $vf);
                if (! empty($prepFlags)) {
                    $section->addText('Prep needed: ', $bf, ['spaceBefore' => 20]);
                    $section->addText($this->t(implode(', ', $prepFlags)), $vf, ['spaceAfter' => 40]);
                }
            }

            // ── Brackets ─────────────────────────────────────────────────────
            $brackets = (array) ($ef['brackets_required'] ?? []);
            if (! empty($brackets)) {
                $section->addText('Brackets to source:', $bf, ['spaceBefore' => 40, 'spaceAfter' => 40]);
                foreach ($brackets as $b) {
                    $eq = trim((string) ($b['equipment'] ?? ''));
                    $mod = trim((string) ($b['model'] ?? ''));
                    $pull = ! empty($b['pull_out']) ? ' (pull-out)' : '';
                    $note = trim((string) ($b['notes'] ?? ''));
                    $line = trim($eq . ($mod ? ' — ' . $mod : '') . $pull);
                    if ($note !== '') $line .= ' — ' . $note;
                    if ($line !== '') {
                        $section->addText('•  ' . $this->t($line), $vf, ['spaceBefore' => 20, 'spaceAfter' => 20]);
                    }
                }
            }

            // ── Table info ───────────────────────────────────────────────────
            $ti = (array) ($ef['table_info'] ?? []);
            if (! empty($ti) && (! empty($ti['has_grommets']) || ! empty($ti['notes']))) {
                $tParts = [];
                if (! empty($ti['has_grommets'])) {
                    $tParts[] = ($ti['grommet_count'] ?? '?') . '× ' . trim((string) ($ti['grommet_size'] ?? '')) . ' grommets';
                }
                if (! empty($ti['notes'])) $tParts[] = $ti['notes'];
                $section->addText('Table: ', $bf, ['spaceBefore' => 40]);
                $section->addText($this->t(implode(' — ', array_filter($tParts))), $vf, ['spaceAfter' => 40]);
            }

            // ── Floor box info ───────────────────────────────────────────────
            $fb = (array) ($ef['floor_box_info'] ?? []);
            if (! empty($fb) && (! empty($fb['has_floor_box']) || ! empty($fb['notes']))) {
                $fParts = [];
                if (! empty($fb['has_floor_box'])) {
                    $fParts[] = ($fb['power_outlets'] ?? 0) . ' power, ' . ($fb['data_outlets'] ?? 0) . ' data';
                    if (! empty($fb['cable_space'])) $fParts[] = trim((string) $fb['cable_space']) . ' cable space';
                }
                if (! empty($fb['notes'])) $fParts[] = $fb['notes'];
                $section->addText('Floor box: ', $bf, ['spaceBefore' => 40]);
                $section->addText($this->t(implode(' — ', array_filter($fParts))), $vf, ['spaceAfter' => 40]);
            }
        }
    }

    // =========================================================================
    // SECTION 5 — Risk Assessment (Landscape)
    // =========================================================================

    private function buildRiskAssessment(PhpWord $phpWord, array $data): void
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

        // ── Risk Colour Key (Tier 1 upgrade) ─────────────────────────────────
        $riskKey = $data['risk_colour_key'] ?? [];
        if (! empty($riskKey)) {
            $keyTable = $section->addTable($this->tableStyle());

            $keyHdr = $keyTable->addRow(380);
            $keyHdr->addCell(2000, ['bgColor' => self::TEAL])->addText('Risk Level',  $this->font(9, bold: true, colour: self::WHITE), ['alignment' => Jc::CENTER]);
            $keyHdr->addCell(1500, ['bgColor' => self::TEAL])->addText('Score Range', $this->font(9, bold: true, colour: self::WHITE), ['alignment' => Jc::CENTER]);
            $keyHdr->addCell(5000, ['bgColor' => self::TEAL])->addText('Description', $this->font(9, bold: true, colour: self::WHITE));
            $keyHdr->addCell(6638, ['bgColor' => self::TEAL])->addText('Action',      $this->font(9, bold: true, colour: self::WHITE));

            $keyColours = ['LOW' => self::RISK_GREEN, 'MEDIUM' => self::RISK_AMBER, 'HIGH' => self::RISK_RED];
            foreach ($riskKey as $entry) {
                $level  = (string) ($entry['level'] ?? '');
                $colour = $keyColours[$level] ?? self::WHITE;
                $kr = $keyTable->addRow(380);
                $kr->addCell(2000, ['bgColor' => $colour])->addText($level,                                    $this->font(9, bold: true), ['alignment' => Jc::CENTER]);
                $kr->addCell(1500, ['bgColor' => $colour])->addText($this->t((string) ($entry['range'] ?? '')),       $this->font(9), ['alignment' => Jc::CENTER]);
                $kr->addCell(5000, ['bgColor' => $colour])->addText($this->t((string) ($entry['description'] ?? '')), $this->font(9));
                $kr->addCell(6638, ['bgColor' => $colour])->addText($this->t((string) ($entry['action'] ?? '')),      $this->font(9));
            }

            $section->addTextBreak(1);
        }

        // Column widths (sum = W_LAND = 15138)
        $wRef = self::COL_REF;      // 600
        $wH   = self::COL_HAZARD;   // 2650
        $wC   = self::COL_CONSEQ;   // 2920
        $wP   = self::COL_P;        // 665
        $wS   = self::COL_S;        // 665
        $wR   = self::COL_RISK;     // 800
        $wCt  = self::COL_CONTROLS; // 4708

        $teal      = ['bgColor' => self::TEAL];
        $whiteFont = $this->font(9, bold: true, colour: self::WHITE);
        $bodyFont  = $this->font(8);
        $boldFont  = $this->font(8, bold: true);
        $centred   = ['alignment' => Jc::CENTER];

        $table = $section->addTable($this->tableStyle());

        // ── Single header row ─────────────────────────────────────────────────
        $hRow = $table->addRow(420);
        $hRow->addCell($wRef, $teal)->addText('Ref',             $whiteFont, $centred);
        $hRow->addCell($wH,   $teal)->addText('Hazard',          $whiteFont);
        $hRow->addCell($wC,   $teal)->addText('Persons at Risk', $whiteFont);
        $hRow->addCell($wP,   $teal)->addText('L',               $whiteFont, $centred);
        $hRow->addCell($wS,   $teal)->addText('S',               $whiteFont, $centred);
        $hRow->addCell($wR,   $teal)->addText('Risk',            $whiteFont, $centred);
        $hRow->addCell($wCt,  $teal)->addText('Control Measures',$whiteFont);
        $hRow->addCell($wP,   $teal)->addText('L',               $whiteFont, $centred);
        $hRow->addCell($wS,   $teal)->addText('S',               $whiteFont, $centred);
        $hRow->addCell($wR,   $teal)->addText('Risk',            $whiteFont, $centred);

        // ── Data rows — one per hazard ────────────────────────────────────────
        foreach ($data['hazards'] ?? [] as $idx => $hazard) {
            $rowBg     = ($idx % 2 === 0) ? self::WHITE : self::ROW_ALT;
            $preL      = (int)($hazard['pre_likelihood']  ?? 1);
            $preS      = (int)($hazard['pre_severity']    ?? 1);
            $postL     = (int)($hazard['post_likelihood'] ?? 1);
            $postS     = (int)($hazard['post_severity']   ?? 1);
            $preScore  = $preL  * $preS;
            $postScore = $postL * $postS;
            $refLabel  = 'RA' . str_pad((string)($idx + 1), 2, '0', STR_PAD_LEFT);

            $dr = $table->addRow(0);  // auto height

            // Ref
            $dr->addCell($wRef, ['bgColor' => $rowBg, 'valign' => 'top'])
               ->addText($refLabel, $boldFont, $centred);

            // Hazard name
            $dr->addCell($wH, ['bgColor' => $rowBg, 'valign' => 'top'])
               ->addText($this->t((string)($hazard['hazard'] ?? '')), $boldFont);

            // Persons at risk
            $personsCell = $dr->addCell($wC, ['bgColor' => $rowBg, 'valign' => 'top']);
            $persons = $hazard['persons_at_risk'] ?? [];
            if (is_array($persons) && ! empty($persons)) {
                foreach ($persons as $person) {
                    $personsCell->addText('•  ' . (string)$person, $bodyFont);
                }
            } else {
                $personsCell->addText('', $bodyFont);
            }

            // Pre L / S / Risk (L×S=R format with badge)
            $dr->addCell($wP, ['bgColor' => $rowBg, 'valign' => 'top'])
               ->addText((string)$preL, $bodyFont, $centred);
            $dr->addCell($wS, ['bgColor' => $rowBg, 'valign' => 'top'])
               ->addText((string)$preS, $bodyFont, $centred);
            $preRiskCell = $dr->addCell($wR, ['bgColor' => $this->riskColour($preScore), 'valign' => 'top']);
            $preRiskCell->addText("{$preL}×{$preS}={$preScore}", $boldFont, $centred);
            $preRiskCell->addText($this->riskBadge($preScore), $this->font(7, bold: true, colour: self::DARK_GREY), $centred);

            // Control measures
            $ctrlCell = $dr->addCell($wCt, ['bgColor' => $rowBg, 'valign' => 'top']);
            foreach ($hazard['controls'] ?? [] as $j => $ctrl) {
                $ctrlCell->addText(($j + 1) . '.  ' . $this->t((string)$ctrl), $bodyFont);
            }

            // Post L / S / Risk
            $dr->addCell($wP, ['bgColor' => $rowBg, 'valign' => 'top'])
               ->addText((string)$postL, $bodyFont, $centred);
            $dr->addCell($wS, ['bgColor' => $rowBg, 'valign' => 'top'])
               ->addText((string)$postS, $bodyFont, $centred);
            $postRiskCell = $dr->addCell($wR, ['bgColor' => $this->riskColour($postScore), 'valign' => 'top']);
            $postRiskCell->addText("{$postL}×{$postS}={$postScore}", $boldFont, $centred);
            $postRiskCell->addText($this->riskBadge($postScore), $this->font(7, bold: true, colour: self::DARK_GREY), $centred);
        }
    }

    // =========================================================================
    // SECTION 6 — Method Statement
    // =========================================================================

    private function buildMethodStatement(PhpWord $phpWord, array $data): void
    {
        $section = $phpWord->addSection($this->portraitStyle() + ['breakType' => 'nextPage']);
        $this->attachFooter($section);

        $projectName = $data['project']['name'] ?? 'RAMS Document';
        $hdr = $section->addHeader();
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

        $this->sectionHeading($section, '6. Method Statement');

        $vf       = $this->font(9);
        $bf       = $this->font(9, bold: true);
        $vcWhite  = ['bgColor' => self::WHITE];
        $vcAlt    = ['bgColor' => self::ROW_ALT];

        // ── 6.1 Team Requirements ─────────────────────────────────────────────
        $section->addText('6.1 Team Requirements', $this->font(10, bold: true, colour: self::TEAL), ['spaceBefore' => 80, 'spaceAfter' => 60]);

        $teamTable = $section->addTable($this->tableStyle());
        $this->tealHeader($teamTable, ['Role', 'Qty', 'Requirements'], [2600, 800, 6466]);

        // D4 — Team list with empty-team fallback that synthesises members
        //       from $project personnel string fields. Matches PDF lines
        //       1304-1324 (rams.blade.php) so DOCX renders the same row set.
        $team = is_array($data['team'] ?? null) ? $data['team'] : [];
        $project = $data['project'] ?? [];
        if (empty($team)) {
            $pmName  = trim((string) ($project['project_manager']      ?? ''));
            $leName  = trim((string) ($project['lead_engineer']        ?? ''));
            $progStr = trim((string) ($project['programmer']           ?? ''));
            $addStr  = trim((string) ($project['additional_engineers'] ?? ''));
            if ($pmName !== '') {
                $team[] = ['role' => 'Project Manager', 'name' => $pmName];
            }
            if ($leName !== '') {
                $team[] = ['role' => 'Lead Engineer', 'name' => $leName];
            }
            foreach (preg_split('/[,;]+/', $addStr) ?: [] as $eng) {
                $eng = trim($eng);
                if ($eng !== '') {
                    $team[] = ['role' => 'Engineer', 'name' => $eng];
                }
            }
            if ($progStr !== '') {
                $team[] = ['role' => 'Programmer', 'name' => $progStr];
            }
        }

        if (! empty($team)) {
            // D4 — full $reqMap ported verbatim from rams.blade.php lines 1338-1344.
            $reqMap = [
                'lead av engineer' => 'Qualified AV installation engineer. CSCS/ECS Card required. Competent with display installation, structured cabling, Biamp DSP configuration, and AV commissioning. IPAF/PASMA required if working at height.',
                'lead engineer'    => 'Qualified AV installation engineer. CSCS/ECS Card required. Competent with display installation, structured cabling, and AV commissioning. IPAF/PASMA required if working at height.',
                'av engineer'      => 'Qualified AV installation engineer. CSCS/ECS Card required. Experienced in structured AV cabling, rack builds, and equipment installation.',
                'engineer'         => 'Qualified AV installation engineer. CSCS/ECS Card required. Experienced in structured AV cabling and equipment installation.',
                'project manager'  => 'SMSTS or equivalent. CSCS Card. First Aid at Work certificate. Responsible for site management and client liaison.',
                'programmer'       => 'AV programmer competent in control system configuration and DSP programming. CSCS Card.',
            ];
            // Aggregate by role, collecting names so the table reads
            // "Lead Engineer — Simon Pittaway" instead of bare "Lead Engineer".
            $roleGroups = [];
            foreach ($team as $member) {
                $role = trim((string) ($member['role'] ?? 'Engineer'));
                $name = trim((string) ($member['name'] ?? ''));
                if (! isset($roleGroups[$role])) {
                    $roleGroups[$role] = ['qty' => 0, 'names' => []];
                }
                $roleGroups[$role]['qty']++;
                if ($name !== '') {
                    $roleGroups[$role]['names'][] = $name;
                }
            }
            $i = 0;
            foreach ($roleGroups as $role => $info) {
                $bg     = ($i % 2 === 0) ? $vcWhite : $vcAlt;
                $req    = $reqMap[strtolower($role)] ?? 'Qualified AV installation engineer. CSCS Card.';
                $names  = array_values(array_unique($info['names']));
                $label  = $names
                    ? $role . ' — ' . implode(', ', $names)
                    : $role;
                $row = $teamTable->addRow(400);
                $row->addCell(2600, $bg)->addText($this->t($label),    $vf);
                $row->addCell(800,  $bg)->addText((string)$info['qty'],$vf, ['alignment' => Jc::CENTER]);
                $row->addCell(6466, $bg)->addText($this->t($req),      $vf);
                $i++;
            }
        } else {
            // Last-resort fallback when no team and no project personnel strings.
            $row = $teamTable->addRow(400);
            $row->addCell(2600, $vcWhite)->addText('Lead AV Engineer',                       $vf);
            $row->addCell(800,  $vcWhite)->addText('1',                                      $vf, ['alignment' => Jc::CENTER]);
            $row->addCell(6466, $vcWhite)->addText('Qualified AV installation engineer. CSCS/ECS Card required. Competent with display installation, structured cabling, Biamp DSP configuration, and AV commissioning. IPAF/PASMA required if working at height.', $vf);
        }

        // ── 6.1.1 Site Vehicles & Registrations ───────────────────────────────
        // Engineer-supplied vehicle list (registration numbers required for
        // site security / parking permits at locations like power stations).
        $vehicles = array_values(array_filter(
            array_map('trim', (array) ($data['site_vehicles'] ?? [])),
            fn (string $v) => $v !== '',
        ));
        if (! empty($vehicles)) {
            $section->addTextBreak(1);
            $section->addText('Site Vehicles & Registrations', $this->font(10, bold: true, colour: self::TEAL), ['spaceBefore' => 80, 'spaceAfter' => 60]);
            $vehTable = $section->addTable($this->tableStyle());
            $this->tealHeader($vehTable, ['Vehicle Registration', 'Notes'], [3200, 6666]);
            foreach ($vehicles as $vi => $entry) {
                // Allow "REG123 - Description" style entries — split at first " - ".
                $reg  = $entry;
                $note = '';
                if (str_contains($entry, ' - ')) {
                    [$reg, $note] = explode(' - ', $entry, 2);
                }
                $bg = ($vi % 2 === 0) ? $vcWhite : $vcAlt;
                $tr = $vehTable->addRow(360);
                $tr->addCell(3200, $bg)->addText($this->t(trim($reg)),  $vf);
                $tr->addCell(6666, $bg)->addText($this->t(trim($note)), $vf);
            }
        }

        $section->addTextBreak(1);

        // ── 6.2 Tools & Equipment ─────────────────────────────────────────────
        $section->addText('6.2 Tools & Equipment', $this->font(10, bold: true, colour: self::TEAL), ['spaceBefore' => 80, 'spaceAfter' => 60]);

        $tools = $data['tools_and_equipment'] ?? [];
        foreach ($tools as $tool) {
            $section->addText(
                '•  ' . $this->t((string)$tool),
                $vf,
                ['spaceBefore' => 40, 'spaceAfter' => 40],
            );
        }

        $section->addTextBreak(1);

        // ── 6.3 Personal Protective Equipment (PPE) (Tier 1 upgrade) ─────────
        $ppeMatrix = $data['ppe_matrix'] ?? [];
        if (! empty($ppeMatrix)) {
            $section->addText('6.3 Personal Protective Equipment (PPE)', $this->font(10, bold: true, colour: self::TEAL), ['spaceBefore' => 80, 'spaceAfter' => 60]);

            $ppeTable = $section->addTable($this->tableStyle());
            $this->tealHeader($ppeTable, ['Task', 'PPE Required'], [3200, 6666]);

            foreach ($ppeMatrix as $pi => $ppeRow) {
                $bg  = ($pi % 2 === 0) ? ['bgColor' => self::WHITE] : ['bgColor' => self::ROW_ALT];
                $tr  = $ppeTable->addRow(0);
                $tr->addCell(3200, $bg)->addText($this->t((string) ($ppeRow['task'] ?? '')), $vf);
                $ppeCell = $tr->addCell(6666, $bg);
                foreach (($ppeRow['ppe'] ?? []) as $ppeItem) {
                    $ppeCell->addText('•  ' . $this->t((string) $ppeItem), $vf);
                }
            }

            $section->addTextBreak(1);
        }

        // ── 6.4 Access Equipment (Tier 1 upgrade) ────────────────────────────
        $accessDetail = $data['access_equipment_detail'] ?? [];
        if (! empty($accessDetail)) {
            $section->addText('6.4 Access Equipment', $this->font(10, bold: true, colour: self::TEAL), ['spaceBefore' => 80, 'spaceAfter' => 60]);

            foreach (($accessDetail['items'] ?? []) as $accessItem) {
                $section->addText(
                    '•  ' . $this->t((string) $accessItem),
                    $vf,
                    ['spaceBefore' => 40, 'spaceAfter' => 40],
                );
            }

            $accessReqs = $accessDetail['requirements'] ?? [];
            if (! empty($accessReqs)) {
                $section->addTextBreak(1);
                $section->addText('Requirements:', $bf, ['spaceBefore' => 60, 'spaceAfter' => 40]);
                foreach ($accessReqs as $req) {
                    $section->addText(
                        '•  ' . $this->t((string) $req),
                        $vf,
                        ['spaceBefore' => 40, 'spaceAfter' => 40],
                    );
                }
            }

            $section->addTextBreak(1);
        }

        // ── Subsection numbering: 6.5 when Tier 1 sections present, 6.3 otherwise
        $preInstallNum = ! empty($ppeMatrix) ? '6.5' : '6.3';

        // ── Pre-Installation Requirements / Client Responsibilities ───────────
        $section->addText($preInstallNum . ' Pre-Installation Requirements / Client Responsibilities', $this->font(10, bold: true, colour: self::TEAL), ['spaceBefore' => 80, 'spaceAfter' => 60]);

        $resp = $data['client_responsibilities'] ?? [];
        foreach ($resp as $item) {
            $section->addText(
                '•  ' . $this->t((string)$item),
                $vf,
                ['spaceBefore' => 40, 'spaceAfter' => 40],
            );
        }

        $section->addTextBreak(1);

        // ── Method of Works ───────────────────────────────────────────────────
        $methodNum = ! empty($ppeMatrix) ? '6.6' : '6.4';
        $section->addText($methodNum . ' Method of Works', $this->font(10, bold: true, colour: self::TEAL), ['spaceBefore' => 80, 'spaceAfter' => 60]);

        $phases = $data['method_statement']['phases'] ?? [];
        foreach ($phases as $i => $phase) {
            $rawTitle = trim((string)($phase['title'] ?? ''));

            // Strip any leading "N. " or "N — " prefix the AI may have added, then
            // rebuild as "Step N — Title" so format is always consistent.
            $cleanTitle = preg_replace('/^\d+[\.\-–—\s]+/', '', $rawTitle);
            $stepTitle  = 'Step ' . ($i + 1) . ' — ' . $cleanTitle;

            $section->addText(
                $this->t($stepTitle),
                $this->font(10, bold: true, colour: self::TEAL),
                ['spaceBefore' => 100, 'spaceAfter' => 60],
            );

            foreach (($phase['steps'] ?? []) as $step) {
                $section->addText(
                    '    •  ' . $this->t((string)$step),
                    $vf,
                    ['spaceBefore' => 40, 'spaceAfter' => 40],
                );
            }

            // Risk cross-reference (Tier 1 upgrade)
            $risksLabel = trim((string) ($phase['associated_risks_label'] ?? ''));
            if ($risksLabel !== '') {
                $section->addText(
                    $this->t($risksLabel),
                    $this->font(8, bold: true, italic: true, colour: self::MID_GREY),
                    ['spaceBefore' => 40, 'spaceAfter' => 80],
                );
            }
        }
    }

    // =========================================================================
    // SECTION 7 — Emergency Procedures
    // =========================================================================

    private function buildEmergencyProcedures(PhpWord $phpWord, array $data, array $formData): void
    {
        $section = $phpWord->addSection($this->portraitStyle() + ['breakType' => 'nextPage']);
        $this->attachFooter($section);

        $project   = $data['project'] ?? [];
        $compShort = config('rams.company_short', '21CAV');
        $compPhone = config('rams.company_phone', '');

        $this->sectionHeading($section, '7. Emergency Procedures');

        $vf      = $this->font(9);
        $bf      = $this->font(9, bold: true);
        $bfWhite = $this->font(9, bold: true, colour: self::WHITE);
        $teal    = ['bgColor' => self::TEAL];
        $white   = ['bgColor' => self::WHITE];
        $alt     = ['bgColor' => self::ROW_ALT];
        $colW    = (int)(self::W_PORT / 2); // 4933

        // ── 7.1 Emergency Contact Numbers ────────────────────────────────────
        $section->addText('7.1 Emergency Contact Numbers', $this->font(10, bold: true, colour: self::TEAL), ['spaceBefore' => 80, 'spaceAfter' => 60]);

        $contactTable = $section->addTable($this->tableStyle());
        // Header row
        $hRow = $contactTable->addRow(380);
        $hRow->addCell($colW, $teal)->addText('Contact',      $bfWhite);
        $hRow->addCell($colW, $teal)->addText('Number',       $bfWhite);

        // D5 — extend the resolution chain to match PDF line 1835.
        //       Falls back through client_contact (name + email concat),
        //       site_contact, form_data.site_contact, and finally the
        //       literal "TBC at site induction" placeholder.
        $clientContactName  = (string) ($project['client_contact_name']  ?? '');
        $clientContactEmail = (string) ($project['client_contact_email'] ?? '');
        $clientContact      = trim($clientContactName . ($clientContactEmail !== '' ? ' | ' . $clientContactEmail : ''));
        $siteContactValue   = (string) ($project['site_contact'] ?? $formData['site_contact'] ?? '');
        $siteContact = $clientContact !== ''
            ? $clientContact
            : ($siteContactValue !== '' ? $siteContactValue : 'TBC at site induction');

        $contactRows = [
            ['Emergency Services (Fire, Police, Ambulance)', '999'],
            ['Non-Emergency Police',                          '101'],
            ['Site Contact',                                  $siteContact],
            [$compShort . ' Operations',                      $compPhone],
        ];
        foreach ($contactRows as $i => [$contact, $number]) {
            $bg  = ($i % 2 === 0) ? $white : $alt;
            $row = $contactTable->addRow(400);
            $row->addCell($colW, $bg)->addText($this->t($contact), $vf);
            $row->addCell($colW, $bg)->addText($this->t($number),  $vf);
        }

        $section->addTextBreak(1);

        // ── 7.2 Accident / Injury ─────────────────────────────────────────────
        $section->addText('7.2 Accident / Injury', $this->font(10, bold: true, colour: self::TEAL), ['spaceBefore' => 80, 'spaceAfter' => 60]);

        $accidentBullets = [
            'Stop all work. Call 999 if life-threatening.',
            'Administer first aid if qualified.',
            'Do not move person with suspected spinal injury.',
            'Contact ' . $compShort . ' operations.',
            'Preserve the scene.',
            'Complete incident report within 24 hours.',
            'Report to client site manager.',
            'RIDDOR reportable incidents must be reported within required timescales.',
        ];
        foreach ($accidentBullets as $bullet) {
            $section->addText('•  ' . $bullet, $vf, ['spaceBefore' => 40, 'spaceAfter' => 40]);
        }

        $section->addTextBreak(1);

        // ── 7.3 Fire Procedure ────────────────────────────────────────────────
        $section->addText('7.3 Fire Procedure', $this->font(10, bold: true, colour: self::TEAL), ['spaceBefore' => 80, 'spaceAfter' => 60]);

        $fireBullets = [
            'Raise the alarm using nearest fire alarm call point.',
            'Evacuate by nearest fire exit, do not use lifts.',
            'Proceed to designated assembly point.',
            'Do not re-enter until instructed.',
            'Inform site manager that ' . $compShort . ' engineers are on-site.',
        ];
        foreach ($fireBullets as $bullet) {
            $section->addText('•  ' . $bullet, $vf, ['spaceBefore' => 40, 'spaceAfter' => 40]);
        }
    }

    // =========================================================================
    // SECTION 8 — Document Sign-Off
    // =========================================================================

    private function buildDocumentSignOff(PhpWord $phpWord, array $data): void
    {
        $section = $phpWord->addSection($this->portraitStyle() + ['breakType' => 'nextPage']);
        $this->attachFooter($section);

        $this->sectionHeading($section, '8. Document Sign-Off');

        $companyName = config('rams.company_name', '21st Century AV Ltd');
        $colW        = (int)(self::W_PORT / 2); // 4933

        $table   = $section->addTable($this->tableStyle());
        $teal    = ['bgColor' => self::TEAL];
        $alt     = ['bgColor' => self::ROW_ALT];
        $white   = ['bgColor' => self::WHITE];
        $bfWhite = $this->font(10, bold: true, colour: self::WHITE);
        $labelF  = $this->font(9, bold: true);
        $vf      = $this->font(9);

        // Header row
        $hRow = $table->addRow(420);
        $hRow->addCell($colW, $teal)->addText($companyName,       $bfWhite, ['alignment' => Jc::CENTER]);
        $hRow->addCell($colW, $teal)->addText('Client Acceptance', $bfWhite, ['alignment' => Jc::CENTER]);

        // Data rows: Name, Position, Date, Signature
        $signOffRows = ['Name', 'Position', 'Date', 'Signature'];
        foreach ($signOffRows as $i => $label) {
            $height = $label === 'Signature' ? 900 : 500;
            $bg     = ($i % 2 === 0) ? $alt : $white;
            $row    = $table->addRow($height);
            $row->addCell($colW, $bg)->addText($label, $labelF);
            $row->addCell($colW, $white)->addText('', $vf);
        }
    }

    // =========================================================================
    // CDM DUTY HOLDERS (Tier 1 upgrade)
    // =========================================================================

    /**
     * Render CDM 2015 duty holders section.
     * Only renders when cdm_duty_holders data is present (from RamsComplianceUpgradeService).
     */
    private function buildCdmSection(PhpWord $phpWord, array $data): void
    {
        $cdm = $data['cdm_duty_holders'] ?? [];
        if (empty($cdm)) {
            return; // No CDM data — skip section entirely (backwards compatible)
        }

        $section = $phpWord->addSection($this->portraitStyle() + ['breakType' => 'nextPage']);
        $this->attachFooter($section);

        $this->sectionHeading($section, 'CDM 2015 — Duty Holders');

        $section->addText(
            $this->t((string) ($cdm['cdm_regulation'] ?? 'Construction (Design and Management) Regulations 2015')),
            $this->font(9, italic: true),
            ['spaceBefore' => 60, 'spaceAfter' => 80],
        );

        $table   = $section->addTable($this->tableStyle());
        $altCell = ['bgColor' => self::ROW_ALT];
        $whtCell = ['bgColor' => self::WHITE];
        $lf      = $this->font(9, bold: true);
        $vf      = $this->font(9);

        $rows = [
            ['Client',               $cdm['client']               ?? '[Client Name]'],
            ['Principal Designer',   $cdm['principal_designer']   ?? '[To be confirmed]'],
            ['Principal Contractor', $cdm['principal_contractor'] ?? '[To be confirmed]'],
            ['Contractor',           $cdm['contractor']           ?? '21st Century AV Ltd'],
            ['Subcontractor',        $cdm['subcontractor']        ?? '21st Century AV Ltd'],
            ['Project Manager',      $cdm['project_manager']      ?? '[To be confirmed]'],
            ['Site Supervisor',      $cdm['site_supervisor']      ?? '[To be confirmed]'],
        ];

        foreach ($rows as $i => [$label, $value]) {
            $bg  = ($i % 2 === 0) ? $altCell : $whtCell;
            $row = $table->addRow(400);
            $row->addCell(3400, $bg)->addText($label,           $lf);
            $row->addCell(6466, $bg)->addText($this->t($value), $vf);
        }

        $notification = trim((string) ($cdm['notification'] ?? ''));
        if ($notification !== '') {
            $section->addTextBreak(1);
            $section->addText(
                $this->t($notification),
                $this->font(8, italic: true, colour: self::MID_GREY),
                ['spaceBefore' => 60],
            );
        }
    }

    // =========================================================================
    // TEMPLATE LOADER (kept for potential future use)
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
                'spaceBefore'       => 120,
                'spaceAfter'        => 80,
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

    /** Return a SHORT risk badge label for the given score. */
    private function riskBadge(int $score): string
    {
        return match (true) {
            $score >= 10 => 'HIGH',
            $score >= 7  => 'MED',
            default      => 'LOW',
        };
    }

    /** Build a font style array. */
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
