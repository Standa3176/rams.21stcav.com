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

        $this->renderer->fromBlade('pdf.site-survey.summary', ['survey' => $survey], $path);

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

        return $this->renderer->fromBlade('pdf.site-survey.blank', [], $path);
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

        return $this->renderer->fromBlade('pdf.site-survey.field-form', ['survey' => $survey]);
    }
}
