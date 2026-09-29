<?php

namespace Tests\Feature\Worksheets;

use Tests\TestCase;

/**
 * Phase 46.7 Plan 01 — THE OFFLINE RULING FOR AUTOSAVE, PINNED AT THE SOURCE.
 *
 * ── ⚠️ READ THIS BEFORE YOU TRUST THIS FILE ─────────────────────────────────
 *
 * **NOTHING HERE OPENS A REAL BROWSER.**
 *
 * This repo has no browser-driving harness. `puppeteer` is a dependency, but it
 * is reached only through Browsershot inside `PdfRenderService`, which renders
 * HTML to a PDF — it does not drive a live application. So this file does two
 * things, and neither of them is "the engineer's phone works":
 *
 *   1. **Source pins.** The ruling text, the storage choice, and the fact that
 *      the schema-frozen photo store was not disturbed. These are `substr_count`
 *      and `strpos` facts about a file.
 *
 *   2. **A logic harness.** The `DraftStore` body is lifted verbatim out of the
 *      view between its two extract markers and executed in **node**, against a
 *      **stubbed** `localStorage` and a **stubbed** DOM. That exercises the real
 *      shipped lines — last-write-wins, the stale-acknowledgement refusal, the
 *      refused-draft retention, and a store that throws — but it is node, not
 *      Safari. A real private-mode `QuotaExceededError`, a real reload, and a
 *      real phone are NOT proven here.
 *
 * **The real proof is step 2 of plan 46.7-04's blocking human checkpoint:**
 * airplane mode on a phone, type a note, reload, see it still marked not-sent,
 * restore signal, watch it arrive. That walk is the proof. This file exists to
 * make that walk's code-level preconditions non-negotiable in the meantime.
 *
 * A guard test that implies more than it checks is worse than no test, so the
 * limit is stated here and repeated in the plan's SUMMARY.
 *
 * ── WHAT IS ACTUALLY PINNED ─────────────────────────────────────────────────
 *
 * 1. **THE RULING LIVES AT THE CODE.** Four headings, verbatim. A ruling that
 *    lives only in a plan file is a ruling nobody will find, and the next
 *    person to touch autosave is the person who needs it.
 *
 * 2. **AUTOSAVE DOES NOT GO NEAR THE PHOTO QUEUE.** That store is schema-frozen
 *    and holds real unsent work on real phones; its creation handler only ever
 *    CREATES, so a version bump or a key change strands every queued photo.
 *    This plan is the one that adds a SECOND storage mechanism, so this is the
 *    file that must prove it did not disturb the first.
 *
 * 3. **AN OLD ROUND TRIP CANNOT RETIRE NEWER TYPING.**
 *
 * @see resources/views/worksheets/public-show.blade.php
 * @see .planning/phases/46.7-engineer-link-tabbed-layout/46.7-01-PLAN.md
 * @see .planning/phases/46.4-engineer-link-install-capture/46.4-06-SUMMARY.md
 */
class EngineerLinkAutosaveOfflineRulingTest extends TestCase
{
    private const VIEW = 'resources/views/worksheets/public-show.blade.php';

    /**
     * Occurrence counts measured on the engineer link BEFORE this plan added a
     * line, and required to be identical after it. Every one of these names an
     * identifier belonging to the schema-frozen photo queue.
     *
     * ⚠️ A CHANGE HERE IS NOT A NUMBER TO UPDATE. It means autosave has reached
     * into the frozen store, and every photo sitting unsent on every engineer's
     * phone is at risk.
     */
    private const FROZEN_STORE_OCCURRENCES = [
        'DB_VERSION'            => 3,
        'const DB_VERSION = 1;' => 1,
        "keyPath: 'id'"         => 1,
        'capturedAt'            => 10,
        'pending_uploads'       => 1,
        'createObjectStore'     => 1,
    ];

    /**
     * Re-asserted HERE, in the plan that adds a long comment block, because
     * that is exactly where a near-miss happens: these guards match COMMENTS,
     * and seven near-misses have been paid for already.
     */
    private const ALPINE_OCCURRENCES = [
        ' x-data'           => 1,
        ' x-show'           => 1,
        ' x-model'          => 2,
        ' x-cloak'          => 1,
        '@click'            => 0,
        '<x-photo-lightbox' => 0,
    ];

