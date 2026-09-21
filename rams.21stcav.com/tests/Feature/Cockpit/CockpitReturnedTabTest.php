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
 * Phase 46.1, Plan 46.1-03 — the Returned tab.
 *
 * THE PRESENCE RULE, stated once and asserted here: the tab renders IF AND
 * ONLY IF the open module's drawer holds at least one visit whose
 * `source_type` is set. Not "every module" (the four document modules hold no
 * visits at all and would carry a permanently empty fourth tab), and not a
 * hardcoded pair of module keys (which would strip the surface from any
 * commissioning / programming / snagging visit that ever gains a source, and
 * would need a hand-maintained list that drifts). The rule follows the DATA.
 *
 * `?tab=returned` on a module that does not offer it is a STALE BOOKMARK: 200,
 * Overview rendered and marked current, and the submitted string never echoed
 * — exactly how `?module=` already behaves.
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

    // ── The presence rule ────────────────────────────────────────────────

    public function test_the_tab_constant_carries_returned_between_overview_and_files(): void
    {
        // The ORDER of the constant IS the tab order, and TABS[0] is still the
        // fallback — everything that iterates it (the fence's everyRegion(),
        // the write-nothing tests) picks the new tab up automatically.
        $this->assertSame(
            ['overview', 'returned', 'files', 'notes'],
            ProjectCockpitController::TABS
        );
    }

    public function test_a_module_holding_a_sourced_visit_offers_four_tabs(): void
    {
        $project = $this->project();
        $this->worksheetVisit($project);

        $this->assertSame(
            ['Overview', 'Returned', 'Files', 'Notes'],
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
        Visit::factory()->create([
            'project_id'  => $project->id,
            'type'        => Visit::TYPE_SNAG,
            'source_type' => null,
            'source_id'   => null,
        ]);

        $this->assertSame(
            ['Overview', 'Files', 'Notes'],
            $this->tabLabels($this->panel($project, ProjectDeliverable::KEY_SNAGGING))
        );
    }

    public function test_the_rule_follows_the_data_not_a_module_list(): void
    {
        $project = $this->project();

        // A SNAGGING visit with a worksheet behind it. No module key is
        // hardcoded anywhere, so this drawer gets the tab the day its visits
        // gain a source — which is the whole point of the rule.
        $worksheet = $this->worksheet($project);

        Visit::factory()
            ->backfilledFromWorksheet($worksheet)
            ->create(['project_id' => $project->id, 'type' => Visit::TYPE_SNAG]);

        $this->worksheetPhoto($worksheet);

        $this->assertContains(
            'Returned',
            $this->tabLabels($this->panel($project, ProjectDeliverable::KEY_SNAGGING))
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

        $html = $this->panel($project, ProjectDeliverable::KEY_WORKSHEET, 'returned');

        $this->assertSame('Returned', $this->currentTab($html));

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
    // -- Rendering helpers, part two: the tab body ------------------------

    /**
     * The `.cav-panel__body` subtree -- the TAB BODY only.
     *
     * Deliberately narrower than `cav-cockpit`: the panel head's close control
     * and the tab strip's own anchors live OUTSIDE it, so the calm budget
     * below counts what the Returned tab itself drew and nothing else.
     */
    private function body(Project $project, string $module): string
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$this->raw($project, $module, 'returned'));
        libxml_clear_errors();

        $node = (new \DOMXPath($dom))
            ->query("//*[contains(concat(' ', normalize-space(@class), ' '), ' cav-panel__body ')]")
            ->item(0);

        $this->assertNotNull($node, 'The panel body was not found in the response.');

        return html_entity_decode($dom->saveHTML($node), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** @return array<int, string> every href in the given markup */
    private function hrefs(string $html): array
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        libxml_clear_errors();

        $found = [];

        foreach ((new \DOMXPath($dom))->query('//a') as $anchor) {
            $found[] = (string) $anchor->getAttribute('href');
        }

        return $found;
    }

    // -- What the tab shows (RV-01) ---------------------------------------

    public function test_the_tab_shows_the_survey_room_its_answer_its_notes_and_its_photo(): void
    {
        $project = $this->project();
        [, , $room] = $this->surveyVisit($project);

        $body = $this->body($project, ProjectDeliverable::KEY_SITE_SURVEY);

        $this->assertStringContainsString('Boardroom', $body);
        $this->assertStringContainsString('Is the comms room reachable without a ladder?', $body);
        $this->assertStringContainsString('A step stool is enough.', $body);

        // The engineer's words, not the enum token behind them.
        $this->assertStringNotContainsString('>other<', $body);
        $this->assertStringContainsString('Ladder needed for the ceiling void.', $body);

        // Unanswered questions are OMITTED and carried as two integers, not as
        // twenty empty rows (RV-08 -- "simple" is an acceptance criterion).
        $this->question($room, ['question' => 'Never answered', 'sort_order' => 1]);

        $body = $this->body($project, ProjectDeliverable::KEY_SITE_SURVEY);

        $this->assertStringNotContainsString('Never answered', $body);
        $this->assertStringContainsString('1 of 2 questions answered', $body);

        // The before bucket is spelled in English -- `before` is a ZIP folder
        // name, never page copy.
        $this->assertStringContainsString('Before (survey)', $body);
    }

    public function test_the_tab_shows_the_worksheet_photo_the_serial_and_the_client_signoff(): void
    {
        $project = $this->project();
        $this->worksheetVisit($project);

        $body = $this->body($project, ProjectDeliverable::KEY_WORKSHEET);

        $this->assertStringContainsString('After (install)', $body);
        $this->assertStringContainsString('Equipment labels', $body);

        // D-05 -- the serial is a LIST entry: room, device, serial, date.
        $this->assertStringContainsString('Ceiling microphone array', $body);
        $this->assertStringContainsString('SN-1122-AA', $body);

        // The sign-off surface is the NAME and the SIGNATURE IMAGE.
        $this->assertStringContainsString('Priya Raman', $body);
        $this->assertStringContainsString('base64,iVBORw0KGgo=', $body);
    }

    public function test_a_serial_not_yet_read_says_so_rather_than_rendering_an_empty_cell(): void
    {
        $project = $this->project();

        $worksheet = $this->worksheet($project);

        Visit::factory()->backfilledFromWorksheet($worksheet)
            ->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);

        $this->labelPhoto($project, $worksheet);

        $this->assertStringContainsString(
            'Serial not read yet',
            $this->body($project, ProjectDeliverable::KEY_WORKSHEET)
        );
    }

    public function test_an_untouched_source_and_a_missing_source_each_say_so_in_one_sentence(): void
    {
        $project = $this->project();

        // Issued, nothing captured.
        $worksheet = $this->worksheet($project);
        Visit::factory()->backfilledFromWorksheet($worksheet)
            ->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);

        $this->assertStringContainsString(
            'Nothing has come back from site yet.',
            $this->body($project, ProjectDeliverable::KEY_WORKSHEET)
        );

        // Force-deleted source -- the visit still happened.
        $worksheet->forceDelete();

        $this->assertStringContainsString(
            'The visit is recorded; the evidence behind it could not be read.',
            $this->body($project, ProjectDeliverable::KEY_WORKSHEET)
        );
    }

    // -- RV-02: read live -------------------------------------------------

    public function test_editing_the_engineers_record_changes_the_tab_and_touches_nothing(): void
    {
        $project = $this->project();

        [$surveyVisit, $survey, $room] = $this->surveyVisit($project);
        [$worksheetVisit, $worksheet]  = $this->worksheetVisit($project);

        $before = [
            'visit_updated'    => $surveyVisit->fresh()->getRawOriginal('updated_at'),
            'wvisit_updated'   => $worksheetVisit->fresh()->getRawOriginal('updated_at'),
            'survey_submitted' => $survey->fresh()->getRawOriginal('submitted_at'),
            'worksheet_token'  => $worksheet->fresh()->getRawOriginal('access_token'),
            'survey_token'     => $survey->fresh()->getRawOriginal('access_token'),
        ];

        $this->body($project, ProjectDeliverable::KEY_SITE_SURVEY);
        $this->body($project, ProjectDeliverable::KEY_WORKSHEET);

        // The engineer's record changes UNDER the review.
        $room->forceFill(['notes' => 'Second visit: the void is boarded.'])->saveQuietly();

        $this->worksheetPhoto($worksheet, ['original_name' => 'install-02-second-pass.jpg']);

        $this->signoff($worksheet, [
            'client_name' => 'Devraj Anand',
            'signed_at'   => now(),
        ]);

        $surveyBody    = $this->body($project, ProjectDeliverable::KEY_SITE_SURVEY);
        $worksheetBody = $this->body($project, ProjectDeliverable::KEY_WORKSHEET);

        $this->assertStringContainsString('Second visit: the void is boarded.', $surveyBody);
        $this->assertStringNotContainsString('Ladder needed for the ceiling void.', $surveyBody);

        // Two worksheet thumbnails in the after bucket now, not one. The link
        // and its <img> carry the same URL, so each photo contributes twice.
        $this->assertSame(
            4,
            substr_count($worksheetBody, '/photo/worksheet/'),
            'A photo added after the first render must reach the second.'
        );

        // The NEWEST sign-off wins and the superseded one is gone.
        $this->assertStringContainsString('Devraj Anand', $worksheetBody);
        $this->assertStringNotContainsString('Priya Raman', $worksheetBody);

        // RENDERING THE REVIEW COPIES NOTHING AND TOUCHES NOTHING.
        $this->assertSame($before['visit_updated'], $surveyVisit->fresh()->getRawOriginal('updated_at'));
        $this->assertSame($before['wvisit_updated'], $worksheetVisit->fresh()->getRawOriginal('updated_at'));
        $this->assertSame($before['survey_submitted'], $survey->fresh()->getRawOriginal('submitted_at'));
        $this->assertSame($before['worksheet_token'], $worksheet->fresh()->getRawOriginal('access_token'));
        $this->assertSame($before['survey_token'], $survey->fresh()->getRawOriginal('access_token'));
    }

    public function test_opening_the_returned_tab_on_every_module_moves_no_row(): void
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

        foreach (array_keys(\App\Support\Cockpit\CockpitModulePresenter::moduleMap()) as $moduleKey) {
            $this->raw($project, $moduleKey, 'returned');
        }

        foreach ($tables as $table) {
            $this->assertSame(
                $before[$table],
                \Illuminate\Support\Facades\DB::table($table)->count(),
                "Opening the Returned tab moved `{$table}`."
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

        $raw = $this->raw($project, ProjectDeliverable::KEY_WORKSHEET, 'returned');

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

    public function test_no_labour_resource_contact_detail_appears_on_the_tab(): void
    {
        $project = $this->project();
        $this->worksheetVisit($project);

        \App\Models\LabourResource::factory()->create([
            'name'  => 'Marcus Feld',
            'email' => 'marcus.feld@example-engineer.test',
            'phone' => '07700900461',
        ]);

        $raw = $this->raw($project, ProjectDeliverable::KEY_WORKSHEET, 'returned');

        // LR-04 adjacent: the cockpit is staff-auth, so contact details would
        // be sanctioned -- the tab simply reads no LabourResource field at all.
        $this->assertStringNotContainsString('marcus.feld@example-engineer.test', $raw);
        $this->assertStringNotContainsString('07700900461', $raw);
    }

    // -- Escaping (T-46.1-12) ---------------------------------------------

    public function test_engineer_and_client_free_text_is_escaped(): void
    {
        $hostile = '<script>alert(1)</script>';

        $project = $this->project();

        // Survey side: room name, caption and question text.
        $survey = $this->survey($project);

        Visit::factory()->backfilledFromSurvey($survey)
            ->create(['project_id' => $project->id, 'type' => Visit::TYPE_SITE_SURVEY]);

        $room = $this->room($survey, ['room_name' => $hostile]);
        $this->surveyPhoto($room, ['caption' => $hostile]);
        $this->question($room, ['question' => $hostile, 'answer' => 'other', 'other_text' => $hostile]);

        $surveyRaw = $this->raw($project, ProjectDeliverable::KEY_SITE_SURVEY, 'returned');

        // Worksheet side: client name and comments.
        $worksheet = $this->worksheet($project);

        Visit::factory()->backfilledFromWorksheet($worksheet)
            ->create(['project_id' => $project->id, 'type' => Visit::TYPE_INSTALL]);

        $this->worksheetPhoto($worksheet);
        $this->signoff($worksheet, [
            'client_name'          => $hostile,
            'comments'             => $hostile,
            'signed_with_comments' => true,
        ]);

        $worksheetRaw = $this->raw($project, ProjectDeliverable::KEY_WORKSHEET, 'returned');

        foreach ([$surveyRaw, $worksheetRaw] as $raw) {
            // Non-vacuous: the value really did reach the page, escaped.
            $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $raw);
            $this->assertStringNotContainsString($hostile, $raw);
        }
    }

    // -- RV-08: the calm budget -------------------------------------------

    public function test_the_returned_tab_renders_no_non_photo_anchor(): void
    {
        $project = $this->project();
        [, $worksheet] = $this->worksheetVisit($project);

        // A third and fourth photo, so the floor below is a real floor.
        $this->worksheetPhoto($worksheet, ['original_name' => 'install-02.jpg']);
        $this->worksheetPhoto($worksheet, ['original_name' => 'install-03.jpg']);

        $hrefs = $this->hrefs($this->body($project, ProjectDeliverable::KEY_WORKSHEET));

        $photoLinks = array_filter(
            $hrefs,
            fn (string $href): bool => str_contains($href, '/cockpit/visits/') && str_contains($href, '/photo/')
        );

        // PLAN 46.1-03 SHIPS ZERO NON-PHOTO ANCHORS ON THIS TAB. Plan 46.1-04
        // raises this budget to EXACTLY ONE -- the photo-archive hand-off link
        // -- and must say so here when it does. A gallery plus four controls is
        // how a calm page becomes a control panel.
        $this->assertSame(
            count($hrefs),
            count($photoLinks),
            'Every anchor on the Returned tab must be a photo link. Budget: 0 others in this plan.'
        );

        $this->assertGreaterThanOrEqual(
            3,
            count($photoLinks),
            'Non-vacuity floor: the contact sheet really did render links.'
        );
    }

    public function test_the_contact_sheet_lazy_loads_and_opens_in_a_new_tab(): void
    {
        $project = $this->project();
        $this->worksheetVisit($project);

        $body = $this->body($project, ProjectDeliverable::KEY_WORKSHEET);

        $this->assertStringContainsString('loading="lazy"', $body);
        $this->assertStringContainsString('rel="noopener"', $body);
        $this->assertStringContainsString('target="_blank"', $body);

        // Nothing per photo: no lightbox, no pager, no delete, no caption
        // editor, no reorder. The fence already bans the script any of them
        // would need; this says the copy is absent too.
        foreach (['Delete', 'Next', 'Previous', 'Rotate', 'Reorder', 'Edit caption'] as $control) {
            $this->assertStringNotContainsString($control, $body);
        }
    }
}
