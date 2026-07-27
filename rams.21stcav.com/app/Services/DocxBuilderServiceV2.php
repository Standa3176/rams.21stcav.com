<?php

namespace App\Services;

use App\Models\RamsDocument;
use App\Services\Rams\RamsDisplayPatchService;
use App\Support\Rams\RamsDocumentComposer;
use App\Support\Rams\RamsTheme;
use App\Support\Rams\Sections\CoverSectionDto;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;

/**
 * Phase 260726-rf3 Plan 04 — Unified DOCX builder (V2).
 *
 * DTO- and Theme-consuming replacement for {@see DocxBuilderService}.
 * Same public interface (`build(array $data, RamsDocument $record): string`)
 * so upstream callers (RamsDocumentRendererService, BuildRamsDocumentJob,
 * RamsRefreshComplianceCommand) route through the kill switch on V1 without
 * any code change — mirrors the {@see \App\Services\PdfService::buildRams}
 * pattern that Plan 03 established for the PDF renderer.
 *
 * ─── Plan 04 Commit 2 (this class) ──────────────────────────────────────
 *
 * Cover renders from `RamsDocumentDTO->cover` via {@see self::buildCoverFromDto}
 * — the first parity win the phase buys us:
 *
 *   • CLIENT CONTACT is rendered as `addText(name) + addTextBreak() + addText(email)`
 *     instead of the legacy `"\n"` string concatenation, so Word actually
 *     line-breaks the value instead of collapsing to a single line.
 *
 * The rest of the document (doc control → appendix A) is delegated to the
 * legacy {@see DocxBuilderService::buildRestOfDocument} seam so the byte-for-byte
 * output on those sections stays identical to pre-refactor.
 *
 * Section-porting plan for later commits (mirroring Plan 03's partial
 * adoption in `rams-v2.blade.php`):
 *  - DTO-consuming (this commit): cover.
 *  - Legacy raw reads (this commit + until Plan 05): every other section.
 *  - Full DTO adoption for the compliance-upgrade surface is reserved for
 *    Plan 05 parity sweep — see .planning/phases/260726-rf3.../deferred-items.md.
 *
 * @see \App\Services\PdfService::buildRams              Kill-switch pattern (Plan 03).
 * @see \App\Support\Rams\RamsDocumentComposer           Post-patch composer.
 * @see \App\Support\Rams\Sections\CoverSectionDto        Cover-page DTO.
 * @see \App\Support\Rams\RamsTheme                       Shared design tokens.
 * @see \App\Services\DocxBuilderService::buildRestOfDocument  Legacy seam we delegate to.
 */
class DocxBuilderServiceV2
{
    public function __construct(
        private readonly DocumentTemplateService  $templates,
        private readonly DocumentArtifactStorage  $artifacts,
        private readonly RamsDisplayPatchService  $patchService,
        private readonly RamsDocumentComposer     $composer,
        private readonly RamsTheme                $theme,
        private readonly DocxBuilderService       $legacy,
    ) {}

    /**
     * Build the RAMS DOCX and return the absolute path to the written file.
     *
     * Flow:
     *   1. `patchService->patch()` — same side-effects the legacy path relies on
     *      (personnel chain, client-contact inference, rooms normalisation).
     *   2. Compose the typed `RamsDocumentDTO` — MUST run after the patch so
     *      the composer's order-of-operations invariant holds (marker check
     *      is soft — only logs a warning — but we honour it here).
     *   3. Bootstrap `PhpWord` with Poppins/10 to match the legacy default
     *      font stack, add a portrait section + running footer.
     *   4. Render the cover from the DTO via {@see self::buildCoverFromDto}.
     *   5. Delegate the rest to `legacy->buildRestOfDocument()` so every
     *      section from doc control through Appendix A stays byte-identical
     *      to the pre-refactor output.
     *   6. Save through the shared `DocumentArtifactStorage` disk with the
     *      exact filename shape legacy uses so upstream `readPath()` calls
     *      resolve regardless of which pipeline produced the file.
     */
    public function build(array $data, RamsDocument $record): string
    {
        // Belt-and-braces — PhpWord escapes &, <, > when this flag is on.
        Settings::setOutputEscapingEnabled(true);

        // Same patch-then-read-back sequence the legacy path uses so the
        // downstream composer + legacy `buildRestOfDocument` observe identical
        // `$record->generated_data` state.
        $this->patchService->patch($record);
        $data = $record->generated_data ?? $data;

        $dto = $this->composer->compose($record);

        $phpWord = new PhpWord();
        // 260725-rd1 — Poppins body font (RamsTheme is source of truth).
        $phpWord->setDefaultFontName($this->theme->font('body'));
        $phpWord->setDefaultFontSize($this->theme->size('body'));

        // Cover section — mirror legacy portraitStyle() + attachFooter() so
        // the section header/footer geometry stays identical to the pre-refactor
        // output. Both helpers are private on legacy; we duplicate their minimal
        // shape here (theme-driven) rather than expose more seams than needed.
        $section = $phpWord->addSection([
            'orientation'  => 'portrait',
            'marginTop'    => $this->theme->spacing('page_margin_portrait'),
            'marginBottom' => $this->theme->spacing('page_margin_portrait'),
            'marginLeft'   => $this->theme->spacing('page_margin_portrait'),
            'marginRight'  => $this->theme->spacing('page_margin_portrait'),
            'headerHeight' => $this->theme->spacing('header_height_twips'),
            'footerHeight' => $this->theme->spacing('footer_height_twips'),
        ]);
        $section->addFooter()->addPreserveText(
            config('rams.company_name') . '  |  Page {PAGE}',
            [
                'name'  => $this->theme->font('body'),
                'size'  => $this->theme->size('caption'),
                'color' => $this->theme->palette('text_muted'),
            ],
            ['alignment' => Jc::CENTER],
        );

        // ── Cover from DTO (parity win: line-break for CLIENT CONTACT) ────────
        $this->buildCoverFromDto($section, $dto->cover, $this->theme);

        // ── Everything after the cover — delegated to legacy seam so V2
        //    stays byte-identical to pre-refactor on the "rest" until the
        //    remaining sections are ported in a future phase.
        $this->legacy->buildRestOfDocument($phpWord, $section, $data, $record);

        // Filename shape mirrors DocxBuilderService::buildLegacy() so upstream
        // readers resolve either pipeline's output identically. Ymd_His_u
        // avoids collisions across concurrent retries in the same second.
        $filename = 'rams_' . $record->id . '_' . now()->format('Ymd_His_u') . '.docx';
        $filePath = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_RAMS, $filename);

