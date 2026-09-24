<?php

namespace Tests\Feature\Cockpit;

use App\Http\Controllers\ProjectCockpitController;
use App\Models\Device;
use App\Models\DeviceLabelPhoto;
use App\Models\Project;
use App\Models\ProjectDeliverable;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyPhoto;
use App\Models\SiteSurveyRoom;
use App\Models\SiteSurveyRoomQuestion;
use App\Models\User;
use App\Models\Visit;
use App\Models\Worksheet;
use App\Models\WorksheetPhoto;
use App\Models\WorksheetSignoff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 46.1, Plan 46.1-03 — the Returned tab. NOW THE TAB'S ABSENCE.
 *
 * ── WHAT THIS CLASS WAS, AND WHAT IT IS (46.2 D-02, Plan 46.2-03) ─────────
 *
 * It asserted THE PRESENCE RULE: the tab renders if and only if the open
 * module's drawer holds at least one visit whose `source_type` is set — the rule
 * followed the DATA rather than a hardcoded list of module keys. And it asserted
 * the tab BODY: room answers, serials, the client sign-off, the anchor budget
 * and the photo-archive hand-off.
 *
 * 46.2 D-02 TOOK THE TAB OFF THE COCKPIT. The rule is therefore retired, and
 * twelve body-render tests with it — each retired BY NAME in the block further
 * down, none deleted to make a red test pass. What is left is the INVERSE, which
 * is now the property worth guarding: THREE TABS, ALWAYS, AND NO AMOUNT OF
 * RETURNED EVIDENCE PRODUCES A FOURTH.
 *
 * ── UNSURFACED, NOT DELETED. DO NOT "FINISH THE JOB" ──────────────────────
 *
 * Nothing behind the tab was removed. `App\Support\Cockpit\VisitEvidence`,
 * `CockpitEvidencePresenter`, `VisitPhotoZipBuilder`,
 * `ProjectCockpitEvidenceController` and both evidence GET routes are untouched
 * and green — see `tests/Unit/Cockpit/CockpitEvidencePresenterTest.php` (16
 * tests) and `tests/Feature/Cockpit/CockpitEvidenceDownloadTest.php` (25 tests,
 * UNEDITED by 46.2-03). The presenter is a zero-caller service from the
 * cockpit's side, deliberately, on the `SiteSurveyDocxService` precedent.
 *
 * `?tab=returned` is now a STALE BOOKMARK handled in the CONTROLLER, because
 * `returned` left `ProjectCockpitController::TABS`: 200, Overview rendered and
 * marked current, and the submitted string never echoed — exactly how `?module=`
 * already behaves. 46.1's second coercion inside `panel.blade.php` went with the
 * tab, so there is one fallback rather than two that could disagree.
 *
 * Assertions run against the extracted `cav-cockpit` subtree, on the same
 * terms and with the same helper shape as CockpitPanelTest, so there is one
 * fence and one panel helper rather than three.
 */
class CockpitReturnedTabTest extends TestCase
{
    use RefreshDatabase;

    /** A realistic value for the audit column that must never be rendered. */
    private const AUDIT_CAPTURE_VALUE = 'ip:203.0.113.9|actor:deadbeef';

    private const SIGNOFF_ADDRESS = '198.51.100.77';

