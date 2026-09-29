<?php

namespace Tests\Feature\Worksheets;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyRoom;
use App\Models\User;
use App\Models\Worksheet;
use App\Models\WorksheetPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 46.7 Plan 02 Task 3 — the tabbed engineer link, RENDERED IN EVERY STATE
 * AND COUNTED.
 *
 * ⚠️ WHY THIS FILE IS SHAPED LIKE THIS. Five defects in two weeks reached the
 * user through green suites, and every one of them was an assertion that
 * rendered a SINGLE state. A tabbed page has a state per tab, a state per room
 * status, a signed state, an unsigned state and a roomless state. So every
 * state below is rendered, and every assertion asserts the number that was
 * measured — never "at least one", never a bare presence check where a count
 * is available.
 *
 * ── THE STATE MATRIX, AND WHAT IS AND IS NOT COVERED ─────────────────────────
 *
 *  | State                              | Covered | By                        |
 *  |------------------------------------|---------|---------------------------|
 *  | room status `unreviewed`           | yes     | four-room fixture         |
 *  | room status `todo`                 | yes     | four-room fixture         |
 *  | room status `in-progress`          | yes     | four-room fixture         |
 *  | room status `complete`             | yes     | four-room fixture         |
 *  | bar status == panel-head pills     | yes     | per room, both directions  |
 *  | bar button <-> panel bijection     | yes     | both directions, counted  |
 *  | exactly one panel active           | yes     | first-incomplete room     |
 *  | every room complete -> site active | yes     | second render             |
 *  | roomless worksheet                 | yes     | zero room buttons, 0 of 0 |
 *  | signed worksheet                   | yes     | unsigned mirror first     |
 *  | site tab carries client/ref/addr   | yes     | by content                |
 *  | pinned counts (raw echo, Alpine)   | yes     | source scan               |
 *  | `#room-{slug}` anchor CONTRACT     | yes     | banner href + panel id    |
 *  | `#room-{slug}` anchor BEHAVIOUR    | NO      | browser fact — plan 04's  |
 *  |                                    |         | blocking human checkpoint |
 *  | the bar on a real phone, one-handed| NO      | plan 04's checkpoint      |
 *  | tab state across a REAL reload     | NO      | plan 04's checkpoint      |
 *
 * The three uncovered rows are uncovered because this repo drives no browser:
 * puppeteer is reachable only through Browsershot inside PdfRenderService,
 * which renders HTML to PDF and does not run a live page. Saying so here is
 * cheaper than somebody later believing the suite proved it.
 *
 * @see resources/views/worksheets/public-show.blade.php
 * @see .planning/phases/46.7-engineer-link-tabbed-layout/46.7-CONTEXT.md (D-01, D-02, D-05)
 */
class EngineerLinkTabbedLayoutTest extends TestCase
{
    use RefreshDatabase;

    private const VIEW = 'resources/views/worksheets/public-show.blade.php';

    /**
     * ONE ROOM PER STATUS, in this order, and the order is the tab order.
     *
     * `unreviewed` sits FIRST on purpose: it is the status that blocks the
     * client's signature, so it is also the status whose precedence must beat
     * the other three. Room 1 has survey data AND photos AND is marked
     * complete — if precedence were wrong it would read `complete` and the bar
     * would tell an engineer a blocked room is finished.
     */
    private const ROOM_UNREVIEWED  = 'Boardroom';

    private const ROOM_TODO        = 'Store Room';

    private const ROOM_IN_PROGRESS = 'Comms Room';

    private const ROOM_COMPLETE    = 'Reception';

    /** @var list<string> */
    private const ROOMS = [
        self::ROOM_UNREVIEWED,
        self::ROOM_TODO,
        self::ROOM_IN_PROGRESS,
        self::ROOM_COMPLETE,
    ];

    /** The status each room in self::ROOMS must report, keyed by room name. */
    private const EXPECTED_STATUS = [
        self::ROOM_UNREVIEWED  => 'unreviewed',
        self::ROOM_TODO        => 'todo',
        self::ROOM_IN_PROGRESS => 'in-progress',
        self::ROOM_COMPLETE    => 'complete',
    ];

    /**
     * ALPINE, PINNED AT TODAY'S COUNT — restated here, not trusted to live in
     * another file. THIS is the plan that rewrites the page's markup, so this is
     * the plan where a sixth directive gets added by accident.
     *
     * ⚠️ These needles match COMMENTS as well as attributes. Naming a directive
     * in a comment fails the guard; eight near-misses so far.
     *
     * @var array<string,int>
     */
    private const ALPINE_OCCURRENCES = [
        ' x-data'           => 1,
        ' x-show'           => 1,
        ' x-model'          => 2,
        ' x-cloak'          => 1,
        '@click'            => 0,
        '<x-photo-lightbox' => 0,
    ];

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function project(): Project
    {
        $user = User::factory()->create();

        return Project::factory()->create(['user_id' => $user->id]);
    }

