<?php

namespace Tests\Feature\Worksheets;

use App\Models\Device;
use App\Models\DeviceLabelPhoto;
use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\SiteSurvey;
use App\Models\SiteSurveyRoom;
use App\Models\User;
use App\Models\Worksheet;
use App\Models\WorksheetAdditionalKit;
use App\Models\WorksheetPhoto;
use App\Support\Worksheets\WorksheetCaptureLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Phase 46.7 Plan 04 Task 1 — A WHOLE VISIT, IN ORDER, ON THE TABBED PAGE.
 *
 * ── WHY THIS FILE EXISTS ─────────────────────────────────────────────────────
 *
 * Plans 02 and 03 proved that every state RENDERS. A state matrix does not
 * prove the states COMPOSE, and every defect the user has found in the last two
 * weeks was a green suite asserting one state. This file asserts the SEQUENCE:
 * open, read the site tab, type a note, mark the survey reviewed, upload a
 * photo, mark the room complete, add a kit row, sign, and be refused.
 *
 * ── THE ORDER IS STRUCTURAL, NOT INCIDENTAL, AND HERE IS WHICH AND WHY ───────
 *
 * The nine steps live in ONE test method, top to bottom, sharing one worksheet
 * and one `$xpath` variable that is REASSIGNED at each re-render. That is the
 * deliberate choice over nine ordered `@depends` methods:
 *
 *   · a reader cannot reorder steps by accident — moving step 6 above step 4
 *     moves the statements that produce the state step 6 asserts, so the test
 *     goes red immediately rather than silently asserting a different page
 *   · PHPUnit gives no ordering guarantee this file could rely on without
 *     `@depends` plumbing that passes a Worksheet between methods — and a
 *     worksheet handed between methods is a worksheet whose `RefreshDatabase`
 *     transaction has already rolled back
 *   · the note typed in step 3 has to be asserted present again at steps 4, 5,
 *     6 and 8. That is one variable read five times in one scope, not five
 *     methods re-deriving it
 *
 * The cost is one long method. It is annotated step by step and every assertion
 * message names the step an engineer was on and what they would have lost.
 *
 * ── WHAT THIS FILE DOES NOT PROVE ────────────────────────────────────────────
 *
 * It drives the ROUTE TABLE with real HTTP calls — never a controller call, so
 * a wrong route constraint or a wrong throttle key is a real failure here. But
 * it is PHP against a rendered body. It does NOT prove:
 *
 *   · the offline half of autosave (airplane mode, a real reload, a real
 *     `online` event) — waves 1 and 3 proved that in node against stubs, which
 *     is strictly less than a phone
 *   · the bar reachable one-handed, its glyphs legible, or the tab surviving a
 *     REAL reload
 *
 * Those are steps 1, 2 and 4 of plan 46.7-04's blocking human checkpoint, and
 * nothing in this file may be read as evidence for any of them.
 *
 * @see app/Http/Controllers/PublicWorksheetController.php
 * @see resources/views/worksheets/public-show.blade.php
 * @see tests/Feature/Worksheets/EngineerLinkInstallCaptureEndToEndTest.php
 * @see .planning/phases/46.7-engineer-link-tabbed-layout/46.7-CONTEXT.md (D-01..D-07)
 */
class EngineerLinkTabbedWalkthroughTest extends TestCase
{
    use RefreshDatabase;

    /** Three rooms. Room 2 is the one the walk works in, so rooms 1 and 3 are the control. */
    private const ROOM_ONE = 'Boardroom';

    private const ROOM_TWO = 'Comms Room';

    private const ROOM_THREE = 'Store Room';

    private const PROJECT_NAME = 'Tabbed Walkthrough Visit';

    private const PROJECT_REF = '21CQ40404-01-OPS';

    private const CLIENT_NAME = 'Walkthrough Client';

    private const SITE_ADDRESS = '9 Walkthrough Way, Reading RG1 9ZZ';

    private const SITE_CONTACT_NAME = 'Dawn Fielding';

    private const SITE_CONTACT_PHONE = '07700 900444';

    /**
     * The note typed in step 3 and asserted still present at steps 4, 5, 6 and 8.
     *
     * It carries a bare tag AND an ampersand on purpose. The tag catches a
     * missing escape on a page a CLIENT signs; the ampersand catches a double
     * escape or an escape applied in the wrong order, which a tag alone does
     * not. Both halves also make the "still there" assertions non-trivial —
     * a truncating or re-encoding round trip fails them.
     */
    private const ROOM_TWO_NOTE = 'Backbox is 40mm short & the <script>alert(1)</script> label is missing';

    /** A name no room has. Aimed at every room-scoped endpoint in turn. */
    private const FORGED_ROOM = 'Server Room That Does Not Exist';

    /** Non-vacuity fuel for step 8's metadata sweep — a value that MUST NOT render. */
    private const LABEL_CAPTURED_BY = 'ip:10.9.9.9|actor:feedfacedead';

    /** Non-vacuity fuel for step 8's metadata sweep — the signing browser's own UA. */
    private const SIGNING_USER_AGENT = 'WalkthroughPhone/1.0 (Nokia; Android 14) NotToBeRendered';

