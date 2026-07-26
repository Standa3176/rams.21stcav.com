<?php

namespace App\Services;

use App\Models\RamsDocument;
use App\Services\Rams\RamsDisplayPatchService;
use App\Support\Rams\RamsDocumentComposer;
use App\Support\Rams\RamsTheme;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;

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
 * Skeleton contract (this commit):
 * - Registered as a singleton via Laravel's auto-DI (no binding needed —
 *   every dependency is auto-wireable).
 * - `build()` delegates to the legacy {@see DocxBuilderService} internally,
 *   so with the kill switch flipped ON, output is byte-identical to the
 *   pre-refactor path. Commit 2 replaces the delegation with the ported
 *   section-by-section implementation that consumes DTO + Theme.
 *
 * Section-porting plan (Plan 04 commit 2, mirroring Plan 03's blade):
 *  - DTO-consuming: cover, doc_control, company_info, signoff.
 *  - Legacy raw reads: every other section (16 total). Full DTO adoption
 *    for the compliance-upgrade surface is reserved for Plan 05 parity
 *    sweep — see .planning/phases/260726-rf3.../deferred-items.md.
 *
 * @see \App\Services\PdfService::buildRams  Kill-switch pattern (Plan 03).
 * @see \App\Support\Rams\RamsDocumentComposer  Post-patch composer.
 * @see \App\Support\Rams\RamsTheme  Shared design tokens.
 */
class DocxBuilderServiceV2
{
    public function __construct(
        private readonly DocumentTemplateService  $templates,
        private readonly DocumentArtifactStorage  $artifacts,
        private readonly RamsDisplayPatchService  $patchService,
        private readonly RamsDocumentComposer     $composer,
        private readonly RamsTheme                $theme,
    ) {}

    /**
     * Skeleton — Commit 1 delegates to the legacy path so a flipped kill
     * switch still produces a valid file. Commits 2-4 replace the
     * delegation with per-section build methods that consume the
     * RamsDocumentDTO + RamsTheme injected above.
     *
     * NOTE: MUST call `buildLegacy()` — calling `build()` recurses because
     * `build()` re-checks the kill switch and re-dispatches here. Bug fix
     * salvaged after the Plan 04 executor spend-limit cutoff caught this
     * mid-restructure.
     */
    public function build(array $data, RamsDocument $record): string
    {
        // Belt-and-braces — PhpWord escapes &, <, > when this flag is on.
        Settings::setOutputEscapingEnabled(true);

        return app(DocxBuilderService::class)->buildLegacy($data, $record);
    }
}
