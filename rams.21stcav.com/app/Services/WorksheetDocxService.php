<?php

namespace App\Services;

use App\Models\Worksheet;
use App\Services\DocumentArtifactStorage;
use Illuminate\Support\Facades\Log;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use RuntimeException;

/**
 * WorksheetDocxService — builds the Worksheet DOCX from generated_data.
 *
 * Renders per-room sections:
 *   1. Header metadata
 *   2. Blockers (if any)
 *   3. Equipment by subsystem
 *   4. Existing Equipment (Retained)
 *   5. Cabling & Connections
 *   6. Phased Install Plan
 *   7. Commissioning Checklist
 *   8. Engineer Notes / Sign-Off
 */
class WorksheetDocxService
{
    public function __construct(
        private readonly DocumentArtifactStorage $artifacts = new DocumentArtifactStorage(),
    ) {}

    private const TEAL  = '178A95';
    private const WHITE = 'FFFFFF';
    private const DARK  = '0B3C45';
    private const GREY  = 'F3F6F7';
    private const MID   = 'E5E7EB';
    private const AMBER = 'F59E0B';
    private const RED   = 'DC2626';

    public function build(array $generatedData, Worksheet $worksheet): void
    {
        Settings::setOutputEscapingEnabled(true);

        $phpWord = new PhpWord();
        $this->configureStyles($phpWord);

        $project  = $generatedData['project']  ?? [];
        $rooms    = $generatedData['rooms']     ?? [];
        $blockers = $generatedData['blockers']  ?? [];
        $warnings = $generatedData['warnings_panel'] ?? [];

        // ── Cover ────────────────────────────────────────────────────────────
        $coverSection = $phpWord->addSection($this->sectionProps());
        $this->buildCoverHeader($coverSection, $project, $worksheet);

        // ── Project-level blockers ───────────────────────────────────────────
        if (! empty($blockers)) {
            $this->renderBlockers($coverSection, $blockers);
        }

        // ── Worksheet QA warnings panel (Pass C) — soft, not a hard block ────
        if (! empty($warnings)) {
            $this->renderWarningsPanel($coverSection, $warnings);
        }

        // ── One section per room ─────────────────────────────────────────────
        foreach ($rooms as $room) {
            $section = $phpWord->addSection($this->sectionProps());
            $this->buildRoom($section, $room);
        }

        // ── Save ─────────────────────────────────────────────────────────────
        $filename = 'worksheet_' . $worksheet->id . '_' . now()->format('Ymd_His') . '.docx';
        $fullPath = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_WORKSHEET, $filename);

        $writer = IOFactory::createWriter($phpWord, 'Word2007');
        $writer->save($fullPath);

        $this->validateDocx($fullPath);

