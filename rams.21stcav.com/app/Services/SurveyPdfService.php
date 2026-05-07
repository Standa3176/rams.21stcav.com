<?php

namespace App\Services;

use App\Models\SiteSurvey;
use Illuminate\Support\Facades\Storage;

/**
 * Renders the site-survey PDFs (completed-summary, blank form, pre-populated
 * field form) via Browsershot. Previously used Dompdf with HTML built up by
 * private string concatenation; now the HTML lives in Blade views under
 * resources/views/pdf/site-survey/ and this class is a thin orchestrator.
 *
 * Note: site-surveys/ is NOT one of the H-07 DocumentArtifactStorage types,
 * so writes still go through Storage::disk('local')->path('site-surveys/...')
 * — they predate the H-07 unification and have no migration mandate yet.
 *
 * Running footer (the equivalent of dompdf's `position: running(footer)` or
 * the `@page { @bottom-right }` rule that Chromium doesn't fully honour) is
 * passed to Browsershot via the options array — Chromium repeats it on every
 * page and supports `<span class="pageNumber">` / `<span class="totalPages">`
 * placeholders.
 */
class SurveyPdfService
{
    public function __construct(private readonly PdfRenderService $renderer) {}

    /**
     * Build a PDF summary of a completed site survey and return its absolute
     * path. Side-effect: updates $survey->filename so the controller can
     * stream the same file by name.
     */
    public function buildSummary(SiteSurvey $survey): string
    {
        $survey->loadMissing(['rooms.photos', 'rooms.questions']);

        $filename = 'site_survey_' . $survey->id . '_' . now()->format('Ymd_His') . '.pdf';
        $path     = Storage::disk('local')->path('site-surveys/' . $filename);

        $this->renderer->fromBlade(
            'pdf.site-survey.summary',
            ['survey' => $survey],
            $path,
            $this->browsershotOptions(
                'Site Survey | ' . $survey->project_name . ' | Generated ' . now()->format('d/m/Y'),
            ),
        );

        $survey->update(['filename' => $filename]);

        return $path;
    }

    /**
     * Build a blank printable site survey form PDF and return its absolute
     * path.
     */
    public function buildBlank(): string
    {
        $path = Storage::disk('local')->path('site-surveys/blank-survey-form.pdf');

        return $this->renderer->fromBlade(
            'pdf.site-survey.blank',
            [],
            $path,
            $this->browsershotOptions('Site Survey Form | Confidential'),
        );
    }

    /**
     * Build an in-memory printable Field Survey Form PDF pre-populated with
     * project/client/site header, planned works + planned quote kit, and a
     * per-room section with blank manual-fill areas for power / network /
     * access / notes / sign-off.
     *
     * Returns the raw PDF bytes — no disk write, no DB mutation. Used by the
     * public /survey/{token}/download-form endpoint so engineers can complete
     * the survey by hand on-site when the mobile wizard isn't viable.
     */
    public function buildFieldFormContents(SiteSurvey $survey): string
    {
        $survey->loadMissing(['rooms', 'project.latestPackage']);

        return $this->renderer->fromBlade(
            'pdf.site-survey.field-form',
            ['survey' => $survey],
            null,
            $this->browsershotOptions(
                'Field Survey Form | ' . $survey->project_name . ' | Generated ' . now()->format('d/m/Y'),
            ),
        );
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * Common Browsershot option set for site-survey PDFs: a Puppeteer-style
     * footer with the supplied label on the left and "Page N of M" on the
     * right, plus margins sized to leave room for the running footer.
     *
     * Inline styles only — Chromium's headerTemplate / footerTemplate runs
     * in a separate document where class selectors and external stylesheets
     * do not apply, and the default font-size is ~5pt.
     */
    private function browsershotOptions(string $footerLeft): array
    {
        $company  = (string) config('rams.company_name', '21st Century AV Ltd');
        $left     = htmlspecialchars($company, ENT_QUOTES, 'UTF-8') . ' &mdash; ' . htmlspecialchars($footerLeft, ENT_QUOTES, 'UTF-8');

        $footerHtml = <<<HTML
            <div style="font-family: 'DejaVu Sans', Helvetica, Arial, sans-serif; font-size: 7pt; width: 100%; padding: 3mm 10mm 4mm 10mm; box-sizing: border-box; color: #666; border-top: 0.5pt solid #ddd; -webkit-print-color-adjust: exact;">
                <table style="width: 100%; border-collapse: collapse;">
                    <tr>
                        <td style="text-align: left;">{$left}</td>
                        <td style="text-align: right; white-space: nowrap;">Page <span class="pageNumber"></span> of <span class="totalPages"></span></td>
                    </tr>
                </table>
            </div>
            HTML;

        return [
            'footerHtml'   => $footerHtml,
            'marginTop'    => 12,
            'marginBottom' => 16,
            'marginLeft'   => 10,
            'marginRight'  => 10,
        ];
    }
}
