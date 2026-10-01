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
 * Phase 46.1, Plan 46.1-03 — the Returned tab, FIRST BUILT.
 * Phase 46.2, Plan 46.2-03 — UNSURFACED, retired every body-render test here.
 * Phase 47, Plan 47-03 — RE-SURFACED (D-04), THIS IS THE REBUILD.
 *
 * ── WHY THIS IS A REBUILD, NOT A RESTORE ─────────────────────────────────
 *
 * 46.2-03 did not merely hide the tab; it retired the dozen body-render
 * tests this class used to carry, by name, in a block that is itself now
 * retired below (46.2-03's comment is kept, immediately followed by its own
 * retirement note, so the history is legible rather than erased). The
 * PRESENCE RULE — "the tab renders iff this drawer holds a visit with a
 * resolvable source" — is the SAME rule D-01 stated in 46.1; it was never
 * wrong, only unsurfaced, and this class re-derives it against the rebuilt
 * `ProjectCockpitController::TABS` / `$offersReturned` rather than trusting
 * the old assertions to still describe the new code.
 *
 * ── THE FOUR TRUTHS THIS CLASS PROVES NON-VACUOUSLY ──────────────────────
 *
 *   1. The tab is CONDITIONAL — present for a module with a sourced visit,
 *      absent for one without, absent for a module with no visit types at
 *      all (RAMS, O&M), and a stale `?tab=returned` bookmark on any module
 *      that does not offer it falls back to Overview.
 *   2. Evidence is READ LIVE — proved by editing the engineer's own record
 *      between two calls and seeing the second call change.
 *   3. RV-03's three banned columns NEVER render — proved by seeding a
 *      REALISTIC populated value for each of them and asserting its absence
 *      from the rendered page, never by omission.
 *   4. A RECONSTRUCTED visit's evidence STILL renders, with no review-state
 *      sentence and no control (RV-06 / D-06) — controls themselves are
 *      Plan 47-04's concern, not this one's.
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

    // ── Truth 1: THE TAB CONSTANT AND ITS CONDITIONAL PRESENCE ───────────

    public function test_the_tab_constant_is_now_four_entries_with_returned_last(): void
    {
        $this->assertSame(
            ['overview', 'files', 'notes', 'returned'],
            ProjectCockpitController::TABS,
            'Plan 47-03 re-appends `returned` — the three original entries keep their positions.'
        );
    }

    public function test_a_module_holding_a_sourced_visit_now_offers_four_tabs(): void
    {
        $project = $this->project();
        $this->worksheetVisit($project);

        $this->assertSame(
            ['Overview', 'Files', 'Notes', 'Returned'],
            $this->tabLabels($this->panel($project, ProjectDeliverable::KEY_WORKSHEET))
        );
    }

    public function test_a_module_holding_no_visits_at_all_offers_three_tabs(): void
    {
        $project = $this->project();
        $this->worksheetVisit($project);

        // The RAMS drawer holds documents, never visits — `visit_types` is
        // `[]` for it, so `evidenceFor()` has nothing to iterate and the tab
        // never offers, however rich another module's evidence is.
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
            $this->tabLabels($this->panel($project, ProjectDeliverable::KEY_SITE_SURVEY))
        );
    }

    /**
     * THE INVERSE OF 46.2-03's OWN REPLACEMENT TEST. That plan proved "no
     * amount of returned evidence summons a fourth tab" as the property
     * worth protecting under D-02. Under Plan 47-03's D-04 the ORIGINAL
     * property is worth protecting again: the richest drawer this fixture
     * can build — a sourced visit WITH a returned photo, a serial and a
     * sign-off — DOES draw a fourth tab, and names it.
     */
    public function test_the_richest_evidence_this_fixture_can_build_does_summon_the_fourth_tab(): void
    {
        $project = $this->project();
        $this->worksheetVisit($project);

        $labels = $this->tabLabels($this->panel($project, ProjectDeliverable::KEY_WORKSHEET));

        $this->assertSame(['Overview', 'Files', 'Notes', 'Returned'], $labels);
        $this->assertContains('Returned', $labels);
    }

    public function test_tab_returned_on_a_module_without_it_is_a_stale_bookmark(): void
    {
        // A bare project, DELIBERATELY NOT NAMED "Returned …": the project
        // name is printed in the crumb, the masthead and the panel ref, so a
        // fixture name carrying the word would make this assertion pass or
        // fail for a reason that has nothing to do with `?tab=`.
        $project = $this->project(['name' => 'Stale Bookmark Job']);

        // RAMS never offers the tab — `visit_types` is `[]` — so this is
        // still a genuinely stale bookmark under the rebuilt rule.
        $raw = $this->raw($project, ProjectDeliverable::KEY_RAMS, 'returned');

        $html = $this->subtree($raw);

        $this->assertSame(['Overview', 'Files', 'Notes'], $this->tabLabels($html));
        $this->assertSame('Overview', $this->currentTab($html));

        // Asserted on the RAW body, so even an escaped reflection fails.
        $this->assertStringNotContainsStringIgnoringCase(
            '>returned<',
            $this->subtree($raw),
            'A stale `?tab=` value must never be echoed into the page as a tab label.'
        );
    }

    public function test_tab_returned_on_a_module_that_offers_it_opens_the_body(): void
    {
        $project = $this->project();
        [$visit] = $this->worksheetVisit($project);

        $html = $this->panel($project, ProjectDeliverable::KEY_WORKSHEET, 'returned');

        $this->assertSame('Returned', $this->currentTab($html));
        $this->assertStringContainsString('Install visit', $html);
        $this->assertStringContainsString('Download all photos (ZIP)', $html);
    }

    public function test_the_tab_strip_is_still_anchors_only_and_carries_no_aria_expanded(): void
    {
        $project = $this->project();
        $this->worksheetVisit($project);

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

    // ── Truth 2: READ LIVE, NEVER COPIED ─────────────────────────────────

    public function test_evidence_reads_live_so_an_edited_room_note_shows_on_the_next_call(): void
    {
        $project = $this->project();
        [, $survey, $room] = $this->surveyVisit($project);

        $first = $this->panel($project, ProjectDeliverable::KEY_SITE_SURVEY, 'returned');
        $this->assertStringContainsString('Ladder needed for the ceiling void.', $first);

        $room->forceFill(['notes' => 'Access confirmed, no ladder required.'])->save();

        $second = $this->panel($project, ProjectDeliverable::KEY_SITE_SURVEY, 'returned');
        $this->assertStringNotContainsString('Ladder needed for the ceiling void.', $second);
        $this->assertStringContainsString('Access confirmed, no ladder required.', $second);
    }

    // ── Truth 3: RV-03's THREE COLUMNS, SEEDED AND NEVER RENDERED ────────

    public function test_no_capture_address_or_client_agent_is_ever_rendered(): void
    {
        $project = $this->project();
        $this->worksheetVisit($project);

        // The audit values really are in the database -- so the assertions
        // below cannot pass because there was nothing to leak.
        $this->assertDatabaseHas('device_label_photos', ['captured_by' => self::AUDIT_CAPTURE_VALUE]);
        $this->assertDatabaseHas('worksheet_signoffs', ['ip_address' => self::SIGNOFF_ADDRESS]);

        // This now walks a REAL Returned tab render, not a tab that falls
        // back to Overview — the non-vacuity this test needs under the
        // rebuilt rule.
        $raw = '';

        foreach (ProjectCockpitController::TABS as $tab) {
            $raw .= $this->raw($project, ProjectDeliverable::KEY_WORKSHEET, $tab);
        }

        $this->assertStringContainsString(
            'Download all photos (ZIP)',
            $raw,
            'Non-vacuity: the Returned tab must have actually rendered real evidence for the secrets check below to mean anything.'
        );

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

        $raw = '';

        foreach (ProjectCockpitController::TABS as $tab) {
            $raw .= $this->raw($project, ProjectDeliverable::KEY_WORKSHEET, $tab);
        }

        // LR-04 adjacent: the cockpit is staff-auth, so contact details would
        // be sanctioned -- no cockpit tab reads a LabourResource field at all.
        $this->assertStringNotContainsString('marcus.feld@example-engineer.test', $raw);
        $this->assertStringNotContainsString('07700900461', $raw);
    }

    // ── The calm order, every section actually exercised ─────────────────

    public function test_the_calm_order_renders_room_answers_gallery_serials_and_signoff_for_one_visit(): void
    {
        $project = $this->project();
        $this->worksheetVisit($project);

        $html = $this->panel($project, ProjectDeliverable::KEY_WORKSHEET, 'returned');

        // 2. hand-off link.
        $this->assertStringContainsString('Download all photos (ZIP)', $html);

        // 4. gallery — the worksheet photo (after) and the label photo
        // (label) each get a bucket heading.
        $this->assertStringContainsString('After (install)', $html);
        $this->assertStringContainsString('Equipment labels', $html);

        // 5. the serial, and the device description — never the audit column.
        $this->assertStringContainsString('SN-1122-AA', $html);
        $this->assertStringContainsString('Ceiling microphone array', $html);

        // 6. the sign-off — name and date, never ip_address/user_agent.
        $this->assertStringContainsString('Priya Raman', $html);
    }

    public function test_the_calm_order_renders_the_survey_room_its_answer_and_before_bucket(): void
    {
        $project = $this->project();
        $this->surveyVisit($project);

        $html = $this->panel($project, ProjectDeliverable::KEY_SITE_SURVEY, 'returned');

        // 3. per-room card: name, notes, "N of M questions answered", and the
        // engineer's OWN words for an `other` answer rather than the enum
        // token.
        $this->assertStringContainsString('Boardroom', $html);
        $this->assertStringContainsString('Ladder needed for the ceiling void.', $html);
        $this->assertStringContainsString('1 of 1 questions answered', $html);
        $this->assertStringContainsString('A step stool is enough.', $html);
        $this->assertStringNotContainsString('>Other<', $html);

        // 4. gallery — survey photos land in the before bucket.
        $this->assertStringContainsString('Before (survey)', $html);

        // A survey-sourced visit carries no serials and no sign-off.
        $this->assertStringNotContainsString('Captured serials', $html);
        $this->assertStringNotContainsString('Client sign-off', $html);
    }

    public function test_an_unanswered_question_is_omitted_but_still_counted(): void
    {
        $project = $this->project();
        $survey  = $this->survey($project);

        Visit::factory()->backfilledFromSurvey($survey)->create([
            'project_id' => $project->id,
            'type'       => Visit::TYPE_SITE_SURVEY,
            'title'      => 'Partial survey visit',
        ]);

        $room = $this->room($survey, ['notes' => 'One of two answered.']);
        $this->question($room, ['question' => 'Answered one', 'answer' => 'yes']);
        $this->question($room, ['question' => 'Never answered', 'answer' => null]);

        $html = $this->panel($project, ProjectDeliverable::KEY_SITE_SURVEY, 'returned');

        $this->assertStringContainsString('1 of 2 questions answered', $html);
        $this->assertStringContainsString('Answered one', $html);
        $this->assertStringNotContainsString('Never answered', $html);
    }

    public function test_a_visit_with_nothing_returned_yet_renders_one_sentence_and_stops(): void
    {
        $project   = $this->project();
        $worksheet = $this->worksheet($project);

        Visit::factory()->create([
            'project_id'  => $project->id,
            'type'        => Visit::TYPE_INSTALL,
            'title'       => 'Issued, untouched',
            'sent_at'     => now()->subDay(),
            'source_type' => Visit::SOURCE_WORKSHEET,
            'source_id'   => $worksheet->id,
        ]);

        $html = $this->panel($project, ProjectDeliverable::KEY_WORKSHEET, 'returned');

        $this->assertStringContainsString('Nothing has come back from site yet.', $html);
        $this->assertStringNotContainsString('Download all photos (ZIP)', $html);
        $this->assertStringNotContainsString('cav-returned__room', $html);
    }

    public function test_a_visit_whose_source_was_force_deleted_says_so(): void
    {
        $project   = $this->project();
        $worksheet = $this->worksheet($project);
        $goneId    = $worksheet->id;
        // `Visit::source()` uses `withTrashed()`, so a plain soft `delete()`
        // still resolves — see that method's own docblock. `source_missing`
        // only reports true for a genuinely FORCE-deleted row.
        $worksheet->forceDelete();

        Visit::factory()->create([
            'project_id'  => $project->id,
            'type'        => Visit::TYPE_INSTALL,
            'title'       => 'Source gone',
            'source_type' => Visit::SOURCE_WORKSHEET,
            'source_id'   => $goneId,
        ]);

        $html = $this->panel($project, ProjectDeliverable::KEY_WORKSHEET, 'returned');

        $this->assertStringContainsString('The visit is recorded; the evidence behind it could not be read.', $html);
        $this->assertStringNotContainsString('Download all photos (ZIP)', $html);
    }

    // ── Truth 4: RECONSTRUCTED EVIDENCE STILL RENDERS, WITH NO CONTROL ───

    public function test_a_reconstructed_visits_evidence_still_renders_with_no_review_state_sentence(): void
    {
        $project   = $this->project();
        $worksheet = $this->worksheet($project);

        $this->worksheetPhoto($worksheet);
        $this->signoff($worksheet);

        $visit = Visit::factory()->backfilledFromWorksheet($worksheet)->create([
            'project_id' => $project->id,
            'type'       => Visit::TYPE_INSTALL,
            'title'      => 'Reconstructed install',
            'status'     => Visit::STATUS_COMPLETED,
        ]);

        $this->assertTrue($visit->isBackfilled());
        $this->assertTrue($visit->isClosed());

        $html = $this->panel($project, ProjectDeliverable::KEY_WORKSHEET, 'returned');

        // The evidence is there — the visit title, the hand-off link, the
        // sign-off name — exactly as RV-06 requires.
        $this->assertStringContainsString('Reconstructed install', $html);
        $this->assertStringContainsString('Download all photos (ZIP)', $html);
        $this->assertStringContainsString('Priya Raman', $html);

        // No review-state sentence — nobody performed this review.
        foreach (['Accepted by', 'Sent back', 'Awaiting the engineer'] as $sentence) {
            $this->assertStringNotContainsString($sentence, $html);
        }

        // Scope fence: no visit-management control anywhere on this tab —
        // Plan 47-04's concern, deliberately not this one's.
        foreach (['>Accept<', '>Send back<', '>Add note<', '>Raise a snag<'] as $control) {
            $this->assertStringNotContainsString($control, $html);
        }
    }

    // ── The fence copy-collision check, run for real (46-04 / 46.1-04's own
    //    precedent, carried into this plan) ───────────────────────────────

    public function test_the_new_copy_collides_with_no_fence_entry(): void
    {
        $forbidden = array_merge(
            array_keys((new \ReflectionClass(CockpitReadOnlyFenceTest::class))->getConstant('DEFERRED_AFFORDANCES')),
            (new \ReflectionClass(CockpitReadOnlyFenceTest::class))->getConstant('FORBIDDEN_MARKUP'),
        );

        $newCopy = [
            'Download all photos (ZIP)',
            'Nothing has come back from site yet.',
            'The visit is recorded; the evidence behind it could not be read.',
            'Equipment labels',
            'Before (survey)',
            'After (install)',
            'Captured serials',
            'Client sign-off',
            'Serial not read yet',
        ];

        foreach ($newCopy as $copy) {
            foreach ($forbidden as $entry) {
                $this->assertStringNotContainsString(
                    $entry,
                    $copy,
                    "Returned tab copy \"{$copy}\" collides with fence entry \"{$entry}\"."
                );
            }
        }

        // Non-vacuity: 'Download all photos (ZIP)' really would have
        // collided had the entry still been present — proved by checking it
        // WOULD have matched the lifted string before the lift.
        $this->assertStringContainsString('Download', 'Download all photos (ZIP)');
    }
}
