<?php

namespace Tests\Feature\Worksheets;

use App\Models\Project;
use App\Models\User;
use App\Models\Worksheet;
use App\Models\WorksheetPhoto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 46.4 Plan 02 Task 3 — the guards that bite on the engineer link's
 * photo trays.
 *
 * Two halves, and they guard different things:
 *
 *  1. RENDERED DOM, walked with DOMXPath and asserted BY SUBTREE. A
 *     page-level assertSee() would pass if both a start photo and a completion
 *     photo landed in the SAME tray — which is the exact defect the partition
 *     could ship. Every count here is DERIVED from the fixture's room count,
 *     never a bare literal, so adding a room to the fixture cannot silently
 *     weaken the assertion.
 *
 *  2. STATIC SOURCE SCAN of public-show.blade.php. This page is an
 *     unauthenticated, token-only surface that the CLIENT SIGNS, and it has no
 *     bundler and no layout — so a few invariants can only be held by reading
 *     the file:
 *       · exactly ONE `{!! `, the pre-existing $skipRestoreAttr
 *       · no NEW Alpine directive (⚠️ NOT zero — see the constant below)
 *       · window.openPhotoLightbox is actually DEFINED
 *       · the IndexedDB queue schema is untouched
 *
 * @see resources/views/worksheets/public-show.blade.php
 * @see .planning/phases/46.4-engineer-link-install-capture/46.4-CONTEXT.md (D-03, D-04)
 */
class EngineerLinkPhotoTrayGuardTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The rooms the fixture renders. EVERY tray-count assertion multiplies by
     * count(self::ROOMS) — add a room here and the expectations follow.
     *
     * @var list<string>
     */
    private const ROOMS = ['Boardroom', 'Comms Room'];

    /** One Start tray and one Completion tray per room. */
    private const TRAYS_PER_ROOM = 2;

    /**
     * ALPINE, PINNED AT TODAY'S COUNT — DELIBERATELY NOT ZERO.
     *
     * Alpine is NEVER loaded on this standalone page, so every directive on it
     * is dead. Five of them sit in the CLIENT SIGN-OFF FORM (one x-data, two
     * x-model, one x-show, one x-cloak, around line 1470-1525) and they are the
     * SOLE pre-existing site. 46.4-CONTEXT.md rules fixing them out of this
     * phase by name, so asserting zero would fail on untouched code and this
     * file would be deleted rather than obeyed.
     *
     * Pinning the COUNT instead means this phase cannot add a sixth.
     *
     * @var array<string,int>
     */
    private const ALPINE_OCCURRENCES = [
        ' x-data'            => 1,
        ' x-show'            => 1,
        ' x-model'           => 2,
        ' x-cloak'           => 1,
        '@click'             => 0,
        '<x-photo-lightbox'  => 0,
    ];

    private const VIEW = 'resources/views/worksheets/public-show.blade.php';

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function worksheet(): Worksheet
    {
        $user    = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $user->id]);

        return Worksheet::create([
            'user_id'        => $user->id,
            'project_id'     => $project->id,
            'project_name'   => 'Tray Guard Fixture',
            'project_ref'    => '21CQ00000-01-OPS',
            'client_name'    => 'Fixture Client',
            'site_address'   => '1 Fixture Way, Reading RG1 1AA',
            'status'         => Worksheet::STATUS_FINAL,
            'generated_data' => [
                'rooms' => array_map(fn (string $name) => ['name' => $name], self::ROOMS),
            ],
        ]);
    }

    private function photo(Worksheet $worksheet, string $bucket, string $caption, ?string $room = null): WorksheetPhoto
    {
        return $worksheet->photos()->create([
            'room_name'     => $room ?? self::ROOMS[0],
            'bucket'        => $bucket,
            'filename'      => 'worksheet-photos/' . $worksheet->id . '/' . fake()->uuid() . '.jpg',
            'original_name' => 'capture.jpg',
            'mime_type'     => 'image/jpeg',
            'caption'       => $caption,
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

    /** The innerHTML of every tray carrying $bucket, concatenated. */
    private function traySubtree(\DOMXPath $xpath, string $bucket): string
    {
        $nodes = $xpath->query("//div[@data-photo-tray][@data-bucket='{$bucket}']");
        $html  = '';
        foreach ($nodes as $node) {
            $html .= $node->ownerDocument->saveHTML($node);
        }

        return html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    // ── 1. Two trays per room ────────────────────────────────────────────────

    public function test_every_room_renders_one_start_tray_and_one_completion_tray(): void
    {
        $xpath = $this->dom($this->render($this->worksheet()));

        $rooms = count(self::ROOMS);

        $this->assertSame(
            $rooms * self::TRAYS_PER_ROOM,
            $xpath->query('//div[@data-photo-tray]')->length,
            'Expected ' . self::TRAYS_PER_ROOM . ' photo trays per room.',
        );
        $this->assertSame(
            $rooms,
            $xpath->query("//div[@data-photo-tray][@data-bucket='" . WorksheetPhoto::BUCKET_START . "']")->length,
        );
        $this->assertSame(
            $rooms,
            $xpath->query("//div[@data-photo-tray][@data-bucket='" . WorksheetPhoto::BUCKET_COMPLETION . "']")->length,
        );
    }

    /**
     * The tray title 46.4-01's backfill ruling rests on, asserted verbatim.
     * The migration docblock justifies stamping every legacy photo
     * `completion` by quoting this exact wording; renaming the tray would
     * retroactively make that ruling arbitrary.
     */
    public function test_the_completion_trays_title_is_unchanged(): void
    {
        $subtree = $this->traySubtree(
            $this->dom($this->render($this->worksheet())),
            WorksheetPhoto::BUCKET_COMPLETION,
        );

        $this->assertStringContainsString('📷 Photos of completed work', $subtree);
        $this->assertStringContainsString('📸 Before you start', $this->traySubtree(
            $this->dom($this->render($this->worksheet())),
            WorksheetPhoto::BUCKET_START,
        ));
    }

    // ── 2. Photos land in their own tray, asserted BY SUBTREE ────────────────

    public function test_a_start_photo_appears_only_in_the_start_tray_and_a_completion_photo_only_in_the_completion_tray(): void
    {
        $worksheet = $this->worksheet();
        $this->photo($worksheet, WorksheetPhoto::BUCKET_START, 'RACK-AS-FOUND');
        $this->photo($worksheet, WorksheetPhoto::BUCKET_COMPLETION, 'RACK-AS-LEFT');

        $xpath      = $this->dom($this->render($worksheet));
        $start      = $this->traySubtree($xpath, WorksheetPhoto::BUCKET_START);
        $completion = $this->traySubtree($xpath, WorksheetPhoto::BUCKET_COMPLETION);

        $this->assertStringContainsString('RACK-AS-FOUND', $start);
        $this->assertStringNotContainsString('RACK-AS-FOUND', $completion);

        $this->assertStringContainsString('RACK-AS-LEFT', $completion);
        $this->assertStringNotContainsString('RACK-AS-LEFT', $start);
    }

    /**
     * Each tray counts only its own bucket. The room-summary pill stays
     * whole-room, which is what the Mark Room Complete soft gate reads.
     */
    public function test_each_tray_counts_only_its_own_bucket(): void
    {
        $worksheet = $this->worksheet();
        $this->photo($worksheet, WorksheetPhoto::BUCKET_START, 'a');
        $this->photo($worksheet, WorksheetPhoto::BUCKET_START, 'b');
        $this->photo($worksheet, WorksheetPhoto::BUCKET_COMPLETION, 'c');

        $xpath = $this->dom($this->render($worksheet));

        $count = function (string $bucket) use ($xpath): string {
            $node = $xpath
                ->query("//div[@data-photo-tray][@data-bucket='{$bucket}'][@data-room-key='" . strtolower(self::ROOMS[0]) . "']//span[@data-photo-count]")
                ->item(0);

            return $node ? trim($node->textContent) : '(missing)';
        };

        $this->assertSame('2', $count(WorksheetPhoto::BUCKET_START));
        $this->assertSame('1', $count(WorksheetPhoto::BUCKET_COMPLETION));
    }

    // ── 3. The caption is escaped (T-46.4-02-02) ─────────────────────────────

    public function test_a_caption_containing_script_renders_escaped(): void
    {
        $worksheet = $this->worksheet();
        $this->photo($worksheet, WorksheetPhoto::BUCKET_START, '<script>alert(1)</script>');

        $html = $this->render($worksheet);

        // Non-vacuity FIRST: the escaped form must actually be present, so this
        // test cannot pass merely because the caption failed to render at all.
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('<script>alert(1)</script>', $html);
    }

    // ── 4. Every capture control carries plan 46.4-04's lock hook ────────────

    /**
     * 46.4-04 applies ONE sign-off lock across every capture affordance (D-07),
     * server-side, one wave after this plan. What this plan owes it is a hook:
     * a shared attribute on every control, so a single guard can find them all.
     * Two controls per tray — the label field and the capture button — plus the
     * hidden file input inside the button.
     */
    public function test_every_capture_control_carries_the_shared_lock_hook(): void
    {
        $worksheet = $this->worksheet();
        $this->photo($worksheet, WorksheetPhoto::BUCKET_START, 'hooked');

        $xpath = $this->dom($this->render($worksheet));

        foreach ([WorksheetPhoto::BUCKET_START, WorksheetPhoto::BUCKET_COMPLETION] as $bucket) {
            $tray = "//div[@data-photo-tray][@data-bucket='{$bucket}']";

            $this->assertSame(
                count(self::ROOMS),
                $xpath->query($tray . '//input[@data-photo-caption][@data-capture-control]')->length,
                "The {$bucket} tray's label field must carry the lock hook.",
            );
            $this->assertSame(
                count(self::ROOMS),
                $xpath->query($tray . "//input[@type='file'][@data-capture-control]")->length,
                "The {$bucket} tray's capture input must carry the lock hook.",
            );
            $this->assertSame(
                count(self::ROOMS),
                $xpath->query($tray . '//label[@data-capture-control]')->length,
                "The {$bucket} tray's capture button must carry the lock hook.",
            );
        }

        // The photo delete button is a capture-surface write too (D-07 locks it
        // by name), so it carries the hook as well.
        $this->assertSame(
            1,
            $xpath->query("//div[@data-photo-tray]//button[@data-capture-control][contains(@onclick,'deleteWorksheetPhoto')]")->length,
        );
    }

    /**
     * …and the lock ITSELF is NOT this plan's. Shipping a partial, client-side
     * lock here would leave a second place to keep in step with 46.4-04's
     * server-side one. The trays ship unlocked for exactly one wave.
     */
    public function test_this_plan_ships_no_lock_of_its_own(): void
    {
        $worksheet = $this->worksheet();
        $worksheet->signoffs()->create([
            'client_name'          => 'A Client',
            'signature_png_base64' => base64_encode('not-a-real-png'),
            'signed_with_comments' => false,
            'signed_at'            => now(),
        ]);

        $xpath = $this->dom($this->render($worksheet->fresh()));

        // The trays are STILL capturable after a sign-off exists. That is not
        // an oversight and it is not a security hole this plan opened: D-07's
        // lock is plan 46.4-04's, applied SERVER-SIDE in one place across every
        // write endpoint one wave from now. A second, partial, client-only lock
        // here would be a thing to keep in step with it forever — and a hidden
        // button was never a permission on a token-only page anyway.
        $this->assertSame(
            count(self::ROOMS) * self::TRAYS_PER_ROOM,
            $xpath->query('//div[@data-photo-tray]//label[@data-capture-control]')->length,
            'The trays ship UNLOCKED for exactly one wave — 46.4-04 owns the lock.',
        );

        // What 04 gets instead: every control findable by one selector, and
        // every write still on the EXISTING /photos endpoint — no second write
        // surface was invented for it to have to cover.
        $this->assertGreaterThan(0, $xpath->query('//*[@data-capture-control]')->length);
        // Two occurrences: the original online POST and the offline wrapper's
        // inlined copy of it. Both are the SAME endpoint.
        $this->assertSame(2, substr_count($this->source(), "+ '/photos'"));
    }

    // ── 5. Static source scan ────────────────────────────────────────────────

    public function test_the_view_still_has_exactly_one_unescaped_echo(): void
    {
        $source = $this->source();

        $this->assertSame(1, substr_count($source, '{!!'));
        $this->assertStringContainsString('{!! $skipRestoreAttr !!}', $source);
    }

    /**
     * ⚠️ COUNT, not zero. See ALPINE_OCCURRENCES.
     */
    public function test_this_phase_adds_no_alpine_directive_to_a_page_that_never_loads_alpine(): void
    {
        $source = $this->source();

        foreach (self::ALPINE_OCCURRENCES as $needle => $expected) {
            $this->assertSame(
                $expected,
                substr_count($source, $needle),
                "Alpine occurrence count for `{$needle}` changed. Alpine is never loaded on this "
                . 'page; the only pre-existing directives are in the client sign-off form and are '
                . 'ruled out of phase 46.4 by name. Do not add a new one, and do not "fix" this by '
                . 'editing the constant.',
            );
        }
    }

    /**
     * Non-vacuity for the Alpine pin: the pre-existing sign-off directives ARE
     * there, so a count of 1 or 2 above is a real occurrence and not a typo in
     * the needle.
     */
    public function test_the_alpine_pin_is_not_vacuous(): void
    {
        $source = $this->source();

        $this->assertGreaterThan(0, array_sum(self::ALPINE_OCCURRENCES));
        $this->assertStringContainsString('x-data="{', $source);
    }

    public function test_the_lightbox_is_defined_and_both_call_sites_survive(): void
    {
        $source = $this->source();

        // Defined…
        $this->assertStringContainsString('window.openPhotoLightbox = function', $source);
        // …as vanilla <dialog>, NOT by including the Alpine component.
        $this->assertStringContainsString("createElement('dialog')", $source);
        $this->assertSame(0, substr_count($source, '<x-photo-lightbox'));

        // …and BOTH original call sites are untouched: the room tray and the
        // survey-reference strip.
        $this->assertSame(2, substr_count($source, 'openPhotoLightbox(@js('));
        $this->assertStringContainsString('openPhotoLightbox(@js($trayLb)', $source);
        $this->assertStringContainsString('openPhotoLightbox(@js($surveyPhotosLb)', $source);
    }

    /**
     * T-46.4-02-05 — the offline queue schema, pinned.
     *
     * `onupgradeneeded` on this page only ever CREATES; it never migrates. A
     * DB_VERSION bump would therefore strand every photo an engineer has
     * queued on a bad-signal site. bucket and caption ride the EXISTING
     * free-form `fields` bag, which is already spread into the drain FormData
     * key by key, so this plan costs zero schema change.
     */
    public function test_the_offline_queue_schema_is_untouched(): void
    {
        $source = $this->source();

        $this->assertSame(1, substr_count($source, 'DB_VERSION = 1'));
        $this->assertSame(1, substr_count($source, 'createObjectStore'));
        $this->assertSame(1, substr_count($source, "keyPath: 'id'"));
        $this->assertSame(1, substr_count($source, "createIndex('capturedAt'"));

        // …and a photo is still a photo: the bucket is data, not a queue kind.
        $this->assertStringContainsString("kind:  'completed'", $source);
        $this->assertStringContainsString('fields: __cap ?', $source);
    }

    // ── 6. The gate is still visual only ─────────────────────────────────────

    /**
     * Start photos gate NOTHING, and the Mark Room Complete gate still reads
     * the WHOLE-ROOM count it has read since 260504-iy4. A room whose only
     * photo is a start photo still offers the button — because every gate on
     * this page is visual and the server accepts the POST regardless
     * (PublicWorksheetController::markRoomComplete).
     */
    public function test_a_start_photo_alone_does_not_block_mark_room_complete(): void
    {
        $worksheet = $this->worksheet();
        $this->photo($worksheet, WorksheetPhoto::BUCKET_START, 'only a start photo');

        $html  = $this->render($worksheet);
        $xpath = $this->dom($html);

        $buttons = $xpath->query("//button[@type='submit'][contains(., 'Mark Room Complete')]");
        $this->assertGreaterThan(0, $buttons->length);
        $this->assertFalse(
            $buttons->item(0)->hasAttribute('disabled'),
            'A start photo satisfies the whole-room count, so the visual gate opens.',
        );

        // And the server never enforced it anyway — proven, not assumed.
        $this->post(route('public-worksheet.room-complete', [
            'token'    => $worksheet->access_token,
            'roomName' => self::ROOMS[1], // a room with NO photos at all
        ]))->assertRedirect();

        $this->assertNotNull($worksheet->fresh()->roomCompletedAt(self::ROOMS[1]));
    }
}