        IOFactory::createWriter($phpWord, 'Word2007')->save($filePath);

        $record->filename = $filename;
        $record->save();

        return $filePath;
    }

    /**
     * Render the RAMS cover into an already-open portrait section, reading
     * from `RamsDocumentDTO->cover` exclusively.
     *
     * Structural parity with `DocxBuilderService::buildCoverSection`:
     *  - Company-name title + subtitle with teal underline.
     *  - Table 1: CLIENT / SITE / PROJECT REFERENCE / ROOMS / DATE.
     *  - Table 2: PREPARED BY / TELEPHONE / CLIENT CONTACT / REVISION / STATUS.
     *  - Table 3: PROJECT MANAGER / LEAD ENGINEER / ENGINEERS / PROGRAMMER / VEHICLE REGS.
     *
     * Semantic delta vs legacy:
     *  - Every colour + font comes from `$theme` instead of hard-coded const.
     *  - CLIENT CONTACT is name + `addTextBreak()` + email so Word renders it
     *    on two lines. Legacy path concatenated with `"\n"` which Word may
     *    collapse to a space. Empty contact still falls back to
     *    "TBC at site induction" for parity with the PDF blade.
     *  - PROJECT MANAGER falls back to `preparedBy` when `projectManager` is
     *    unset (matches PDF v2 blade's `$cover->projectManager ?: $cover->preparedBy`).
     */
    private function buildCoverFromDto(Section $section, CoverSectionDto $cover, RamsTheme $theme): void
    {
        $brand    = $theme->palette('brand_blue');
        $white    = $theme->palette('white');
        $darkText = $theme->palette('dark_text');
        $body     = $theme->font('body');

        $labelFont = ['name' => $body, 'size' => $theme->size('body'), 'bold' => true, 'color' => $white];
        $valueFont = ['name' => $body, 'size' => $theme->size('body'), 'color' => $darkText];
        $tealCell  = ['bgColor' => $brand];
        $whiteCell = ['bgColor' => $white];

        // Portrait content width matches legacy W_PORT (9866 twips ≈ A4 minus 1.8cm margins).
        $portraitWidth = 9866;
        $colW          = (int) ($portraitWidth / 2);

        $tableStyle = [
            'borderSize'       => $theme->spacing('table_border_size'),
            'borderColor'      => $theme->palette('border'),
            'cellMarginLeft'   => $theme->spacing('cover_cell_padding_twips'),
            'cellMarginRight'  => $theme->spacing('cover_cell_padding_twips'),
            'cellMarginTop'    => $theme->spacing('cell_margin_top_twips'),
            'cellMarginBottom' => $theme->spacing('cell_margin_bottom_twips'),
        ];

        // ── Company name + RAMS title (identical text/style to legacy) ────────
        $section->addText(
            config('rams.company_name'),
            ['name' => $body, 'size' => 24, 'bold' => true, 'color' => $brand],
            ['alignment' => Jc::LEFT],
        );
        $section->addText(
            'RISK ASSESSMENT & METHOD STATEMENT',
            ['name' => $body, 'size' => 17, 'bold' => true, 'color' => $darkText],
            [
                'alignment'         => Jc::LEFT,
                'borderBottomSize'  => 12,
                'borderBottomColor' => $brand,
                'borderBottomSpace' => 4,
                'spaceAfter'        => 200,
            ],
        );

        // ── Table 1: CLIENT / SITE / PROJECT REFERENCE / ROOMS / DATE ─────────
        $table1 = $section->addTable($tableStyle);
        // Legacy behaviour parity: ROOMS displays `implode(', ', rooms)` or empty.
        $roomsDisplay = ! empty($cover->rooms) ? implode(', ', $cover->rooms) : '';
        $rows1 = [
            ['CLIENT',            $cover->client],
            ['SITE',              $cover->site],
            ['PROJECT REFERENCE', $cover->projectRef],
            ['ROOMS',             $roomsDisplay],
            ['DATE',              $cover->date],
        ];
        foreach ($rows1 as [$label, $value]) {
            $row = $table1->addRow(420);
            $row->addCell($colW, $tealCell) ->addText($label,                       $labelFont);
            $row->addCell($colW, $whiteCell)->addText($this->sanitise((string) $value), $valueFont);
        }

        $section->addTextBreak(1);

        // ── Table 2: PREPARED BY / TELEPHONE / CLIENT CONTACT / REVISION / STATUS
        $table2 = $section->addTable($tableStyle);

        // Rows before CLIENT CONTACT (straight label/value).
        $preClientContact = [
            ['PREPARED BY', $cover->preparedBy],
            ['TELEPHONE',   $cover->telephone !== '' ? $cover->telephone : (string) config('rams.company_phone', '')],
        ];
        foreach ($preClientContact as [$label, $value]) {
            $row = $table2->addRow(420);
            $row->addCell($colW, $tealCell) ->addText($label,                       $labelFont);
            $row->addCell($colW, $whiteCell)->addText($this->sanitise((string) $value), $valueFont);
        }

        // CLIENT CONTACT — parity win: explicit line-break between name + email
        // via `addTextBreak()` so Word renders two lines instead of collapsing
        // the "\n" to a space. Empty falls back to the PDF's "TBC" placeholder
        // as a single line so a missing contact never leaves a blank cell.
        $ccRow  = $table2->addRow(420);
        $ccRow->addCell($colW, $tealCell)->addText('CLIENT CONTACT', $labelFont);
        $ccCell = $ccRow->addCell($colW, $whiteCell);

        $ccName  = $this->sanitise($cover->clientContactName);
        $ccEmail = $this->sanitise($cover->clientContactEmail);
        if ($ccName === '' && $ccEmail === '') {
            $ccCell->addText('TBC at site induction', $valueFont);
        } else {
            if ($ccName !== '') {
                $ccCell->addText($ccName, $valueFont);
            }
            if ($ccName !== '' && $ccEmail !== '') {
                $ccCell->addTextBreak();
            }
            if ($ccEmail !== '') {
                $ccCell->addText($ccEmail, $valueFont);
            }
        }

        // Rows after CLIENT CONTACT.
        $postClientContact = [
            ['REVISION', $cover->revision !== '' ? $cover->revision : 'Rev 1.0'],
            ['STATUS',   $cover->status   !== '' ? $cover->status   : 'For Issue'],
        ];
        foreach ($postClientContact as [$label, $value]) {
            $row = $table2->addRow(420);
            $row->addCell($colW, $tealCell) ->addText($label,                       $labelFont);
            $row->addCell($colW, $whiteCell)->addText($this->sanitise((string) $value), $valueFont);
        }

        $section->addTextBreak(1);

        // ── Table 3: PROJECT MANAGER / LEAD ENGINEER / ENGINEERS / PROGRAMMER / VEHICLE REGS
        $table3 = $section->addTable($tableStyle);

        // PDF v2 blade fallback: projectManager falls back to preparedBy.
        $projectManager = $cover->projectManager !== '' ? $cover->projectManager : $cover->preparedBy;
        $additionalEngs = ! empty($cover->additionalEngineers) ? implode(', ', $cover->additionalEngineers) : '';
        $vehiclesDisp   = ! empty($cover->vehicles) ? implode(', ', $cover->vehicles) : '—';

        $rows3 = [
            ['PROJECT MANAGER', $projectManager !== ''         ? $projectManager       : '—'],
            ['LEAD ENGINEER',   $cover->leadEngineer !== ''    ? $cover->leadEngineer  : '—'],
            ['ENGINEERS',       $additionalEngs !== ''         ? $additionalEngs       : '—'],
            ['PROGRAMMER',      $cover->programmer !== ''      ? $cover->programmer    : '—'],
            ['VEHICLE REGS',    $vehiclesDisp],
        ];
        foreach ($rows3 as [$label, $value]) {
            $row = $table3->addRow(420);
            $row->addCell($colW, $tealCell) ->addText($label,                       $labelFont);
            $row->addCell($colW, $whiteCell)->addText($this->sanitise((string) $value), $valueFont);
        }
    }

    /**
     * Strip XML 1.0 control characters that make PhpWord/libxml choke.
     * Kept tabs (0x09), newlines (0x0A), carriage returns (0x0D). Mirrors
     * {@see DocxBuilderService::t()} — duplicated here to avoid exposing a
     * fourth public seam on the legacy class for a one-liner.
     */
    private function sanitise(string $text): string
    {
        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
    }
}
