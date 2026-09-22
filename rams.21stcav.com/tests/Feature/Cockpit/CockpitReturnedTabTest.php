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

    // -- RV-04: the Bitrix hand-off (Plan 46.1-04) ------------------------

    public function test_a_visit_with_photos_carries_one_zip_handoff_link(): void
    {
        $project = $this->project();
        [$visit] = $this->worksheetVisit($project);

        $body = $this->body($project, ProjectDeliverable::KEY_WORKSHEET);

        $url = route('projects.cockpit.visits.photos-zip', [
            'project' => $project,
            'visit'   => $visit->id,
        ]);

        $this->assertStringContainsString($url, $body);
        $this->assertStringContainsString('Download all photos (ZIP)', $body);

        // ONE, not one per bucket and not one per photo.
        $this->assertSame(1, substr_count($body, 'Download all photos (ZIP)'));

        // A hand-off leaves the page it was handed off from.
        $this->assertStringContainsString('target="_blank"', $body);
        $this->assertStringContainsString('rel="noopener"', $body);

        // The shape of the archive, said before a PM opens it.
        $this->assertStringContainsString('Grouped by room, into before / after / label folders.', $body);
    }

    /**
     * AN ARCHIVE HOLDING ONLY A README IS A WORSE ANSWER THAN A SENTENCE.
     *
     * The builder (Plan 46.1-02) will happily produce a valid ZIP for a visit
     * with no photos — and it should, because the route is reachable directly.
     * Offering it in the page is a different question, and the answer is no.
     */
    public function test_no_handoff_link_renders_for_a_visit_with_no_photos(): void
    {
        $project   = $this->project();
        $worksheet = $this->worksheet($project);

        Visit::factory()->backfilledFromWorksheet($worksheet)->create([
            'project_id' => $project->id,
            'type'       => Visit::TYPE_INSTALL,
        ]);

        // Something DID come back — just not a photo. So this proves the link
        // follows the photo count, not the empty-state branch.
        $this->signoff($worksheet);

        $body = $this->body($project, ProjectDeliverable::KEY_WORKSHEET);

        $this->assertStringContainsString('Priya Raman', $body, 'Non-vacuity: the tab really did render evidence.');
        $this->assertStringNotContainsString('Download all photos (ZIP)', $body);
        $this->assertStringNotContainsString('photos.zip', $body);
    }

    /**
     * VL-11, PROTECTED FROM A TIDY-UP.
     *
     * CockpitVisitActionsTest::countControls() counts `<button` and `<a `
     * INSIDE a `.cav-visit` row and caps that at four. The hand-off is not one
     * of D-02's four acts, so it must not spend a quarter of that cap. It
     * lives outside the row, and this says so structurally rather than in a
     * comment somebody can move the markup past.
     */
    public function test_the_handoff_sits_outside_any_visit_row(): void
    {
        $project = $this->project();
        $this->worksheetVisit($project);

        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>'.$this->raw($project, ProjectDeliverable::KEY_WORKSHEET, 'returned'));
        libxml_clear_errors();

        $anchors = (new \DOMXPath($dom))->query("//a[contains(@href, 'photos.zip')]");

        $this->assertSame(1, $anchors->length, 'Exactly one hand-off anchor on the page.');

        for ($node = $anchors->item(0)->parentNode; $node !== null; $node = $node->parentNode) {
            if (! $node instanceof \DOMElement) {
                continue;
            }

            $this->assertStringNotContainsString(
                'cav-visit',
                (string) $node->getAttribute('class'),
                'The hand-off moved INSIDE a visit row and now spends one of VL-11\'s four controls.'
            );
        }
    }

    // -- RV-08: the calm budget -------------------------------------------

    public function test_the_returned_tab_renders_exactly_one_non_photo_anchor(): void
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

        // THE BUDGET WAS RAISED FROM 0 TO EXACTLY ONE BY PLAN 46.1-04, and by
        // exactly one thing: the photo-archive hand-off link (RV-04, D-03),
        // the same affordance that plan paid for by lifting `Download` from
        // the fence. 46.1-03 shipped zero and wrote here that 46.1-04 would
        // raise it to one and must say so when it did. This is that edit.
        //
        // EXACTLY ONE, NOT "AT MOST ONE" AND NOT "SOME". The cap is the point:
        // a gallery plus four controls is how a calm page becomes a control
        // panel (RV-08), and the user's words were "simple and less scary".
        // CORRECTED BY PLAN 46.1-05, WHICH SHIPPED THEM AND COUNTED THEM.
        // 46.1-04 wrote here that the four review controls "are BUTTONS
        // inside a form, not anchors". ONE of them is: Accept. `Send back`,
        // `Add note` and `Raise a snag` are ANCHORS carrying a disclosure
        // URL (visit-row.blade.php) — there is no JavaScript on this page,
        // so a control that only changes the URL has to be a link.
        //
        // THE BUDGET IS STILL EXACTLY ONE, AND THE REASON IS THE FIXTURE
        // RATHER THAN THE MARKUP: `worksheetVisit()` builds a RECONSTRUCTED
        // visit, which offers ZERO controls (D-06). A reviewable returned
        // visit on this tab renders three anchors and one button, and that
        // is the deliberate cost of D-02. Anything OTHER than those four,
        // on a reconstructed visit, is a new link nobody decided to ship.
        //
        // AND THAT IS WHY THIS TEST IS NOT THE WHOLE STORY (Plan 46.1-06).
        // A budget whose fixture offers zero controls cannot claim to have
        // counted them: this one would keep passing if the four acts became
        // fourteen. It is kept because "a reconstructed visit grows no new
        // link" is worth pinning on its own — the cost of D-02 is counted
        // where it is actually paid, in
        // test_a_reviewable_returned_visit_spends_four_non_photo_anchors_and_one_button().
        $this->assertSame(
            count($hrefs) - 1,
            count($photoLinks),
            'The Returned tab carries exactly one non-photo anchor: the ZIP hand-off. Budget: 1.'
        );

        // ...and it is THAT anchor, not some other one that happened to
        // balance the arithmetic.
        $nonPhoto = array_values(array_diff($hrefs, $photoLinks));

        $this->assertCount(1, $nonPhoto);
        $this->assertStringContainsString('photos.zip', $nonPhoto[0]);

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
    /**
     * A REVIEWABLE returned install visit — the row `worksheetVisit()` cannot
     * produce (Plan 46.1-06).
     *
     * `worksheetVisit()` builds a RECONSTRUCTED visit, which offers ZERO
     * controls (D-06). That makes it the right fixture for "a visit nobody
     * performed a review on shows evidence and offers nothing" and the WRONG
     * one for any budget that claims to have counted the controls: the budget
     * passes because the controls are absent, not because they were counted.
     *
     * This one is not backfilled, was sent, and its worksheet carries a
     * sign-off — so `Visit::state()` reads RETURNED, `isClosed()` is false,
     * and the row renders all four of D-02's acts.
     *
     * @return array{0: Visit, 1: Worksheet}
     */
    private function reviewableWorksheetVisit(Project $project): array
    {
        $worksheet = $this->worksheet($project);

        $visit = Visit::factory()->create([
            'project_id'  => $project->id,
            'type'        => Visit::TYPE_INSTALL,
            'title'       => 'Install visit',
            'status'      => Visit::STATUS_PLANNED,
            'sent_at'     => now()->subDays(3),
            'source_type' => Visit::SOURCE_WORKSHEET,
            'source_id'   => $worksheet->id,
            'is_backfilled' => false,
        ]);

        $this->worksheetPhoto($worksheet);
        $this->worksheetPhoto($worksheet, ['original_name' => 'install-02.jpg']);
        $this->worksheetPhoto($worksheet, ['original_name' => 'install-03.jpg']);
        $this->signoff($worksheet);

        return [$visit, $worksheet];
    }

    /**
     * THE BUDGET, JUDGED AGAINST A ROW THAT ACTUALLY HAS CONTROLS
     * (Plan 46.1-06, RV-08).
     *
     * WHY THIS TEST EXISTS. The one-anchor budget above is real, but its
     * fixture is a reconstructed visit offering nothing, so it never exercises
     * a row with controls on it — it would keep passing if D-02's four acts
     * silently became fourteen. The cost of D-02 is counted HERE, on the row
     * that pays it, and it is FOUR non-photo anchors plus ONE button:
     *
     *   • `Download all photos (ZIP)` — the hand-off (RV-04)
     *   • `Send back`, `Add note`, `Raise a snag` — disclosure ANCHORS, because
     *     there is no JavaScript on this page and a control that only changes
     *     the URL has to be a link
     *   • `Accept` — the one real BUTTON, inside its own form POST
     *
     * EXACTLY, not "at most". A gallery plus a growing control strip is how a
     * calm page becomes the busy control panel the user rejected, and the
     * numbers are the only thing standing between the two.
     */
    public function test_a_reviewable_returned_visit_spends_four_non_photo_anchors_and_one_button(): void
    {
        $project = $this->project();
        $this->reviewableWorksheetVisit($project);

        $body  = $this->body($project, ProjectDeliverable::KEY_WORKSHEET);
        $hrefs = $this->hrefs($body);

        $photoLinks = array_filter(
            $hrefs,
            fn (string $href): bool => str_contains($href, '/cockpit/visits/') && str_contains($href, '/photo/')
        );

        $this->assertGreaterThanOrEqual(
            3,
            count($photoLinks),
            'Non-vacuity floor: the contact sheet really did render links.'
        );

        $nonPhoto = array_values(array_diff($hrefs, $photoLinks));

        $this->assertCount(
            4,
            $nonPhoto,
            'A reviewable returned visit spends exactly four non-photo anchors: the ZIP and the three D-02 disclosures.'
        );

        // ...and they are THOSE four, not some other set that happens to
        // balance the arithmetic.
        foreach (['photos.zip', 'action=send-back', 'action=note', 'action=snag'] as $expected) {
            $this->assertNotEmpty(
                array_filter($nonPhoto, fn (string $href): bool => str_contains(html_entity_decode($href), $expected)),
                "The non-photo anchor `{$expected}` is missing from the reviewable row."
            );
        }

        // ONE button — Accept. The other three acts are anchors, so a second
        // button would mean an act grew a form nobody decided to ship.
        $this->assertSame(
            1,
            substr_count($body, '<button'),
            'Accept is the only button on the Returned tab.'
        );

        // Every disclosure carries the tab it was opened from (Plan 46.1-06),
        // so pressing one does not throw the PM back to Overview.
        foreach ($nonPhoto as $href) {
            if (str_contains($href, 'photos.zip')) {
                continue;
            }

            $this->assertStringContainsString('tab=returned', html_entity_decode($href));
        }
    }
}
