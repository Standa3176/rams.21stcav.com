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
 * Quick task 261011-hfb — Task A (sign-off placement) and Task C (serial
 * capture guidance) on the engineer link.
 *
 * ── WHAT THE USER SAW, AND WHAT WAS ACTUALLY TRUE ────────────────────────────
 *
 * The user read a live worksheet and reported the sign-off as "per room". It
 * never was. There has always been exactly ONE signature pad and it was never
 * inside a room panel — it was a SIBLING of the tab panels, so it rendered
 * below whichever tab happened to be showing. One signature for the whole
 * worksheet, drawn in a place that made it look like one signature per room.
 *
 * The user's choice, made with the risk stated: put it on the SITE TAB ONLY.
 * The risk is that an engineer who never opens Site never sees it, which is
 * what the always-visible pointer below (asserted here, outside every panel)
 * exists to answer.
 *
 * ── THE TRAP THIS FILE EXISTS FOR ────────────────────────────────────────────
 *
 * `resizeCanvas()` sizes the pad from `getBoundingClientRect()`. Inside a
 * HIDDEN tab panel that rect is 0x0, and the function's own `Math.max(1, ...)`
 * floors the backing store to ONE PIXEL SQUARE. The initial call runs on load,
 * once, and the page's `resize` listener is deliberately gated to genuine
 * WIDTH changes (260919-chb) — switching a tab is not one.
 *
 * So the moment the card moved into the Site panel, an engineer whose page
 * loads with a ROOM tab active (the server activates the first incomplete
 * room) got a dead pad: 1x1, un-drawable, and the client cannot sign off a
 * completed install. The fix is a reflow called from the tab switcher.
 *
 * ── THE STATE MATRIX ─────────────────────────────────────────────────────────
 *
 *  | State                                   | Covered | By                   |
 *  |-----------------------------------------|---------|----------------------|
 *  | card inside the site panel              | yes     | parsed DOM           |
 *  | card inside NO room panel               | yes     | parsed DOM, counted  |
 *  | exactly ONE signature pad               | yes     | parsed DOM, counted  |
 *  | a ROOM tab is the active one on load    | yes     | parsed DOM           |
 *  | capture lock still wraps the sign-off   | yes     | signed fixture       |
 *  | pointer to Site is OUTSIDE every panel  | yes     | parsed DOM           |
 *  | serial guidance on the page + in the    | yes     | by content           |
 *  |   kit drawer                            |         |                      |
 *  | reflow is wired from the tab switcher   | yes     | source contract      |
 *  | reflow bails on a hidden (0-width) pad  | yes     | source contract      |
 *  | the pad's REAL PIXEL SIZE after a       | NO      | browser fact. Driven |
 *  |   room -> Site switch                   |         | with puppeteer and   |
 *  |                                         |         | reported in SUMMARY  |
 *
 * The last row cannot be asserted in PHPUnit: there is no layout engine, so
 * every rect is 0x0 here and the assertion would pass on the broken code too.
 * It was measured in a real headless Chrome at 390x844 instead; the figures
 * are in the quick task's SUMMARY. Saying so is cheaper than letting a reader
 * believe this suite proved it.
 *
 * @see resources/views/worksheets/public-show.blade.php
 */
class WorksheetSignoffPlacementTest extends TestCase
{
    use RefreshDatabase;

    private const VIEW = 'resources/views/worksheets/public-show.blade.php';

    /** Has a survey and no review -> `unreviewed`, and it sorts first. */
    private const ROOM_BLOCKING = 'Board Room';