    /**
     * @param  list<string>  $rooms
     */
    private function worksheet(array $rooms, ?Project $project = null, ?array $confirmations = null): Worksheet
    {
        $project ??= $this->project();

        // Worksheet::create (not ->update) so boot::creating mints access_token
        // by direct assignment — access_token is deliberately absent from
        // $fillable and must never be mass-assigned.
        return Worksheet::create([
            'user_id'                    => $project->user_id,
            'project_id'                 => $project->id,
            'project_name'               => 'Tabbed Layout Fixture',
            'project_ref'                => '21CQ00000-01-OPS',
            'client_name'                => 'Fixture Client',
            'site_address'               => '1 Fixture Way, Reading RG1 1AA',
            'status'                     => Worksheet::STATUS_FINAL,
            'generated_data'             => [
                'rooms' => array_map(fn (string $name) => ['name' => $name, 'equipment' => []], $rooms),
            ],
            'pre_install_confirmations'  => $confirmations,
        ]);
    }

    /**
     * The four-room, four-status fixture. ONE room per status, so a status class
     * counted at exactly 1 in the bar cannot be satisfied by the wrong room.
     */
    private function fourStatusWorksheet(): Worksheet
    {
        $project = $this->project();

        // A survey covering ONLY the unreviewed room, so the review gate applies
        // to that room and to no other. If it covered them all, three statuses
        // would collapse into `unreviewed` and this fixture would prove nothing.
        $survey = SiteSurvey::create([
            'user_id'      => $project->user_id,
            'project_id'   => $project->id,
            'project_name' => 'Tabbed Layout Fixture',
            'client_name'  => 'Fixture Client',
            'status'       => 'completed',
        ]);
        SiteSurveyRoom::create([
            'site_survey_id'   => $survey->id,
            'room_name'        => self::ROOM_UNREVIEWED,
            'mounting_heights' => [['item' => 'Display', 'height_m' => '1.2']],
        ]);

        $worksheet = $this->worksheet(self::ROOMS, $project, [
            'room_complete' => [
                // The unreviewed room is ALSO marked complete, deliberately.
                // `unreviewed` must still win — a room that blocks the client's
                // signature showing green is the worst thing this bar can do.
                self::ROOM_UNREVIEWED => ['completed_at' => '2026-09-29 09:00:00', 'completed_by' => 'Engineer One'],
                self::ROOM_COMPLETE   => ['completed_at' => '2026-09-29 10:00:00', 'completed_by' => 'Engineer One'],
            ],
        ]);

        // The unreviewed room has photos too — again so `unreviewed` has to beat
        // `in-progress` as well as `complete`.
        $this->photo($worksheet, self::ROOM_UNREVIEWED);
        $this->photo($worksheet, self::ROOM_IN_PROGRESS);
        $this->photo($worksheet, self::ROOM_IN_PROGRESS);

        return $worksheet->fresh();
    }

    private function photo(Worksheet $worksheet, string $room): WorksheetPhoto
    {
        return $worksheet->photos()->create([
            'room_name'     => $room,
            'bucket'        => WorksheetPhoto::BUCKET_COMPLETION,
            'filename'      => 'worksheet-photos/' . $worksheet->id . '/' . fake()->uuid() . '.jpg',
            'original_name' => 'capture.jpg',
            'mime_type'     => 'image/jpeg',
            'caption'       => 'Fixture capture',
            'sort_order'    => 1,
        ]);
    }

    private function render(Worksheet $worksheet): string
    {
        return $this
            ->get(route('public-worksheet.show', ['token' => $worksheet->access_token]))
            ->assertOk()
            ->getContent();
    }

    private function source(): string
    {
        $source = file_get_contents(base_path(self::VIEW));
        $this->assertIsString($source);

        return $source;
    }

    // ── DOM helpers — parsed, never grepped ──────────────────────────────────

    private function dom(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        return new \DOMXPath($dom);
    }

