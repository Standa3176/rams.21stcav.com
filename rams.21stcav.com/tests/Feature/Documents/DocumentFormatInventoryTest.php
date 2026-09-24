<?php

namespace Tests\Feature\Documents;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyRoom;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * DC-06 / DC-07 — the document format inventory, as an assertion rather than prose.
 *
 * `.planning/phases/46.2-doc-creation-cockpit/46.2-FORMAT-INVENTORY.md` holds the
 * human-readable eight-cell Word x PDF matrix for the four documents the cockpit
 * creates. A markdown table rots silently; this test is the record that does not.
 *
 * Two halves:
 *   - every NON-null cell must resolve to a real named route, so a renamed or
 *     deleted route fails here rather than as a dead control on a PM's screen
 *     (the same discipline as `quick-actions.blade.php`'s `Route::has()` guard);
 *   - the set of NULL cells must be EXACTLY one — the Worksheet PDF. Kept exact,
 *     never a floor: if a later plan quietly drops another format, this goes red.
 *
 * @see .planning/phases/46.2-doc-creation-cockpit/46.2-FORMAT-INVENTORY.md
 */
class DocumentFormatInventoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The measured inventory. document key => ['word' => route|null, 'pdf' => route|null].
     *
     * Measured 2026-09-24 by grep over `app/` and `routes/web.php` — see the
     * inventory file for the pasted command output behind every cell.
     */
    private const FORMAT_ROUTES = [
        'site_survey' => [
            'word' => 'site-surveys.docx',          // SiteSurveyDocxService — wired by plan 46.2-02; had ZERO callers before it
            'pdf'  => 'site-surveys.pdf',           // SurveyPdfService::buildSummary
        ],
        'worksheet' => [
            'word' => 'worksheets.download',        // WorksheetDocxService
            // DC-07, NOT DELIVERED. There is no worksheet PDF renderer at all:
            // no `resources/views/pdf/worksheet*.blade.php` exists, and
            // `worksheets.engineer-report-pdf` is a DIFFERENT document (the
            // engineer's activity report) which `abort_if`s 404 without engineer
            // activity — so it would 404 for a PM generating a worksheet up
            // front. Producing one means authoring a new renderer, which D-04
            // forbids in this phase. Deliberately null; do not "fix" by pointing
            // this at the engineer report.
            'pdf' => null,
        ],
        'rams' => [
            'word' => 'rams.download',              // DocxBuilderService -> DocxBuilderServiceV2
            'pdf'  => 'rams.download-pdf',          // PdfService::buildRams
        ],
        'om_manual' => [
            'word' => 'om-manuals.download',        // OmManualDocxService
            'pdf'  => 'om-manuals.download-pdf',    // PdfService
        ],
    ];

    public function test_every_named_format_route_exists(): void
    {
        foreach (self::FORMAT_ROUTES as $document => $formats) {
            foreach ($formats as $format => $routeName) {
                if ($routeName === null) {
                    continue;
                }

                $this->assertTrue(
                    Route::has($routeName),
                    "Inventory claims {$document}.{$format} is produced by route '{$routeName}', but that route does not exist. "
                    . 'Either the route was renamed/deleted (fix the route, or update the inventory file AND this map together).'
                );
            }
        }
    }

    public function test_the_only_missing_format_is_the_worksheet_pdf(): void
    {
        $missing = [];

        foreach (self::FORMAT_ROUTES as $document => $formats) {
            foreach ($formats as $format => $routeName) {
                if ($routeName === null) {
                    $missing[] = "{$document}.{$format}";
                }
            }
        }

        sort($missing);

        // EXACT, never a floor. A new null cell means a format was silently
        // dropped; a missing null cell means DC-07 was closed without recording it.
        $this->assertSame(
            ['worksheet.pdf'],
            $missing,
            'The set of documents without a format must be exactly the Worksheet PDF (DC-07, NOT DELIVERED). '
            . 'If this changed, update 46.2-FORMAT-INVENTORY.md in the same commit and say why.'
        );
    }

    public function test_site_survey_word_downloads_a_docx(): void
    {
        [$user, $survey] = $this->makeSurvey(withRoom: true);

        $response = $this->actingAs($user)->get(route('site-surveys.docx', $survey));

        $response->assertOk();
        $this->assertStringContainsString(
            '.docx',
            (string) $response->headers->get('content-disposition'),
            'site-surveys.docx must stream a .docx attachment.'
        );
        $this->assertStringContainsString(
            'attachment',
            (string) $response->headers->get('content-disposition')
        );
    }

    /**
     * The D-46-05-01 shape, proven ABSENT here rather than assumed.
     *
     * `/worksheet/{token}` 500s live right now for a worksheet with no rooms
     * (`Undefined variable $signOffBlocked`). That defect is carried forward
     * deliberately and is not this phase's to fix — but shipping a SECOND
     * instance of it on a brand-new route would be. `SiteSurveyDocxService`
     * iterates `$survey->rooms->sortBy('sort_order')`, which is empty-safe;
     * this proves it instead of reasoning about it.
     */
    public function test_site_survey_word_survives_a_survey_with_no_rooms(): void
    {
        [$user, $survey] = $this->makeSurvey(withRoom: false);

        $this->assertSame(0, $survey->rooms()->count(), 'Fixture must genuinely have no rooms.');

        $this->actingAs($user)
            ->get(route('site-surveys.docx', $survey))
            ->assertOk();
    }

    public function test_site_survey_word_is_guarded_like_site_survey_pdf(): void
    {
        [, $survey] = $this->makeSurvey(withRoom: true);

        $docx = $this->get(route('site-surveys.docx', $survey));
        $pdf  = $this->get(route('site-surveys.pdf', $survey));

        $this->assertSame(
            $pdf->getStatusCode(),
            $docx->getStatusCode(),
            'An unauthenticated GET on site-surveys.docx must be rejected by the SAME guard as site-surveys.pdf '
            . '(authorizeSurvey + the auth middleware group), not a looser one.'
        );
        $this->assertNotSame(200, $docx->getStatusCode(), 'An unauthenticated GET must never reach the generator.');
    }

    // -- Fixtures --

    /** @return array{0: User, 1: SiteSurvey} */
    private function makeSurvey(bool $withRoom): array
    {
        $user = User::factory()->create();

        $project = Project::create([
            'user_id'      => $user->id,
            'name'         => 'Format Inventory Project',
            'ref'          => 'FMT-'.fake()->numerify('###'),
            'client_name'  => 'Acme Ltd',
            'site_address' => '1 Example Way, London',
            'status'       => 'quote_imported',
        ]);

        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Format Inventory Survey',
            'project_ref'  => 'Q-460202',
            'client_name'  => 'Acme Ltd',
            'site_address' => '1 Example Way, London',
            'status'       => 'draft',
        ]);

        if ($withRoom) {
            SiteSurveyRoom::create([
                'site_survey_id' => $survey->id,
                'room_name'      => 'Boardroom',
                'sort_order'     => 0,
            ]);
        }

        return [$user, $survey->refresh()];
    }
}
