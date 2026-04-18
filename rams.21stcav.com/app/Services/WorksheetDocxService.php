<?php

namespace App\Services;

use App\Models\Worksheet;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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

        // ── Cover ────────────────────────────────────────────────────────────
        $coverSection = $phpWord->addSection($this->sectionProps());
        $this->buildCoverHeader($coverSection, $project, $worksheet);

        // ── Project-level blockers ───────────────────────────────────────────
        if (! empty($blockers)) {
            $this->renderBlockers($coverSection, $blockers);
        }

        // ── One section per room ─────────────────────────────────────────────
        foreach ($rooms as $room) {
            $section = $phpWord->addSection($this->sectionProps());
            $this->buildRoom($section, $room);
        }

        // ── Save ─────────────────────────────────────────────────────────────
        $filename  = 'worksheet_' . $worksheet->id . '_' . now()->format('Ymd_His') . '.docx';
        $directory = Storage::disk('local')->path('worksheets');
        if (! is_dir($directory)) mkdir($directory, 0755, true);

        $fullPath = $directory . DIRECTORY_SEPARATOR . $filename;
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

        // ── Room works description ───────────────────────────────────────────
        $this->heading($section, 'ROOM WORKS DESCRIPTION');
        $worksDesc = trim((string) ($room['room_works_description'] ?? ''));
        if ($worksDesc !== '') {
            $section->addText($this->t($worksDesc),
                ['size' => 10, 'color' => self::DARK], ['lineHeight' => 1.4, 'spaceAfter' => 80]);
        } else {
            $section->addText('Works description not available for this room.',
                ['size' => 10, 'color' => '9CA3AF', 'italic' => true]);
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

        // ── A. Equipment by Subsystem ────────────────────────────────────────
        $this->heading($section, 'A. EQUIPMENT TO INSTALL');
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

        // ── B. Existing / Retained ───────────────────────────────────────────
        if (! empty($room['existing_reuse'])) {
            $this->heading($section, 'B. EXISTING EQUIPMENT (RETAINED)');
            $this->equipmentTable($section, $room['existing_reuse']);
            $section->addTextBreak(1);
        }

        // ── C. Cabling & Connections ─────────────────────────────────────────
        if (! empty($room['cables'])) {
            $this->heading($section, 'C. CABLING & CONNECTIONS');
            $this->equipmentTable($section, $room['cables']);
            $section->addTextBreak(1);
        }

        // ── D. Cable Routes ──────────────────────────────────────────────────
        $this->heading($section, 'D. CABLE ROUTES');
        $cableRoute = $room['cable_route_desc'] ?? null;
        if ($cableRoute) {
            $section->addText($this->t((string) $cableRoute), ['size' => 10, 'color' => self::DARK]);
        } else {
            $section->addText('Not surveyed', ['size' => 10, 'color' => '9CA3AF', 'italic' => true]);
        }
        $section->addTextBreak(1);

        // ── E. Power & Network ───────────────────────────────────────────────
        $this->heading($section, 'E. POWER & NETWORK');
        $this->powerNetworkTable($section, $room);
        $section->addTextBreak(1);

        // ── F. Phased Install Plan ───────────────────────────────────────────
        $this->heading($section, 'F. PHASED INSTALL PLAN');
        $plan = $room['phased_plan'] ?? $room['install_steps'] ?? null;
        if (is_array($plan) && ! empty($plan)) {
            foreach ($plan as $phase) {
                if (is_array($phase) && isset($phase['title'])) {
                    $section->addText(
                        $this->t(($phase['step'] ?? '') . '. ' . ($phase['title'] ?? '')),
                        ['size' => 10, 'bold' => true, 'color' => self::TEAL]);
                    foreach ($phase['items'] ?? [] as $item) {
                        $section->addListItem($this->t((string) $item), 0,
                            ['size' => 10, 'color' => self::DARK], 'listBullet');
                    }
                    $section->addTextBreak();
                } elseif (is_string($phase) && trim($phase) !== '') {
                    $section->addText($this->t($phase), ['size' => 10, 'color' => self::DARK], ['lineHeight' => 1.6]);
                }
            }
        } elseif (is_string($plan) && trim($plan) !== '') {
            $section->addText($this->t($plan), ['size' => 10, 'color' => self::DARK], ['lineHeight' => 1.6]);
        } else {
            $section->addText('Install plan not available.', ['size' => 10, 'color' => '9CA3AF', 'italic' => true]);
        }
        $section->addTextBreak(1);

        // ── G. Commissioning Checklist ───────────────────────────────────────
        if (! empty($room['commissioning'])) {
            $this->heading($section, 'G. COMMISSIONING CHECKLIST');
            $table = $section->addTable(['borderSize' => 6, 'borderColor' => self::MID, 'cellMargin' => 80]);
            $h = $table->addRow();
            $h->addCell(1500, ['bgColor' => self::TEAL])->addText('System', ['bold' => true, 'color' => self::WHITE, 'size' => 9]);
            $h->addCell(5000, ['bgColor' => self::TEAL])->addText('Check', ['bold' => true, 'color' => self::WHITE, 'size' => 9]);
            $h->addCell(1200, ['bgColor' => self::TEAL])->addText('Pass/Fail', ['bold' => true, 'color' => self::WHITE, 'size' => 9]);
            $h->addCell(2300, ['bgColor' => self::TEAL])->addText('Notes', ['bold' => true, 'color' => self::WHITE, 'size' => 9]);

            foreach ($room['commissioning'] as $i => $check) {
                $bg = ($i % 2 === 0) ? self::WHITE : self::GREY;
                $row = $table->addRow();
                $row->addCell(1500, ['bgColor' => $bg])->addText($this->t($check['system'] ?? ''), ['size' => 9, 'bold' => true]);
                $row->addCell(5000, ['bgColor' => $bg])->addText($this->t($check['check'] ?? ''), ['size' => 9]);
                $row->addCell(1200, ['bgColor' => $bg])->addText('', ['size' => 9]); // Blank for engineer
                $row->addCell(2300, ['bgColor' => $bg])->addText('', ['size' => 9]); // Blank for notes
            }
            $section->addTextBreak(1);
        }

        // ── H. Pre-Install Answers ───────────────────────────────────────────
        if (! empty($room['pre_install_answers'])) {
            $this->heading($section, 'H. PRE-INSTALL CHECK ANSWERS');
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

        // ── I. Engineer Notes / Snags / Sign-Off ─────────────────────────────
        $this->heading($section, 'I. ENGINEER SIGN-OFF');
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
            $bg = ($i % 2 === 0) ? self::WHITE : self::GREY;
            $row = $table->addRow();
            $row->addCell(6500, ['bgColor' => $bg])->addText(
                $this->t($item['name'] ?? $item['description'] ?? ''),
                ['size' => 10, 'color' => self::DARK]);
            $row->addCell(1200, ['bgColor' => $bg])->addText(
                (string) ($item['quantity'] ?? $item['qty'] ?? 1),
                ['size' => 10, 'color' => self::DARK]);
            $row->addCell(2300, ['bgColor' => $bg])->addText(
                $this->t($item['part_no'] ?? $item['part_number'] ?? ''),
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
            if ($value !== null && $value !== '') {
                $cell->addText($this->t((string) $value), ['size' => 10, 'color' => self::DARK]);
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
