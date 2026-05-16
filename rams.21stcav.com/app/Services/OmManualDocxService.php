<?php

namespace App\Services;

use App\Models\OmManual;
use App\Services\DocumentArtifactStorage;
use App\Services\DocumentTemplateService;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Font;
use PhpOffice\PhpWord\TemplateProcessor;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Builds the O&M Manual .docx file from the generated_data JSON.
 *
 * Document structure:
 *   Cover page  (template if available, else programmatic)
 *   1.  Introduction (scope table + contacts table)
 *   2–N System Operation (per room/area)
 *   N+1 Routine Maintenance
 *   N+2 Fault Finding
 *   N+3 Network & IP Configuration
 *   N+4 Manufacturer Support & Warranty
 *   N+5 Installed Asset Register
 *   N+6 Document Control
 */
class OmManualDocxService
{
    public function __construct(
        private readonly DocumentTemplateService $templates,
        private readonly DocumentArtifactStorage $artifacts = new DocumentArtifactStorage(),
    ) {}

    // Brand colours
    private const TEAL  = '007B8A';
    private const WHITE = 'FFFFFF';
    private const DARK  = '1A1A1A';
    private const GREY  = 'F4F6F8';
    private const MID   = 'CCCCCC';

    // ── Public entry point ───────────────────────────────────────────────────