        $worksheet->update(['filename' => $filename]);
        Log::info('WorksheetDocxService: DOCX saved', ['worksheet_id' => $worksheet->id, 'filename' => $filename]);
    }

    // ── Cover Header ─────────────────────────────────────────────────────────

    private function buildCoverHeader($section, array $project, Worksheet $worksheet): void
    {
        $section->addText('Installation Worksheet',
            ['name' => 'Arial', 'size' => 22, 'bold' => true, 'color' => self::TEAL], ['alignment' => Jc::START]);
        $section->addText($this->t($project['name'] ?? $worksheet->project_name ?? ''),
            ['name' => 'Arial', 'size' => 16, 'bold' => true, 'color' => self::DARK], ['alignment' => Jc::START]);
        $section->addTextBreak(1);

        $table = $section->addTable(['borderSize' => 0, 'borderColor' => self::MID, 'cellMarginLeft' => 100, 'cellMarginRight' => 100]);
        $meta = [
            ['Client',    $project['client_name'] ?? $worksheet->client_name ?? ''],
            ['Site',      $project['site_address'] ?? $worksheet->site_address ?? ''],
            ['Reference', $project['quote_reference'] ?? $worksheet->project_ref ?? ''],
            ['Date',      now()->format('d F Y')],
        ];
        foreach ($meta as [$label, $value]) {
            $row = $table->addRow();
            $row->addCell(2000)->addText($label, ['bold' => true, 'size' => 10, 'color' => self::TEAL]);
            $row->addCell(7000)->addText($this->t((string) $value), ['size' => 10, 'color' => self::DARK]);
        }

        $section->addTextBreak(1);
        $section->addLine(['weight' => 1, 'color' => self::TEAL, 'width' => 9000, 'height' => 0]);
        $section->addTextBreak(1);
    }

    // ── Blockers ─────────────────────────────────────────────────────────────

    private function renderBlockers($section, array $blockers): void
    {
        $this->heading($section, 'BLOCKERS — RESOLVE BEFORE INSTALL');

        $table = $section->addTable(['borderSize' => 6, 'borderColor' => self::MID, 'cellMargin' => 80]);
        $h = $table->addRow();
        $h->addCell(2000, ['bgColor' => self::RED])->addText('Type', ['bold' => true, 'color' => self::WHITE, 'size' => 9]);
        $h->addCell(4500, ['bgColor' => self::RED])->addText('Issue', ['bold' => true, 'color' => self::WHITE, 'size' => 9]);
        $h->addCell(4500, ['bgColor' => self::RED])->addText('Action Required', ['bold' => true, 'color' => self::WHITE, 'size' => 9]);

        foreach ($blockers as $b) {
            $row = $table->addRow();
            $row->addCell(2000)->addText($this->t(strtoupper($b['type'] ?? '')), ['size' => 9, 'bold' => true, 'color' => self::RED]);
            $row->addCell(4500)->addText($this->t($b['message'] ?? ''), ['size' => 9, 'color' => self::DARK]);
            $row->addCell(4500)->addText($this->t($b['action'] ?? ''), ['size' => 9, 'color' => self::DARK]);
        }
        $section->addTextBreak(1);
    }

    // ── Warnings panel (Pass C) ──────────────────────────────────────────────

    /**
     * Soft QA warnings — surfaces unclassified / mount-without-parent /
     * warranty-without-parent / existing-unknown items so the document is
     * never silently incorrect. Severity only: never hard-blocks the render.
     */
    private function renderWarningsPanel($section, array $panels): void
    {
        $this->heading($section, 'WORKSHEET QA WARNINGS');
        foreach ($panels as $p) {
            $severity = strtoupper((string) ($p['severity'] ?? 'INFO'));
            $bg = match ($severity) {
                'REVIEW' => 'FEF2F2',
                default  => 'FFFBEB',
            };
            $text = match ($severity) {
                'REVIEW' => '991B1B',
                default  => '92400E',
            };

            $table = $section->addTable(['borderSize' => 4, 'borderColor' => self::AMBER]);
            $row = $table->addRow();
            $cell = $row->addCell(9000, ['bgColor' => $bg]);
            $cell->addText(
                $severity . ' — ' . $this->t((string) ($p['title'] ?? '')),
                ['size' => 10, 'bold' => true, 'color' => $text],
            );
            $cell->addText(
                $this->t((string) ($p['message'] ?? '')),
                ['size' => 9, 'color' => $text],
            );
            foreach (($p['items'] ?? []) as $it) {
                if (! is_array($it)) continue;
                $line = '  • ' . ($it['room'] ?? '?') . ' — ' . ($it['item'] ?? '?');
                $cell->addText($this->t($line), ['size' => 9, 'color' => $text]);
            }
            $section->addTextBreak();
        }
    }

    // ── Room Section ─────────────────────────────────────────────────────────

    private function buildRoom($section, array $room): void
    {
        $roomName = $room['name'] ?? 'Unknown Room';
        $isSurveyed = $room['is_surveyed'] ?? false;

        // Room heading
        $section->addText($this->t($roomName),
            ['name' => 'Arial', 'size' => 14, 'bold' => true, 'color' => self::DARK]);
        $section->addText($isSurveyed ? 'Surveyed' : 'Not Surveyed',
            ['size' => 9, 'color' => $isSurveyed ? '065F46' : '6B7280', 'italic' => true]);
        $section->addTextBreak(1);

        // ── Engineer work summary ────────────────────────────────────────────
        $this->heading($section, 'ENGINEER WORK SUMMARY');
        $worksDesc = trim((string) ($room['room_works_description'] ?? ''));
        if ($worksDesc !== '') {
            $section->addText($this->t($worksDesc),
                ['size' => 10, 'color' => self::DARK], ['lineHeight' => 1.4, 'spaceAfter' => 80]);
        } else {
            $section->addText('Works description not available for this room.',
                ['size' => 10, 'color' => '9CA3AF', 'italic' => true]);
        }
        $section->addTextBreak(1);

        // ── Install steps (AI-generated, equipment-specific) ─────────────────
        // The pipeline calls WorksheetPrompt per room and stores the numbered
        // install sequence. Render it here so engineers see project-specific
        // actions instead of the generic checklist below.
        $installStepsRaw = trim((string) ($room['install_steps'] ?? ''));
        $installStepLines = [];
        if ($installStepsRaw !== '') {
            foreach (preg_split('/\r?\n/', $installStepsRaw) ?: [] as $ln) {
                $ln = trim($ln);
                if ($ln === '') continue;
                // Strip leading "1.", "1)", "•", "-" etc. so the docx list
                // numbering doesn't collide with text-side numbering.
                $ln = preg_replace('/^\s*(?:\d+[\.\)]|[-•])\s*/', '', $ln);
                $installStepLines[] = $ln;
            }
        }
        if (! empty($installStepLines)) {
            $this->heading($section, 'INSTALL STEPS');
            foreach ($installStepLines as $i => $step) {
                $section->addListItem($this->t(($i + 1) . '. ' . $step), 0,
                    ['size' => 10, 'color' => self::DARK], 'listBullet');
            }
            $section->addTextBreak(1);
        }

        // ── Engineer task checklist (generic backstop) ───────────────────────
        // Kept as a high-level reminder block; the install steps above are
        // the project-specific sequence the engineer follows on site.
        $this->heading($section, 'ENGINEER TASK CHECKLIST');
        foreach ($this->roomWorkChecklist($room) as $task) {
            $section->addListItem($this->t($task), 0, ['size' => 10, 'color' => self::DARK], 'listBullet');
        }
        $section->addTextBreak(1);

        // ── Safety callouts ──────────────────────────────────────────────────
        foreach ($room['safety'] ?? [] as $callout) {
            $tbl = $section->addTable(['borderSize' => 4, 'borderColor' => self::AMBER]);
            $r = $tbl->addRow();
            $c = $r->addCell(9000, ['bgColor' => 'FFF3CD']);
            $c->addText($this->t($callout), ['size' => 9, 'bold' => true, 'color' => '92400E']);
            $section->addTextBreak();
        }

        // ── Tools required ───────────────────────────────────────────────────
        if (! empty($room['tools'])) {
            $this->heading($section, 'TOOLS REQUIRED');
            foreach ($room['tools'] as $tool) {
                $section->addListItem($this->t($tool), 0, ['size' => 9, 'color' => self::DARK], 'listBullet');
            }
            $section->addTextBreak(1);
        }

        // ── Kit required by room/subsystem ───────────────────────────────────
        $this->heading($section, 'KIT REQUIRED IN THIS ROOM');
        $subsystems = $room['subsystems'] ?? [];
        if (! empty($subsystems)) {
            foreach ($subsystems as $label => $items) {
                $section->addText($this->t($label),
                    ['size' => 10, 'bold' => true, 'color' => self::TEAL], ['spaceBefore' => 60]);
                $this->equipmentTable($section, $items);
            }
        } elseif (! empty($room['equipment'])) {
            $this->equipmentTable($section, $room['equipment']);
        } else {
            $section->addText('No equipment listed.', ['size' => 10, 'color' => '9CA3AF', 'italic' => true]);
        }
        $section->addTextBreak(1);

        // ── Existing / Retained ──────────────────────────────────────────────
        if (! empty($room['existing_reuse'])) {
            $this->heading($section, 'EXISTING EQUIPMENT (RETAINED)');
            $this->equipmentTable($section, $room['existing_reuse']);
            $section->addTextBreak(1);
        }

        // ── Cabling & Connections (reference) ────────────────────────────────
        if (! empty($room['cables'])) {
            $this->heading($section, 'CABLING & CONNECTIONS (REFERENCE)');
            $this->equipmentTable($section, $room['cables']);
            $section->addTextBreak(1);
        }

        // ── Cable routes ─────────────────────────────────────────────────────
        $this->heading($section, 'CABLE ROUTE NOTES');
        $cableRoute = $room['cable_route_desc'] ?? null;
        if ($cableRoute) {
            $section->addText($this->t((string) $cableRoute), ['size' => 10, 'color' => self::DARK]);
        } else {
            $section->addText('Not surveyed', ['size' => 10, 'color' => '9CA3AF', 'italic' => true]);
        }
        $section->addTextBreak(1);

        // ── Power & Network check ────────────────────────────────────────────
        $this->heading($section, 'POWER & NETWORK CHECK');
        $this->powerNetworkTable($section, $room);
        $section->addTextBreak(1);

        // ── Pre-Install Answers ──────────────────────────────────────────────
        if (! empty($room['pre_install_answers'])) {
            $this->heading($section, 'PRE-INSTALL CHECK ANSWERS');
            $table = $section->addTable(['borderSize' => 6, 'borderColor' => self::MID, 'cellMargin' => 80]);
            $h = $table->addRow();
            $h->addCell(6000, ['bgColor' => self::TEAL])->addText('Question', ['bold' => true, 'color' => self::WHITE, 'size' => 9]);
            $h->addCell(3000, ['bgColor' => self::TEAL])->addText('Answer', ['bold' => true, 'color' => self::WHITE, 'size' => 9]);
            foreach ($room['pre_install_answers'] as $i => $row) {
                $bg = ($i % 2 === 0) ? self::WHITE : self::GREY;
                $tr = $table->addRow();
                $tr->addCell(6000, ['bgColor' => $bg])->addText($this->t((string) ($row['question'] ?? '')), ['size' => 10]);
                $ans = ucfirst((string) ($row['answer'] ?? 'unanswered'));
                if (($row['answer'] ?? '') === 'other' && ! empty($row['other_text'])) {
                    $ans = 'Other: ' . $row['other_text'];
                }
                $tr->addCell(3000, ['bgColor' => $bg])->addText($this->t($ans), ['size' => 10]);
            }
            $section->addTextBreak(1);
        }

        // ── Commissioning checklist ──────────────────────────────────────────
        // Subsystem-specific test-and-handover checks generated by
        // WorksheetGeneratorService::buildCommissioningChecklist(). The
        // engineer ticks Pass / Fail / N/A on site and records remedial
        // notes for any failure.
        if (! empty($room['commissioning']) && is_array($room['commissioning'])) {
            $this->heading($section, 'COMMISSIONING CHECKLIST');
            $cmTable = $section->addTable(['borderSize' => 6, 'borderColor' => self::MID, 'cellMargin' => 80]);
            $h = $cmTable->addRow();
            $h->addCell(1500, ['bgColor' => self::TEAL])->addText('System',  ['bold' => true, 'color' => self::WHITE, 'size' => 9]);
            $h->addCell(4500, ['bgColor' => self::TEAL])->addText('Check',   ['bold' => true, 'color' => self::WHITE, 'size' => 9]);
            $h->addCell(1200, ['bgColor' => self::TEAL])->addText('Result',  ['bold' => true, 'color' => self::WHITE, 'size' => 9]);
            $h->addCell(1800, ['bgColor' => self::TEAL])->addText('Notes',   ['bold' => true, 'color' => self::WHITE, 'size' => 9]);
            foreach ($room['commissioning'] as $i => $cm) {
                if (! is_array($cm)) continue;
                $bg = ($i % 2 === 0) ? self::WHITE : self::GREY;
                $tr = $cmTable->addRow();
                $tr->addCell(1500, ['bgColor' => $bg])->addText($this->t((string) ($cm['system'] ?? '')), ['size' => 10, 'bold' => true, 'color' => self::TEAL]);
                $tr->addCell(4500, ['bgColor' => $bg])->addText($this->t((string) ($cm['check']  ?? '')), ['size' => 10]);
                $tr->addCell(1200, ['bgColor' => $bg])->addText('☐ Pass  ☐ Fail  ☐ N/A',                  ['size' => 9, 'color' => self::DARK]);
                $tr->addCell(1800, ['bgColor' => $bg])->addText($this->t((string) ($cm['notes']  ?? '')), ['size' => 10]);
            }
            $section->addTextBreak(1);
        }

        // ── Engineer Notes / Snags / Sign-Off ────────────────────────────────
        $this->heading($section, 'ENGINEER SIGN-OFF');
        $soTable = $section->addTable(['borderSize' => 6, 'borderColor' => self::MID, 'cellMargin' => 80]);
        $soFields = [
            ['Engineer Name', ''],
            ['Date', ''],
            ['Snags / Notes', ''],
            ['Engineer Signature', ''],
            ['Client Name', ''],
            ['Client Signature', ''],
        ];
        foreach ($soFields as [$label, $val]) {
            $row = $soTable->addRow($label === 'Snags / Notes' ? 600 : 400);
            $row->addCell(3000, ['bgColor' => self::GREY])->addText($label, ['bold' => true, 'size' => 9, 'color' => '4B5563']);
            $row->addCell(6000)->addText($this->t($val), ['size' => 10, 'color' => self::DARK]);
        }
    }

    private function roomWorkChecklist(array $room): array
    {
        $checks = [];
        $subsystems = $room['subsystems'] ?? [];
        $equipmentCount = count($room['equipment'] ?? []);
        $items = $equipmentCount === 1 ? 'item' : 'items';

        $checks[] = 'Review room summary and kit list before unloading equipment.';

        if ($equipmentCount > 0) {
            $checks[] = "Install and secure {$equipmentCount} listed {$items} to agreed room positions.";
        } else {
            $checks[] = 'Confirm room scope and kit list with project lead before starting.';
        }

        if (isset($subsystems['Display'])) {
            $checks[] = 'Set display mounting height/alignment and confirm input mapping.';
        }
        if (isset($subsystems['Audio'])) {
            $checks[] = 'Terminate audio endpoints, label terminations, and verify signal path.';
        }
        if (isset($subsystems['Video Conferencing'])) {
            $checks[] = 'Commission VC endpoints and complete a live test call.';
        }
        if (isset($subsystems['Rack & Infrastructure'])) {
            $checks[] = 'Rack, terminate, dress, and label all associated cabling.';
        }
        if (isset($subsystems['Control & Automation'])) {
            $checks[] = 'Verify control UI pages and room automation triggers.';
        }

        if (! ($room['is_surveyed'] ?? false)) {
            $checks[] = 'Before first fix: confirm fixing points, cable routes, and power/network outlet availability.';
        }

        $checks[] = 'Record snags and complete engineer sign-off before leaving the room.';

        return array_values(array_unique($checks));
    }

    // ── Equipment table ──────────────────────────────────────────────────────

    private function equipmentTable($section, array $equipment): void
    {
        if (empty($equipment)) return;

        $table = $section->addTable(['borderSize' => 6, 'borderColor' => self::MID, 'cellMargin' => 80]);
        $h = $table->addRow();
        $h->addCell(6500, ['bgColor' => self::TEAL])->addText('Item', ['bold' => true, 'color' => self::WHITE, 'size' => 9]);
        $h->addCell(1200, ['bgColor' => self::TEAL])->addText('Qty', ['bold' => true, 'color' => self::WHITE, 'size' => 9]);
        $h->addCell(2300, ['bgColor' => self::TEAL])->addText('Part No.', ['bold' => true, 'color' => self::WHITE, 'size' => 9]);

        foreach ($equipment as $i => $item) {
            $bg     = ($i % 2 === 0) ? self::WHITE : self::GREY;
            $name   = trim((string) ($item['name']    ?? $item['description']  ?? ''));
            $partNo = trim((string) ($item['part_no'] ?? $item['part_number'] ?? ''));

            // When a line item arrives with no part_no AND the name field is
            // carrying a bare SKU (e.g. `MXWAPXD2UK=-Z11`), the quote had no
            // friendly name and no resolver entry. Echo the SKU into the Part
            // No. column so the row doesn't visibly half-render — the
            // classifier QA warnings panel still flags SKU-only items for the
            // reviewer to enrich.
            if ($partNo === '' && $name !== '' && $this->looksLikeBareSku($name)) {
                $partNo = $name;
            }

            $row = $table->addRow();
            $row->addCell(6500, ['bgColor' => $bg])->addText(
                $this->t($name),
                ['size' => 10, 'color' => self::DARK]);
            $row->addCell(1200, ['bgColor' => $bg])->addText(
                (string) ($item['quantity'] ?? $item['qty'] ?? 1),
                ['size' => 10, 'color' => self::DARK]);
            $row->addCell(2300, ['bgColor' => $bg])->addText(
                $this->t($partNo),
                ['size' => 9, 'color' => '6B7280']);
        }
        $section->addTextBreak(1);
    }

    // ── Power & Network table ────────────────────────────────────────────────

    private function powerNetworkTable($section, array $room): void
    {
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => self::MID, 'cellMargin' => 80]);
        $rows = [
            ['Power outlets',             $room['power_outlet_count'] ?? null],
            ['Additional power required', $room['requires_additional_power'] ?? null],
            ['Network ports',             $room['network_port_count'] ?? null],
            ['Existing cabling',          $room['existing_cabling'] ?? null],
        ];
        foreach ($rows as [$label, $value]) {
            $row = $table->addRow();
            $row->addCell(3500, ['bgColor' => self::GREY])->addText($label, ['bold' => true, 'size' => 9, 'color' => '4B5563']);
            $cell = $row->addCell(5500);
            // Render booleans as Yes/No rather than "1"/"" so users see a meaningful
            // answer for questions like "Additional power required". (M-16)
            if (is_bool($value)) {
                $display = $value ? 'Yes' : 'No';
            } elseif ($value !== null && $value !== '') {
                $display = (string) $value;
            } else {
                $display = null;
            }
            if ($display !== null) {
                $cell->addText($this->t($display), ['size' => 10, 'color' => self::DARK]);
            } else {
                $cell->addText('Not surveyed', ['size' => 10, 'color' => '9CA3AF', 'italic' => true]);
            }
        }
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function heading($section, string $text): void
    {
        $section->addText($text,
            ['bold' => true, 'size' => 11, 'color' => self::TEAL, 'allCaps' => true],
            ['spaceAfter' => 80]);
    }

    private function t(string|null $value): string
    {
        if ($value === null || $value === '') return '';
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value);
    }

    /**
     * Heuristic: looks like a bare SKU rather than a human name.
     * True for symbol-heavy, space-free identifiers (`MXW2X/SM86=-Z11`), or
     * single-token all-caps strings with digits of length ≥ 6 (`QE65T`,
     * `LH65QETELGCXEN`). False for normal descriptions and short brand-only
     * strings.
     */
    private function looksLikeBareSku(string $s): bool
    {
        $s = trim($s);
        if ($s === '' || str_contains($s, ' ')) return false;
        if (preg_match('/[=\/]/', $s)) return true;
        return strlen($s) >= 6
            && preg_match('/[A-Z]/', $s) === 1
            && preg_match('/\d/', $s) === 1;
    }

    private function validateDocx(string $filePath): void
    {
        if (! class_exists(\ZipArchive::class)) return;
        $zip = new \ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new RuntimeException('Worksheet DOCX validation failed: cannot open as ZIP.');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            throw new RuntimeException('Worksheet DOCX validation failed: no word/document.xml.');
        }
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();
        $ok = $doc->loadXML($xml);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (! $ok || ! empty($errors)) {
            $msg = ! empty($errors) ? "line {$errors[0]->line}: {$errors[0]->message}" : 'unknown';
            throw new RuntimeException("Worksheet DOCX validation failed: invalid XML ({$msg})");
        }
    }

    private function sectionProps(): array
    {
        return ['marginLeft' => 1080, 'marginRight' => 1080, 'marginTop' => 1080, 'marginBottom' => 1080];
    }

    private function configureStyles(PhpWord $phpWord): void
    {
        $phpWord->setDefaultFontName('Arial');
        $phpWord->setDefaultFontSize(10);
    }
}