    private const ROOM_PLAIN = 'Comms Room';

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function worksheet(): Worksheet
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Signoff Placement Fixture',
            'client_name'  => 'Fixture Client',
            'status'       => 'completed',
        ]);
        SiteSurveyRoom::create([
            'site_survey_id'   => $survey->id,
            'room_name'        => self::ROOM_BLOCKING,
            'mounting_heights' => [['item' => 'Display', 'height_m' => '1.2']],
        ]);

        // Worksheet::create (not ->update) so boot::creating mints the token by
        // direct assignment — access_token is deliberately absent from
        // $fillable and is never mass-assigned.
        $worksheet = Worksheet::create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => 'Signoff Placement Fixture',
            'project_ref'    => '21CQ00000-01-OPS',
            'client_name'    => 'Fixture Client',
            'site_address'   => '1 Fixture Way, Reading RG1 1AA',
            'status'         => Worksheet::STATUS_FINAL,
            'generated_data' => [
                'rooms' => [
                    [
                        'name'      => self::ROOM_BLOCKING,
                        'equipment' => [
                            ['description' => 'Sony 85in display', 'make' => 'Sony', 'model' => 'FW-85BZ40L', 'quantity' => 1],
                        ],
                    ],
                    [
                        'name'      => self::ROOM_PLAIN,
                        'equipment' => [],
                    ],
                ],
            ],
        ]);

        $worksheet->photos()->create([
            'room_name'     => self::ROOM_PLAIN,
            'bucket'        => WorksheetPhoto::BUCKET_COMPLETION,
            'filename'      => 'worksheet-photos/' . $worksheet->id . '/fixture.jpg',
            'original_name' => 'capture.jpg',
            'mime_type'     => 'image/jpeg',
            'sort_order'    => 1,
        ]);

        return $worksheet->fresh();
    }

    private function sign(Worksheet $worksheet): void
    {
        $worksheet->signoffs()->create([
            'client_name'          => 'A Client',
            'signature_png_base64' => base64_encode('not-a-real-png'),
            'signed_with_comments' => false,
            'signed_at'            => now(),
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

    private function dom(string $html): \DOMXPath
    {
        $dom = new \DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html);
        libxml_clear_errors();

        return new \DOMXPath($dom);
    }

    // ── 0. NON-VACUITY, FIRST ────────────────────────────────────────────────

    public function test_the_fixture_renders_a_real_and_substantial_worksheet(): void
    {
        $html = $this->render($this->worksheet());

        $this->assertGreaterThan(
            50000,
            strlen($html),
            'The fixture page is too small to be the real worksheet — every count below would '
            . 'be counting an error page or an empty shell.',
        );
        $this->assertStringContainsString('Signoff Placement Fixture', $html);
        $this->assertStringContainsString(self::ROOM_BLOCKING, $html);
        $this->assertStringContainsString('Client Sign-Off', $html);
    }

    /**
     * The precondition the 1x1 trap needs. If the server activated SITE on
     * load, the pad would always initialise visible and every assertion about
     * the reflow would be theatre.
     */
    public function test_the_server_activates_a_room_tab_on_load_not_the_site_tab(): void
    {
        $xpath = $this->dom($this->render($this->worksheet()));

        $this->assertSame(
            0,
            $xpath->query('//section[@data-tab="site"][contains(@class, "is-active")]')->length,
            'The site panel is active on load, so the sign-off pad would never initialise hidden '
            . 'and this file would be proving nothing.',
        );
        $this->assertSame(
            1,
            $xpath->query('//section[@data-tab][@data-room-name][contains(@class, "is-active")]')->length,
            'Exactly one ROOM panel must be the active one on load.',
        );
    }

    // ── A. THE CARD IS ON THE SITE TAB, AND NOWHERE ELSE ─────────────────────

    public function test_the_signoff_card_renders_inside_the_site_tab_panel(): void
    {
        $xpath = $this->dom($this->render($this->worksheet()));

        $this->assertSame(
            1,
            $xpath->query('//section[@data-tab="site"]//form[@id="signoff-form"]')->length,
            'The Client Sign-Off form is not inside the site tab panel. The user asked for it '
            . 'there and only there (261011-hfb Task A).',
        );
        $this->assertSame(
            1,
            $xpath->query('//section[@data-tab="site"]//*[@class="card-title"][normalize-space()="Client Sign-Off"]')->length,
            'The card TITLE did not move with the form — half a card on the Site tab is worse '
            . 'than none.',
        );
    }

    public function test_the_signoff_card_renders_inside_no_room_panel(): void
    {
        $xpath = $this->dom($this->render($this->worksheet()));

        // Non-vacuity: there really are room panels to have failed this.
        $this->assertSame(
            2,
            $xpath->query('//section[@data-tab][@data-room-name]')->length,
            'The fixture lost its room panels, so the "not in a room" assertion below would '
            . 'pass by having nothing to look in.',
        );

        $this->assertSame(
            0,
            $xpath->query('//section[@data-tab][@data-room-name]//form[@id="signoff-form"]')->length,
            'A sign-off form is inside a room panel. There is ONE signature for the whole '
            . 'worksheet and a per-room pad would invite a second.',
        );
        $this->assertSame(
            0,
            $xpath->query('//section[@data-tab][@data-room-name]//canvas[@id="sig-pad"]')->length,
            'A signature pad is inside a room panel.',
        );
    }

    public function test_there_is_still_exactly_one_signature_pad_and_one_signoff_form(): void
    {
        $xpath = $this->dom($this->render($this->worksheet()));

        $this->assertSame(
            1,
            $xpath->query('//canvas[@id="sig-pad"]')->length,
            'The signature pad count moved. A duplicate id means the script wires the first and '
            . 'the client draws on the second.',
        );
        $this->assertSame(
            1,
            $xpath->query('//form[@id="signoff-form"]')->length,
            'The sign-off form count moved.',
        );
    }

    /**
     * The capture lock is untouched by the move. A signed worksheet's sign-off
     * form must still sit INSIDE the disabled fieldset — the fieldset opens
     * before the site panel and closes after it, and a card moved out of that
     * span would become re-submittable on a completed record.
     */
    public function test_a_signed_worksheet_still_has_the_signoff_inside_the_disabled_fieldset(): void
    {
        $worksheet = $this->worksheet();
        $this->sign($worksheet);

        $xpath = $this->dom($this->render($worksheet->fresh()));

        $this->assertSame(
            1,
            $xpath->query('//fieldset[@disabled]//form[@id="signoff-form"]')->length,
            'The sign-off form is no longer inside the capture-lock fieldset. A signed '
            . 'worksheet could be re-submitted from the page.',
        );
        $this->assertSame(
            1,
            $xpath->query('//fieldset[@disabled]//section[@data-tab="site"]//form[@id="signoff-form"]')->length,
            'The form is in the fieldset but not in the site panel, or the other way round.',
        );
    }

    /**
     * FINDABILITY — the stated risk of the user's own choice, answered.
     *
     * The pointer has to live OUTSIDE every tab panel, because a pointer that
     * only shows on the tab it points at is not a pointer.
     */
    public function test_an_always_visible_pointer_sends_the_engineer_to_the_site_tab_to_sign_off(): void
    {
        $html  = $this->render($this->worksheet());
        $xpath = $this->dom($html);

        $this->assertStringContainsString(
            'Sign-off is on the Site tab',
            $html,
            'Nothing on the page tells an engineer where the sign-off went.',
        );

        $this->assertSame(
            0,
            $xpath->query('//section[contains(@class, "ws-tab-panel")][contains(., "Sign-off is on the Site tab")]')->length,
            'The pointer to the Site tab is itself inside a tab panel, so it is hidden exactly '
            . 'when it is needed.',
        );
    }

    // ── C. SERIAL CAPTURE GUIDANCE ───────────────────────────────────────────

    /**
     * Task C is GUIDANCE ONLY. The capture is not missing — 45 "Box Serial
     * Label" buttons were counted on the live worksheet, 3 of 4 kit rows on
     * Board Room (the mount correctly excluded). It is simply inside the
     * collapsed Kit List drawer, per hardware row. The user asked for "clear
     * instruction for engineer to remind them to take serials and where the
     * section is". A count, a typed serial field and a new capture control were
     * all offered and declined, so none of them appears here.
     */
    public function test_the_page_tells_the_engineer_to_capture_serials_and_where(): void
    {
        $html  = $this->render($this->worksheet());
        $xpath = $this->dom($html);

        $this->assertStringContainsString(
            'Serial numbers are required',
            $html,
            'The page never tells the engineer that serial capture is expected.',
        );
        $this->assertStringContainsString(
            'Kit List',
            $html,
            'The guidance never names the section the capture buttons live in.',
        );
        $this->assertStringContainsString(
            'Box Serial Label',
            $html,
            'The guidance never names the button the engineer is looking for.',
        );

        // Outside every panel, same reasoning as the sign-off pointer: an
        // engineer reads this once, on whatever tab they happen to be on.
        $this->assertSame(
            0,
            $xpath->query('//section[contains(@class, "ws-tab-panel")][contains(., "Serial numbers are required")]')->length,
            'The serial guidance is inside a tab panel, so it is invisible on every other tab.',
        );
    }

    public function test_the_kit_drawer_itself_repeats_where_the_serial_button_is(): void
    {
        $html = $this->render($this->worksheet());

        $this->assertStringContainsString(
            'Tap 📷 Box Serial Label on each hardware line',
            $html,
            'The Kit List drawer gives no instruction at the point of capture — the guidance at '
            . 'the top of the page is read once and forgotten by the time the drawer is open.',
        );
    }

    public function test_task_c_adds_no_new_capture_control_and_no_typed_serial_field(): void
    {
        $source = $this->source();

        foreach (['name="serial_number"', 'id="serial_number"', 'name="serial"'] as $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $source,
                "Task C is guidance only. A typed serial field ({$needle}) was explicitly "
                . 'offered to the user and declined.',
            );
        }
    }

    // ── THE 1x1 TRAP — SOURCE CONTRACT ───────────────────────────────────────

    /**
     * PHPUnit cannot measure a canvas. What it CAN pin is that the wiring which
     * makes the browser measurement come out right is present, is called from
     * the one chokepoint every tab switch goes through, and refuses to run
     * while the pad is hidden.
     */
    public function test_the_tab_switcher_reflows_the_signature_pad(): void
    {
        $source = $this->source();

        $this->assertSame(
            1,
            substr_count($source, 'window.__resizeSignaturePad = reflowCanvas;'),
            'The pad reflow is not exposed to the tab switcher. Switching from a room to Site '
            . 'would leave the pad at the 1x1 size it initialised to while hidden, and the '
            . 'client could not sign.',
        );

        $this->assertSame(
            1,
            substr_count($source, 'window.__resizeSignaturePad && window.__resizeSignaturePad();'),
            'Nothing calls the reflow. It must run from activate() — the ONE function every '
            . 'route into a tab goes through: a bar tap, a hashchange, and the session restore '
            . 'that survives the post-upload redirect.',
        );

        // The call site is inside activate(), not merely somewhere in the file.
        $activate = strpos($source, 'function activate(panel, opts) {');
        $this->assertIsInt($activate, 'activate() is gone — the reflow has no chokepoint to hang off.');
        $activeEnd = strpos($source, 'function activeKey()', $activate);
        $this->assertIsInt($activeEnd);
        $this->assertStringContainsString(
            'window.__resizeSignaturePad',
            substr($source, $activate, $activeEnd - $activate),
            'The reflow call is in the file but not inside activate(), so some route into the '
            . 'Site tab does not reflow the pad.',
        );
    }

    /**
     * THE OTHER HALF, AND THE ONE THAT WOULD HAVE DESTROYED A SIGNATURE.
     *
     * activate() fires for ROOM tabs too. A reflow that ran unconditionally
     * would measure the now-hidden pad at 0 width, floor it to 1x1 and write
     * that back — wiping a signature the client had already drawn, and
     * snapshotting the 1x1 as the "restore". So the reflow must bail while the
     * pad has no width.
     */
    public function test_the_reflow_refuses_to_run_while_the_pad_is_hidden(): void
    {
        $source = $this->source();

        $start = strpos($source, 'function reflowCanvas() {');
        $this->assertIsInt($start, 'reflowCanvas() is gone.');
        $end = strpos($source, "\n            }", $start);
        $this->assertIsInt($end);
        $body = substr($source, $start, $end - $start);

        $this->assertStringContainsString(
            'if (newWidth <= 0) return;',
            $body,
            'The reflow does not bail on a zero-width (hidden) pad. Switching AWAY from Site '
            . 'would then re-size the pad to 1x1 and destroy a signature already drawn.',
        );
        $this->assertStringContainsString(
            'widthChanged',
            $body,
            'The 260919-chb width gate is gone from the reflow — height-only mobile resize '
            . 'events (keyboard, URL bar) would wipe a drawn signature again.',
        );
    }

    // ── THE PINS, RE-ASSERTED BECAUSE THIS TASK EDITS THE PAGE ───────────────

    /**
     * A client signs this page. Every echo stays escaped and no second raw echo
     * is added. These needles match COMMENTS as well as attributes, which is
     * why nothing this task wrote names a directive in prose.
     */
    public function test_the_pinned_occurrence_counts_are_unmoved(): void
    {
        $source = $this->source();

        $expected = [
            ' x-data'           => 1,
            ' x-show'           => 1,
            ' x-model'          => 2,
            ' x-cloak'          => 1,
            '@click'            => 0,
            '{!!'               => 1,
            '<x-photo-lightbox' => 0,
        ];

        foreach ($expected as $needle => $count) {
            $this->assertSame(
                $count,
                substr_count($source, $needle),
                "The pinned occurrence count for '{$needle}' moved.",
            );
        }

        $this->assertStringContainsString('{!! $skipRestoreAttr !!}', $source, 'The one permitted raw echo is gone.');
    }
}
