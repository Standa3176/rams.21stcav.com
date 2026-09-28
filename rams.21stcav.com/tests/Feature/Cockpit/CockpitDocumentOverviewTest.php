<?php

namespace Tests\Feature\Cockpit;

use App\Models\OmManual;
use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\SiteSurvey;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Support\Cockpit\CockpitModulePresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quick task 260928-dq2 — the module drawer's Overview tab must report what the
 * module HOLDS, not only what visits it has.
 *
 * THE DEFECT THIS FILE EXISTS TO PIN, in the user's words: *"iN OVERVIEW , TXT
 * SAYS RAMS has nothing recorded against it yet. EVEN THOUGH THERE ARE 2
 * VERSIONS OF RAMS UNDER FILES"*.
 *
 * `panel.blade.php`'s empty sentence was the `@else` of a `@foreach ($visits)`,
 * so it measured visits and only visits. RAMS and the O&M have no visits BY
 * DESIGN — `CockpitModulePresenter::MODULE_MAP` gives both `'visit_types' => []`
 * and `VisitLinkIssuer`'s docblock says why that rule is not arbitrary — so the
 * sentence was PERMANENT on two of the four rows.
 *
 * ── WHY THIS FILE COUNTS WHAT IT RENDERS ─────────────────────────────────────
 *
 * The bug survived a green cockpit suite of 489 tests because NOTHING HAD EVER
 * RENDERED A DOCUMENT-ONLY MODULE WITH DOCUMENTS IN IT. A test that renders one
 * module in one state proves nothing about the other fifteen combinations, and
 * the user has now found four defects that green suites missed, every one for
 * that reason.
 *
 * So the matrix below is EXHAUSTIVE and its size is ASSERTED: four modules
 * (two that hold visits, two that cannot) times four document states (none /
 * one / several / superseded). `test_every_module_and_state_is_rendered()`
 * counts the renders and fails if the matrix is ever quietly narrowed, and the
 * module list is read from `CockpitModulePresenter::moduleMap()` rather than
 * typed here, so a fifth module row joins the matrix on the day it is added
 * instead of silently escaping it.
 */
class CockpitDocumentOverviewTest extends TestCase
{
    use RefreshDatabase;

    /** The four document states every module is rendered in. */
    private const STATES = ['none', 'one', 'several', 'superseded'];

    /** How many documents each state seeds. The hint must appear iff this is 0. */
    private const STATE_DOCUMENT_COUNT = [
        'none'       => 0,
        'one'        => 1,
        'several'    => 3,
        // One live document plus one superseded one: a superseded document is
        // still a record the module HOLDS, so it is listed and says so.
        'superseded' => 2,
    ];

    private const HINT = 'has nothing recorded against it yet';

    // ── Fixtures ──────────────────────────────────────────────────────────

    private function project(): Project
    {
        return Project::factory()->create([
            'name'         => 'Overview Reporting Job',
            'ref'          => 'Q-4628',
            'client_name'  => 'Northbank Media',
            'site_address' => '12 Wharf Road, Leeds',
            'status'       => Project::STATUS_INSTALLING,
        ]);
    }

    /**
     * Seed `$module` with the documents `$state` calls for.
     *
     * Keyed on the module so the matrix can iterate module keys as data; this is
     * a TEST FIXTURE, which is the one place a document key is legitimate — the
     * production code under test contains none.
     */
    private function seedDocuments(Project $project, string $module, string $state): void
    {
        $count      = self::STATE_DOCUMENT_COUNT[$state];
        $superseded = $state === 'superseded';

        for ($i = 0; $i < $count; $i++) {
            // The last row of the `superseded` state is the superseded one.
            $status = $superseded && $i === $count - 1 ? 'superseded' : 'completed';

            match ($module) {
                'site_survey' => SiteSurvey::create([
                    'user_id'      => User::factory()->create()->id,
                    'project_id'   => $project->id,
                    'project_name' => $project->name,
                    'filename'     => "survey-{$i}.docx",
                    'status'       => $status === 'superseded' ? 'draft' : 'completed',
                ]),
                'worksheet'   => Worksheet::factory()->create([
                    'project_id' => $project->id,
                    'filename'   => "worksheet-{$i}.docx",
                ]),
                'rams'        => RamsDocument::factory()->create([
                    'project_id' => $project->id,
                    'filename'   => "method-statement-{$i}.docx",
                    'status'     => $status,
                ]),
                'om'          => OmManual::factory()->create([
                    'project_id' => $project->id,
                    'filename'   => "om-manual-{$i}.docx",
                ]),
                default       => null,
            };
        }
    }