    /** The four ruling headings, verbatim, and what is lost if each one goes. */
    private const RULING_HEADINGS = [
        'AUTOSAVE USES localStorage, NEVER THE PHOTO QUEUE'
            => 'The reason autosave keeps its own store is gone from the code. The next person '
             . 'to need offline storage will add a kind to the schema-frozen photo queue, and '
             . 'strand every unsent photo on every engineer\'s phone.',

        'A DRAFT HELD ON THE DEVICE IS A DRAFT THE ENGINEER CAN SEE'
            => 'The rule that a held draft must be visibly marked not-yet-sent is gone. A field '
             . 'that looks saved and is not is the exact failure this phase exists to prevent.',

        'LAST WRITE WINS, AND THAT IS SAFE HERE BECAUSE THE PAYLOAD IS WHOLE'
            => 'The justification for overwriting rather than merging is gone. Somebody will '
             . '"fix" this into a merge and start losing halves of notes.',

        'A DRAFT THE SERVER REFUSES IS NEVER DISCARDED'
            => 'The rule that a draft refused by the sign-off lock STAYS on the device is gone. '
             . 'The next error handler will delete an engineer\'s words on a 422.',
    ];

    // ── Source helpers ───────────────────────────────────────────────────────

    private function source(): string
    {
        $path = base_path(self::VIEW);

        $this->assertFileExists($path, 'The engineer link view is gone — every guard below would pass vacuously.');

        $source = (string) file_get_contents($path);

        // NON-VACUITY: if the store were renamed or deleted, every
        // "does not contain X" guard below would pass for the wrong reason.
        $this->assertStringContainsString(
            'window.DraftStore = DraftStore;',
            $source,
            'DraftStore is no longer exposed on this page — these guards are measuring nothing.',
        );

        return $source;
    }

    /**
     * The DraftStore body, lifted between its two extract markers. Scoping the
     * slice matters: the page mentions the photo queue elsewhere by design, so a
     * whole-file "does not contain" guard would be unsatisfiable by construction.
     */
    private function draftStoreSlice(string $source): string
    {
        $start = strpos($source, '// ── DRAFTSTORE-EXTRACT-BEGIN');
        $end   = strpos($source, '// ── DRAFTSTORE-EXTRACT-END');

        $this->assertIsInt($start, 'The DraftStore extract BEGIN marker is gone — the slice guards below cannot run.');
        $this->assertIsInt($end, 'The DraftStore extract END marker is gone — the slice guards below cannot run.');
        $this->assertGreaterThan($start, $end, 'The DraftStore extract markers are out of order.');

        return substr($source, $start, $end - $start);
    }

    private function drainSlice(string $source): string
    {
        $start = strpos($source, 'OfflineQueue.drain = function');
        $end   = strpos($source, 'OfflineQueue._notifyChange = function', (int) $start);

        $this->assertIsInt($start, 'drain() is gone from the page — the branch-ordering guard cannot run.');
        $this->assertIsInt($end, 'The member after drain() is gone — the drain slice is unbounded.');

        return substr($source, $start, $end - $start);
    }

    // ── 1. Non-vacuity for the whole file ────────────────────────────────────

    public function test_the_view_source_helper_reads_a_real_file(): void
    {
        $source = $this->source();

        $this->assertGreaterThan(
            100000,
            strlen($source),
            'The engineer link view is suddenly tiny. Every assertStringContainsString in this '
            . 'file is scanning something other than the page it claims to guard.',
        );
    }

    // ── 2. The ruling is readable at the code ────────────────────────────────

    public function test_the_ruling_is_titled_at_the_code_not_pointed_at_from_a_plan_file(): void
    {
        $this->assertStringContainsString(
            '46.7-01 — WHAT AUTOSAVE DOES WHEN THERE IS NO SIGNAL',
            $this->source(),
            'The ruling block title is gone. The planning file it used to live in will not be '
            . 'open when somebody next wonders why autosave has its own store — that is the '
            . 'whole reason the ruling was written at the code.',
        );
    }