    /**
     * Build the .docx, save it to storage/app/om-manuals/, update the model
     * filename column and return the absolute path.
     */
    public function build(array $data, OmManual $manual): string
    {
        // ── Runtime marker: prove patched class is executing ────────────────
        Log::info('OmManualDocxService::build start', [
            'om_manual_id'      => $manual->id,
            'class_file'        => __FILE__,
            'class_modified_at' => date('Y-m-d H:i:s', filemtime(__FILE__)),
            'escaping_patch'    => true,
        ]);

        // Ensure PhpWord escapes &, <, > in text content (off by default).
        Settings::setOutputEscapingEnabled(true);

        $project = $data['project'] ?? [];

        if ($this->templates->exists('om-manual')) {
            $phpWord = $this->loadTemplate('om-manual', [
                'project_ref'     => $project['ref']    ?? ($manual->project_ref  ?? '—'),
                'project_name'    => $project['name']   ?? ($manual->project_name ?? '—'),
                'client_name'     => $project['client'] ?? ($manual->client_name  ?? '—'),
                'site_address'    => $project['site']   ?? ($manual->site_address ?? '—'),
                'date'            => now()->format('F Y'),
                'status'          => 'For Review',
                'equipment_table' => '',
            ]);
            $this->configureStyles($phpWord);
        } else {
            $phpWord = new PhpWord();
            $this->configureStyles($phpWord);

            // ── Cover page (programmatic fallback) ───────────────────────────
            $cover = $phpWord->addSection($this->sectionProps(true));
            $this->buildCover($cover, $data, $manual);
        }

        // ── Section 1: Introduction ─────────────────────────────────────────
        $s = $phpWord->addSection($this->sectionProps());
        $this->addHeading1($s, '1.  Introduction', 1);
        $this->addParagraph($s,
            'This Operation and Maintenance Manual has been prepared by 21st Century AV Ltd for '
            . $this->t($data['project']['client'] ?? 'the Client')
            . ' in relation to the AV installation at '
            . $this->t($data['project']['site'] ?? 'the above site')
            . ' (Project Reference ' . $this->t($data['project']['ref'] ?? '') . ').'
        );
        $this->addParagraph($s,
            'The manual covers the operation of all installed AV systems, day-to-day user guidance, '
            . 'routine maintenance requirements, fault-finding procedures, and contact information for '
            . 'technical support. It should be retained on site or with the facilities management team '
            . 'and made available to all relevant staff.'
        );

        // 1.1 Scope of installation
        $this->addHeading2($s, '1.1  Scope of Installation');
        $this->addParagraph($s,
            '21st Century AV Ltd supplied and installed AV systems in the following areas:'
        );
        $this->buildScopeTable($s, $data['rooms_summary'] ?? []);

        // 1.2 Existing equipment interfaced with
        $this->addHeading2($s, '1.2  Existing Equipment Interfaced With');
        $this->buildExistingEquipmentSection($s, $data['existing_reuse'] ?? []);

        // 1.3 Document contacts
        $this->addHeading2($s, '1.3  Document Contacts');
        $this->buildContactsTable($s, $data['project'] ?? []);

        // 1.4 Room Overviews — F-OM-02 parity fix (audit 2026-05-17).
        //
        // The PDF blade renders a "Room Overviews" sub-block inside §1
        // Executive Summary (resources/views/pdf/om-manual.blade.php:639-647)
        // built from AI-generated $data['system_overviews'] with a fallback
        // to each room's static narrative (lines 301-318). The DOCX was
        // rendering ZERO per-room narrative — users downloading the DOCX
        // saw a markedly worse document than the PDF for the same data.
        //
        // Build $narrativesByRoom mirroring the PDF's preference chain:
        //   1. $data['system_overviews'][*] {room_name, narrative}
        //      (AI-generated by buildSystemOverviewNarratives, primary)
        //   2. $data['rooms_summary'][*]['narrative'] (deterministic mirror
        //      from package room_overviews, fallback)
        //   3. $data['rooms_summary'][*]['description'] (legacy template
        //      back-compat, last resort)
        $this->buildRoomOverviewsSection(
            $s,
            $data['system_overviews'] ?? [],
            $data['rooms_summary']    ?? [],
        );

        // ── Sections 2–N: System Operation per room ─────────────────────────
        $sectionNum = 2;
        foreach ($data['operation_sections'] ?? [] as $roomSection) {
            if (! is_array($roomSection)) {
                continue;
            }
            $s = $phpWord->addSection($this->sectionProps());
            $this->buildOperationSection($s, $roomSection, $sectionNum);
            $sectionNum++;
        }

        // ── Maintenance ─────────────────────────────────────────────────────
        $s = $phpWord->addSection($this->sectionProps());
        $this->buildMaintenanceSection($s, $data['maintenance_schedule'] ?? [], $sectionNum++);

        // ── Fault Finding ────────────────────────────────────────────────────
        $s = $phpWord->addSection($this->sectionProps());
        $this->buildFaultFindingSection($s, $data['fault_finding'] ?? [], $sectionNum++);

        // ── Network & IP ─────────────────────────────────────────────────────
        $s = $phpWord->addSection($this->sectionProps());
        $this->buildNetworkSection($s, $data, $sectionNum++);

        // ── Manufacturer Support ─────────────────────────────────────────────
        $s = $phpWord->addSection($this->sectionProps());
        $this->buildManufacturerSection($s, $data, $sectionNum++);

        // ── Asset Register ───────────────────────────────────────────────────
        $s = $phpWord->addSection($this->sectionProps());
        $this->buildAssetRegisterSection($s, $data['rooms_summary'] ?? [], $sectionNum++);

        // ── Document Control ─────────────────────────────────────────────────
        $s = $phpWord->addSection($this->sectionProps());
        $this->buildDocumentControlSection($s, $sectionNum, $manual);

        // ── DRAW-26 — Phase 17 v1.3: Drawings section (PNG embed for Word compat) ──
        // Uses DrawingExportRendererService::ensurePngForHandover() which is idempotent
        // per-drawing-version. PhpWord's addImage handles raster cleanly; SVG support
        // in PhpWord 1.4+ exists but is inconsistent across Word/Word Online/LibreOffice
        // (per ARCHITECTURE.md §6.3). PNG is the safe path; switch to SVG embed if
        // a future Word release firms up SVG handling.
        //
        // Blocker 3 fix: opens a FRESH PhpWord section (does NOT reuse $s from the
        // Document Control block). Variable name $drawingsSection makes the diff
        // self-documenting and prevents append-after-save issues. Project access
        // via $manual->project — relation exists on App\Models\OmManual line 59
        // (verified during plan revision).
        $drawings = $manual->project?->drawings()
            ->where('status', \App\Models\ProjectDrawing::STATUS_READY)
            ->whereNull('superseded_by_id')
            ->orderBy('kind')
            ->orderBy('site_survey_room_id')
            ->get() ?? collect();

        if ($drawings->isNotEmpty()) {
            $sectionNum++;
            $drawingsSection = $phpWord->addSection($this->sectionProps());
            $this->addHeading1($drawingsSection, $sectionNum.'.  Drawings', $sectionNum);

            $renderer = app(\App\Services\Drawings\DrawingExportRendererService::class);

            foreach ($drawings as $drawing) {
                $pngPath = null;
                try {
                    $pngPath = $renderer->ensurePngForHandover($drawing);
                } catch (\Throwable $e) {
                    Log::warning(
                        'OmManualDocxService: drawing PNG render failed (skipping)',
                        [
                            'drawing_id' => $drawing->id,
                            'om_manual_id' => $manual->id,
                            'error' => $e->getMessage(),
                        ]
                    );

                    continue;
                }
                if (! $pngPath || ! is_file($pngPath)) {
                    continue;
                }

                $drawingsSection->addText(
                    $this->t($drawing->kindLabel().' — '.($drawing->room?->name ?? 'Whole project').' ('.$drawing->revisionLabel().')'),
                    ['name' => 'Arial', 'size' => 12, 'bold' => true, 'color' => self::TEAL],
                    ['spaceBefore' => 120, 'spaceAfter' => 80, 'alignment' => Jc::CENTER]
                );
                $drawingsSection->addImage($pngPath, [
                    'width' => 500,            // points; fits A4 portrait with margins
                    'height' => null,           // null preserves aspect
                    'wrappingStyle' => 'square',
                    'alignment' => Jc::CENTER,
                ]);
                $drawingsSection->addPageBreak();   // one drawing per page (DRAW-26)
            }
        }

        // ── Save ──────────────────────────────────────────────────────────────
        $filename = 'OM_'
            . preg_replace('/[^A-Za-z0-9_\-]/', '_', $manual->project_ref ?? 'manual')
            . '_' . now()->format('YmdHis') . '.docx';

        $filePath = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_OM, $filename);

        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($filePath);

        // ── Post-build XML validation ────────────────────────────────────────
        $this->validateDocx($filePath);

        $manual->update(['filename' => $filename]);