    private function overview(Project $project, string $module): string
    {
        config(['cockpit.enabled' => true]);

        $url = route('projects.cockpit', ['project' => $project, 'module' => $module, 'tab' => 'overview']);

        $body = $this->actingAs(User::factory()->create())->get($url)->assertOk()->getContent();

        return $this->subtree($body, 'cav-panel');
    }

    private function subtree(string $html, string $class): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $node = (new \DOMXPath($dom))
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]")
            ->item(0);

        $this->assertNotNull($node, "The .{$class} element was not found in the response.");

        return html_entity_decode($dom->saveHTML($node), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private function countByClass(string $html, string $class): int
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        return (new \DOMXPath($dom))
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' {$class} ')]")
            ->length;
    }

    /** @return array<int, string> */
    private function modules(): array
    {
        return array_keys(CockpitModulePresenter::moduleMap());
    }

    // ── The matrix ────────────────────────────────────────────────────────

    /**
     * THE COUNTED PROOF. Sixteen renders — four modules times four states — and
     * the count is asserted so the matrix cannot be narrowed to a sample, which
     * is exactly how this defect got past 489 green tests.
     */
    public function test_every_module_and_state_is_rendered(): void
    {
        $rendered = [];

        foreach ($this->modules() as $module) {
            foreach (self::STATES as $state) {
                $project = $this->project();
                $this->seedDocuments($project, $module, $state);

                $panel = $this->overview($project, $module);
                $rows  = $this->countByClass($panel, 'cav-file');
                $hint  = str_contains($panel, self::HINT);

                $expectedRows = self::STATE_DOCUMENT_COUNT[$state];

                $this->assertSame(
                    $expectedRows,
                    $rows,
                    "{$module}/{$state}: Overview listed {$rows} documents where the project holds {$expectedRows}."
                );

                // THE WHOLE POINT: the sentence appears IFF the module is empty.
                $this->assertSame(
                    $expectedRows === 0,
                    $hint,
                    $expectedRows === 0
                        ? "{$module}/{$state}: an empty module must say so."
                        : "{$module}/{$state}: Overview claims nothing is recorded while {$expectedRows} documents exist. "
                          .'That is the 260928-dq2 defect.'
                );

                $rendered[] = "{$module}/{$state}";
            }
        }

        $this->assertCount(
            16,
            $rendered,
            'The matrix is four modules times four document states. A smaller number means a module or a '
            .'state escaped the proof — which is how this defect shipped.'
        );

        // AND IT REALLY WAS FOUR MODULES OF EACH KIND — two that hold visits and
        // two that cannot. Asserted from the map so the matrix cannot silently
        // become four copies of the same kind of module.
        $withVisits = array_filter(
            CockpitModulePresenter::moduleMap(),
            static fn (array $definition): bool => $definition['visit_types'] !== [],
        );

        $this->assertCount(2, $withVisits, 'Two modules hold visits — the site survey and the worksheet.');
        $this->assertCount(2, array_diff_key(CockpitModulePresenter::moduleMap(), $withVisits), 'Two hold none.');
    }

    /**
     * THE ORIGINAL REPORT, RENDERED. Two RAMS documents on the project, and
     * Overview must not say nothing is recorded.
     */
    public function test_two_rams_documents_are_reported_on_overview(): void
    {
        $project = $this->project();

        RamsDocument::factory()->create(['project_id' => $project->id, 'filename' => 'rams-v1.docx']);
        RamsDocument::factory()->create(['project_id' => $project->id, 'filename' => 'rams-v2.docx']);

        $panel = $this->overview($project, 'rams');

        $this->assertStringNotContainsString(self::HINT, $panel);
        $this->assertStringContainsString('rams-v1.docx', $panel);
        $this->assertStringContainsString('rams-v2.docx', $panel);
        $this->assertSame(2, $this->countByClass($panel, 'cav-file'));
    }

    /** The O&M is fixed by the SAME branch, and that is asserted, not assumed. */
    public function test_the_om_manual_is_reported_on_overview_by_the_same_change(): void
    {
        $project = $this->project();

        OmManual::factory()->create(['project_id' => $project->id, 'filename' => 'om-v1.docx']);

        $panel = $this->overview($project, 'om');

        $this->assertStringNotContainsString(self::HINT, $panel);
        $this->assertStringContainsString('om-v1.docx', $panel);
    }

    /**
     * A VISIT MODULE WITH DOCUMENTS AND NO VISITS IS ALSO NOT EMPTY. This is the
     * second false sentence the fix removes, and the reason the Documents card is
     * NOT gated on the module type.
     */
    public function test_a_visit_module_with_documents_and_no_visits_does_not_claim_emptiness(): void
    {
        $project = $this->project();

        Worksheet::factory()->create(['project_id' => $project->id, 'filename' => 'worksheet-only.docx']);

        $panel = $this->overview($project, 'worksheet');

        $this->assertStringNotContainsString(self::HINT, $panel);
        $this->assertStringContainsString('worksheet-only.docx', $panel);
    }

    /** A visit module's visits card is unchanged — it still reports visits. */
    public function test_a_visit_module_still_reports_its_visits(): void
    {
        $project = $this->project();

        Visit::factory()->count(2)->create([
            'project_id' => $project->id,
            'type'       => Visit::TYPE_INSTALL,
        ]);

        $panel = $this->overview($project, 'worksheet');

        $this->assertStringNotContainsString(self::HINT, $panel);
        $this->assertStringContainsString('Visits', $panel);
    }

    /**
     * A MODULE THAT HOLDS NO VISITS NEVER RENDERS A VISITS CARD, even with visit
     * rows on the project. The structural half of the fix: the card is gated on
     * the module TYPE, not merely on a collection being non-empty.
     */
    public function test_a_document_only_module_renders_no_visits_card(): void
    {
        $project = $this->project();

        Visit::factory()->count(2)->create(['project_id' => $project->id]);
        RamsDocument::factory()->create(['project_id' => $project->id, 'filename' => 'rams-only.docx']);

        $panel = $this->overview($project, 'rams');

        $this->assertStringNotContainsString('>Visits<', $panel);
        $this->assertStringContainsString('>Documents<', $panel);
    }

    // ── Regenerate, Edit and the finished artefact ─────────────────────────

    /**
     * The user: *"ONCE A DOC HAS BEEN CREATED , CAN THE BUTTON UNDER OVERVIEW
     * CHANGES TO REGENERATE AND EDIT"*.
     */
    public function test_the_closed_control_reads_create_before_a_document_exists(): void
    {
        $panel = $this->overview($this->project(), 'rams');

        $this->assertStringContainsString('Create document', $panel);
        $this->assertStringNotContainsString('Regenerate', $panel);
    }

    public function test_the_closed_control_reads_regenerate_once_a_document_exists(): void
    {
        $project = $this->project();
        RamsDocument::factory()->create(['project_id' => $project->id]);

        $panel = $this->overview($project, 'rams');

        $this->assertStringContainsString('Regenerate', $panel);
        $this->assertStringNotContainsString('Create document', $panel);

        // AND IT STILL NAMES THE FORMATS IT OFFERS (D-05 / DL-05 is not undone).
        $this->assertStringContainsString('Word', $panel);
        $this->assertStringContainsString('PDF', $panel);
    }

    /** The verb flips for EVERY module, because it is derived, not written. */
    public function test_the_verb_flips_for_every_module(): void
    {
        $checked = [];

        foreach ($this->modules() as $module) {
            $bare = $this->overview($this->project(), $module);
            $this->assertStringContainsString('Create document', $bare, "{$module}: empty module must offer Create.");

            $project = $this->project();
            $this->seedDocuments($project, $module, 'one');
            $held = $this->overview($project, $module);

            $this->assertStringContainsString('Regenerate', $held, "{$module}: a module that holds a document must offer Regenerate.");

            $checked[] = $module;
        }

        $this->assertCount(4, $checked, 'All four module rows, not a sample.');
    }

    /**
     * EDIT IS THE REVIEW PAGE, which is the edit form (`review.blade.php:541`
     * calls itself "Edit & Download form"). The word comes from the presenter's
     * map, so a module whose route only SHOWS its document says "View".
     */
    public function test_a_rams_row_offers_edit_pointing_at_the_review_form(): void
    {
        $project = $this->project();
        $rams    = RamsDocument::factory()->create(['project_id' => $project->id]);

        $panel = $this->overview($project, 'rams');

        $this->assertStringContainsString('>Edit<', $panel);
        $this->assertStringContainsString(route('rams.review', $rams), $panel);
    }

    public function test_a_survey_row_offers_view_not_edit_because_its_route_only_shows(): void
    {
        $project = $this->project();
        $this->seedDocuments($project, 'site_survey', 'one');

        $panel = $this->overview($project, 'site_survey');

        $this->assertStringContainsString('>View<', $panel);
        $this->assertStringNotContainsString('>Edit<', $panel);
    }

    /**
     * THE FINISHED DOCUMENT IS REACHABLE — Word and PDF — and the word
     * "Download" is NOT used, so no fence entry is lifted by this task.
     */
    public function test_a_rams_row_reaches_the_finished_word_and_pdf(): void
    {
        $project = $this->project();
        $rams    = RamsDocument::factory()->create(['project_id' => $project->id]);

        $panel = $this->overview($project, 'rams');

        $this->assertStringContainsString(route('rams.download', $rams), $panel);
        $this->assertStringContainsString(route('rams.download-pdf', $rams), $panel);
        $this->assertStringContainsString('>Word<', $panel);
        $this->assertStringContainsString('>PDF<', $panel);

        // ENTRY 21 OF DEFERRED_AFFORDANCES IS NOT LIFTED. Case-sensitive, like
        // the fence's own assertion: the route PATH is lowercase `/download`,
        // the COPY never uses the word at all.
        $this->assertStringNotContainsString('Download', $panel);
    }

    /**
     * THE WORKSHEET HAS NO PDF (DC-07) AND THE ROW MUST NOT INVENT ONE. Read
     * from the same `formats` map the Generate control derives its copy from, so
     * the row and the control cannot drift into disagreement.
     */
    public function test_a_worksheet_row_offers_word_only(): void
    {
        $project   = $this->project();
        $worksheet = Worksheet::factory()->create(['project_id' => $project->id]);

        $panel = $this->overview($project, 'worksheet');

        $this->assertStringContainsString(route('worksheets.download', $worksheet), $panel);
        $this->assertStringContainsString('>Word<', $panel);
        $this->assertStringNotContainsString('>PDF<', $panel);
    }

    /**
     * A SUPERSEDED DOCUMENT IS LISTED AND SAYS SO. It is still a record the
     * module holds; dropping it would make the Overview disagree with the Files
     * tab about what exists.
     */
    public function test_a_superseded_document_is_listed_and_labelled(): void
    {
        $project = $this->project();

        RamsDocument::factory()->create([
            'project_id' => $project->id,
            'filename'   => 'superseded.docx',
            'status'     => 'superseded',
        ]);

        $panel = $this->overview($project, 'rams');

        $this->assertStringContainsString('superseded.docx', $panel);
        $this->assertStringContainsString('Superseded', $panel);
    }

    // ── The compliance failure behind the PDF anchor ───────────────────────

    /**
     * PROMOTING THE PDF LINK MUST NOT PROMOTE A 500.
     *
     * `RamsController::downloadPdf()` raises `RamsGenerationException` for
     * GATE-06/07/09 via `RamsComplianceUpgradeService::upgrade()`. It is caught
     * (`:875-882`) and returned as `back()->with('error', ...)`, so a PM who
     * clicks PDF on a non-compliant document lands back on the page they came
     * from with the gate's own sentence in the layout's flash banner.
     *
     * A document with NO generated_data takes the same shape of exit (the
     * earliest guard in the method) — which is what a queued or failed
     * regeneration looks like, and the state a PM is most likely to click.
     */
    public function test_the_pdf_link_redirects_with_a_message_rather_than_failing(): void
    {
        $project = $this->project();

        $rams = RamsDocument::factory()->create([
            'project_id'     => $project->id,
            'generated_data' => null,
        ]);

        $referer = route('projects.cockpit', ['project' => $project, 'module' => 'rams']);

        $response = $this->actingAs(User::factory()->create())
            ->get(route('rams.download-pdf', $rams), ['referer' => $referer]);

        $response->assertRedirect($referer);
        $response->assertSessionHas('error');

        $this->assertIsString(session('error'));
        $this->assertNotSame('', session('error'), 'A redirect with an empty message is a silent failure.');
    }

    // ── The structural guard ──────────────────────────────────────────────

    /**
     * THE PANEL NAMES NO DOCUMENT AND BRANCHES ON NO DOCUMENT KEY.
     *
     * ⚠ COMMENTS ARE STRIPPED FIRST. This repo has had seven near-misses where a
     * guard test matched a COMMENT instead of code — and this panel's comments
     * discuss RAMS and the O&M at length by necessity, because that is what the
     * decisions are about. So the assertion is made against the EXECUTABLE text
     * only.
     */
    public function test_the_panel_branches_on_no_document_key(): void
    {
        $code = $this->executableBlade('resources/views/components/cockpit/panel.blade.php');

        $this->assertNotSame('', $code, 'The panel source was unreadable — this proof would be vacuous.');

        foreach (["'site_survey'", "'worksheet'", "'rams'", "'om'"] as $key) {
            $this->assertStringNotContainsString(
                $key,
                $code,
                "panel.blade.php names the document key {$key} in executable code. This component switches on "
                .'TYPE — `has_visits` — and must name no document.'
            );
        }
    }

    public function test_the_document_row_branches_on_no_document_key(): void
    {
        $code = $this->executableBlade('resources/views/components/cockpit/document-row.blade.php');

        $this->assertNotSame('', $code);

        foreach (["'site_survey'", "'worksheet'", "'rams'", "'om'", 'Edit<'] as $key) {
            $this->assertStringNotContainsString(
                $key,
                $code,
                "document-row.blade.php hard-codes {$key}. Both the route AND the verb come from the "
                .'presenter, so a view cannot give a show-only route an edit affordance.'
            );
        }
    }

    /** A Blade file with every `{{-- --}}` and `//` comment removed. */
    private function executableBlade(string $relative): string
    {
        $source = (string) file_get_contents(base_path($relative));

        $source = (string) preg_replace('/\{\{--.*?--\}\}/s', '', $source);

        return (string) preg_replace('~^\s*//.*$~m', '', $source);
    }
}