    private const SIGNOFF_AGENT = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X)';

    // ── Fixtures ─────────────────────────────────────────────────────────

    private function project(array $overrides = []): Project
    {
        return Project::factory()->create(array_merge([
            'name'   => 'Returned Tab Job',
            'status' => Project::STATUS_INSTALLING,
        ], $overrides));
    }

    private function survey(Project $project, array $overrides = []): SiteSurvey
    {
        return SiteSurvey::create(array_merge([
            'user_id'      => User::factory()->create()->id,
            'project_id'   => $project->id,
            'project_name' => $project->name,
        ], $overrides));
    }

    private function room(SiteSurvey $survey, array $overrides = []): SiteSurveyRoom
    {
        return SiteSurveyRoom::create(array_merge([
            'site_survey_id' => $survey->id,
            'room_name'      => 'Boardroom',
            'sort_order'     => 0,
        ], $overrides));
    }

    private function surveyPhoto(SiteSurveyRoom $room, array $overrides = []): SiteSurveyPhoto
    {
        return SiteSurveyPhoto::create(array_merge([
            'site_survey_room_id' => $room->id,
            'filename'            => 'projects/9/surveys/3/'.fake()->uuid().'.jpg',
            'original_name'       => 'IMG_4410.jpg',
            'mime_type'           => 'image/jpeg',
            'sort_order'          => 0,
        ], $overrides));
    }

    private function question(SiteSurveyRoom $room, array $overrides = []): SiteSurveyRoomQuestion
    {
        return SiteSurveyRoomQuestion::create(array_merge([
            'site_survey_room_id' => $room->id,
            'question'            => 'Is the comms room reachable without a ladder?',
            'sort_order'          => 0,
            'answer'              => null,
        ], $overrides));
    }

    private function worksheet(Project $project, array $overrides = []): Worksheet
    {
        return Worksheet::factory()->create(array_merge([
            'project_id' => $project->id,
        ], $overrides));
    }

    private function worksheetPhoto(Worksheet $worksheet, array $overrides = []): WorksheetPhoto
    {
        return WorksheetPhoto::create(array_merge([
            'worksheet_id'  => $worksheet->id,
            'room_name'     => 'Boardroom',
            'filename'      => 'worksheet-photos/'.fake()->uuid().'.jpg',
            'original_name' => 'install-01.jpg',
            'mime_type'     => 'image/jpeg',
            'sort_order'    => 0,
        ], $overrides));
    }

    private function labelPhoto(Project $project, Worksheet $worksheet, array $overrides = []): DeviceLabelPhoto
    {
        return DeviceLabelPhoto::create(array_merge([
            'project_id'   => $project->id,
            'worksheet_id' => $worksheet->id,
            'room_name'    => 'Boardroom',
            'photo_path'   => 'device-labels/'.fake()->uuid().'.jpg',
            'confirmed'    => true,
            'captured_at'  => now()->subDay(),
            // Populated with a realistic value so the RV-03 assertion below
            // cannot pass merely because there was nothing to leak.
            'captured_by'  => self::AUDIT_CAPTURE_VALUE,
        ], $overrides));
    }

    private function signoff(Worksheet $worksheet, array $overrides = []): WorksheetSignoff
    {
        return WorksheetSignoff::create(array_merge([
            'worksheet_id'         => $worksheet->id,
            'client_name'          => 'Priya Raman',
            'signature_png_base64' => 'iVBORw0KGgo=',
            'signed_with_comments' => false,
            'signed_at'            => now()->subHour(),
            'ip_address'           => self::SIGNOFF_ADDRESS,
            'user_agent'           => self::SIGNOFF_AGENT,
        ], $overrides));
    }

    /**
     * A worksheet-sourced INSTALL visit — lands in the `worksheet` drawer —
     * carrying a photo, a serial and a real client sign-off.
     *
     * @return array{0: Visit, 1: Worksheet}
     */
    private function worksheetVisit(Project $project): array
    {
        $worksheet = $this->worksheet($project);

        $visit = Visit::factory()
            ->backfilledFromWorksheet($worksheet)
            ->create([
                'project_id' => $project->id,
                'type'       => Visit::TYPE_INSTALL,
                'title'      => 'Install visit',
            ]);

        $this->worksheetPhoto($worksheet);

        $device = Device::create([
            'project_id'    => $project->id,
            'room_name'     => 'Boardroom',
            'description'   => 'Ceiling microphone array',
            'serial_number' => 'SN-1122-AA',
        ]);

        $this->labelPhoto($project, $worksheet, ['device_id' => $device->id]);
        $this->signoff($worksheet);

        return [$visit, $worksheet];
    }

    /**
     * A survey-sourced SITE SURVEY visit — lands in the `site_survey` drawer —
     * with one returning room, one answered question and one photo.
     *
     * @return array{0: Visit, 1: SiteSurvey, 2: SiteSurveyRoom}
     */
    private function surveyVisit(Project $project): array
    {
        $survey = $this->survey($project);

        $visit = Visit::factory()
            ->backfilledFromSurvey($survey)
            ->create([
                'project_id' => $project->id,
                'type'       => Visit::TYPE_SITE_SURVEY,
                'title'      => 'Site survey visit',
            ]);

        $room = $this->room($survey, ['notes' => 'Ladder needed for the ceiling void.']);

        $this->surveyPhoto($room);
        // `site_survey_room_questions.answer` is an ENUM of yes/no/other —
        // the engineer's own words live in `other_text`.
        $this->question($room, ['answer' => 'other', 'other_text' => 'A step stool is enough.']);

        return [$visit, $survey, $room];
    }

    // ── Rendering helpers (CockpitPanelTest's shape) ─────────────────────

    private function subtree(string $html): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $node = (new \DOMXPath($dom))
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' cav-cockpit ')]")
            ->item(0);

        $this->assertNotNull($node, 'The cav-cockpit root element was not found in the response.');

        return html_entity_decode($dom->saveHTML($node), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** The RAW response body — entities intact, for escaping assertions. */
    private function raw(Project $project, string $module, string $tab = 'overview'): string
    {
        config(['cockpit.enabled' => true]);

        $url = route('projects.cockpit', ['project' => $project, 'module' => $module, 'tab' => $tab]);

        return $this->actingAs(User::factory()->create())->get($url)->assertOk()->getContent();
    }

    private function panel(Project $project, string $module, string $tab = 'overview'): string
    {
        return $this->subtree($this->raw($project, $module, $tab));
    }

    /** @return array<int, string> the tab labels, in the order rendered */
    private function tabLabels(string $html): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $nodes = (new \DOMXPath($dom))
            ->query("//a[contains(concat(' ', normalize-space(@class), ' '), ' cav-panel__tab ')]");

        $labels = [];

        foreach ($nodes as $node) {
            $labels[] = trim($node->textContent);
        }

        return $labels;
    }

    private function currentTab(string $html): ?string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $node = (new \DOMXPath($dom))
            ->query("//a[contains(concat(' ', normalize-space(@class), ' '), ' cav-panel__tab ')][@aria-current='page']")
            ->item(0);

        return $node === null ? null : trim($node->textContent);
    }

    // ── THE PRESENCE RULE IS RETIRED, AND THREE IS NOW A LITERAL ──────────
    //
    // RETIRED BY NAME, 46.2 D-02, Plan 46.2-03 — unsurfaced, NOT deleted:
    //
    //   test_the_tab_constant_carries_returned_between_overview_and_files()
    //     asserted TABS === ['overview', 'returned', 'files', 'notes'].
    //     Replaced below by the three-entry literal. The ORDER-IS-TAB-ORDER and
    //     TABS[0]-IS-THE-FALLBACK properties it protected are KEPT, because the
    //     replacement asserts the same constant the same way.
    //
    //   test_a_module_holding_a_sourced_visit_offers_four_tabs()
    //     asserted a sourced visit's drawer drew FOUR tab labels. Replaced
    //     below by the same fixture asserting THREE — four became three, so the
    //     test became its own inverse rather than disappearing.
    //
    //   test_the_rule_follows_the_data_not_a_module_list()
    //     asserted that a SNAGGING visit with a worksheet behind it gained the
    //     Returned tab, proving the rule read the data rather than a hardcoded
    //     key list. That rule no longer exists: no data produces a fourth tab.
    //     Replaced below by the assertion that carries the same weight under
    //     D-02 — NO drawer, however rich its evidence, draws a fourth tab.
    //
    // The capability is at projects.cockpit.visits.photos-zip / .photo and in
    // App\Support\Cockpit\CockpitEvidencePresenter, all untouched and green.

    public function test_the_tab_constant_is_exactly_overview_files_notes(): void
    {
        // MOVED 4 -> 3 BY 46.2 D-02: `returned` was at index 1 and is gone,
        // because the cockpit no longer surfaces the Returned tab. The ORDER of
        // the constant IS the tab order, and TABS[0] is still the fallback —
        // everything that iterates it (the fence's everyRegion(), the
        // write-nothing tests) follows this edit automatically.
        //
        // This is also now the ONLY fallback for a stale `?tab=returned`
        // bookmark: 46.1's second coercion inside panel.blade.php went with the
        // tab, so `returned` being absent HERE is what makes the strip and the
        // body unable to disagree.
        $this->assertSame(
            ['overview', 'files', 'notes'],
            ProjectCockpitController::TABS
        );
    }

    public function test_a_module_holding_a_sourced_visit_still_offers_only_three_tabs(): void
    {
        $project = $this->project();
        $this->worksheetVisit($project);

        // FOUR -> THREE (46.2 D-02). Same fixture, same helper, same assertion
        // shape: a drawer whose visit HAS a source — the exact condition that
        // used to summon a fourth tab — now draws three.
        $this->assertSame(
            ['Overview', 'Files', 'Notes'],
            $this->tabLabels($this->panel($project, ProjectDeliverable::KEY_WORKSHEET))
        );
    }

    public function test_a_module_holding_no_visits_at_all_offers_three_tabs(): void
    {
        $project = $this->project();
        $this->worksheetVisit($project);

        // The RAMS drawer holds documents, never visits — a permanently empty
        // fourth tab is exactly what the presence rule exists to prevent.
        $this->assertSame(
            ['Overview', 'Files', 'Notes'],
            $this->tabLabels($this->panel($project, ProjectDeliverable::KEY_RAMS))
        );
    }

    public function test_a_module_holding_only_a_sourceless_visit_offers_three_tabs(): void
    {
        $project = $this->project();

        // A visit that was never issued has no engineer record behind it, so
        // nothing could ever have come back from site.
        //
        // REPOINTED, NOT RETIRED (46.2 D-01, Plan 46.2-03): this read
        // `KEY_SNAGGING`, a row 46.2-01 removed from the map, so the panel
        // never opened. The visit type stays TYPE_SNAG — the point is a
        // SOURCELESS visit, not which drawer holds it — and the drawer is now
        // Site survey, which survives. The assertion below is byte-identical.
        Visit::factory()->create([
            'project_id'  => $project->id,
            'type'        => Visit::TYPE_SNAG,
            'source_type' => null,
            'source_id'   => null,
        ]);

        $this->assertSame(
            ['Overview', 'Files', 'Notes'],
            $this->tabLabels($this->panel($project, ProjectDeliverable::KEY_SITE_SURVEY))
        );
    }

    /**
     * THE REPLACEMENT FOR THE PRESENCE RULE (46.2 D-02, Plan 46.2-03).
     *
     * `test_the_rule_follows_the_data_not_a_module_list()` proved the fourth tab
     * was summoned by the DATA — a snagging visit with a worksheet behind it got
     * it, because no module key was hardcoded. Under D-02 the inverse is the
     * property worth protecting, and it is the stronger statement: the richest
     * drawer this fixture can build — a sourced visit WITH a returned photo,
     * exactly the shape that used to summon the tab — still draws three.
     *
     * So a later plan that re-derives a tab from visit data trips this, rather
     * than finding an absence of a test where a rule used to be.
     */
    public function test_no_amount_of_returned_evidence_summons_a_fourth_tab(): void
    {
        $project = $this->project();

        $worksheet = $this->worksheet($project);

        Visit::factory()
            ->backfilledFromWorksheet($worksheet)
            ->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);

        $this->worksheetPhoto($worksheet);

        $this->assertSame(
            ['Overview', 'Files', 'Notes'],
            $this->tabLabels($this->panel($project, ProjectDeliverable::KEY_WORKSHEET))
        );

        $this->assertNotContains(
            'Returned',
            $this->tabLabels($this->panel($project, ProjectDeliverable::KEY_WORKSHEET))
        );
    }

    public function test_tab_returned_on_a_module_without_it_is_a_stale_bookmark(): void
    {
        // A bare project, DELIBERATELY NOT NAMED "Returned …": the project
        // name is printed in the crumb, the masthead and the panel ref, so a
        // fixture name carrying the word would make this assertion pass or
        // fail for a reason that has nothing to do with `?tab=`.
        $project = $this->project(['name' => 'Stale Bookmark Job']);

        $raw = $this->raw($project, ProjectDeliverable::KEY_RAMS, 'returned');

        $html = $this->subtree($raw);

        $this->assertSame(['Overview', 'Files', 'Notes'], $this->tabLabels($html));
        $this->assertSame('Overview', $this->currentTab($html));

        // Asserted on the RAW body, so even an escaped reflection fails.
        $this->assertStringNotContainsStringIgnoringCase(
            'returned',
            $this->subtree($raw),
            'A stale `?tab=` value must never be echoed into the page.'
        );
    }

    public function test_the_tab_strip_is_still_anchors_only_and_carries_no_aria_expanded(): void
    {
        $project = $this->project();
        $this->worksheetVisit($project);

        // REPOINTED, NOT RETIRED (46.2 D-02, Plan 46.2-03): this opened
        // `?tab=returned`, which is no longer a legal tab. It now opens `files`.
        // The property is the STRIP's, not the tab's — anchors only, no
        // aria-expanded — and every assertion below is byte-identical.
        $html = $this->panel($project, ProjectDeliverable::KEY_WORKSHEET, 'files');

        $this->assertSame('Files', $this->currentTab($html));

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $strip = (new \DOMXPath($dom))
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' cav-panel__tabs ')]")
            ->item(0);

        $this->assertNotNull($strip);
        $this->assertStringNotContainsString('aria-expanded', $dom->saveHTML($strip));

        foreach ($strip->childNodes as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $this->assertSame('a', $child->nodeName, 'The tab strip is navigation between URLs, not a widget.');
            }
        }
    }

    // ══ TWELVE TAB-BODY RENDER TESTS RETIRED HERE, BY NAME ═════════════════
    //
    // 46.2 D-02, Plan 46.2-03. UNSURFACED, NOT DELETED. Every one of these
    // asserted MARKUP OR COPY inside `.cav-panel__body` on `?tab=returned`.
    // There is no such tab, so there is no such body, so the assertion is not
    // failing — it is IMPOSSIBLE. None of them was removed to make a red test
    // pass, and none of the code they exercised was touched.
    //
    // Two private helpers went with them because they had no other caller:
    // `body()` (extracted `.cav-panel__body` from a `?tab=returned` render) and
    // `hrefs()`. `reviewableWorksheetVisit()` likewise.
    //
    //  1. test_the_tab_shows_the_survey_room_its_answer_its_notes_and_its_photo
    //     Room name, question, answer, the engineer's words rather than the enum
    //     token, omitted-unanswered + "1 of 2 questions answered", "Before
    //     (survey)".
    //     → CockpitEvidencePresenterTest::test_a_survey_sourced_visit_returns_its_photos_in_the_before_bucket(),
    //       ::test_unanswered_questions_are_omitted_but_still_counted(),
    //       ::test_a_room_that_returned_nothing_is_omitted() — all green, all unedited.
    //
    //  2. test_the_tab_shows_the_worksheet_photo_the_serial_and_the_client_signoff
    //     "After (install)", "Equipment labels", the serial list entry, the
    //     client name and the signature image.
    //     → CockpitEvidencePresenterTest::test_a_worksheet_sourced_visit_returns_photos_serials_and_the_signoff().
    //
    //  3. test_a_serial_not_yet_read_says_so_rather_than_rendering_an_empty_cell
    //     The "Serial not read yet" copy.
    //     → CockpitEvidencePresenterTest::test_a_serial_falls_back_to_the_ai_extraction_when_no_device_row_holds_one()
    //       keeps the DATA half; the COPY was the deleted Blade's and goes with it.
    //
    //  4. test_an_untouched_source_and_a_missing_source_each_say_so_in_one_sentence
    //     "Nothing has come back from site yet." and "The visit is recorded; the
    //     evidence behind it could not be read."
    //     → CockpitEvidencePresenterTest::test_an_issued_but_untouched_source_has_nothing()
    //       and ::test_a_force_deleted_source_still_resolves_and_reports_itself_missing().
    //
    //  5. test_editing_the_engineers_record_changes_the_tab_and_touches_nothing
    //     RV-02 read-live, plus the strongest write-nothing assertion in the
    //     phase (visit updated_at, survey submitted_at and BOTH access tokens
    //     unmoved by a render).
    //     → CockpitEvidencePresenterTest::test_it_reads_live_so_an_edited_room_note_shows_on_the_next_call(),
    //       ::test_it_reads_live_so_a_later_worksheet_capture_shows_on_the_next_call()
    //       and ::test_calling_evidence_writes_nothing_to_any_read_table().
    //       The ACCESS-TOKEN half also survives in
    //       tests/Feature/Worksheets/SurveyCarryForwardOnEngineerLinkTest::test_rendering_does_not_rotate_the_worksheet_access_token().
    //
    //  6. test_engineer_and_client_free_text_is_escaped (T-46.1-12)
    //     The escaping was `returned-tab.blade.php`'s. With no template there is
    //     nothing to escape. CockpitPanelTest::test_no_cockpit_view_uses_unescaped_output()
    //     still greps EVERY surviving cockpit view, so the rule is enforced on
    //     the whole directory rather than on one file.
    //
    //  7. test_a_visit_with_photos_carries_one_zip_handoff_link
    //  8. test_no_handoff_link_renders_for_a_visit_with_no_photos
    //  9. test_the_handoff_sits_outside_any_visit_row
    // 10. test_the_returned_tab_renders_exactly_one_non_photo_anchor
    // 11. test_the_contact_sheet_lazy_loads_and_opens_in_a_new_tab
    // 12. test_a_reviewable_returned_visit_spends_four_non_photo_anchors_and_one_button
    //     All six asserted the ANCHOR BUDGET and the hand-off link of a tab that
    //     no longer renders. The ROUTES they pointed at are untouched and proved
    //     by tests/Feature/Cockpit/CockpitEvidenceDownloadTest.php — 25 tests,
    //     green, UNEDITED by this plan, including path traversal, hostile room
    //     names, cross-project 404s and "neither GET writes a row in any of the
    //     eleven tables". The ZIP works; nothing links to it. That distinction
    //     is asserted directly by
    //     CockpitReadOnlyFenceTest::test_the_unsurfaced_write_routes_all_still_work_with_no_link_on_the_page().
    //
    // ══════════════════════════════════════════════════════════════════════

    public function test_opening_every_tab_on_every_module_moves_no_row(): void
    {
        $project = $this->project();
        $this->surveyVisit($project);
        $this->worksheetVisit($project);

        $tables = [
            'visits', 'install_records', 'install_programmes', 'site_surveys',
            'worksheets', 'snags', 'project_activity_logs', 'site_survey_photos',
            'worksheet_photos', 'worksheet_signoffs', 'device_label_photos',
        ];

        $before = [];

        foreach ($tables as $table) {
            $before[$table] = \Illuminate\Support\Facades\DB::table($table)->count();
        }

        // REPOINTED, NOT RETIRED (46.2 D-02): this opened `?tab=returned` on
        // every module. `returned` is no longer in TABS, so that single render
        // would now be an OVERVIEW render wearing a Returned tab's name — green,
        // and covering less than its name claimed. It walks the real tab list
        // instead, so this test cannot pass vacuously and follows TABS the next
        // time TABS moves.
        foreach (array_keys(\App\Support\Cockpit\CockpitModulePresenter::moduleMap()) as $moduleKey) {
            foreach (ProjectCockpitController::TABS as $tab) {
                $this->raw($project, $moduleKey, $tab);
            }
        }

        foreach ($tables as $table) {
            $this->assertSame(
                $before[$table],
                \Illuminate\Support\Facades\DB::table($table)->count(),
                "Opening a panel tab moved `{$table}`."
            );
        }
    }

    // -- RV-03: no capture IP reaches the page ----------------------------

    public function test_no_capture_address_or_client_agent_is_ever_rendered(): void
    {
        $project = $this->project();
        $this->worksheetVisit($project);

        // The audit values really are in the database -- so the assertions
        // below cannot pass because there was nothing to leak.
        $this->assertDatabaseHas('device_label_photos', ['captured_by' => self::AUDIT_CAPTURE_VALUE]);
        $this->assertDatabaseHas('worksheet_signoffs', ['ip_address' => self::SIGNOFF_ADDRESS]);

        // REPOINTED (46.2 D-02): was one `?tab=returned` render. `returned` is
        // no longer a tab, so it walks every real tab instead — the audit values
        // must not reach ANY cockpit render, which is the stronger reading and
        // the one that cannot go stale when TABS moves again.
        $raw = '';

        foreach (ProjectCockpitController::TABS as $tab) {
            $raw .= $this->raw($project, ProjectDeliverable::KEY_WORKSHEET, $tab);
        }

        $secrets = [
            self::AUDIT_CAPTURE_VALUE,
            'ip:',
            '203.0.113.9',
            'captured_by',
            self::SIGNOFF_ADDRESS,
            self::SIGNOFF_AGENT,
            'ip_address',
            'user_agent',
        ];

        foreach ($secrets as $secret) {
            $this->assertStringNotContainsString(
                $secret,
                $raw,
                "RV-03: `{$secret}` must never reach a PM's screen. See ".
                '2026_07_08_170000_backfill_device_label_photos_captured_by_leak.php.'
            );
        }
    }

    public function test_no_labour_resource_contact_detail_appears_on_any_tab(): void
    {
        $project = $this->project();
        $this->worksheetVisit($project);

        \App\Models\LabourResource::factory()->create([
            'name'  => 'Marcus Feld',
            'email' => 'marcus.feld@example-engineer.test',
            'phone' => '07700900461',
        ]);

        // REPOINTED (46.2 D-02), same reason as the test above.
        $raw = '';

        foreach (ProjectCockpitController::TABS as $tab) {
            $raw .= $this->raw($project, ProjectDeliverable::KEY_WORKSHEET, $tab);
        }

        // LR-04 adjacent: the cockpit is staff-auth, so contact details would
        // be sanctioned -- no cockpit tab reads a LabourResource field at all.
        $this->assertStringNotContainsString('marcus.feld@example-engineer.test', $raw);
        $this->assertStringNotContainsString('07700900461', $raw);
    }

}