    public function test_each_of_the_four_ruling_headings_is_present_verbatim(): void
    {
        $source = $this->source();

        foreach (self::RULING_HEADINGS as $heading => $whatIsLost) {
            $this->assertStringContainsString($heading, $source, $whatIsLost);
        }
    }

    public function test_the_ruling_states_the_cost_rather_than_hiding_it(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            'THE COST, STATED AND NOT HIDDEN',
            $source,
            'The cost section is gone from the ruling. An engineer, or the next person to read '
            . 'this, would be left believing a held note is safe when it lives on one handset.',
        );

        $this->assertStringContainsString(
            'never reach the office by',
            $source,
            'The ruling no longer says that a draft refused by the sign-off lock will not arrive '
            . 'by itself. That is the sharp edge of this design and it must stay written down.',
        );
    }

    public function test_the_ruling_records_that_it_extends_the_shipped_46_4_06_ruling(): void
    {
        $source = $this->source();

        $this->assertStringContainsString(
            'plan 46.4-06',
            $source,
            'The link back to the shipped offline ruling is gone. Without it this looks like a '
            . 'contradiction of 46.4-06 rather than an extension of it, and somebody will '
            . '"restore consistency" by refusing offline notes.',
        );

        $this->assertStringContainsString(
            'an identity problem, not a',
            $source,
            'The REASON this extends 46.4-06 is gone — that 46.4-06 refused an offline '
            . 'correction because a queued row has no identity, whereas a room\'s notes are '
            . 'keyed by room name and always have one.',
        );
    }

    public function test_the_ruling_says_the_store_is_wired_to_nothing_in_this_plan(): void
    {
        $this->assertStringContainsString(
            'WIRED TO NOTHING IN THIS PLAN',
            $this->source(),
            'The note that nothing consumes DraftStore yet is gone. A reader will assume '
            . 'autosave is live when no endpoint exists until wave 3.',
        );
    }

    // ── 3. The storage choice: localStorage, and NOT the photo queue ─────────

    public function test_draftstore_reaches_localstorage_under_a_per_worksheet_key(): void
    {
        $slice = $this->draftStoreSlice($this->source());

        $this->assertStringContainsString(
            "localStorage.setItem('wsDraft_",
            $slice,
            'DraftStore no longer writes to a per-worksheet localStorage key. Either it stopped '
            . 'persisting at all — in which case an engineer with no signal loses every word — '
            . 'or it moved somewhere it must not be.',
        );

        $this->assertStringContainsString(
            "localStorage.getItem('wsDraft_",
            $slice,
            'DraftStore no longer reads its localStorage key, so nothing typed offline can '
            . 'reappear after a reload.',
        );
    }

    public function test_draftstore_never_opens_the_frozen_photo_store(): void
    {
        $slice = $this->draftStoreSlice($this->source());

        $this->assertStringNotContainsString(
            'indexedDB',
            $slice,
            'DraftStore now touches the device database used by the photo queue. That store is '
            . 'schema-frozen at version 1 and its creation handler has no migration branch — '
            . 'this is how every unsent photo on every engineer phone gets stranded.',
        );

        $this->assertStringNotContainsString(
            'pending_uploads',
            $slice,
            'DraftStore now names the photo queue\'s object store. Autosave must not share it; '
            . 'that separation is the entire reason the frozen store stayed safe.',
        );
    }

    public function test_the_frozen_photo_store_schema_is_untouched_by_this_plan(): void
    {
        $source = $this->source();

        foreach (self::FROZEN_STORE_OCCURRENCES as $needle => $expected) {
            // NON-VACUITY FIRST: an equality against a count means nothing if
            // the needle itself has a typo in it — `0 === 0` would "pass".
            if ($expected > 0) {
                $this->assertStringContainsString(
                    $needle,
                    $source,
                    "The needle `{$needle}` is not in the page at all, so its occurrence count "
                    . 'below would be measuring nothing.',
                );
            }

            $this->assertSame(
                $expected,
                substr_count($source, $needle),
                "The occurrence count for `{$needle}` changed. This is NOT a number to update. "
                . 'The photo queue is schema-frozen at version 1 and holds real unsent work on '
                . 'real phones; its upgrade handler only ever CREATES the store, so a version '
                . 'bump, a key-path change or an index change strands every queued photo. '
                . 'Autosave has its own separate store precisely so this count never moves.',
            );
        }
    }

    /**
     * Restated here rather than trusted to live in OfflineQueueKitKindGuardTest:
     * this plan is the one that adds a second storage mechanism to the page, so
     * this is the plan that must prove it did not disturb the first.
     */
    public function test_the_is_binary_guard_still_sits_above_the_blob_append(): void
    {
        $drain = $this->drainSlice($this->source());

        $guardAt  = strpos($drain, "const isBinary = row.kind !== 'kit';");
        $appendAt = strpos($drain, "fd.append('photo'");

        // NON-VACUITY FIRST: both must exist, or `false < 123` would "pass".
        $this->assertIsInt($guardAt, 'The isBinary guard is gone from drain(). A blobless queued row now hits the blob append.');
        $this->assertIsInt($appendAt, 'The blob append is gone from drain() — this guard is measuring nothing.');

        $this->assertLessThan(
            $appendAt,
            $guardAt,
            "The isBinary guard is no longer ABOVE fd.append('photo'). A blobless kit row will "
            . 'now be stamped unreadable forever and the engineer\'s work is gone. Plan 46.4-06 '
            . 'exists for this one ordering; plan 46.7-01 must not have moved it.',
        );

        $this->assertMatchesRegularExpression(
            "/const isBinary = row\.kind !== 'kit';\s*\n\s*if \(isBinary\) \{/",
            $drain,
            'The isBinary guard no longer opens a block — the append may be running unconditionally.',
        );
    }

    // ── 4. The stale-acknowledgement refusal, pinned at the source ──────────

    public function test_marksent_only_clears_a_draft_whose_value_still_matches(): void
    {
        $slice = $this->draftStoreSlice($this->source());

        $this->assertStringContainsString(
            "if (! entry || entry.value !== _asText(sentValue)) {",
            $slice,
            'markSent no longer checks that the value it is acknowledging is still the value on '
            . 'the device. A save that started before the engineer\'s last keystroke would now '
            . 'retire the NEWER text, the field would look saved, and the newest words would be '
            . 'silently lost — which is the precise failure this store was built to prevent.',
        );
    }

    public function test_a_refused_draft_is_kept_with_the_servers_own_sentence(): void
    {
        $slice = $this->draftStoreSlice($this->source());

        $this->assertStringContainsString(
            'DraftStore.markRefused = function',
            $slice,
            'There is no longer any way to record that the server refused a draft, so the only '
            . 'remaining options are to delete the engineer\'s words or to pretend they sent.',
        );

        $this->assertStringContainsString(
            'refusedMessage',
            $slice,
            'A refused draft no longer carries the server\'s own sentence, so the engineer '
            . 'cannot be told WHY it will not send.',
        );

        // Scoped to markRefused's OWN body — clear() legitimately deletes, and a
        // whole-tail scan would be unsatisfiable by construction.
        $from = (int) strpos($slice, 'DraftStore.markRefused = function');
        $to   = (int) strpos($slice, 'DraftStore.clear = function');
        $this->assertGreaterThan($from, $to, 'clear() no longer follows markRefused — this guard cannot scope itself.');

        $this->assertStringNotContainsString(
            'delete map[fieldKey];',
            substr($slice, $from, $to - $from),
            'markRefused now deletes the draft. A draft the server refuses must NEVER be '
            . 'discarded — that is one of the four headings of this ruling.',
        );
    }

    // ── 5. A store that throws must be survivable and must SAY SO ───────────

    public function test_every_localstorage_access_is_wrapped_and_a_failure_is_surfaced(): void
    {
        $slice = $this->draftStoreSlice($this->source());

        // Four touch points: the draft read, the draft write, and the two halves
        // of the probe. A fifth appearing unreviewed is a new way to throw.
        $this->assertSame(
            4,
            substr_count($slice, 'window.localStorage.'),
            'The number of device-storage calls in DraftStore changed. Every one of them can '
            . 'throw — private browsing, cleared site data, a full quota — so a new one must be '
            . 'wrapped and accounted for here deliberately.',
        );

        // Every one of the localStorage touch points sits inside a try block.
        foreach (['getItem', 'setItem', 'removeItem'] as $op) {
            $pos = strpos($slice, 'window.localStorage.' . $op);
            $this->assertIsInt($pos, "DraftStore no longer calls localStorage.{$op} — this guard is vacuous.");

            $before = substr($slice, 0, $pos);
            $this->assertGreaterThan(
                (int) strrpos($before, '} catch'),
                (int) strrpos($before, 'try {'),
                "The localStorage.{$op} call is not inside an open try block. Private browsing, "
                . 'cleared site data and a full quota all THROW here, and an uncaught throw on a '
                . 'phone in a plant room takes the page down mid-note.',
            );
        }

        $this->assertStringContainsString(
            'DraftStore.warnIfUnusable = function',
            $slice,
            'A store that cannot hold a draft can no longer say so. The engineer would type two '
            . 'hundred words into a field that silently keeps nothing.',
        );

        $this->assertStringContainsString(
            'This phone cannot keep notes on the device.',
            $slice,
            'The sentence shown when the device refuses to store a draft is gone.',
        );
    }

    public function test_restored_draft_text_is_never_assigned_as_markup(): void
    {
        $slice = $this->draftStoreSlice($this->source());

        $this->assertStringNotContainsString(
            'innerHTML',
            $slice,
            'DraftStore now writes into innerHTML. Draft text is unauthenticated engineer input '
            . 'read back out of device storage on a page a CLIENT signs — it goes in by '
            . 'textContent or by a form value, never as markup.',
        );

        $this->assertStringContainsString(
            'textContent',
            $slice,
            'The warning banner no longer uses textContent, so either it is gone or it is '
            . 'building markup.',
        );
    }

    // ── 6. The pinned counts this plan could have broken with a comment ─────

    public function test_the_view_still_has_exactly_one_unescaped_echo(): void
    {
        $source = $this->source();

        $this->assertSame(
            1,
            substr_count($source, '{!!'),
            'The unescaped-echo count on the engineer link changed. This page is signed by a '
            . 'client and carries unauthenticated engineer input; the one raw echo is a '
            . 'hard-coded attribute and predates all of this.',
        );

        $this->assertStringContainsString('{!! $skipRestoreAttr !!}', $source);
    }

    public function test_this_plan_adds_no_alpine_directive_to_a_page_that_never_loads_alpine(): void
    {
        $source = $this->source();

        foreach (self::ALPINE_OCCURRENCES as $needle => $expected) {
            $this->assertSame(
                $expected,
                substr_count($source, $needle),
                "Alpine occurrence count for `{$needle}` changed. Alpine is never loaded on this "
                . 'page. ⚠️ THIS GUARD MATCHES COMMENTS — the likeliest cause is a comment in '
                . 'the new ruling block that names a directive. Reword the comment; do not edit '
                . 'the constant.',
            );
        }
    }

    public function test_the_alpine_pin_is_not_vacuous(): void
    {
        $this->assertGreaterThan(
            0,
            array_sum(self::ALPINE_OCCURRENCES),
            'The Alpine pin now expects zero of everything, so a typo in every needle would pass.',
        );

        $this->assertStringContainsString(' x-data', $this->source());
    }

    // ── 7. The logic harness — five states, executed, not eyeballed ─────────

    /**
     * Lifts the DraftStore body out of the view and runs it in node against a
     * stubbed localStorage and DOM, exercising every state the store has:
     * empty, holding, holding-after-reload, synced, stale-acknowledgement,
     * refused-by-server, and a store that throws on write and on read.
     *
     * ⚠️ This is node with stubs, NOT a phone. See the class docblock.
     */
    public function test_the_draft_store_behaves_correctly_in_all_of_its_states(): void
    {
        $node = $this->resolveNode();

        if ($node === null) {
            $this->markTestSkipped(
                'node is not reachable from PHP on this machine, so the DraftStore logic harness '
                . 'cannot run. The SOURCE pins in this file still ran. Re-run where node is on PATH.',
            );
        }

        $slice = $this->draftStoreSlice($this->source());

        $harness = $this->harnessScript($slice);
        $file    = rtrim(sys_get_temp_dir(), '\\/') . '/draftstore-' . bin2hex(random_bytes(8)) . '.mjs';
        file_put_contents($file, $harness);

        $out  = [];
        $code = 0;
        exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1', $out, $code);
        @unlink($file);

        $raw = implode("\n", $out);

        $this->assertSame(
            0,
            $code,
            "The DraftStore harness did not run cleanly. Output:\n" . $raw,
        );

        $result = json_decode($raw, true);

        $this->assertIsArray($result, "The harness did not emit JSON. Output:\n" . $raw);

        // ── STATE 1: empty ──────────────────────────────────────────────
        $this->assertSame(0, $result['empty']['count'], 'A fresh store reports a draft it does not hold.');
        $this->assertNull($result['empty']['get'], 'A fresh store returns an entry for a field nobody has typed in.');

        // ── STATE 2: holding ────────────────────────────────────────────
        $this->assertTrue($result['holding']['putOk'], 'Typing offline no longer records anything — the words are lost.');
        $this->assertSame('cracked backbox behind the rack', $result['holding']['value'], 'The held draft is not the text that was typed.');
        $this->assertFalse($result['holding']['sent'], 'A draft that has not reached the office is flagged as sent. It would be shown as saved when it is not.');
        $this->assertSame(1, $result['holding']['count']);
        $this->assertIsInt($result['holding']['queuedAt'], 'The draft carries no timestamp, so "N minutes ago" cannot be shown.');

        // Last write wins, per field key.
        $this->assertSame('cracked backbox behind the rack, plus a missing blank', $result['lastWriteWins']['value'], 'A second edit did not replace the first. Last-write-wins is the ruling.');
        $this->assertSame(1, $result['lastWriteWins']['count'], 'A second edit to the same field created a second draft instead of replacing it.');

        // ── STATE 2b: holding, after a reload ───────────────────────────
        $this->assertSame(
            'cracked backbox behind the rack, plus a missing blank',
            $result['afterReload']['value'],
            'The draft did not survive re-loading the store. The page still does full-page '
            . 'reloads for photo upload and sign-off, so a draft that dies on reload is a draft '
            . 'the engineer loses by uploading a photo.',
        );
        $this->assertFalse($result['afterReload']['sent'], 'After a reload the held draft no longer reads as not-yet-sent.');

        // ── STATE 3: synced ─────────────────────────────────────────────
        $this->assertTrue($result['synced']['markSentOk'], 'An acknowledgement of the exact text on the device was refused.');
        $this->assertSame(0, $result['synced']['count'], 'A draft the office has confirmed is still held, so it would be shown as unsent forever.');
        $this->assertNull($result['synced']['get']);

        // ── STATE 3b: a stale acknowledgement must NOT retire newer text ─
        $this->assertFalse(
            $result['stale']['markSentOk'],
            'An acknowledgement for the OLD text retired the draft. The engineer\'s newest '
            . 'keystrokes would be dropped while the field showed as saved.',
        );
        $this->assertSame(
            'second thoughts — it is the NEXT backbox',
            $result['stale']['value'],
            'The newer text is not what is still held after a stale acknowledgement.',
        );
        $this->assertSame(1, $result['stale']['count'], 'The stale acknowledgement emptied the store.');

        // ── STATE 4: refused by the server ──────────────────────────────
        $this->assertTrue($result['refused']['markRefusedOk']);
        $this->assertSame(1, $result['refused']['count'], 'A draft the server refused was discarded. It must NEVER be.');
        $this->assertTrue($result['refused']['refused'], 'The refusal was not recorded, so the engineer cannot be told about it.');
        $this->assertSame(
            'This worksheet has been signed off and can no longer be changed.',
            $result['refused']['refusedMessage'],
            'The server\'s own sentence is not what is stored, so the engineer would be shown '
            . 'our guess instead of the real reason.',
        );
        $this->assertFalse($result['refused']['sent'], 'A refused draft is flagged sent.');
        $this->assertSame(
            'This worksheet has been signed off and can no longer be changed.',
            $result['refused']['stillThereAfterReload'],
            'The refused draft did not survive a reload, so the words the engineer was told to '
            . 'read out to the office are gone from the screen.',
        );

        // ── STATE 5: storage throws (private mode / cleared data / quota) ─
        $this->assertFalse($result['throwing']['probeOk'], 'A store that throws on write was probed as healthy.');
        $this->assertFalse($result['throwing']['storageWorks'], 'storageWorks stayed true on a store that throws.');
        $this->assertFalse($result['throwing']['putOk'], 'A write into a throwing store reported success.');
        $this->assertFalse($result['throwing']['threw'], 'The throw escaped DraftStore. On a phone that takes the page down mid-note.');
        $this->assertSame(
            'This phone cannot keep notes on the device. Stay on a signal while you type — '
            . 'anything typed with no signal will NOT be kept.',
            $result['throwing']['bannerText'],
            'A device that cannot hold a draft says nothing. The engineer types two hundred '
            . 'words into a field that keeps none of them — the exact silent loss this ruling '
            . 'exists to prevent.',
        );
        $this->assertStringStartsWith('write:', (string) $result['throwing']['lastError'], 'The failure was not recorded against the operation that failed.');

        // A read that throws must degrade to "nothing held", never blow up.
        $this->assertFalse($result['throwingRead']['threw'], 'A throwing read escaped DraftStore.');
        $this->assertNull($result['throwingRead']['get'], 'A throwing read returned something other than "nothing held".');
        $this->assertSame(0, $result['throwingRead']['count']);

        // Subscribers are notified, so wave 3's indicator can subscribe rather than poll.
        $this->assertGreaterThanOrEqual(
            4,
            $result['events'],
            'DraftStore stopped announcing changes, so any indicator built on it goes stale and '
            . 'the engineer stops being told which state they are in.',
        );

        // Non-vacuity for the harness itself.
        $this->assertSame('worksheet-draft-change', $result['eventName']);
        $this->assertGreaterThan(2000, $result['sliceLength'], 'The extracted DraftStore slice is too small to be the real store.');
    }

    private function resolveNode(): ?string
    {
        foreach (['node', 'C:\\Program Files\\nodejs\\node.exe'] as $candidate) {
            $out  = [];
            $code = 0;
            exec(escapeshellarg($candidate) . ' --version 2>&1', $out, $code);

            if ($code === 0 && preg_match('/^v\d+\./', (string) ($out[0] ?? ''))) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * The harness. Stubs only what the store touches: one localStorage, one
     * window with an event listener, one very small document. The store body is
     * inserted verbatim and loaded TWICE against the same storage, which is how
     * "survives a reload" is exercised.
     */
    private function harnessScript(string $slice): string
    {
        $store = json_encode($slice);

        return <<<JS
        const STORE_BODY = {$store};

        let backing = {};
        let throwOnWrite = false;
        let throwOnRead  = false;
        let events       = 0;
        let bannerText   = null;

        const localStorageStub = {
            getItem(k) {
                if (throwOnRead) { const e = new Error('denied'); e.name = 'SecurityError'; throw e; }
                return Object.prototype.hasOwnProperty.call(backing, k) ? backing[k] : null;
            },
            setItem(k, v) {
                if (throwOnWrite) { const e = new Error('quota'); e.name = 'QuotaExceededError'; throw e; }
                backing[k] = String(v);
            },
            removeItem(k) {
                if (throwOnWrite) { const e = new Error('quota'); e.name = 'QuotaExceededError'; throw e; }
                delete backing[k];
            },
        };

        const elements = {};
        const bodyEl = { firstChild: null, insertBefore(node) { bannerText = node.textContent; } };

        globalThis.window = {
            localStorage: localStorageStub,
            addEventListener(name, handler) { if (name === 'worksheet-draft-change') events++; },
            dispatchEvent() { events++; return true; },
        };
        globalThis.CustomEvent = class CustomEvent { constructor(name) { this.type = name; } };
        globalThis.document = {
            getElementById(id) { return elements[id] || null; },
            createElement() { return { style: {}, setAttribute() {}, textContent: '' }; },
            body: bodyEl,
        };

        const WORKSHEET_ID = 4242;

        function loadStore() {
            const factory = new Function('window', 'document', 'CustomEvent', 'WORKSHEET_ID',
                STORE_BODY + '\\n return DraftStore;');
            return factory(globalThis.window, globalThis.document, globalThis.CustomEvent, WORKSHEET_ID);
        }

        const out = {};

        // ── STATE 1: empty ──────────────────────────────────────────────
        let s = loadStore();
        out.empty = { count: s.count(), get: s.get('room-1-notes') };

        // ── STATE 2: holding ────────────────────────────────────────────
        const putOk = s.put('room-1-notes', 'cracked backbox behind the rack');
        let e = s.get('room-1-notes');
        out.holding = { putOk: putOk, value: e.value, sent: e.sent, queuedAt: e.queuedAt, count: s.count() };

        s.put('room-1-notes', 'cracked backbox behind the rack, plus a missing blank');
        out.lastWriteWins = { value: s.get('room-1-notes').value, count: s.count() };

        // ── STATE 2b: holding, after a reload ───────────────────────────
        s = loadStore();
        e = s.get('room-1-notes');
        out.afterReload = { value: e.value, sent: e.sent };

        // ── STATE 3: synced ─────────────────────────────────────────────
        const markSentOk = s.markSent('room-1-notes', 'cracked backbox behind the rack, plus a missing blank');
        out.synced = { markSentOk: markSentOk, count: s.count(), get: s.get('room-1-notes') };

        // ── STATE 3b: a stale acknowledgement ───────────────────────────
        s.put('room-2-notes', 'first pass');
        s.put('room-2-notes', 'second thoughts — it is the NEXT backbox');
        const staleOk = s.markSent('room-2-notes', 'first pass');
        // Read defensively: if the stale acknowledgement HAS destroyed the newer
        // draft, the crafted PHP assertion must be what reports it, not a crash.
        const staleEntry = s.get('room-2-notes');
        out.stale = {
            markSentOk: staleOk,
            value: staleEntry ? staleEntry.value : null,
            count: s.count(),
        };

        // ── STATE 4: refused by the server ──────────────────────────────
        backing = {};
        s = loadStore();
        s.put('room-3-notes', 'no isolator fitted');
        const refusedOk = s.markRefused('room-3-notes', 'This worksheet has been signed off and can no longer be changed.');
        e = s.get('room-3-notes');
        out.refused = {
            markRefusedOk: refusedOk,
            count: s.count(),
            refused: e.refused,
            refusedMessage: e.refusedMessage,
            sent: e.sent,
        };
        s = loadStore();
        out.refused.stillThereAfterReload = s.get('room-3-notes').refusedMessage;

        // ── STATE 5: the store throws ───────────────────────────────────
        backing = {};
        throwOnWrite = true;
        bannerText = null;
        s = loadStore();
        let threw = false;
        let probeOk, throwPutOk;
        try {
            probeOk = s.probe();
            throwPutOk = s.put('room-4-notes', 'two hundred words about a comms cupboard');
        } catch (err) {
            threw = true;
        }
        out.throwing = {
            probeOk: probeOk,
            putOk: throwPutOk,
            threw: threw,
            storageWorks: s.storageWorks,
            lastError: s.lastError,
            bannerText: bannerText,
        };

        throwOnWrite = false;
        throwOnRead  = true;
        s = loadStore();
        let readThrew = false;
        let readGet, readCount;
        try {
            readGet   = s.get('room-1-notes');
            readCount = s.count();
        } catch (err) {
            readThrew = true;
        }
        out.throwingRead = { threw: readThrew, get: readGet === undefined ? null : readGet, count: readCount };
        throwOnRead = false;

        // Subscription plumbing.
        s = loadStore();
        s.subscribe(function () {});
        out.events      = events;
        out.eventName   = 'worksheet-draft-change';
        out.sliceLength = STORE_BODY.length;

        process.stdout.write(JSON.stringify(out));
        JS;
    }
}