    private function subtree(\DOMXPath $xpath, string $query): string
    {
        $html = '';
        foreach ($xpath->query($query) as $node) {
            $html .= $node->ownerDocument->saveHTML($node);
        }

        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** Every bar button that targets a ROOM (the site button is excluded). */
    private function roomButtons(\DOMXPath $xpath): \DOMNodeList
    {
        return $xpath->query("//nav[@data-ws-tab-bar]//button[@data-tab-target][@data-room-status]");
    }

    // ── 0. NON-VACUITY, FIRST IN THE FILE ────────────────────────────────────

    /**
     * Every count in this file is counted against THIS page. If the fixture
     * silently rendered an error page, a redirect body or an empty shell, a
     * dozen assertions below would pass by counting nothing at all. So the page
     * is proved substantial before anything is counted on it.
     */
    public function test_the_fixture_renders_a_real_and_substantial_page(): void
    {
        $html = $this->render($this->fourStatusWorksheet());

        $this->assertGreaterThan(
            50000,
            strlen($html),
            'The fixture page is too small to be the real worksheet — every count in this file '
            . 'would be counting an error page or an empty shell instead.',
        );
        $this->assertStringContainsString('Tabbed Layout Fixture', $html);

        foreach (self::ROOMS as $room) {
            $this->assertStringContainsString(
                $room,
                $html,
                "The fixture room {$room} is not on the page at all.",
            );
        }
    }

    // ── 1. FOUR STATUSES, ONE ROOM EACH, SERVER-EMITTED ──────────────────────

    /**
     * THE POINT OF THE PHASE. The user, recorded in 46.7-CONTEXT.md: *"the win
     * is the sticky bar showing which rooms are done. If a room's status is not
     * visible in the bar, the phase has missed its point."*
     */
    public function test_the_bar_emits_exactly_one_button_per_room_and_one_room_per_status(): void
    {
        $xpath = $this->dom($this->render($this->fourStatusWorksheet()));

        $this->assertSame(
            1,
            $xpath->query('//nav[@data-ws-tab-bar]')->length,
            'There is no sticky bar. An engineer has no way to see which rooms are done without '
            . 'opening every one of them, which is the whole thing this phase set out to fix.',
        );

        $this->assertSame(
            count(self::ROOMS),
            $this->roomButtons($xpath)->length,
            'The bar does not carry one button per room. A room with no button is a room the '
            . 'engineer cannot reach at all.',
        );

        // The site button, counted separately — it is NOT a room and must not be
        // swept into the room count by a loose selector.
        $this->assertSame(
            1,
            $xpath->query("//nav[@data-ws-tab-bar]//button[@data-tab-target='site']")->length,
            'The site tab button is missing — the client, reference and address have nowhere to live.',
        );
        $this->assertSame(
            count(self::ROOMS) + 1,
            $xpath->query('//nav[@data-ws-tab-bar]//button[@data-tab-target]')->length,
            'The bar holds something other than one site button plus one button per room.',
        );

        // Exactly one room per status. A collapsed precedence would show two
        // rooms with the same status and one status missing entirely.
        foreach (['unreviewed', 'todo', 'in-progress', 'complete'] as $status) {
            $this->assertSame(
                1,
                $xpath->query("//nav[@data-ws-tab-bar]//button[@data-room-status='{$status}']")->length,
                "Exactly one fixture room is {$status}. A different number means the status "
                . 'precedence collapsed and the bar is reporting the wrong thing about a real room.',
            );
            $this->assertSame(
                1,
                $xpath->query("//nav[@data-ws-tab-bar]//button[contains(@class,'tab--status-{$status}')]")->length,
                "The {$status} button carries no tab--status-{$status} class, so the status is in "
                . 'the markup but invisible to the engineer looking at the bar.',
            );
        }

        // Per room, by name — not just "four statuses exist somewhere".
        foreach (self::EXPECTED_STATUS as $room => $status) {
            $button = $xpath->query(
                "//nav[@data-ws-tab-bar]//button[@data-room-status][.//span[normalize-space(text())="
                . $this->xpathLiteral($room) . ']]'
            );

            $this->assertSame(1, $button->length, "No single bar button is labelled {$room}.");
            $this->assertSame(
                $status,
                $button->item(0)->getAttribute('data-room-status'),
                "The bar reports the wrong status for {$room}. An engineer trusts this bar and "
                . 'leaves site on it.',
            );
        }
    }

    /**
     * ⚠️ SERVER-EMITTED, AND THAT IS THE RELIABILITY ARGUMENT, NOT A STYLE
     * PREFERENCE. This page is read in plant rooms and comms cupboards on a
     * dying signal. A bar whose statuses are painted by JavaScript shows an
     * engineer nothing on the one device-and-day where it matters. The status
     * must be in the HTML the server sent.
     */
    public function test_every_room_status_is_in_the_server_html_with_no_script_involved(): void
    {
        $html = $this->render($this->fourStatusWorksheet());

        // The raw response body, before any parser and before any script could
        // ever have run. A test of the DOM alone would not distinguish the two.
        foreach (self::EXPECTED_STATUS as $room => $status) {
            $this->assertStringContainsString(
                'data-room-status="' . $status . '"',
                $html,
                "The status {$status} (room {$room}) is not in the server's own HTML. With "
                . 'scripting off or a script that never loaded, the bar would show nothing.',
            );
        }

        // And the status is not assigned anywhere in JS — if it were, the server
        // copy could go stale and the two would disagree.
        $this->assertStringNotContainsString(
            "setAttribute('data-room-status'",
            $this->source(),
            'A script assigns data-room-status. The status must be the server\'s, so that the bar '
            . 'is correct on a phone with no working JavaScript.',
        );
    }

    // ── 2. THE BAR AND THE PANEL MUST AGREE ──────────────────────────────────

    /**
     * ⚠️ THE DEFECT THIS TEST EXISTS TO CATCH. The same fact — this room's
     * status — is rendered twice: in the bar and on the panel head. They are
     * derived once in PHP precisely so they cannot drift, and this is the
     * assertion that proves the derivation is still single.
     *
     * A bar that says a room is done when its panel says otherwise is WORSE
     * than no bar, because the engineer believes the bar and goes home.
     */
    public function test_each_bar_button_agrees_with_its_own_rooms_panel_head(): void
    {
        $xpath = $this->dom($this->render($this->fourStatusWorksheet()));

        $checked = 0;

        foreach ($this->roomButtons($xpath) as $button) {
            $target = $button->getAttribute('data-tab-target');
            $status = $button->getAttribute('data-room-status');

            $head = $this->subtree(
                $xpath,
                "//section[@data-tab=" . $this->xpathLiteral($target) . "]/h2[@class='room-panel-head']",
            );

            $this->assertNotSame('', $head, "Room tab {$target} has no panel head to agree with.");

            // The pills the head renders, mapped back to the status the bar
            // claims. Asserted in BOTH directions per status: the pill that must
            // be there, and the pills that must not.
            $unreviewedPill = str_contains($head, 'Survey not reviewed');
            $reviewedPill   = str_contains($head, '✓ Reviewed');
            $completePill   = str_contains($head, '✓ Complete');

            switch ($status) {
                case 'unreviewed':
                    $this->assertTrue(
                        $unreviewedPill,
                        "The bar calls {$target} unreviewed but its panel head shows no "
                        . '"Survey not reviewed" pill. One of the two is lying to the engineer.',
                    );
                    $this->assertFalse(
                        $reviewedPill,
                        "The panel head for {$target} claims the survey IS reviewed while the bar "
                        . 'says it is not — sign-off will block for a reason the engineer cannot see.',
                    );
                    break;

                case 'complete':
                    $this->assertTrue(
                        $completePill,
                        "The bar calls {$target} complete but its panel head carries no Complete pill.",
                    );
                    $this->assertFalse(
                        $unreviewedPill,
                        "The panel head for {$target} still blocks sign-off while the bar shows it green.",
                    );
                    break;

                case 'in-progress':
                case 'todo':
                    $this->assertFalse(
                        $completePill,
                        "The bar says {$target} is not finished but its panel head shows a Complete "
                        . 'pill. The engineer would skip a room that still needs work.',
                    );
                    $this->assertFalse(
                        $unreviewedPill,
                        "The bar says {$target} is {$status} but the panel head says the survey is "
                        . 'unreviewed, which outranks both and should have been the bar\'s status.',
                    );
                    break;

                default:
                    $this->fail("Unknown room status emitted into the bar: {$status}");
            }

            // The photo count is on both, and it is the same number on both.
            $this->assertStringContainsString(
                '📷 ' . $this->photoCountFromButton($button),
                $head,
                "The photo count on the bar and on the panel head disagree for {$target}. The "
                . 'Mark Room Complete gate reads that number.',
            );

            $checked++;
        }

        $this->assertSame(
            count(self::ROOMS),
            $checked,
            'The agreement loop ran a different number of times than there are rooms — a selector '
            . 'that finds nothing would otherwise pass this test by checking nothing.',
        );
    }

    /**
     * BIJECTION, ASSERTED IN BOTH DIRECTIONS AND COUNTED. A one-directional
     * check passes a page with an orphan panel (a room nobody can navigate to)
     * or an orphan button (a tap that does nothing).
     */
    public function test_every_bar_button_resolves_to_exactly_one_panel_and_back(): void
    {
        $xpath = $this->dom($this->render($this->fourStatusWorksheet()));

        $targets = [];
        foreach ($xpath->query('//nav[@data-ws-tab-bar]//button[@data-tab-target]') as $button) {
            $targets[] = $button->getAttribute('data-tab-target');
        }

        $panels = [];
        foreach ($xpath->query('//*[@data-tab]') as $panel) {
            $panels[] = $panel->getAttribute('data-tab');
        }

        $this->assertSame(
            count(self::ROOMS) + 1,
            count($targets),
            'The bar button count changed — site plus one per room is the whole bar.',
        );
        $this->assertSame(
            count($targets),
            count($panels),
            'There are more panels than buttons, or more buttons than panels. Either an engineer '
            . 'cannot reach a room, or a button in the bar does nothing when tapped.',
        );
        $this->assertSame(
            $targets,
            $panels,
            'The bar and the panels are not in the same order with the same keys. Tab order IS '
            . 'room order on this page.',
        );

        // Forward: each button target resolves to EXACTLY one panel.
        foreach ($targets as $target) {
            $this->assertSame(
                1,
                $xpath->query('//*[@data-tab=' . $this->xpathLiteral($target) . ']')->length,
                "Bar button {$target} does not resolve to exactly one panel.",
            );
        }

        // Backward: each panel is reachable from EXACTLY one button.
        foreach ($panels as $panel) {
            $this->assertSame(
                1,
                $xpath->query(
                    '//nav[@data-ws-tab-bar]//button[@data-tab-target=' . $this->xpathLiteral($panel) . ']'
                )->length,
                "Panel {$panel} is not reachable from exactly one bar button.",
            );
        }
    }

    // ── 3. EXACTLY ONE PANEL ACTIVE, CHOSEN BY THE SERVER ────────────────────

    public function test_exactly_one_panel_is_active_and_it_is_the_first_incomplete_room(): void
    {
        $xpath = $this->dom($this->render($this->fourStatusWorksheet()));

        $active = $xpath->query("//*[@data-tab][contains(@class,'is-active')]");

        $this->assertSame(
            1,
            $active->length,
            'A tabbed page with no active panel shows the engineer an empty screen; with two, it '
            . 'shows them two rooms stacked and the bar lies about where they are.',
        );

        // Room 1 is marked complete in the fixture, room 2 (todo) is the first
        // that is not — the same rule the accordions used to open with.
        $this->assertSame(
            'room-2',
            $active->item(0)->getAttribute('data-tab'),
            'The page did not open on the first room that is not marked complete. An engineer '
            . 'arriving on site lands on a finished room and has to go looking.',
        );

        $selected = $xpath->query("//nav[@data-ws-tab-bar]//button[@aria-selected='true']");
        $this->assertSame(
            1,
            $selected->length,
            'The bar marks a different number than one button selected, so the engineer cannot '
            . 'tell which room they are in.',
        );
        $this->assertSame(
            'room-2',
            $selected->item(0)->getAttribute('data-tab-target'),
            'The bar highlights a different tab than the one the server actually opened.',
        );
    }

    /**
     * The second render: every room complete. The accordions' rule was "leave
     * them all closed so the engineer sees a clean all-done page"; the tabbed
     * equivalent is the SITE tab, because a tabbed page cannot show nothing.
     */
    public function test_when_every_room_is_complete_the_site_panel_is_the_active_one(): void
    {
        $confirmations = ['room_complete' => []];
        foreach ([self::ROOM_TODO, self::ROOM_IN_PROGRESS, self::ROOM_COMPLETE] as $room) {
            $confirmations['room_complete'][$room] = [
                'completed_at' => '2026-09-29 11:00:00',
                'completed_by' => 'Engineer One',
            ];
        }

        // No survey at all, so no room is unreviewed and "all complete" is real.
        $worksheet = $this->worksheet(
            [self::ROOM_TODO, self::ROOM_IN_PROGRESS, self::ROOM_COMPLETE],
            null,
            $confirmations,
        );

        $xpath = $this->dom($this->render($worksheet));

        $active = $xpath->query("//*[@data-tab][contains(@class,'is-active')]");
        $this->assertSame(1, $active->length, 'Exactly one panel is active on every render.');
        $this->assertSame(
            'site',
            $active->item(0)->getAttribute('data-tab'),
            'With every room complete the page must open on the site tab, not drop the engineer '
            . 'back into a finished room.',
        );
        $this->assertSame(
            'true',
            $xpath->query("//nav[@data-ws-tab-bar]//button[@data-tab-target='site']")
                ->item(0)->getAttribute('aria-selected'),
            'The site button is not marked selected while the site panel is the active one.',
        );

        // And the chip counts them all.
        $this->assertStringContainsString(
            '3 of 3 rooms marked complete',
            $this->subtree($xpath, "//section[@data-tab='site']"),
            'The site tab does not report how many rooms are done.',
        );
    }

    // ── 4. THE SITE TAB CARRIES THE THREE FACTS (D-02) ───────────────────────

    public function test_the_site_tab_carries_client_reference_address_and_the_site_contact(): void
    {
        $project = $this->project();

        // Same package shape as PublicWorksheetHeaderContactTest — the keys are
        // TOP-LEVEL in extracted_data, not nested under 'project'.
        \App\Models\ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $project->user_id,
            'quote_filename' => 'fixture.pdf',
            'quote_path'     => 'fixtures/fixture.pdf',
            'extracted_data' => ['ship_contact' => 'Dana Keys', 'ship_phone' => '0118 900 1234'],
            'status'         => \App\Models\ProjectPackage::STATUS_EXTRACTED,
        ]);