        return $filePath;
    }

    // ── Cover page ───────────────────────────────────────────────────────────

    private function buildCover(Section $s, array $data, OmManual $manual): void
    {
        $project = $data['project'] ?? [];

        // Vertical spacer
        for ($i = 0; $i < 4; $i++) {
            $s->addTextBreak();
        }

        // Company name
        $s->addText(
            '21st Century AV Ltd',
            ['name' => 'Arial', 'size' => 28, 'bold' => true, 'color' => self::TEAL],
            ['alignment' => Jc::CENTER]
        );
        $s->addTextBreak();

        // Document title
        $s->addText(
            'Operation & Maintenance Manual',
            ['name' => 'Arial', 'size' => 22, 'bold' => true, 'color' => self::DARK],
            ['alignment' => Jc::CENTER]
        );
        $s->addTextBreak();

        // Project name
        $s->addText(
            $this->t($project['name'] ?? $manual->project_name ?? ''),
            ['name' => 'Arial', 'size' => 18, 'bold' => true, 'color' => self::TEAL],
            ['alignment' => Jc::CENTER]
        );

        // Client name
        $s->addText(
            $this->t($project['client'] ?? $manual->client_name ?? ''),
            ['name' => 'Arial', 'size' => 16, 'color' => self::DARK],
            ['alignment' => Jc::CENTER]
        );

        // Site address
        $s->addText(
            $this->t($project['site'] ?? $manual->site_address ?? ''),
            ['name' => 'Arial', 'size' => 12, 'color' => '555555'],
            ['alignment' => Jc::CENTER]
        );
        $s->addText(
            'Project Reference: ' . $this->t($project['ref'] ?? $manual->project_ref ?? ''),
            ['name' => 'Arial', 'size' => 11, 'color' => '555555'],
            ['alignment' => Jc::CENTER]
        );

        for ($i = 0; $i < 3; $i++) {
            $s->addTextBreak();
        }

        // Info table (Document Type, Client, Site, Reference, Prepared by, Date, Revision, Status)
        $infoRows = [
            ['Document Type:',   'Operation & Maintenance Manual'],
            ['Client:',          $this->t($project['client']  ?? $manual->client_name   ?? '—')],
            ['Site:',            $this->t($project['site']    ?? $manual->site_address  ?? '—')],
            ['Project Reference:', $this->t($project['ref']   ?? $manual->project_ref   ?? '—')],
            ['Prepared by:',     '21st Century AV Ltd'],
            ['Date:',            now()->format('F Y')],
            ['Revision:',        '01 – Initial Issue'],
            ['Status:',          $manual->status === 'final' ? 'Final' : 'For Client Use'],
        ];

        $table = $s->addTable(['borderColor' => self::MID, 'borderSize' => 6]);
        foreach ($infoRows as [$label, $value]) {
            $row = $table->addRow();
            $cell = $row->addCell(2500, ['bgColor' => 'E0F4F6']);
            $cell->addText($label, ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => self::TEAL]);
            $cell = $row->addCell(6000);
            $cell->addText($this->t($value), ['name' => 'Arial', 'size' => 10, 'color' => self::DARK]);
        }

        $s->addTextBreak(3);

        // Company footer on cover
        $s->addText(
            'Unit 4 Thames Court  |  2 Richfield Avenue  |  Reading  |  Berkshire  |  RG4 8EQ',
            ['name' => 'Arial', 'size' => 9, 'color' => '888888'],
            ['alignment' => Jc::CENTER]
        );
        $s->addText(
            'Tel: 01189 977 771  |  alison@21stcenturyav.com',
            ['name' => 'Arial', 'size' => 9, 'color' => '888888'],
            ['alignment' => Jc::CENTER]
        );
    }

    // ── Scope and contact tables ─────────────────────────────────────────────

    private function buildScopeTable(Section $s, array $rooms): void
    {
        if (empty($rooms)) {
            $this->addParagraph($s, 'No room data available.');
            return;
        }

        foreach ($rooms as $room) {
            if (! is_array($room)) {
                continue;
            }
            $roomName = $this->t((string) ($room['name'] ?? 'Room'));
            $drawing  = $this->t((string) ($room['drawing_ref'] ?? ''));

            $title = $roomName;
            if ($drawing !== '') {
                $title .= ' (Drg: ' . $drawing . ')';
            }
            $this->addHeading2($s, $title);

            $table = $s->addTable(['borderColor' => self::MID, 'borderSize' => 6]);
            $header = $table->addRow();
            foreach (['Qty', 'Description', 'Model', 'Part No.'] as $i => $heading) {
                $widths = [800, 3600, 2600, 1500];
                $cell = $header->addCell($widths[$i], ['bgColor' => self::TEAL]);
                $cell->addText($heading, ['name' => 'Arial', 'size' => 9, 'bold' => true, 'color' => self::WHITE]);
            }

            foreach ($room['equipment'] ?? [] as $eq) {
                if (! is_array($eq)) {
                    continue;
                }
                $row = $table->addRow();
                $row->addCell(800)->addText((string) ($eq['qty'] ?? 1), ['name' => 'Arial', 'size' => 9], ['alignment' => Jc::CENTER]);
                $row->addCell(3600)->addText($this->t($eq['description'] ?? '—'), ['name' => 'Arial', 'size' => 9]);
                $row->addCell(2600)->addText($this->t($eq['model'] ?? '—'), ['name' => 'Arial', 'size' => 9]);
                $row->addCell(1500)->addText($this->t($eq['part_no'] ?? ''), ['name' => 'Arial', 'size' => 9]);
            }

            $s->addTextBreak(1);
        }
    }

    /**
     * Section 1.4 — Room Overviews.
     *
     * F-OM-02 parity port from resources/views/pdf/om-manual.blade.php:301-318
     * + 639-647. Builds a per-room narrative block under §1 Introduction,
     * matching the PDF's "Room Overviews" sub-section.
     *
     * Preference chain (room name → narrative):
     *   1. system_overviews[].narrative — AI-generated, keyed by room_name.
     *   2. rooms_summary[].narrative    — deterministic mirror sourced from
     *                                     ProjectPackage.room_overviews via
     *                                     OmManualGeneratorService.
     *   3. rooms_summary[].description  — legacy template back-compat.
     *
     * Silent no-op when no narrative is available for any room — the PDF
     * wraps the whole block in `@if (! empty($narrativesByRoom))`, so we
     * skip the heading entirely in that case to avoid an empty section.
     *
     * @param  array<int, array<string, mixed>>  $systemOverviews
     * @param  array<int, array<string, mixed>>  $roomsSummary
     */
    private function buildRoomOverviewsSection(
        Section $s,
        array $systemOverviews,
        array $roomsSummary,
    ): void {
        $narrativesByRoom = [];

        // Pass 1 — AI-generated overviews (primary, mirrors PDF lines 304-311).
        foreach ($systemOverviews as $ov) {
            if (! is_array($ov)) {
                continue;
            }
            $roomName  = trim((string) ($ov['room_name'] ?? ''));
            $narrative = trim((string) ($ov['narrative'] ?? ''));
            if ($roomName !== '' && $narrative !== '') {
                $narrativesByRoom[$roomName] = $narrative;
            }
        }

        // Pass 2 — rooms_summary fallback (narrative → description), mirrors
        // PDF lines 312-318. `empty()` check prevents overwriting an AI
        // overview with the deterministic mirror — the AI version wins.
        foreach ($roomsSummary as $room) {
            if (! is_array($room)) {
                continue;
            }
            $roomName  = trim((string) ($room['name'] ?? ''));
            $narrative = trim((string) ($room['narrative'] ?? $room['description'] ?? ''));
            if ($roomName !== '' && $narrative !== '' && empty($narrativesByRoom[$roomName])) {
                $narrativesByRoom[$roomName] = $narrative;
            }
        }

        if (empty($narrativesByRoom)) {
            return;
        }

        $this->addHeading2($s, '1.4  Room Overviews');
        foreach ($narrativesByRoom as $roomName => $narrative) {
            // Room name as a sub-heading (mirrors PDF .room-title at line 643).
            $s->addText(
                $this->t($roomName),
                ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => self::TEAL],
                ['spaceBefore' => 120, 'spaceAfter' => 40]
            );
            $this->addParagraph($s, $narrative);
        }
    }

    private function buildContactsTable(Section $s, array $project): void
    {
        $table = $s->addTable(['borderColor' => self::MID, 'borderSize' => 6]);

        $header = $table->addRow();
        foreach (['Role', 'Name / Organisation', 'Contact'] as $i => $heading) {
            $widths = [2000, 3500, 3000];
            $cell = $header->addCell($widths[$i], ['bgColor' => self::TEAL]);
            $cell->addText($heading, ['name' => 'Arial', 'size' => 9, 'bold' => true, 'color' => self::WHITE]);
        }

        $contacts = [
            ['AV Installer',       '21st Century AV Ltd',                               'alison@21stcenturyav.com  |  01189 977 771'],
            ['Client',             $this->t($project['client'] ?? '—'),                 '—'],
            ['Facilities Management', 'TBC',                                            'TBC'],
            ['Client IT / Network', $this->t(($project['client'] ?? '') . ' IT'),       'TBC'],
        ];

        foreach ($contacts as [$role, $name, $contact]) {
            $row = $table->addRow();
            $row->addCell(2000)->addText($this->t($role),    ['name' => 'Arial', 'size' => 9, 'bold' => true]);
            $row->addCell(3500)->addText($this->t($name),    ['name' => 'Arial', 'size' => 9]);
            $row->addCell(3000)->addText($this->t($contact), ['name' => 'Arial', 'size' => 9]);
        }
    }

    private function buildExistingEquipmentSection(Section $s, array $existing): void
    {
        if (empty($existing)) {
            $this->addParagraph($s, 'No existing client-owned equipment has been identified as interfaced in this scope.');
            return;
        }

        $this->addParagraph($s,
            'The following client-owned or pre-existing equipment is interfaced with the new AV installation. '
            . 'These items are not part of the newly installed asset register.'
        );

        $table = $s->addTable(['borderColor' => self::MID, 'borderSize' => 6]);
        $header = $table->addRow();
        foreach (['Room', 'Qty', 'Existing Equipment', 'Model / Part No.'] as $i => $heading) {
            $widths = [1700, 700, 4200, 2200];
            $cell = $header->addCell($widths[$i], ['bgColor' => self::TEAL]);
            $cell->addText($heading, ['name' => 'Arial', 'size' => 9, 'bold' => true, 'color' => self::WHITE]);
        }

        foreach ($existing as $rowData) {
            if (! is_array($rowData)) {
                continue;
            }
            $row = $table->addRow();
            $small = ['name' => 'Arial', 'size' => 9];
            $room = $this->t($rowData['room'] ?? 'General');
            $desc = $this->t($rowData['description'] ?? '');
            $qty  = (string) ($rowData['qty'] ?? 1);
            $modelPart = trim($this->t($rowData['model'] ?? '') . ' ' . $this->t($rowData['part_no'] ?? ''));

            $row->addCell(1700)->addText($room, $small);
            $row->addCell(700)->addText($qty, $small, ['alignment' => Jc::CENTER]);
            $row->addCell(4200)->addText($desc, $small);
            $row->addCell(2200)->addText($modelPart !== '' ? $modelPart : '—', $small);
        }
    }

    // ── System Operation (per room) ──────────────────────────────────────────

    private function buildOperationSection(Section $s, array $roomSection, int $num): void
    {
        $title = $num . '.  System Operation — ' . $this->t($roomSection['room_name'] ?? 'Unknown Room');
        if (! empty($roomSection['drawing_ref'])) {
            $title .= ' (' . $this->t($roomSection['drawing_ref']) . ')';
        }

        $this->addHeading1($s, $title, $num);

        foreach ($roomSection['subsections'] ?? [] as $sub) {
            if (! is_array($sub)) {
                continue;
            }
            $this->addHeading2($s, $this->t($sub['title'] ?? ''));

            foreach ($sub['steps'] ?? [] as $i => $step) {
                $this->addNumberedStep($s, ($i + 1) . '.  ' . $this->t((string) $step));
            }

            foreach ($sub['notes'] ?? [] as $note) {
                if (! is_array($note)) {
                    continue;
                }
                $this->addCallout($s, $note['type'] ?? 'info', $this->t($note['text'] ?? ''));
            }
        }
    }

    // ── Routine Maintenance ──────────────────────────────────────────────────

    private function buildMaintenanceSection(Section $s, array $schedule, int $num): void
    {
        $this->addHeading1($s, $num . '.  Routine Maintenance', $num);
        $this->addParagraph($s,
            'The installed AV systems are largely maintenance-free under normal use. '
            . 'The following routine checks and tasks are recommended to keep systems performing correctly.'
        );

        $this->addHeading2($s, $num . '.1  Recommended Maintenance Schedule');

        if (empty($schedule)) {
            $this->addParagraph($s, 'See individual manufacturer documentation for maintenance schedules.');
            return;
        }

        $table = $s->addTable(['borderColor' => self::MID, 'borderSize' => 6]);

        $header = $table->addRow();
        foreach (['Frequency', 'Item', 'Task', 'Responsible'] as $i => $heading) {
            $widths = [1200, 2200, 4200, 1100];
            $cell = $header->addCell($widths[$i], ['bgColor' => self::TEAL]);
            $cell->addText($heading, ['name' => 'Arial', 'size' => 9, 'bold' => true, 'color' => self::WHITE]);
        }

        foreach ($schedule as $item) {
            if (! is_array($item)) {
                continue;
            }
            $row = $table->addRow();
            $row->addCell(1200)->addText($this->t($item['frequency'] ?? ''), ['name' => 'Arial', 'size' => 9, 'bold' => true]);
            $row->addCell(2200)->addText($this->t($item['item'] ?? ''), ['name' => 'Arial', 'size' => 9]);
            $row->addCell(4200)->addText($this->t($item['task'] ?? ''), ['name' => 'Arial', 'size' => 9]);
            $row->addCell(1100)->addText($this->t($item['responsible_party'] ?? 'AV Support'), ['name' => 'Arial', 'size' => 9]);
        }
    }

    // ── Fault Finding ────────────────────────────────────────────────────────

    private function buildFaultFindingSection(Section $s, array $faults, int $num): void
    {
        $this->addHeading1($s, $num . '.  Fault Finding & First-Line Support', $num);
        $this->addParagraph($s,
            'The following table covers the most common issues staff may encounter and the '
            . 'recommended first-line steps before contacting 21st Century AV for support.'
        );

        if (empty($faults)) {
            $this->addParagraph($s, 'For all faults contact 21st Century AV on 01189 977 771.');
            return;
        }

        $table = $s->addTable(['borderColor' => self::MID, 'borderSize' => 6]);

        $header = $table->addRow();
        foreach (['Symptom', 'Likely Cause', 'Action'] as $i => $heading) {
            $widths = [2000, 2000, 4500];
            $cell = $header->addCell($widths[$i], ['bgColor' => self::TEAL]);
            $cell->addText($heading, ['name' => 'Arial', 'size' => 9, 'bold' => true, 'color' => self::WHITE]);
        }

        foreach ($faults as $fault) {
            if (! is_array($fault)) {
                continue;
            }

            $row = $table->addRow();
            $row->addCell(2000)->addText($this->t($fault['symptom'] ?? ''), ['name' => 'Arial', 'size' => 9, 'bold' => true]);
            $row->addCell(2000)->addText($this->t($fault['cause']   ?? ''), ['name' => 'Arial', 'size' => 9]);
            $actionCell = $row->addCell(4500);
            foreach ($fault['steps'] ?? [] as $i => $step) {
                $actionCell->addText(($i + 1) . '. ' . $this->t((string) $step), ['name' => 'Arial', 'size' => 9]);
            }
        }

        $this->addCallout($s, 'info',
            'For any fault not covered above, or if the above steps do not resolve the issue, '
            . 'contact 21st Century AV on 01189 977 771 or alison@21stcenturyav.com.'
        );
    }

    // ── Network & IP Configuration ────────────────────────────────────────────

    private function buildNetworkSection(Section $s, array $data, int $num): void
    {
        $this->addHeading1($s, $num . '.  Network & IP Configuration', $num);
        $this->addParagraph($s,
            'The following networked AV devices require IP addresses on the client LAN. '
            . 'IP addresses, VLAN assignments and credentials should be recorded by the IT team. '
            . 'The table below provides the device type, its network requirements, and columns for the '
            . 'IT team to record the assigned addresses after commissioning.'
        );

        $this->addCallout($s, 'info',
            'All IP addresses, VLAN tags and admin credentials must be stored securely by the client IT team. '
            . '21st Century AV does not retain login credentials after commissioning handover.'
        );

        $this->addHeading2($s, $num . '.1  Networked Device IP Register');

        // IP register table (device rows — IP/VLAN/MAC left blank for IT to complete)
        $networkDevices = $data['network_devices'] ?? [];
        if (! empty($networkDevices)) {
            $table = $s->addTable(['borderColor' => self::MID, 'borderSize' => 6]);
            $header = $table->addRow();
            foreach (['Room', 'Dwg', 'Device', 'Hostname', 'IP Address', 'VLAN', 'MAC Address', 'Admin URL'] as $i => $heading) {
                $widths = [1300, 500, 2300, 1100, 1100, 700, 1000, 1100];
                $cell = $header->addCell($widths[$i], ['bgColor' => self::TEAL]);
                $cell->addText($heading, ['name' => 'Arial', 'size' => 8, 'bold' => true, 'color' => self::WHITE]);
            }

            foreach ($networkDevices as $dev) {
                if (! is_array($dev)) {
                    continue;
                }
                $row   = $table->addRow();
                $small = ['name' => 'Arial', 'size' => 8];
                $grey  = ['name' => 'Arial', 'size' => 8, 'color' => 'AAAAAA', 'italic' => true];
                $hostname = (string) ($dev['hostname'] ?? '');
                $ip = (string) ($dev['ip_address'] ?? '');
                $vlan = (string) ($dev['vlan'] ?? '');
                $mac = (string) ($dev['mac_address'] ?? '');
                $adminUrl = (string) ($dev['admin_url'] ?? '');
                $row->addCell(1300)->addText($this->t($dev['room'] ?? ''), $small);
                $row->addCell(500) ->addText($this->t($dev['drawing_ref'] ?? ''), $small);
                $row->addCell(2300)->addText($this->t($dev['device'] ?? ''), $small);
                $row->addCell(1100)->addText($this->t($hostname !== '' ? $hostname : '(to complete)'), $hostname === '' ? $grey : $small);
                $row->addCell(1100)->addText($this->t($ip !== '' ? $ip : '(to complete)'), $ip === '' ? $grey : $small);
                $row->addCell(700) ->addText($this->t($vlan !== '' ? $vlan : '(to complete)'), $vlan === '' ? $grey : $small);
                $row->addCell(1000)->addText($this->t($mac !== '' ? $mac : '(to complete)'), $mac === '' ? $grey : $small);
                $row->addCell(1100)->addText($this->t($adminUrl !== '' ? $adminUrl : '(to complete)'), $adminUrl === '' ? $grey : $small);
            }
        }

        // Device-specific network notes
        if (! empty($networkDevices)) {
            $this->addHeading2($s, $num . '.2  Device-Specific Network Notes');
            foreach ($networkDevices as $dev) {
                if (! is_array($dev)) {
                    continue;
                }
                if (! empty($dev['network_notes'])) {
                    $s->addText(
                        $this->t($dev['device'] ?? ''),
                        ['name' => 'Arial', 'size' => 10, 'bold' => true, 'color' => self::TEAL]
                    );
                    $this->addParagraph($s, $this->t($dev['network_notes']));
                }
            }
        }

        // Security recommendations
        $secNotes = $data['network_security_notes'] ?? [];
        if (! empty($secNotes)) {
            $this->addHeading2($s, $num . '.3  Network Security Recommendations');
            foreach ($secNotes as $note) {
                $this->addBullet($s, $this->t((string) $note));
            }
        }
    }

    // ── Manufacturer Support ─────────────────────────────────────────────────

    private function buildManufacturerSection(Section $s, array $data, int $num): void
    {
        $this->addHeading1($s, $num . '.  Manufacturer Support & UK Contact Information', $num);
        $this->addParagraph($s,
            'The following section provides support information for each manufacturer whose equipment '
            . 'is installed. For all warranty claims and support requests within the first 12 months of '
            . 'installation, contact 21st Century AV in the first instance on 01189 977 771 or '
            . 'alison@21stcenturyav.com, quoting the project reference. We will manage the manufacturer '
            . 'liaison on your behalf where the fault relates to the original installation.'
        );

        $subNum = 1;
        foreach ($data['manufacturer_support'] ?? [] as $mfr) {
            if (! is_array($mfr)) {
                continue;
            }
            $this->addHeading2($s, $num . '.' . $subNum . '  ' . $this->t($mfr['brand'] ?? 'Unknown'));
            $subNum++;

            $infoRows = [
                ['Equipment installed:', $this->t($mfr['equipment_installed'] ?? '—')],
                ['UK support telephone:', $this->t($mfr['uk_phone']           ?? '—')],
                ['Support portal:',       $this->t($mfr['support_portal']     ?? '—')],
            ];
            if (! empty($mfr['support_email'])) {
                $infoRows[] = ['Support email:', $this->t($mfr['support_email'])];
            }
            $infoRows[] = ['Warranty:', $this->t($mfr['warranty'] ?? '—')];

            $table = $s->addTable(['borderColor' => self::MID, 'borderSize' => 6]);
            foreach ($infoRows as [$label, $value]) {
                $row = $table->addRow();
                $row->addCell(2400, ['bgColor' => 'F0FAFB'])
                    ->addText($label, ['name' => 'Arial', 'size' => 9, 'bold' => true, 'color' => self::TEAL]);
                $row->addCell(6100)
                    ->addText($this->t($value), ['name' => 'Arial', 'size' => 9]);
            }

            foreach ($mfr['notes'] ?? [] as $note) {
                $this->addBullet($s, $this->t((string) $note));
            }

            $s->addTextBreak();
        }

        // 21CAV own support entry
        $this->addHeading2($s, $num . '.' . $subNum . '  21st Century AV Ltd — Installation Support');
        $cavRows = [
            ['Company:',              '21st Century AV Ltd'],
            ['Address:',              'Unit 4 Thames Court, 2 Richfield Avenue, Reading, Berkshire, RG4 8EQ'],
            ['Telephone:',            '01189 977 771'],
            ['Email:',                'alison@21stcenturyav.com'],
            ['Project reference:',    $this->t($data['project']['ref'] ?? '') . ' — always quote when contacting support'],
            ['Scope of support:',     'Configuration changes, system re-programming, additional training, fault investigation, equipment replacement coordination, annual maintenance visits'],
            ['Installation warranty:', '12 months from practical completion for defects arising from 21st Century AV installation workmanship'],
        ];

        $table = $s->addTable(['borderColor' => self::MID, 'borderSize' => 6]);
        foreach ($cavRows as [$label, $value]) {
            $row = $table->addRow();
            $row->addCell(2400, ['bgColor' => 'F0FAFB'])
                ->addText($label, ['name' => 'Arial', 'size' => 9, 'bold' => true, 'color' => self::TEAL]);
            $row->addCell(6100)
                ->addText($this->t($value), ['name' => 'Arial', 'size' => 9]);
        }

        // Warranty summary table
        $warrantySummary = $data['warranty_summary'] ?? [];
        if (! empty($warrantySummary)) {
            $subNum++;
            $this->addHeading2($s, $num . '.' . $subNum . '  Warranty Summary');

            $table = $s->addTable(['borderColor' => self::MID, 'borderSize' => 6]);
            $header = $table->addRow();
            foreach (['Equipment', 'Warranty Period', 'Notes'] as $i => $heading) {
                $widths = [3500, 1800, 3200];
                $cell = $header->addCell($widths[$i], ['bgColor' => self::TEAL]);
                $cell->addText($heading, ['name' => 'Arial', 'size' => 9, 'bold' => true, 'color' => self::WHITE]);
            }

            foreach ($warrantySummary as $w) {
                if (! is_array($w)) {
                    continue;
                }
                $row = $table->addRow();
                $row->addCell(3500)->addText($this->t($w['equipment'] ?? ''), ['name' => 'Arial', 'size' => 9]);
                $row->addCell(1800)->addText($this->t($w['period']    ?? ''), ['name' => 'Arial', 'size' => 9]);
                $row->addCell(3200)->addText($this->t($w['notes']     ?? ''), ['name' => 'Arial', 'size' => 9]);
            }

            $this->addCallout($s, 'warning',
                'Warranty is void if equipment has been physically damaged, moved from its installed position, '
                . 'or modified by parties other than 21st Century AV or the manufacturer\'s authorised service team.'
            );
        }
    }

    // ── Asset Register ────────────────────────────────────────────────────────

    private function buildAssetRegisterSection(Section $s, array $rooms, int $num): void
    {
        $this->addHeading1($s, $num . '.  Installed Asset Register', $num);
        $this->addParagraph($s,
            'The following table lists all AV equipment installed as part of this project. '
            . 'Serial numbers should be completed by the facilities team from equipment labels after handover.'
        );

        if (empty($rooms)) {
            return;
        }

        foreach ($rooms as $room) {
            if (! is_array($room)) {
                continue;
            }
            $roomName = $this->t((string) ($room['name'] ?? 'Room'));
            $this->addHeading2($s, $roomName);

            $table = $s->addTable(['borderColor' => self::MID, 'borderSize' => 6]);
            $header = $table->addRow();
            foreach (['Qty', 'Equipment', 'Model / Part No.', 'Serial No. (to complete)'] as $i => $heading) {
                $widths = [700, 3000, 3200, 2000];
                $cell = $header->addCell($widths[$i], ['bgColor' => self::TEAL]);
                $cell->addText($heading, ['name' => 'Arial', 'size' => 9, 'bold' => true, 'color' => self::WHITE]);
            }

            foreach ($room['equipment'] ?? [] as $eq) {
                if (! is_array($eq)) {
                    continue;
                }
                $row   = $table->addRow();
                $small = ['name' => 'Arial', 'size' => 9];
                $grey  = ['name' => 'Arial', 'size' => 9, 'color' => 'AAAAAA', 'italic' => true];
                $row->addCell(700)->addText((string) ($eq['qty'] ?? 1), $small, ['alignment' => Jc::CENTER]);
                $row->addCell(3000)->addText($this->t($eq['description'] ?? ''), $small);
                $model = trim($this->t($eq['model'] ?? '') . (! empty($eq['part_no']) ? ' ' . $this->t($eq['part_no']) : ''));
                $row->addCell(3200)->addText($model, $small);
                $row->addCell(2000)->addText('', $grey);
            }

            $s->addTextBreak(1);
        }
    }

    // ── Document Control ─────────────────────────────────────────────────────

    private function buildDocumentControlSection(Section $s, int $num, OmManual $manual): void
    {
        $this->addHeading1($s, $num . '.  Document Control', $num);

        $table = $s->addTable(['borderColor' => self::MID, 'borderSize' => 6]);

        $header = $table->addRow();
        foreach (['Rev', 'Date', 'Author', 'Checked', 'Description'] as $i => $heading) {
            $widths = [600, 1500, 2000, 1800, 2600];
            $cell = $header->addCell($widths[$i], ['bgColor' => self::TEAL]);
            $cell->addText($heading, ['name' => 'Arial', 'size' => 9, 'bold' => true, 'color' => self::WHITE]);
        }

        $row   = $table->addRow();
        $small = ['name' => 'Arial', 'size' => 9];
        $row->addCell(600) ->addText('01',                               $small);
        $row->addCell(1500)->addText(now()->format('F Y'),               $small);
        $row->addCell(2000)->addText('21st Century AV Ltd',              $small);
        $row->addCell(1800)->addText('—',                                $small);
        $row->addCell(2600)->addText('Initial Issue — For Client Use',   $small);
    }

    // ── PhpWord helpers ──────────────────────────────────────────────────────

    private function sectionProps(bool $coverPage = false): array
    {
        return [
            'marginTop'    => 1440,   // 1 inch in twips
            'marginBottom' => 1440,
            'marginLeft'   => 1440,
            'marginRight'  => 1440,
            'headerHeight' => $coverPage ? 0 : 720,
            'footerHeight' => $coverPage ? 0 : 720,
        ];
    }

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

        $tmp = tempnam(sys_get_temp_dir(), 'om_tpl_') . '.docx';
        $processor->saveAs($tmp);

        $phpWord = IOFactory::load($tmp);
        @unlink($tmp);

        return $phpWord;
    }

    private function configureStyles(PhpWord $phpWord): void
    {
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(10);

        $phpWord->addParagraphStyle('Heading1Style', [
            'spaceAfter'  => 120,
            'spaceBefore' => 240,
        ]);

        $phpWord->addParagraphStyle('Heading2Style', [
            'spaceAfter'  => 80,
            'spaceBefore' => 160,
        ]);

        $phpWord->addParagraphStyle('BodyStyle', [
            'spaceAfter'  => 80,
            'lineHeight'  => 1.15,
        ]);

        $phpWord->addParagraphStyle('CalloutStyle', [
            'spaceAfter'  => 80,
            'spaceBefore' => 80,
            'indentation' => ['left' => 360],
        ]);
    }

    /**
     * Sanitise a string for safe XML output.
     * Strips control characters (except tab, newline, carriage return) that
     * would produce invalid XML 1.0 in word/document.xml.
     * Does NOT manually escape &/</>/" — PhpWord output escaping handles that
     * when Settings::setOutputEscapingEnabled(true) is set.
     */
    private function t(string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        // Strip XML 1.0 illegal control characters (keep \t \n \r)
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    }

    private function addHeading1(Section $s, string $text, int $num = 0): void
    {
        $s->addText(
            $this->t($text),
            ['name' => 'Arial', 'size' => 14, 'bold' => true, 'color' => self::TEAL],
            ['spaceBefore' => 280, 'spaceAfter' => 120, 'pageBreakBefore' => ($num > 1)]
        );
    }

    private function addHeading2(Section $s, string $text): void
    {
        $s->addText(
            $this->t($text),
            ['name' => 'Arial', 'size' => 11, 'bold' => true, 'color' => self::DARK],
            ['spaceBefore' => 160, 'spaceAfter' => 80]
        );
    }

    private function addParagraph(Section $s, string $text): void
    {
        $s->addText(
            $this->t($text),
            ['name' => 'Arial', 'size' => 10, 'color' => self::DARK],
            ['spaceAfter' => 80, 'lineHeight' => 1.15]
        );
    }

    private function addNumberedStep(Section $s, string $text): void
    {
        $s->addText(
            $this->t($text),
            ['name' => 'Arial', 'size' => 10, 'color' => self::DARK],
            ['spaceAfter' => 40, 'indentation' => ['left' => 360]]
        );
    }

    private function addBullet(Section $s, string $text): void
    {
        $s->addListItem($this->t($text), 0, ['name' => 'Arial', 'size' => 10], 'listBullet');
    }

    private function addCallout(Section $s, string $type, string $text): void
    {
        $icon   = $type === 'warning' ? '⚠  ' : 'ℹ  ';
        $colour = $type === 'warning' ? '856404' : '0C4A52';
        $bg     = $type === 'warning' ? 'FFF3CD' : 'E0F4F6';

        // PhpWord doesn't have a native callout — approximate with a single-cell table
        $table  = $s->addTable(['borderColor' => $type === 'warning' ? 'FFC107' : self::TEAL, 'borderSize' => 4]);
        $row    = $table->addRow();
        $cell   = $row->addCell(8500, ['bgColor' => $bg]);
        $cell->addText(
            $icon . $this->t($text),
            ['name' => 'Arial', 'size' => 9, 'color' => $colour],
            ['spaceAfter' => 0]
        );
        $s->addTextBreak();
    }

    // ── Post-build validation ────────────────────────────────────────────────

    /**
     * Open the generated .docx as a ZIP, parse word/document.xml with libxml,
     * and throw if the XML is malformed (e.g. unescaped ampersands).
     */
    private function validateDocx(string $filePath): void
    {
        if (! class_exists(\ZipArchive::class)) {
            return; // ZipArchive not available — skip validation
        }

        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new RuntimeException('O&M DOCX validation failed: cannot open file as ZIP archive.');
        }

        $xml = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xml === false) {
            throw new RuntimeException('O&M DOCX validation failed: word/document.xml not found in archive.');
        }

        $prev = libxml_use_internal_errors(true);
        $doc  = new \DOMDocument();
        $ok   = $doc->loadXML($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);

        if (! $ok || ! empty($errors)) {
            $firstError = $errors[0] ?? null;
            $msg = $firstError
                ? "line {$firstError->line}: {$firstError->message}"
                : 'unknown XML parse error';

            throw new RuntimeException(
                "O&M DOCX validation failed: word/document.xml contains invalid XML ({$msg}). "
                . 'This is usually caused by unescaped special characters in the source data.'
            );
        }
    }
}
