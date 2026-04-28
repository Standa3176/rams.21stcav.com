<?php

namespace App\Services;

use App\Models\OmManual;
use App\Models\RamsDocument;
use Illuminate\Support\Str;

/**
 * Renders RAMS and O&M documents to PDF via Browsershot (chrome-headless-shell).
 *
 * Previously used Dompdf directly — that produced brittle output and
 * duplicated render code with SurveyPdfService. Now both pipelines share
 * one engine via PdfRenderService.
 *
 * Outputs land under storage/app/documents/{rams,om-manuals}/ via the
 * H-07 DocumentArtifactStorage convention so legacy/current path resolution
 * stays consistent with the DOCX writers.
 *
 * Running headers/footers (the equivalent of dompdf's `position: fixed`
 * trick that Chromium doesn't honour the same way) are passed via the
 * options array — Chromium repeats them on every page natively.
 */
class PdfService
{
    public function __construct(
        private readonly PdfRenderService        $renderer,
        private readonly DocumentArtifactStorage $artifacts,
    ) {}

    // ── Public methods ────────────────────────────────────────────────────────

    /**
     * Render a RAMS document to PDF and return its absolute path.
     */
    public function buildRams(RamsDocument $rams): string
    {
        $filename = $this->filenameFor('rams', $rams->id, $rams->project_name ?? 'rams');
        $path     = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_RAMS, $filename);

        $ref    = $rams->project_ref  ?? ($rams->reviewed_data['project']['ref']    ?? '');
        $client = $rams->client_name  ?? ($rams->reviewed_data['project']['client'] ?? '');

        return $this->renderer->fromBlade('pdf.rams', [
            'rams' => $rams,
            'data' => $rams->generated_data ?? [],
        ], $path, [
            'headerHtml'   => $this->headerHtml('RAMS', $ref, $client),
            'footerHtml'   => $this->footerHtml(),
            'marginTop'    => 22,
            'marginBottom' => 18,
            'marginLeft'   => 0,
            'marginRight'  => 0,
        ]);
    }

    /**
     * Render an O&M Manual to PDF and return its absolute path.
     */
    public function buildOmManual(OmManual $manual): string
    {
        $filename = $this->filenameFor('om-manuals', $manual->id, $manual->project_name ?? 'om-manual');
        $path     = $this->artifacts->writePath(DocumentArtifactStorage::TYPE_OM, $filename);

        $ref    = $manual->project_ref  ?? '';
        $client = $manual->client_name  ?? '';

        return $this->renderer->fromBlade('pdf.om-manual', [
            'manual' => $manual,
            'data'   => $manual->generated_data ?? [],
        ], $path, [
            // Phase 7 — OM uses the canonical 21CAV brand teal #01889F.
            // RAMS keeps the legacy palette via the headerHtml/footerHtml defaults.
            'headerHtml'   => $this->headerHtml('O&M Manual', $ref, $client, '#01889F'),
            'footerHtml'   => $this->footerHtml('#01889F'),
            'marginTop'    => 22,
            'marginBottom' => 18,
            'marginLeft'   => 0,
            'marginRight'  => 0,
        ]);
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Build the legacy filename pattern ({subfolder}_{id}_{slug}_{ymd}.pdf) so
     * existing email/download flows that reference the filename keep working.
     */
    private function filenameFor(string $subfolder, int $id, string $projectName): string
    {
        return implode('_', [
            $subfolder,
            $id,
            Str::slug($projectName) ?: 'untitled',
            now()->format('Ymd'),
        ]) . '.pdf';
    }

    /**
     * Running header HTML for Puppeteer. All styles MUST be inline because
     * Chromium's headerTemplate runs in a separate (mostly-stripped) document
     * — class selectors and external stylesheets do not apply. Default
     * font-size is also very small (~5pt), so we set it explicitly.
     *
     * $brandColor lets each document pipeline pass its own brand teal —
     * defaults to the RAMS legacy #1B7A7A so RAMS rendering is unchanged.
     * Phase 7 OM passes the canonical 21CAV brand #01889F.
     */
    private function headerHtml(string $docLabel, string $ref, string $client, string $brandColor = '#1B7A7A'): string
    {
        $company  = (string) config('rams.company_name', '21st Century AV Ltd');
        $left     = htmlspecialchars($company, ENT_QUOTES, 'UTF-8')
            . ' &nbsp;|&nbsp; ' . htmlspecialchars($docLabel, ENT_QUOTES, 'UTF-8')
            . ($ref    ? ' &nbsp;|&nbsp; ' . htmlspecialchars($ref,    ENT_QUOTES, 'UTF-8') : '')
            . ($client ? ' &nbsp;|&nbsp; ' . htmlspecialchars($client, ENT_QUOTES, 'UTF-8') : '');

        return <<<HTML
            <div style="font-family: Arial, sans-serif; font-size: 8.5pt; width: 100%; padding: 4mm 18mm 3mm 18mm; box-sizing: border-box; color: {$brandColor}; border-bottom: 1pt solid {$brandColor}; -webkit-print-color-adjust: exact;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="text-align: left; font-weight: 700; color: {$brandColor};">{$left}</td>
                        <td style="text-align: right; color: #555; white-space: nowrap; font-weight: normal;">Page <span class="pageNumber"></span> of <span class="totalPages"></span></td>
                    </tr>
                </table>
            </div>
            HTML;
    }

    /**
     * Running footer HTML for Puppeteer. See header note re: inline styles.
     *
     * $brandColor parameter mirrors headerHtml — RAMS uses the default legacy
     * teal, OM passes the canonical 21CAV brand colour from buildOmManual.
     */
    private function footerHtml(string $brandColor = '#1B7A7A'): string
    {
        $address = htmlspecialchars((string) config('rams.company_address', 'Thames Court, 2 Richfield Avenue, Reading, Berkshire'), ENT_QUOTES, 'UTF-8');
        $phone   = htmlspecialchars((string) config('rams.company_phone',   '01189 977770'),                                        ENT_QUOTES, 'UTF-8');
        $website = htmlspecialchars((string) config('rams.company_website', 'www.21stcenturyav.com'),                              ENT_QUOTES, 'UTF-8');

        return <<<HTML
            <div style="font-family: Arial, sans-serif; font-size: 7.5pt; width: 100%; padding: 2mm 18mm 4mm 18mm; box-sizing: border-box; color: #666; border-top: 0.75pt solid {$brandColor}; -webkit-print-color-adjust: exact;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="text-align: left;">{$address} &nbsp;|&nbsp; {$phone} &nbsp;|&nbsp; {$website}</td>
                        <td style="text-align: right; white-space: nowrap; font-style: italic;">CONFIDENTIAL &ndash; For Authorised Persons Only</td>
                    </tr>
                </table>
            </div>
            HTML;
    }
}
