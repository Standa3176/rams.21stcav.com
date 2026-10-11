<?php

namespace Tests\Feature\Cockpit;

use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Worksheet;
use App\Models\WorksheetPhoto;
use App\Services\DocumentArtifactStorage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quick task 261011-hfb — Task B. The cockpit surfaces PROJECT DATA and THE
 * BRANDED DOCUMENTS, as real controls rather than a footnote.
 *
 * ── WHAT WAS ACTUALLY WRONG ──────────────────────────────────────────────────
 *
 * Nothing was missing. Every document below already existed and was already
 * generated; they were linked only from `projects/show.blade.php` and
 * `site-survey/edit.blade.php`, and never once from the page a project is
 * delivered from. The cockpit's only links were a grey note under the activity
 * panel: "Open full project · Edit AV Works Summary", the second deep-linked
 * to one section of the review page. The user asked for both halves: edit
 * links AND document downloads.
 *
 * ── A DEAD DOWNLOAD IS WORSE THAN NO LINK ────────────────────────────────────
 *
 * Every link is conditional on the thing behind it, and both sides of every
 * condition are asserted below. For the survey downloads the "document" IS the
 * record — all six are rendered on demand from the `SiteSurvey` row by
 * Browsershot/PhpSpreadsheet, with no stored artifact to miss — so the survey
 * row is the honest condition. The two worksheet links are different and are
 * gated on what their controllers actually abort on:
 *
 *   · `worksheets.engineer-report-pdf` 404s unless `hasEngineerActivity()`.
 *   · `worksheets.download` 404s unless `filename` is set AND the artifact is
 *     readable through `DocumentArtifactStorage`.
 *
 * ── THE FENCE ────────────────────────────────────────────────────────────────
 *
 * `'Edit details' => 'Phase 49'` was an entry in
 * `CockpitReadOnlyFenceTest::DEFERRED_AFFORDANCES`. Project-data editing was
 * deliberately deferred; this task pulls it forward at the user's explicit
 * request on 2026-10-11. It is lifted BY NAME and the count moves 19 -> 18 in
 * the same commit that ships the control, per that file's standing rule.
 *
 * The DOCUMENT links are NOT a lift. Their copy was substring-checked against
 * every remaining entry and against both `FORBIDDEN_MARKUP` entries before
 * use: they are new read-only surface and move no count. ('Download' is on no
 * entry — Plan 47-03 lifted it. 'Add document', 'Open register' and
 * 'Export CSV' are Phase 48 and are not used here.)
 */
class CockpitProjectDataAndDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private function project(): Project
    {
        return Project::factory()->create([
            'name'         => 'Documents Surfacing Job',
            'site_address' => '7 Test Street',
            'status'       => Project::STATUS_INSTALLING,
        ]);
    }

    private function package(Project $project): ProjectPackage
    {
        return ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => User::factory()->create()->id,
            'quote_filename' => 'quote.pdf',
            'status'         => ProjectPackage::STATUS_EXTRACTED,
        ]);
    }

    private function survey(Project $project): SiteSurvey
    {
        return SiteSurvey::create([
            'project_id'    => $project->id,
            'user_id'       => User::factory()->create()->id,
            'project_name'  => $project->name,
            'status'        => 'completed',
            'surveyor_name' => 'Fixture Surveyor',
        ]);
    }

    private function worksheet(Project $project): Worksheet
    {
        // Worksheet::create (not ->update) so boot::creating mints the token by
        // direct assignment — access_token is absent from $fillable.
        return Worksheet::create([
            'user_id'      => $project->user_id ?? User::factory()->create()->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
            'status'       => Worksheet::STATUS_FINAL,
        ]);
    }

    private function render(Project $project): string
    {
        config(['cockpit.enabled' => true]);

        return $this->actingAs(User::factory()->create())
            ->get(route('projects.cockpit', $project))
            ->assertOk()
            ->getContent();
    }

    private function dom(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        return new \DOMXPath($dom);
    }

    /** The cav-cockpit subtree only, so the shared layout's chrome is never judged. */
    private function subtree(string $html): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        $node = (new \DOMXPath($dom))
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' cav-cockpit ')]")
            ->item(0);

        $this->assertNotNull($node, 'The cav-cockpit root element is not in the response.');

        return html_entity_decode($dom->saveHTML($node), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function hrefCount(string $html, string $href): int
    {
        return substr_count($html, 'href="' . e($href) . '"');
    }

    // ── 0. NON-VACUITY ───────────────────────────────────────────────────────

    public function test_the_fully_populated_fixture_renders_a_real_cockpit(): void
    {
        $project = $this->project();
        $this->package($project);
        $this->survey($project);
        $this->worksheet($project);

        $subtree = $this->subtree($this->render($project));

        $this->assertStringContainsString('Documents Surfacing Job', $subtree);
        $this->assertStringContainsString('Project modules', $subtree);
        $this->assertStringContainsString('Project data and documents', $subtree);
    }

    // ── B1. PROJECT DATA ─────────────────────────────────────────────────────

    public function test_the_cockpit_links_to_the_project_data_editor_without_deep_linking_a_section(): void
    {
        $project = $this->project();
        $package = $this->package($project);

        $html = $this->render($project);

        $this->assertSame(
            1,
            $this->hrefCount($html, route('project-packages.review.show', $package)),
            'The cockpit does not link to the project-data review page itself. The pre-existing '
            . 'link is deep-linked to #s-room-overviews, which is one section and is not what '
            . '"edit project data" means.',
        );

        $this->assertStringContainsString(
            'Edit details',
            $this->subtree($html),
            'The project-data control copy is missing. "Edit details" is the entry lifted from '
            . 'the read-only fence for this control; if it never renders, the lift was free.',
        );

        // The pre-existing deep link is NOT replaced — CockpitAvWorksSummaryLinkTest
        // pins it, and it goes somewhere different on purpose.
        $this->assertStringContainsString(
            'href="' . e(route('project-packages.review.show', $package)) . '#s-room-overviews"',
            $html,
            'The AV Works Summary deep link was removed. It is a different destination and '
            . 'another test pins it.',
        );
    }

    public function test_a_project_with_no_package_renders_no_project_data_control(): void
    {
        $project = $this->project();
        $this->assertNull($project->latestPackage, 'Fixture sanity: no package.');

        $subtree = $this->subtree($this->render($project));

        $this->assertStringNotContainsString(
            'Edit details',
            $subtree,
            'The project-data control renders without a package to edit — the route cannot even '
            . 'be built, so this would be a dead link.',
        );
        $this->assertStringNotContainsString('project-packages', $subtree);
    }

    // ── B2. THE DOCUMENTS ────────────────────────────────────────────────────

    /** @return array<string, string> label => href */
    private function surveyLinks(SiteSurvey $survey): array
    {
        return [
            'Survey report (PDF) — Client-facing'    => route('site-surveys.pdf', ['siteSurvey' => $survey, 'internal' => 0]),
            'Survey report (PDF) — Internal'         => route('site-surveys.pdf', ['siteSurvey' => $survey, 'internal' => 1]),
            'Survey report (Word) — Internal'        => route('site-surveys.docx', $survey),
            'Completed report (PDF) — Internal'      => route('site-surveys.completed-report', $survey),
            'Completed report (.xlsx) — Internal'    => route('site-surveys.completed-report.xlsx', $survey),
            'Client report (PDF) — Client-facing'    => route('site-surveys.client-report', $survey),
        ];
    }

    public function test_every_survey_document_renders_with_its_internal_or_client_label(): void
    {
        $project = $this->project();
        $survey  = $this->survey($project);

        $html    = $this->render($project);
        $subtree = $this->subtree($html);

        foreach ($this->surveyLinks($survey) as $label => $href) {
            $this->assertSame(
                1,
                $this->hrefCount($html, $href),
                "The survey document link {$href} does not render exactly once.",
            );
            $this->assertStringContainsString(
                $label,
                $subtree,
                "The survey document label \"{$label}\" is missing. Getting Internal and "
                . 'Client-facing the wrong way round would send an internal document to a client, '
                . 'which is why site-survey/edit.blade.php labels them and so does this.',
            );
        }
    }

    public function test_a_project_with_no_survey_renders_no_survey_document_link(): void
    {
        $project = $this->project();
        $this->assertNull($project->latestSurvey, 'Fixture sanity: no survey.');

        $subtree = $this->subtree($this->render($project));

        foreach (['site-surveys', 'completed-report', 'client-report'] as $fragment) {
            $this->assertStringNotContainsString(
                $fragment,
                $subtree,
                "A survey download link ({$fragment}) renders for a project that has no survey.",
            );
        }
    }

    public function test_the_worksheet_pdf_renders_only_once_there_is_engineer_activity(): void
    {
        $project   = $this->project();
        $worksheet = $this->worksheet($project);

        $this->assertFalse($worksheet->hasEngineerActivity(), 'Fixture sanity: no activity yet.');

        $this->assertSame(
            0,
            $this->hrefCount($this->render($project), route('worksheets.engineer-report-pdf', $worksheet)),
            'The engineer-report PDF link renders before there is any engineer activity. Its '
            . 'controller 404s in that state — a dead download.',
        );

        $worksheet->photos()->create([
            'room_name'     => 'Boardroom',
            'bucket'        => WorksheetPhoto::BUCKET_COMPLETION,
            'filename'      => 'worksheet-photos/' . $worksheet->id . '/one.jpg',
            'original_name' => 'one.jpg',
            'mime_type'     => 'image/jpeg',
            'sort_order'    => 0,
        ]);

        $html = $this->render($project->fresh());

        $this->assertSame(
            1,
            $this->hrefCount($html, route('worksheets.engineer-report-pdf', $worksheet)),
            'The engineer-report PDF link does not render once there IS engineer activity.',
        );
        $this->assertStringContainsString(
            'Engineer report (PDF) — Internal',
            $this->subtree($html),
            'The engineer-report label is missing.',
        );
    }

    public function test_the_worksheet_word_download_renders_only_when_the_artifact_is_really_there(): void
    {
        $project   = $this->project();
        $worksheet = $this->worksheet($project);

        $this->assertSame(
            0,
            $this->hrefCount($this->render($project), route('worksheets.download', $worksheet)),
            'The worksheet Word download renders with no filename on the record at all.',
        );

        // A filename but NO artifact — the controller's SECOND guard. This is
        // the state a half-finished generation leaves behind, and it is the one
        // a bare `if ($worksheet->filename)` would have shipped as a dead link.
        $worksheet->filename = 'worksheet-' . $worksheet->id . '.docx';
        $worksheet->save();

        $this->assertSame(
            0,
            $this->hrefCount($this->render($project->fresh()), route('worksheets.download', $worksheet)),
            'The worksheet Word download renders for a filename whose artifact is missing. The '
            . 'controller 404s on exactly that.',
        );

        $storage = app(DocumentArtifactStorage::class);
        $path    = $storage->writePath(DocumentArtifactStorage::TYPE_WORKSHEET, $worksheet->filename);
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, 'not-a-real-docx');

        try {
            $html = $this->render($project->fresh());

            $this->assertSame(
                1,
                $this->hrefCount($html, route('worksheets.download', $worksheet)),
                'The worksheet Word download does not render when the artifact IS on disk.',
            );
            $this->assertStringContainsString(
                'Worksheet (Word) — Internal',
                $this->subtree($html),
                'The worksheet Word label is missing.',
            );
        } finally {
            @unlink($path);
        }
    }

    // ── B3. THE NEW SURFACE IS STILL READ-ONLY ───────────────────────────────

    /**
     * The cockpit's own ruling, re-asserted on the markup this task adds: every
     * new control is an anchor. A form or a script here would be a different
     * kind of change entirely and the fence would catch it next door — but the
     * fence judges a fixture, and this one judges the fully populated project
     * where every new link actually renders.
     */
    public function test_the_new_section_adds_no_form_no_control_and_no_script(): void
    {
        $project = $this->project();
        $this->package($project);
        $this->survey($project);
        $this->worksheet($project);

        $subtree = $this->subtree($this->render($project));

        $xpath = $this->dom($subtree);
        foreach (['form', 'input', 'select', 'textarea', 'button', 'script'] as $tag) {
            $this->assertSame(
                0,
                $xpath->query('//*[contains(@class, "cav-docs")]//' . $tag)->length,
                "The project-data-and-documents section contains a <{$tag}>. This page is "
                . 'read-only and every control it adds is an anchor.',
            );
        }

        // Non-vacuity: the section is really there to have been judged.
        $this->assertGreaterThan(
            0,
            $xpath->query('//*[contains(@class, "cav-docs")]')->length,
            'The section this test judges does not exist, so every zero above is a zero over '
            . 'nothing.',
        );
    }

    /**
     * Phase 48's copy stays banned. Checked here as well as in the fence
     * because this is the task that adds document links, which is exactly where
     * somebody would reach for "Add document" or "Export CSV" next.
     */
    public function test_this_task_ships_none_of_phase_48s_deferred_copy(): void
    {
        $project = $this->project();
        $this->package($project);
        $this->survey($project);
        $this->worksheet($project);

        $subtree = $this->subtree($this->render($project));

        foreach (['Add document', 'Open register', 'Export CSV', 'Upload files', 'Issue to client', 'Mark as sent'] as $banned) {
            $this->assertStringNotContainsString(
                $banned,
                $subtree,
                "\"{$banned}\" is still deferred to Phase 48. Only 'Edit details' was lifted, and "
                . 'only because the user asked for project-data editing by name.',
            );
        }
    }
}