        $worksheet = $this->worksheet(self::ROOMS, $project->fresh());

        $xpath = $this->dom($this->render($worksheet));
        $site  = $this->subtree($xpath, "//section[@data-tab='site']");

        $this->assertNotSame('', $site, 'There is no site tab at all.');

        foreach (['Fixture Client', '21CQ00000-01-OPS', '1 Fixture Way, Reading RG1 1AA'] as $fact) {
            $this->assertStringContainsString(
                $fact,
                $site,
                "The site tab does not carry \"{$fact}\". An engineer arriving on site opens the "
                . 'first tab to find out where they are and who they are working for.',
            );
        }

        // The site-contact block — the four strings
        // PublicWorksheetHeaderContactTest pins, proved to have MOVED here
        // rather than merely to still exist somewhere on the page.
        $this->assertStringContainsString('Site contact:', $site);
        $this->assertStringContainsString('Dana Keys', $site);
        $this->assertStringContainsString('tel:+441189001234', $site);
        $this->assertStringContainsString('0118 900 1234', $site);
    }

    /**
     * MOVED, NOT COPIED. Three facts in two places is three facts that can
     * disagree the first time one of them is edited.
     */
    public function test_the_header_no_longer_duplicates_the_site_details(): void
    {
        $xpath = $this->dom($this->render($this->fourStatusWorksheet()));

        $header = $this->subtree($xpath, "//header[contains(@class,'ws-header')]");

        $this->assertNotSame('', $header, 'The page header is gone entirely.');
        $this->assertStringContainsString(
            'Tabbed Layout Fixture',
            $header,
            'The project name must STAY in the header — it is the page\'s always-visible identity '
            . 'and an engineer should never have to open a tab to see which job this is.',
        );
        $this->assertStringNotContainsString(
            '1 Fixture Way, Reading RG1 1AA',
            $header,
            'The site address is still in the header as well as the site tab. Two copies of one '
            . 'fact is one copy that will be wrong.',
        );
    }

    // ── 5. THE ROOMLESS WORKSHEET (it 500'd in production once already) ──────

    public function test_a_roomless_worksheet_renders_a_site_tab_and_an_empty_room_list(): void
    {
        $worksheet = $this->worksheet([]);

        $response = $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]));
        $response->assertOk();

        $html  = $response->getContent();
        $xpath = $this->dom($html);

        $this->assertSame(
            1,
            $xpath->query("//*[@data-tab='site']")->length,
            'A roomless worksheet has no site tab, so it has no tab at all and the page is blank.',
        );
        $this->assertSame(
            0,
            $this->roomButtons($xpath)->length,
            'A roomless worksheet emits room buttons for rooms that do not exist.',
        );
        $this->assertSame(
            1,
            $xpath->query('//nav[@data-ws-tab-bar]//button[@data-tab-target]')->length,
            'The bar on a roomless worksheet must hold exactly the site button — no empty bar, '
            . 'and no button that resolves to nothing.',
        );

        // The summary chip, with no room count to divide by.
        $this->assertStringContainsString(
            '0 of 0 rooms marked complete',
            $this->subtree($xpath, "//section[@data-tab='site']"),
            'The roomless summary chip does not read "0 of 0".',
        );

        // No warning, no notice, no deprecation escaped into the response — a
        // divide-by-zero or an undefined index would surface right here.
        foreach (['Undefined', 'Division by zero', 'Warning:', 'Deprecated:', 'ErrorException'] as $noise) {
            $this->assertStringNotContainsString(
                $noise,
                $html,
                "The roomless page leaked \"{$noise}\" into the response an engineer reads.",
            );
        }

        // The pre-existing behaviour is untouched.
        $response->assertSee('No room data is available yet');
        $response->assertSee('Client Sign-Off');
    }

    // ── 6. SIGNED — THE BAR STAYS, THE CONTROLS DO NOT ───────────────────────

    /**
     * A signed worksheet is a RECORD, and whoever reads it back — the client,
     * the office — still has to navigate it. So the bar stays, with the same
     * buttons and the same statuses. It emits no write (T-46.7-02-03), which is
     * why it is rendered outside the lock's fieldset.
     *
     * The unsigned mirror runs FIRST in both halves, the house pattern from
     * EngineerLinkPhotoTrayGuardTest: without it, a typo in a selector would
     * "prove" the lock by finding nothing anywhere.
     */
    public function test_a_signed_worksheet_still_renders_the_whole_bar_with_the_same_statuses(): void
    {
        $worksheet = $this->fourStatusWorksheet();

        // MIRROR FIRST — unsigned.
        $unsigned = $this->dom($this->render($worksheet));
        $this->assertSame(
            count(self::ROOMS),
            $this->roomButtons($unsigned)->length,
            'An UNSIGNED worksheet must carry one bar button per room.',
        );

        $before = [];
        foreach ($this->roomButtons($unsigned) as $b) {
            $before[$b->getAttribute('data-tab-target')] = $b->getAttribute('data-room-status');
        }

        $worksheet->signoffs()->create([
            'client_name'          => 'A Client',
            'signature_png_base64' => base64_encode('not-a-real-png'),
            'signed_with_comments' => false,
            'signed_at'            => now(),
        ]);

        $signed = $this->dom($this->render($worksheet->fresh()));

        $this->assertSame(
            count(self::ROOMS),
            $this->roomButtons($signed)->length,
            'The bar vanished once the worksheet was signed. A completed record still has to be '
            . 'readable, and with no bar its rooms are unreachable.',
        );

        $after = [];
        foreach ($this->roomButtons($signed) as $b) {
            $after[$b->getAttribute('data-tab-target')] = $b->getAttribute('data-room-status');
        }

        $this->assertSame(
            $before,
            $after,
            'Signing changed what the bar says about the rooms. A signed record must read back '
            . 'exactly as it was signed.',
        );
    }

    /**
     * Re-asserted HERE, in the plan that rewrites the markup, so a layout change
     * cannot quietly resurrect a control the D-07 lock removed.
     */
    public function test_the_signed_page_still_renders_no_capture_control(): void
    {
        $worksheet = $this->fourStatusWorksheet();

        // MIRROR FIRST — unsigned, controls present. A bare "0 controls" on a
        // signed page proves nothing on its own.
        $unsigned = $this->dom($this->render($worksheet));
        $this->assertGreaterThan(
            0,
            $unsigned->query('//*[@data-capture-control]')->length,
            'An UNSIGNED worksheet renders no capture control at all — the selector is wrong and '
            . 'the signed assertion below would pass against nothing.',
        );

        $worksheet->signoffs()->create([
            'client_name'          => 'A Client',
            'signature_png_base64' => base64_encode('not-a-real-png'),
            'signed_with_comments' => false,
            'signed_at'            => now(),
        ]);

        $signed = $this->dom($this->render($worksheet->fresh()));

        $this->assertSame(
            0,
            $signed->query('//*[@data-capture-control]')->length,
            'The tabbed rewrite brought a capture control back onto a SIGNED worksheet. The '
            . 'client signed to confirm the work is finished; nothing may change after that.',
        );
    }

    // ── 7. THE ANCHOR CONTRACT (landmine 3) ──────────────────────────────────

    /**
     * The sign-off-blocked banner's "Jump to first unreviewed room →" link is
     * the engineer's only way out of a blocked sign-off. It emits
     * `#room-{slug}`, and a panel must carry that exact id or the link is dead.
     *
     * The BEHAVIOUR — that the fragment activates the tab rather than scrolling
     * to a hidden panel — is a browser fact and belongs to plan 04's blocking
     * human checkpoint. The CONTRACT is assertable here, and is.
     */
    public function test_the_signoff_blocked_banner_anchor_still_names_a_real_panel(): void
    {
        $html  = $this->render($this->fourStatusWorksheet());
        $xpath = $this->dom($html);

        $slug = \Illuminate\Support\Str::slug(self::ROOM_UNREVIEWED);

        $this->assertStringContainsString(
            'Sign-off blocked',
            $html,
            'The fixture is meant to have one unreviewed room, so the blocked banner must render — '
            . 'otherwise this test asserts a contract nobody reaches.',
        );

        $anchor = $xpath->query("//a[@href='#room-{$slug}']");
        $this->assertSame(
            1,
            $anchor->length,
            'The "Jump to first unreviewed room" link no longer points at #room-' . $slug . '.',
        );

        $panel = $xpath->query("//*[@id='room-{$slug}'][@data-tab]");
        $this->assertSame(
            1,
            $panel->length,
            'The banner anchor names #room-' . $slug . ' but no tab panel carries that id. The '
            . 'link would silently do nothing and the engineer is stranded at a sign-off they '
            . 'cannot complete.',
        );

        // And the resolver that makes it work is wired to hashchange, not only
        // to load — tapping the link on an already-loaded page fires hashchange
        // and nothing else.
        $source = $this->source();
        $this->assertStringContainsString(
            "addEventListener('hashchange'",
            $source,
            'Nothing listens for hashchange. Tapping the jump link on a page that is already '
            . 'loaded would do nothing at all.',
        );
    }

    // ── 8. TAB STATE SURVIVES A RELOAD (D-05) ────────────────────────────────

    public function test_tab_state_is_carried_by_replace_state_and_by_the_existing_session_record(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            'history.replaceState',
            $source,
            'Nothing writes the active tab into the URL, so a manual refresh loses it.',
        );
        $this->assertStringNotContainsString(
            'history.pushState',
            $source,
            'pushState makes the phone back button WALK the tabs instead of leaving the page — an '
            . 'engineer trying to go back taps nine times and never escapes.',
        );
        $this->assertStringContainsString(
            'activeTab:',
            $source,
            'The wsState_ record does not carry the active tab. The hash alone is dropped by the '
            . 'server redirect after every photo upload, so the bar would reset to room one.',
        );

        // ONE script owns it — folded into the existing restore IIFE, not added
        // as another one. One storage key, one stale guard, one restore order.
        $this->assertSame(
            1,
            substr_count($source, "var KEY = 'wsState_'"),
            'There is more than one wsState_ owner. Two restore paths on one key is two orderings '
            . 'and a coin-toss about which tab wins.',
        );
        $this->assertSame(
            1,
            substr_count($source, 'function activate(panel, opts)'),
            'Tab activation is defined more than once, so two code paths can disagree about which '
            . 'panel is showing.',
        );
    }

    // ── 9. THE PINNED COUNTS, RESTATED IN THE PLAN THAT REWRITES THE MARKUP ──

    public function test_the_page_still_has_exactly_one_unescaped_echo(): void
    {
        $source = $this->source();

        // Non-vacuity first: the needle must be findable before its count means
        // anything.
        $this->assertStringContainsString('{!!', $source);

        $this->assertSame(
            1,
            substr_count($source, '{!!'),
            'This page is unauthenticated input that a CLIENT signs. Exactly one raw echo is '
            . 'allowed and it is the pre-existing skip-restore attribute.',
        );
        $this->assertStringContainsString(
            '{!! $skipRestoreAttr !!}',
            $source,
            'The skip-restore raw echo was dropped or replaced by a helper. It had to MOVE onto '
            . 'the tab panel verbatim — without it, a room the engineer has just marked complete '
            . 'pulls them straight back into it on reload.',
        );

        // And it sits on the PANEL now, which is what makes its new meaning
        // ("do not auto-activate this tab") true.
        $this->assertMatchesRegularExpression(
            '/<section class="ws-tab-panel card.*?\{!! \$skipRestoreAttr !!\}/s',
            $source,
            'The skip-restore echo is no longer on the room tab panel, so the restore handler '
            . 'cannot see the flag it checks.',
        );
    }

    public function test_the_tabbed_rewrite_added_no_framework_directive(): void
    {
        $source = $this->source();

        foreach (self::ALPINE_OCCURRENCES as $needle => $expected) {
            if ($expected > 0) {
                $this->assertStringContainsString($needle, $source, "Pin needle {$needle} no longer matches anything.");
            }

            $this->assertSame(
                $expected,
                substr_count($source, $needle),
                "The occurrence count for \"{$needle}\" moved. This page loads no reactive "
                . 'framework, so a directive here is dead markup that looks like working code to '
                . 'the next reader.',
            );
        }
    }

    /**
     * All CSS for the tabbed layout lives in the page's own style block. The
     * three baseline-pinned files are the ones a tabbed layout most wants to
     * reach for, and a pinned hash is never refreshed to fit a diff.
     */
    public function test_the_tab_styles_live_in_the_pages_own_style_block(): void
    {
        $source = $this->source();

        $styleEnd = strpos($source, '</style>');
        $this->assertIsInt($styleEnd, 'The page has no style block at all.');

        foreach (['.ws-tab-bar', '.ws-tab-btn', '.ws-tab-panel', '.room-panel-head'] as $rule) {
            $at = strpos($source, $rule . ' ');
            $this->assertIsInt($at, "The rule {$rule} is not defined anywhere in the page.");
            $this->assertLessThan(
                $styleEnd,
                $at,
                "The rule {$rule} is defined outside the page's own style block. This view has no "
                . 'bundler and no layout; the alternative is one of the three sha256-pinned files.',
            );
        }

        // The bar is fixed, so without bottom padding the client's Sign & Submit
        // button sits under it and cannot be tapped — on the exact device this
        // page exists for.
        $this->assertStringContainsString(
            'env(safe-area-inset-bottom',
            substr($source, 0, $styleEnd),
            'The bar is not safe-area padded, so on any modern phone it sits under the home '
            . 'indicator and its buttons are unreachable.',
        );
        $this->assertMatchesRegularExpression(
            '/body \{ padding-bottom: calc\(/',
            $source,
            'Nothing reserves room for the fixed bar, so the last thing on the page — the client\'s '
            . 'Sign & Submit button — is underneath it and cannot be tapped.',
        );
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /** Quote a string safely for use as an XPath literal. */
    private function xpathLiteral(string $value): string
    {
        if (! str_contains($value, "'")) {
            return "'" . $value . "'";
        }

        return 'concat(' . implode(', "\'", ', array_map(
            fn (string $part) => "'" . $part . "'",
            explode("'", $value),
        )) . ')';
    }

    private function photoCountFromButton(\DOMElement $button): int
    {
        $title = $button->getAttribute('title');
        preg_match('/(\d+) photo/', $title, $m);

        return (int) ($m[1] ?? -1);
    }
}