    /**
     * The IP the SIGNATURE is posted from, deliberately not 127.0.0.1.
     *
     * `worksheet_signoffs.ip_address` is the column T-46.7-04-01 forbids
     * rendering. The room-complete and survey-reviewed stamps written earlier in
     * this walk ALREADY carry the request IP and ARE rendered (F-46.7-04-01), so
     * sweeping for 127.0.0.1 cannot distinguish the two. A distinct signing IP
     * can: it exists in exactly one place, the signoff row.
     *
     * TEST-NET-1 (RFC 5737), so it can never be a real address.
     */
    private const SIGNING_IP = '203.0.113.77';

    protected function setUp(): void
    {
        parent::setUp();

        // The public write routes carry per-token throttles and this walk drives
        // five of them. Disabling ONLY the throttler keeps every guard under
        // test genuinely exercised instead of turning a 422 assertion into a 429
        // that says nothing about the guard.
        $this->withoutMiddleware(ThrottleRequests::class);

        Storage::fake('local');
        Storage::fake('public');
        Mail::fake();
    }

    // ── Fixture ──────────────────────────────────────────────────────────────

    /**
     * Three rooms, none complete, and a survey covering ROOM TWO ONLY.
     *
     * The survey has to cover exactly one room. If it covered all three, every
     * room would open `unreviewed` and step 4's "rooms 1 and 3 do not move"
     * assertion would be asserting nothing.
     */
    private function walkWorksheet(): Worksheet
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        // D-02's site-contact line reads project.latestPackage.extracted_data.
        ProjectPackage::create([
            'project_id'     => $project->id,
            'user_id'        => $user->id,
            'quote_filename' => 'walkthrough.pdf',
            'quote_path'     => 'fixtures/walkthrough.pdf',
            'extracted_data' => [
                'ship_contact' => self::SITE_CONTACT_NAME,
                'ship_phone'   => self::SITE_CONTACT_PHONE,
            ],
            'status'         => ProjectPackage::STATUS_EXTRACTED,
        ]);

        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => self::PROJECT_NAME,
            'client_name'  => self::CLIENT_NAME,
            'status'       => 'completed',
        ]);
        SiteSurveyRoom::create([
            'site_survey_id'   => $survey->id,
            'room_name'        => self::ROOM_TWO,
            'mounting_heights' => [['item' => 'Rack', 'height_m' => '0.4']],
        ]);

        // Worksheet::create (not ->update) so boot::creating mints access_token
        // by direct assignment — access_token is deliberately absent from
        // $fillable and must never be mass-assigned.
        return Worksheet::create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => self::PROJECT_NAME,
            'project_ref'    => self::PROJECT_REF,
            'client_name'    => self::CLIENT_NAME,
            'site_address'   => self::SITE_ADDRESS,
            'status'         => Worksheet::STATUS_FINAL,
            'generated_data' => [
                'rooms' => array_map(
                    fn (string $name) => ['name' => $name, 'equipment' => []],
                    [self::ROOM_ONE, self::ROOM_TWO, self::ROOM_THREE],
                ),
            ],
        ]);
    }

    // ── Render + DOM helpers — parsed, never grepped ──────────────────────────

    private function open(Worksheet $worksheet): TestResponse
    {
        return $this->get(route('public-worksheet.show', ['token' => $worksheet->access_token]))->assertOk();
    }

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

    /**
     * Every room button in the sticky bar, keyed by its tab key, carrying the
     * SERVER-EMITTED status and the photo count it displays.
     *
     * @return array<string,array{status:string,count:int,name:string}>
     */
    private function barRooms(\DOMXPath $xpath): array
    {
        $out = [];

        foreach ($xpath->query("//nav[@data-ws-tab-bar]/button[@data-room-status]") as $btn) {
            /** @var \DOMElement $btn */
            $countNode = null;
            foreach ($btn->getElementsByTagName('span') as $span) {
                /** @var \DOMElement $span */
                if ($span->getAttribute('class') === 'ws-tab-btn__count') {
                    $countNode = $span;
                }
            }

            $out[$btn->getAttribute('data-tab-target')] = [
                'status' => $btn->getAttribute('data-room-status'),
                'count'  => (int) preg_replace('/\D/', '', $countNode?->textContent ?? '0'),
                'name'   => $btn->getAttribute('title'),
            ];
        }

        return $out;
    }

    /** The whole bar, site button included — the count the bijection is asserted against. */
    private function barButtonCount(\DOMXPath $xpath): int
    {
        return $xpath->query("//nav[@data-ws-tab-bar]/button")->length;
    }

    /** The text currently sitting in one room's notes textarea, decoded. */
    private function notesFieldValue(\DOMXPath $xpath, string $room): ?string
    {
        $nodes = $xpath->query(sprintf(
            "//textarea[@data-room-notes][@data-room-name=%s]",
            $this->xpathLiteral($room),
        ));

        return $nodes->length === 1 ? $nodes->item(0)->textContent : null;
    }

    /** The `N of M rooms marked complete` chip on the site tab. */
    private function siteChip(\DOMXPath $xpath): string
    {
        $nodes = $xpath->query("//section[@data-tab='site']//span[@class='ws-tab-summary']");

        return $nodes->length === 1 ? trim($nodes->item(0)->textContent) : '';
    }

    private function roomPanel(\DOMXPath $xpath, string $tabKey): string
    {
        return $this->subtree($xpath, sprintf("//section[@data-tab='%s']", $tabKey));
    }

    /** Room names carry spaces and could one day carry a quote. Never interpolate raw. */
    private function xpathLiteral(string $value): string
    {
        return ! str_contains($value, "'")
            ? "'" . $value . "'"
            : 'concat("' . str_replace('"', '", \'"\', "', $value) . '")';
    }

    /**
     * Assert a forged-room-name attempt was REFUSED, with a sentence a reader
     * can act on.
     *
     * ⚠️ NOT `assertStatus(422)`. Laravel's status assertion takes no message,
     * so its failure reads "Expected response status code [422] but received
     * 200" and says nothing about what a 200 would MEAN. Waves 1 and 3 both paid
     * for an assertion that reported a mechanism instead of a consequence; this
     * is the same lesson in a different shape.
     */
    private function assertForgedNameRefused(TestResponse $response, string $endpoint): void
    {
        $this->assertSame(
            422,
            $response->status(),
            "A forged room name was ACCEPTED by {$endpoint}. Anyone holding the link can "
            . 'write against rooms this worksheet does not have — growing the shared JSON '
            . 'column without limit and putting text in front of the office that it will '
            . 'read as an engineer\'s finding about a real room.',
        );
    }

    /** The whole shared JSON column, encoded, so "byte-identical" means what it says. */
    private function confirmationsBlob(Worksheet $worksheet): string
    {
        return (string) json_encode(
            (array) ($worksheet->fresh()->pre_install_confirmations ?? []),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );
    }

    // ── Non-vacuity gate — FIRST TEST IN THE FILE ────────────────────────────

    /**
     * Every count in the walk below is a count taken off a rendered body. If
     * that body were an error page, an empty shell or a redirect, "0 textareas"
     * and "3 room buttons" would both be satisfiable by nothing at all. So the
     * page is measured as a real page FIRST, before anything is counted on it.
     */
    public function test_the_opening_render_is_a_real_page_before_anything_is_counted_on_it(): void
    {
        $worksheet = $this->walkWorksheet();
        $body      = $this->open($worksheet)->getContent();

        $this->assertGreaterThan(
            50000,
            strlen((string) $body),
            'The engineer link rendered under 50,000 characters. Every count in this file '
            . 'would then be counting an error page or an empty shell, and a "0 capture '
            . 'controls after sign-off" assertion would pass on a blank screen.',
        );

        $this->assertStringContainsString(
            self::PROJECT_NAME,
            (string) $body,
            'The rendered page does not name the project. Whatever was measured, it was '
            . 'not this worksheet.',
        );
    }

    // ── THE WALK ─────────────────────────────────────────────────────────────

    public function test_a_whole_visit_walks_in_order_on_the_tabbed_page(): void
    {
        $worksheet = $this->walkWorksheet();
        $token     = $worksheet->access_token;

        // ── STEP 1. OPEN THE LINK ────────────────────────────────────────────
        // One active panel, and it is the first INCOMPLETE room — not the site
        // tab, because rooms are outstanding. An engineer who opens the link and
        // lands on a page of site details has to tap before they can work.

        $xpath = $this->dom($this->open($worksheet)->getContent());

        $active = $xpath->query("//section[contains(@class,'ws-tab-panel')][contains(@class,'is-active')]");

        $this->assertSame(
            1,
            $active->length,
            'STEP 1 — opening the link did not activate exactly one panel. With none the '
            . 'engineer sees an empty screen; with two they see two rooms stacked and the '
            . 'bar lies about where they are.',
        );
        $this->assertSame(
            'room-1',
            $active->item(0)->attributes->getNamedItem('data-tab')->nodeValue,
            'STEP 1 — the link opened on the wrong tab. Every room is outstanding, so the '
            . 'first incomplete room must be active; opening on the site tab costs a tap '
            . 'before any work can start.',
        );

        $this->assertSame(
            4,
            $this->barButtonCount($xpath),
            'STEP 1 — the sticky bar is not 1 site button plus 3 room buttons. A room with '
            . 'no button is a room the engineer cannot reach.',
        );

        // The status of each button against that room's REAL state. Nothing has
        // happened yet, so: room 2 is unreviewed (the survey covers it and it has
        // not been reviewed) and rooms 1 and 3 are todo.
        $this->assertSame(
            ['room-1' => 'todo', 'room-2' => 'unreviewed', 'room-3' => 'todo'],
            array_map(fn (array $b) => $b['status'], $this->barRooms($xpath)),
            'STEP 1 — a bar button disagrees with its room\'s real state on first open. '
            . 'An engineer reads this bar and leaves site on it.',
        );

        // ── STEP 2. READ THE SITE TAB ────────────────────────────────────────

        $sitePanel = $this->subtree($xpath, "//section[@data-tab='site']");

        foreach ([
            'the client name'       => self::CLIENT_NAME,
            'the project ref'      => self::PROJECT_REF,
            'the site address'     => self::SITE_ADDRESS,
            'the site contact name'  => self::SITE_CONTACT_NAME,
            'the site contact phone' => self::SITE_CONTACT_PHONE,
        ] as $what => $needle) {
            $this->assertStringContainsString(
                $needle,
                $sitePanel,
                "STEP 2 — {$what} is not on the site tab. D-02 moved these four facts out of "
                . 'the header INTO this tab; if they are in neither place an engineer cannot '
                . 'tell whose site they are standing on or who to ring at the gate.',
            );
        }

        $this->assertSame(
            '0 of 3 rooms marked complete',
            $this->siteChip($xpath),
            'STEP 2 — the site tab\'s progress chip is wrong on first open. It is the only '
            . 'place on the page that says how much of the visit is left.',
        );

        // ── STEP 3. TYPE A NOTE IN ROOM 2 ────────────────────────────────────

        $before = $this->confirmationsBlob($worksheet);

        $this->postJson(
            route('public-worksheet.room-notes', ['token' => $token, 'roomName' => self::ROOM_TWO]),
            ['notes' => self::ROOM_TWO_NOTE],
        )->assertOk()->assertJsonPath('ok', true);

        $xpath = $this->dom($this->open($worksheet)->getContent());

        $this->assertSame(
            self::ROOM_TWO_NOTE,
            $this->notesFieldValue($xpath, self::ROOM_TWO),
            'STEP 3 — the note the engineer typed did not come back in room 2\'s box. They '
            . 'would retype it, or worse, assume the office already has it.',
        );

        // A note leaking across rooms is the defect a single-state assertion
        // misses entirely: assert the two rooms nobody typed in are EMPTY.
        foreach ([self::ROOM_ONE, self::ROOM_THREE] as $otherRoom) {
            $this->assertSame(
                '',
                $this->notesFieldValue($xpath, $otherRoom),
                "STEP 3 — room 2's note has leaked into {$otherRoom}'s box. The office would "
                . 'read a fault against a room that does not have it, and the engineer would '
                . 'see their own words in a room they never opened.',
            );
        }

        // Neither sibling namespace moved. A notes write that silently sets a
        // room complete, or un-reviews one, produces no error and no failed
        // request — just a page that is quietly wrong.
        $afterNote = (array) ($worksheet->fresh()->pre_install_confirmations ?? []);
        $this->assertArrayNotHasKey(
            'survey_review',
            $afterNote,
            'STEP 3 — typing a note wrote into the survey_review namespace. Sign-off would '
            . 'start blocking, or stop blocking, for a reason nobody can see.',
        );
        $this->assertArrayNotHasKey(
            'room_complete',
            $afterNote,
            'STEP 3 — typing a note marked a room complete. The engineer would leave site on '
            . 'a room they never finished.',
        );

        $this->assertSame(
            ['room-1' => 'todo', 'room-2' => 'unreviewed', 'room-3' => 'todo'],
            array_map(fn (array $b) => $b['status'], $this->barRooms($xpath)),
            'STEP 3 — typing a note moved a bar status. A note is not a status; nothing in '
            . 'the bar may move because somebody typed.',
        );

        // A FORGED room name at the notes endpoint: refused, nothing written.
        $blobBeforeForge = $this->confirmationsBlob($worksheet);

        $this->assertForgedNameRefused($this->postJson(
            route('public-worksheet.room-notes', ['token' => $token, 'roomName' => self::FORGED_ROOM]),
            ['notes' => 'A note against a room this worksheet does not have'],
        ), 'saveRoomNotes');

        $this->assertSame(
            $blobBeforeForge,
            $this->confirmationsBlob($worksheet),
            'STEP 3 — a forged room name minted a key in the shared JSON column. Anyone with '
            . 'the link could grow that column without limit and write text the office reads '
            . 'as an engineer\'s finding.',
        );
        $this->assertNotSame(
            $before,
            $blobBeforeForge,
            'STEP 3 — the column did not change when the REAL note was saved, so the forged-name '
            . 'assertion above is comparing nothing to nothing.',
        );

        // ── STEP 4. MARK ROOM 2's SURVEY REVIEWED ────────────────────────────
        // A FULL-PAGE POST with a redirect. This is the step that would lose the
        // note if the page rebuilt the field from anything but the column.

        $this->post(
            route('public-worksheet.survey-reviewed', ['token' => $token, 'roomName' => self::ROOM_TWO]),
        )->assertRedirect(route('public-worksheet.show', ['token' => $token]));

        $xpath = $this->dom($this->open($worksheet)->getContent());
        $bar   = $this->barRooms($xpath);

        $this->assertNotSame(
            'unreviewed',
            $bar['room-2']['status'],
            'STEP 4 — room 2 still reads unreviewed after its survey was reviewed. The bar '
            . 'would keep telling the engineer that sign-off is blocked when it is not.',
        );
        $this->assertSame(
            'todo',
            $bar['room-2']['status'],
            'STEP 4 — room 2 landed on the wrong status after review. It has no photos and is '
            . 'not complete, so it is todo; anything else misreports how much work is left.',
        );
        $this->assertSame(
            ['room-1' => 'todo', 'room-3' => 'todo'],
            ['room-1' => $bar['room-1']['status'], 'room-3' => $bar['room-3']['status']],
            'STEP 4 — reviewing room 2\'s survey moved another room\'s status. One tap must '
            . 'change one room.',
        );

        // ⚠️ THE ASSERTION THIS WHOLE FILE EXISTS FOR, PART 1 OF 4.
        $this->assertSame(
            self::ROOM_TWO_NOTE,
            $this->notesFieldValue($xpath, self::ROOM_TWO),
            'STEP 4 — the note typed in STEP 3 is gone after the survey-reviewed redirect. '
            . 'An engineer types what they found, taps Mark Reviewed, and the words vanish '
            . 'off the screen with no error. This is the silent loss D-04 forbids.',
        );

        // A FORGED room name at the survey-reviewed endpoint.
        $blobBeforeForge = $this->confirmationsBlob($worksheet);

        $this->assertForgedNameRefused($this->post(
            route('public-worksheet.survey-reviewed', ['token' => $token, 'roomName' => self::FORGED_ROOM]),
        ), 'markSurveyReviewed');

        $this->assertSame(
            $blobBeforeForge,
            $this->confirmationsBlob($worksheet),
            'STEP 4 — a forged room name was recorded as reviewed. A room that does not exist '
            . 'cannot have been cross-checked against a survey.',
        );

        // ── STEP 5. UPLOAD A PHOTO TO ROOM 2 ─────────────────────────────────

        $countsBefore = array_map(fn (array $b) => $b['count'], $this->barRooms($xpath));

        $this->postJson(
            route('public-worksheet.photos.upload', ['token' => $token]),
            [
                'room_name' => self::ROOM_TWO,
                'photo'     => UploadedFile::fake()->image('rack-before.jpg'),
                'caption'   => 'Rack bay as found',
                'bucket'    => WorksheetPhoto::BUCKET_START,
            ],
        )->assertOk();

        $xpath = $this->dom($this->open($worksheet)->getContent());
        $bar   = $this->barRooms($xpath);

        $this->assertSame(
            'in-progress',
            $bar['room-2']['status'],
            'STEP 5 — a room with a photo and no completion mark is in-progress. The bar '
            . 'showing it as untouched hides work that has already been done.',
        );
        $this->assertSame(
            $countsBefore['room-2'] + 1,
            $bar['room-2']['count'],
            'STEP 5 — room 2\'s photo count did not move by exactly 1. The Mark Room Complete '
            . 'soft gate reads this number.',
        );
        $this->assertSame(
            ['room-1' => $countsBefore['room-1'], 'room-3' => $countsBefore['room-3']],
            ['room-1' => $bar['room-1']['count'], 'room-3' => $bar['room-3']['count']],
            'STEP 5 — uploading to room 2 changed another room\'s photo count. A photo filed '
            . 'against the wrong room is evidence attached to the wrong job.',
        );

        // ⚠️ PART 2 OF 4 — a photo upload is a full-page reload.
        $this->assertSame(
            self::ROOM_TWO_NOTE,
            $this->notesFieldValue($xpath, self::ROOM_TWO),
            'STEP 5 — the note typed in STEP 3 is gone after the photo upload reload. The '
            . 'engineer took a photo and lost the sentence describing what it shows.',
        );

        // ⚠️ THE KNOWN PRE-EXISTING GAP, RECORDED HONESTLY AND DELIBERATELY
        // NOT FIXED HERE.
        //
        // `uploadPhoto` has NO room-name inclusion guard. Its two room-scoped
        // siblings have one and 46.7-03's new `saveRoomNotes` COPIED the guard
        // rather than the omission. 46.7-CONTEXT.md defers the fix by name
        // ("Fixing uploadPhoto's missing room-name guard"), and plan 46.7-04
        // writes NO production code — so the fix belongs to whichever plan owns
        // that controller method next, not to this file.
        //
        // What is asserted below is the endpoint's CURRENT behaviour, so that
        // this file is an honest record of the gap rather than silent about it:
        // a forged room name is ACCEPTED, a photo row IS written, and the photo
        // is then unreachable — no bar button, no panel, no tray. It is storage
        // and disk consumed by a name nobody can navigate to.
        $forgedUpload = $this->postJson(
            route('public-worksheet.photos.upload', ['token' => $token]),
            [
                'room_name' => self::FORGED_ROOM,
                'photo'     => UploadedFile::fake()->image('orphan.jpg'),
                'bucket'    => WorksheetPhoto::BUCKET_COMPLETION,
            ],
        );

        $forgedUpload->assertOk();

        $this->assertSame(
            1,
            $worksheet->photos()->where('room_name', self::FORGED_ROOM)->count(),
            'STEP 5 — uploadPhoto\'s missing room-name guard has CHANGED behaviour. If it now '
            . 'refuses a forged name that is an improvement, not a regression: delete this '
            . 'assertion and the DEFERRED note above it, and record the fix in the plan that '
            . 'made it. Do not weaken the assertion to keep it green.',
        );

        $xpath = $this->dom($this->open($worksheet)->getContent());

        $this->assertSame(
            4,
            $this->barButtonCount($xpath),
            'STEP 5 — the orphan photo grew the sticky bar. A forged room must not become a '
            . 'tab; the gap costs disk, it must not cost navigation.',
        );

        // ── STEP 6. MARK ROOM 2 COMPLETE ─────────────────────────────────────

        $this->post(
            route('public-worksheet.room-complete', ['token' => $token, 'roomName' => self::ROOM_TWO]),
        )->assertRedirect(route('public-worksheet.show', ['token' => $token]));

        $xpath = $this->dom($this->open($worksheet)->getContent());

        $this->assertSame(
            'complete',
            $this->barRooms($xpath)['room-2']['status'],
            'STEP 6 — room 2 does not read complete in the bar after being marked complete. '
            . 'The bar showing which rooms are done IS the point of this phase.',
        );

        $this->assertSame(
            '1 of 3 rooms marked complete',
            $this->siteChip($xpath),
            'STEP 6 — the site tab\'s progress chip did not move to 1 of 3. It is the only '
            . 'number on the page that says how much of the visit is left.',
        );

        $this->assertSame(
            1,
            $xpath->query("//section[@data-tab='room-2'][@data-skip-restore='1']")->length,
            'STEP 6 — room 2\'s panel is missing the skip-restore attribute after being marked '
            . 'complete. The next reload would pull the engineer straight back into the room '
            . 'they have just finished instead of the one they have not.',
        );

        // ⚠️ PART 3 OF 4 — a third redirect.
        $this->assertSame(
            self::ROOM_TWO_NOTE,
            $this->notesFieldValue($xpath, self::ROOM_TWO),
            'STEP 6 — the note typed in STEP 3 is gone after the mark-complete redirect. Three '
            . 'redirects in and the engineer\'s only written record of the room has been lost '
            . 'without a single error message.',
        );

        // A FORGED room name at the room-complete endpoint.
        $blobBeforeForge = $this->confirmationsBlob($worksheet);

        $this->assertForgedNameRefused($this->post(
            route('public-worksheet.room-complete', ['token' => $token, 'roomName' => self::FORGED_ROOM]),
        ), 'markRoomComplete');

        $this->assertSame(
            $blobBeforeForge,
            $this->confirmationsBlob($worksheet),
            'STEP 6 — a forged room name was marked complete. The site tab\'s N-of-M count '
            . 'would then be measured against rooms that do not exist.',
        );

        // ── STEP 7. ADD A KIT ROW TO ROOM 2 ──────────────────────────────────
        // Untouched by this phase. This step exists to catch the regression of
        // it having stopped working now that it lives inside a tab panel.

        $this->postJson(
            route('public-worksheet.additional-kit.add', ['token' => $token]),
            [
                'room_name'          => self::ROOM_TWO,
                'labour_resource_id' => null,
                'qty'                => 2,
                'part_description'   => 'Cage nut pack M6',
            ],
        )->assertStatus(201);

        $xpath = $this->dom($this->open($worksheet)->getContent());

        $this->assertStringContainsString(
            'Cage nut pack M6',
            $this->roomPanel($xpath, 'room-2'),
            'STEP 7 — a kit row added to room 2 does not render inside room 2\'s tab panel. '
            . 'Moving the rooms into tabs has broken the additional-kit list, and the office '
            . 'never learns what was actually used on site.',
        );

        foreach (['room-1', 'room-3'] as $otherTab) {
            $this->assertStringNotContainsString(
                'Cage nut pack M6',
                $this->roomPanel($xpath, $otherTab),
                "STEP 7 — room 2's kit row is also rendering in {$otherTab}. The office would "
                . 'bill the same part against every room on the job.',
            );
        }

        // ── STEP 8. SIGN OFF ─────────────────────────────────────────────────
        // Non-vacuity fuel for the metadata sweep: a label photo carrying a real
        // captured_by stamp, and a signature carrying a distinctive user agent.
        // A sweep with nothing to find is a sweep that proves nothing.

        $device = Device::create([
            'project_id'    => $worksheet->project_id,
            'room_name'     => self::ROOM_TWO,
            'description'   => 'Cisco codec',
            'serial_number' => 'SN-WALK-0001',
        ]);

        Storage::disk('public')->put('device-label-photos/walkthrough.jpg', 'jpeg-bytes');

        DeviceLabelPhoto::create([
            'project_id'   => $worksheet->project_id,
            'device_id'    => $device->id,
            'worksheet_id' => $worksheet->id,
            'room_name'    => self::ROOM_TWO,
            'photo_path'   => 'device-label-photos/walkthrough.jpg',
            'confirmed'    => true,
            'captured_at'  => now(),
            'captured_by'  => self::LABEL_CAPTURED_BY,
        ]);

        $statusesBeforeSigning = array_map(fn (array $b) => $b['status'], $this->barRooms($xpath));
        $buttonsBeforeSigning  = $this->barButtonCount($xpath);

        // A DISTINCT signing IP, and the distinctness is load bearing. The
        // room-complete / survey-reviewed stamps written earlier in this walk
        // already contain the request IP (see F-46.7-04-01 below), so if the
        // signature were posted from the same 127.0.0.1 the sweep below could
        // not tell a signoff leak from a pre-existing stamp.
        $this->withServerVariables(['REMOTE_ADDR' => self::SIGNING_IP])
            ->withHeader('User-Agent', self::SIGNING_USER_AGENT)->post(
            route('public-worksheet.sign', ['token' => $token]),
            [
                'client_name'     => 'Walkthrough Signer',
                'signature_image' => 'data:image/png;base64,' . base64_encode('png-bytes'),
                'happy_with_work' => true,
            ],
        )->assertRedirect(route('public-worksheet.show', ['token' => $token]));

        $this->assertTrue(
            WorksheetCaptureLock::isLocked($worksheet->fresh()),
            'STEP 8 — the worksheet is not locked after the client signed. Every assertion '
            . 'below would then be measuring an unsigned page.',
        );

        $signedBody = (string) $this->open($worksheet)->getContent();
        $xpath      = $this->dom($signedBody);

        $this->assertSame(
            0,
            $xpath->query('//textarea[@data-room-notes]')->length,
            'STEP 8 — a notes textarea is still editable on a SIGNED worksheet. The client has '
            . 'put their name to this record and somebody can still type into it.',
        );
        $this->assertSame(
            0,
            $xpath->query('//*[@data-capture-control]')->length,
            'STEP 8 — a capture control survived sign-off. D-07 closes the whole capture '
            . 'surface at the signature, not most of it.',
        );

        // The note must still be READABLE — a signed worksheet is a record and
        // its signer must be able to read what they signed — and must be
        // ESCAPED, because this is unauthenticated engineer free text on the
        // page a client signed.
        $readonly = $this->subtree($xpath, '//div[@data-room-notes-readonly]');

        $this->assertStringContainsString(
            self::ROOM_TWO_NOTE,
            $readonly,
            'STEP 8 — the engineer\'s note is not readable on the signed worksheet. The client '
            . 'signed a record whose only written finding has disappeared from it.',
        );

        $this->assertStringNotContainsString(
            '<script>alert(1)</script>',
            $signedBody,
            'STEP 8 — engineer-typed text reached the signed page as RAW MARKUP. This is an '
            . 'unauthenticated field on a document a client signs.',
        );
        $this->assertStringContainsString(
            '&lt;script&gt;',
            $signedBody,
            'STEP 8 — the note is not present in escaped form either, so the assertion above '
            . 'passed because the text was missing rather than because it was escaped.',
        );
        $this->assertStringContainsString(
            'short &amp; the',
            $signedBody,
            'STEP 8 — the ampersand in the note was not escaped, or was escaped twice. Either '
            . 'way the office reads something the engineer did not type.',
        );

        // ⚠️ PART 4 OF 4 — the note survived sign-off itself.
        // (The field is gone, so its value is read from the read-only render.)

        // The bar is still there, unchanged, because a signed worksheet is a
        // record and whoever reads it back still has to navigate it.
        $this->assertSame(
            $buttonsBeforeSigning,
            $this->barButtonCount($xpath),
            'STEP 8 — the sticky bar lost buttons at sign-off. A signed worksheet is a record '
            . 'and its reader still has to move between rooms.',
        );
        $this->assertSame(
            $statusesBeforeSigning,
            array_map(fn (array $b) => $b['status'], $this->barRooms($xpath)),
            'STEP 8 — a room\'s status changed at sign-off. The record must read back exactly '
            . 'as it read at the moment it was signed.',
        );

        // T-46.7-04-01 — the one render where a signature's metadata is nearest
        // the surface.
        //
        // THE VALUES are swept over the WHOLE body, because a value appearing
        // anywhere at all is the leak. Each one has real fuel behind it: the
        // label photo above carries LABEL_CAPTURED_BY, the signature was posted
        // with SIGNING_USER_AGENT, and the signoff row holds 127.0.0.1. A sweep
        // for a string nothing was ever going to emit proves nothing.
        foreach ([
            'the signing browser\'s user agent'     => self::SIGNING_USER_AGENT,
            'the label photo\'s captured_by stamp'  => self::LABEL_CAPTURED_BY,
            'the signer\'s IP address'              => self::SIGNING_IP,
        ] as $what => $needle) {
            $this->assertStringNotContainsString(
                $needle,
                $signedBody,
                "STEP 8 — {$what} is rendered on the signed worksheet. Signature metadata is "
                . 'audit data for the office, never site context for whoever holds the link.',
            );
        }

        // ⚠️ F-46.7-04-01 — A PRE-EXISTING FINDING THIS WALK UNCOVERED, RECORDED
        // AS CURRENT BEHAVIOUR AND DELIBERATELY NOT FIXED HERE.
        //
        // `pre_install_confirmations.room_complete.{room}.completed_by` holds
        // `ip:{request ip}|actor:{sha256 prefix}` — and the ENGINEER LINK RENDERS
        // IT, twice: as "✓ Room Complete by {stamp} at {time}"
        // (public-show.blade.php:1655) and in a `title=` attribute on the panel
        // head's Complete pill (:1328). The same is true of the survey-reviewed
        // stamp. So the engineer's own IP address is on the page the CLIENT signs
        // and reads back afterwards.
        //
        // This is NOT one of the three columns T-46.7-04-01 names — those are
        // `worksheet_signoffs.ip_address`, `worksheet_signoffs.user_agent` and
        // `device_label_photos.captured_by`, and all three are swept clean above.
        // It predates phase 46.7 entirely: the stamp shape came from Audit M-06
        // (2026-07), which replaced a leaked token prefix with an IP + hash and
        // did not notice the field was rendered. 46.7 writes no production code
        // in this plan and changed nothing here.
        //
        // Asserted as CURRENT behaviour, not as desired behaviour, so the day
        // somebody fixes it this assertion fails and tells them to delete it.
        $this->assertStringContainsString(
            'ip:127.0.0.1|actor:',
            $signedBody,
            'STEP 8 — F-46.7-04-01 appears to have been FIXED: the room-complete / '
            . 'survey-reviewed stamp no longer renders its ip: prefix on the engineer link. '
            . 'That is an improvement, not a regression — delete this assertion and the '
            . 'comment above it, and record the fix in the plan that made it. Do NOT '
            . 're-introduce the stamp to keep this green.',
        );

        // ⚠️ THE COLUMN-NAME SWEEP IS NARROWED TO THE NON-SCRIPT BODY, AND THE
        // NARROWING IS A FINDING RATHER THAN A WEAKENING.
        //
        // `captured_by` is named in a JS COMMENT inside the page's own
        // `<script>` block (`public-show.blade.php:2666` — "…and never
        // `captured_by` (T-46.4-02-04)"). Blade `{{-- --}}` comments are
        // stripped from the response; a `//` comment inside a script tag is NOT
        // — it ships to every browser. So a whole-body sweep for the bare string
        // `captured_by` is UNACHIEVABLE AS WRITTEN, and it fails on the comment
        // that exists to stop the very leak being swept for.
        //
        // The narrowing keeps the assertion meaningful: the column names are
        // swept over the body with every `<script>` element removed, which is
        // every place a column name could arrive as DATA. The VALUE sweep above
        // still covers the whole body, script tags included, so a real leak
        // inside a script is still caught by the assertion that matters.
        $scriptlessBody = (string) preg_replace('#<script\b[^>]*>.*?</script>#is', '', $signedBody);

        $this->assertGreaterThan(
            20000,
            strlen($scriptlessBody),
            'STEP 8 — stripping the script tags removed almost the whole page, so the '
            . 'column-name sweep below is sweeping nothing.',
        );

        foreach (['ip_address', 'user_agent', 'captured_by'] as $column) {
            $this->assertStringNotContainsString(
                $column,
                $scriptlessBody,
                "STEP 8 — the {$column} column name reached the signed worksheet's markup. "
                . 'Signature and capture metadata is audit data for the office, never site '
                . 'context for whoever holds the link.',
            );
        }

        // And the one script-borne occurrence is pinned, so the narrowing above
        // cannot quietly start covering a second one.
        $this->assertSame(
            1,
            substr_count($signedBody, 'captured_by'),
            'STEP 8 — `captured_by` now appears more than once in the response. Exactly one '
            . 'occurrence is expected and it is the JS comment at public-show.blade.php:2666 '
            . 'warning against rendering it. A second occurrence is either a real leak or a '
            . 'second comment, and the sweep above cannot tell them apart — investigate it.',
        );

        // ── STEP 9. POST A NOTE AFTER SIGN-OFF ───────────────────────────────
        // The lock has to hold after six prior SUCCESSFUL writes, which is why
        // this is asserted at the end of a sequence and not in isolation.

        $blobBeforeRefusal = $this->confirmationsBlob($worksheet);
        $kitRowsBefore     = WorksheetAdditionalKit::query()->where('worksheet_id', $worksheet->id)->count();

        $refusal = $this->postJson(
            route('public-worksheet.room-notes', ['token' => $token, 'roomName' => self::ROOM_TWO]),
            ['notes' => 'One more thing I remembered in the van on the way home'],
        );

        $refusal->assertStatus(422);

        $this->assertSame(
            WorksheetCaptureLock::MESSAGE,
            $refusal->json('message'),
            'STEP 9 — a note posted after sign-off was refused with the wrong sentence. The '
            . 'page shows the server\'s own words verbatim while KEEPING the draft; a '
            . 'different sentence is a message nobody wrote for an engineer to read.',
        );
        $this->assertNull(
            $refusal->json('errors'),
            'STEP 9 — the refusal carried a validation error bag, which means the lock runs '
            . 'AFTER validation. A locked caller must learn nothing about the payload shape.',
        );

        $this->assertSame(
            $blobBeforeRefusal,
            $this->confirmationsBlob($worksheet),
            'STEP 9 — a note written after the client signed changed the record they signed. '
            . 'pre_install_confirmations must be byte-identical across a refused write.',
        );
        $this->assertSame(
            $kitRowsBefore,
            WorksheetAdditionalKit::query()->where('worksheet_id', $worksheet->id)->count(),
            'STEP 9 — a refused note moved a kit row. The refusal must touch nothing at all.',
        );
    }
}
