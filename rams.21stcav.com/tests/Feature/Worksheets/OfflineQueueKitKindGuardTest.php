<?php

namespace Tests\Feature\Worksheets;

use Tests\TestCase;

/**
 * Phase 46.4 Plan 06 — THE OFFLINE QUEUE'S THIRD KIND, PINNED AT THE SOURCE.
 *
 * ── ⚠️ READ THIS BEFORE YOU TRUST THIS FILE ─────────────────────────────────
 *
 * **THESE TESTS DO NOT EXERCISE INDEXEDDB. THEY CANNOT.**
 *
 * Two rows actually sitting in a real browser's store — one photo, one kit row
 * — and BOTH draining when signal returns is a **browser** fact. This repo has
 * no browser-driving harness: `puppeteer` is a dependency, but `PdfRenderService`
 * uses it to render HTML to PDF, not to drive a live application (see
 * `tests/Feature/Rams/RamsRenderRegressionTest.php`). Nothing here opens a
 * browser, nothing here runs a line of the page's JavaScript.
 *
 * **The real coexistence proof is step 3 of plan 46.4-07's blocking human
 * checkpoint:** airplane mode on a phone, one photo and one kit row queued,
 * signal restored, both arrive. That walk is the proof. This file exists to
 * make that walk's CODE-LEVEL PRECONDITIONS non-negotiable, so a later refactor
 * cannot quietly undo them between now and then.
 *
 * A guard test that implies more than it checks is worse than no test, so the
 * limit is stated here and repeated in the plan's SUMMARY.
 *
 * ── WHAT IS ACTUALLY PINNED ─────────────────────────────────────────────────
 *
 * 1. **THE SCHEMA CANNOT MOVE.** DB version 1, keyPath `id`, one
 *    `capturedAt` index, one `createObjectStore`. `onupgradeneeded` only ever
 *    CREATES — it has no migration branch — so a version bump or a keyPath
 *    change would strand every photo already queued on every engineer's phone.
 *    The queue's own record is the engineer's ONLY copy of that work.
 *
 * 2. **THE BRANCH ORDERING.** The `isBinary` guard's source position must be
 *    BEFORE the first `fd.append('photo'`. This is THE assertion that encodes
 *    the whole plan: a blobless kit row reaching that append is stamped
 *    'Local blob unreadable' permanently, and the engineer's work is gone.
 *
 * 3. **THE RULING LIVES AT THE CODE.** A ruling that lives only in a plan file
 *    is a ruling nobody will find.
 *
 * @see resources/views/worksheets/public-show.blade.php
 * @see .planning/phases/46.4-engineer-link-install-capture/46.4-06-PLAN.md
 */
class OfflineQueueKitKindGuardTest extends TestCase
{
    private const VIEW = 'resources/views/worksheets/public-show.blade.php';

    private function source(): string
    {
        $path = base_path(self::VIEW);

        $this->assertFileExists($path, 'The engineer link view is gone — every guard below would pass vacuously.');

        $source = (string) file_get_contents($path);

        // NON-VACUITY: if the queue module itself were deleted or renamed, an
        // "it does not contain X" guard would pass for the wrong reason.
        $this->assertStringContainsString(
            'OfflineQueue.drain = function',
            $source,
            'The OfflineQueue module is not in this file any more — these guards are measuring nothing.',
        );

        return $source;
    }

    /**
     * The slice of the file between drain() and the next public member, so a
     * `/photos` literal somewhere else on the page cannot satisfy a drain guard.
     */
    private function drainSlice(string $source): string
    {
        $start = strpos($source, 'OfflineQueue.drain = function');
        $end   = strpos($source, 'OfflineQueue._notifyChange = function', (int) $start);

        $this->assertIsInt($start);
        $this->assertIsInt($end);
        $this->assertGreaterThan($start, $end);

        return substr($source, (int) $start, (int) $end - (int) $start);
    }

    /** The queue panel's IIFE — the chip + expandable list at the bottom of the page. */
    private function panelSlice(string $source): string
    {
        $start = strpos($source, 'Pending-uploads chip + panel UI controller');
        $this->assertIsInt($start, 'The queue panel IIFE marker is gone — the naming guards below would be vacuous.');

        return substr($source, (int) $start);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  GUARD 1 — THE SCHEMA IS FROZEN
    // ═══════════════════════════════════════════════════════════════════════

    public function test_the_indexeddb_version_is_still_one(): void
    {
        $source = $this->source();

        $this->assertStringContainsString('const DB_VERSION = 1;', $source);

        // ⚠️ THE ABSENCE IS THE ASSERTION. `onupgradeneeded` only ever creates
        // the store; there is no migration branch. A second assignment ANYWHERE
        // means somebody bumped the version, and a bump strands every photo
        // already queued on an engineer's phone.
        $assignments = preg_match_all('/DB_VERSION\s*=\s*/', $source);

        $this->assertSame(
            1,
            $assignments,
            'DB_VERSION is assigned more than once. onupgradeneeded NEVER migrates — a version bump '
            . 'strands every pending photo on every engineer\'s phone.',
        );
    }

    public function test_the_store_definition_is_untouched(): void
    {
        $source = $this->source();

        $this->assertSame(1, substr_count($source, 'createObjectStore'));
        $this->assertSame(1, substr_count($source, "keyPath: 'id'"));
        $this->assertSame(1, substr_count($source, "createIndex('capturedAt'"));

        $this->assertStringContainsString("const DB_NAME    = 'engineer-worksheet';", $source);
        $this->assertStringContainsString("const STORE      = 'pending_uploads';", $source);
        $this->assertStringContainsString('autoIncrement: true', $source);
    }

    public function test_the_record_shape_gained_no_field(): void
    {
        $source = $this->source();

        $start = strpos($source, 'const record = {');
        $this->assertIsInt($start, 'enqueue() no longer builds a record literal — this guard is vacuous.');

        $record = substr($source, (int) $start, 700);
        $record = substr($record, 0, (int) strpos($record, '};') + 2);

        // Exactly the nine keys the store has carried since version 1. A tenth
        // is a schema field in all but name, and update() must never add one.
        $expected = ['token:', 'kind:', 'room:', 'blob:', 'mime:', 'fields:', 'attemptCount:', 'lastError:', 'capturedAt:'];

        foreach ($expected as $key) {
            $this->assertStringContainsString($key, $record, "The record literal lost `{$key}`.");
        }

        $this->assertSame(
            count($expected),
            preg_match_all('/^\s{20}[a-zA-Z]+:/m', $record),
            'The queued record gained or lost a field. The store is frozen at version 1 and '
            . 'onupgradeneeded never migrates — a record-shape change is a schema change.',
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  GUARD 2 — THE BRANCH ORDERING. THIS ONE ENCODES THE WHOLE PLAN.
    // ═══════════════════════════════════════════════════════════════════════

    public function test_the_is_binary_guard_sits_before_the_blob_append(): void
    {
        $drain = $this->drainSlice($this->source());

        $guardAt  = strpos($drain, "const isBinary = row.kind !== 'kit';");
        $appendAt = strpos($drain, "fd.append('photo'");

        // NON-VACUITY FIRST: both must exist, or `false < 123` would "pass".
        $this->assertIsInt($guardAt, 'The isBinary guard is gone from drain(). A blobless kit row now hits the blob append.');
        $this->assertIsInt($appendAt, 'The blob append is gone from drain() — this guard is measuring nothing.');

        // ⚠️ THE ASSERTION THIS FILE EXISTS FOR. Compared by SOURCE POSITION,
        // not by eyeballing. A kit row has no blob; reaching that append marks
        // it 'Local blob unreadable' FOREVER and the engineer's work is lost on
        // the one surface used by somebody standing in a plant room.
        $this->assertLessThan(
            $appendAt,
            $guardAt,
            "The isBinary guard is no longer ABOVE fd.append('photo'). A blobless kit row will now be "
            . "stamped unreadable forever. This ordering is the entire point of plan 46.4-06.",
        );

        // And the append is genuinely INSIDE the guard, not merely after it.
        $this->assertMatchesRegularExpression(
            "/const isBinary = row\.kind !== 'kit';\s*\n\s*if \(isBinary\) \{/",
            $drain,
            'The isBinary guard no longer opens a block — the append may be running unconditionally.',
        );
    }

    public function test_the_photo_failure_path_was_not_collaterally_edited(): void
    {
        $source = $this->source();

        // Exactly once. Plan 06 wrapped this handler; it did not review it, and
        // a second occurrence usually means a copy-paste of the whole branch.
        $this->assertSame(
            1,
            substr_count($source, 'Local blob unreadable'),
            "'Local blob unreadable' should appear exactly once — the photo path's own failure handling.",
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  GUARD 3 — THREE ARMS, ONE PER KIND
    // ═══════════════════════════════════════════════════════════════════════

    public function test_drain_routes_all_three_kinds(): void
    {
        $drain = $this->drainSlice($this->source());

        $this->assertStringContainsString("'/label-photo'", $drain);
        $this->assertStringContainsString("'/additional-kit'", $drain);
        $this->assertStringContainsString("'/photos'", $drain);

        // The kit arm must key off the kind, not off "no blob" — a future
        // binary kind must not silently inherit the kit endpoint.
        $this->assertStringContainsString("row.kind === 'kit'   ? '/additional-kit'", $drain);
    }

    public function test_drain_prefers_the_servers_own_sentence_over_the_status_text(): void
    {
        $drain = $this->drainSlice($this->source());

        $this->assertStringContainsString('(body && body.message)', $drain);
        $this->assertStringContainsString('|| resp.statusText', $drain);

        // The counters and the retry threshold are NOT part of that change.
        $this->assertSame(3, substr_count($drain, 'if (row.attemptCount >= 3) hitMaxRetry++;'));
        $this->assertSame(3, substr_count($drain, 'row.attemptCount = (row.attemptCount || 0) + 1;'));
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  GUARD 4 — THE PANEL NAMES A KIT ROW INSTEAD OF CALLING IT A PHOTO
    // ═══════════════════════════════════════════════════════════════════════

    public function test_the_queue_panel_knows_about_the_kit_kind(): void
    {
        $panel = $this->panelSlice($this->source());

        // The icon table — keyed by kind with a default, so a fourth kind is a
        // line in an object literal rather than another ternary arm.
        $this->assertStringContainsString('const KIND_ICONS = {', $panel);
        $this->assertMatchesRegularExpression('/kit:\s+\'/', $panel);
        $this->assertStringContainsString('return KIND_ICONS[kind] ||', $panel);

        // The subtitle. Without this a kit row renders as 'Completed-work photo'.
        $this->assertStringContainsString("if (row.kind === 'kit') {", $panel);
        $this->assertStringContainsString("'Additional kit ", $panel);

        // renderList must actually USE it — otherwise the branch is dead code.
        $renderAt   = strpos($panel, 'function renderList(items) {');
        $subtitleAt = strpos($panel, '+       _subtitle(row)');

        $this->assertIsInt($renderAt, 'renderList is gone — this guard is vacuous.');
        $this->assertIsInt($subtitleAt, 'renderList no longer calls _subtitle — a kit row will render as a photo.');
        $this->assertGreaterThan($renderAt, $subtitleAt);
    }

    public function test_the_description_is_truncated_before_it_is_escaped(): void
    {
        $panel = $this->panelSlice($this->source());

        // ⚠️ ORDER MATTERS. Truncating already-escaped markup can cut an
        // `&amp;` in half, and the fragment then renders raw. The nesting IS
        // the assertion: _esc(_trunc(...)), never _trunc(_esc(...)).
        $this->assertStringContainsString('_esc(_trunc(', $panel);
        $this->assertStringNotContainsString('_trunc(_esc(', $panel);
    }

    // ═══════════════════════════════════════════════════════════════════════
    //  GUARD 5 — THE OFFLINE RULING IS AT THE CODE, NOT IN A PLAN FILE
    // ═══════════════════════════════════════════════════════════════════════

    public function test_the_offline_ruling_is_written_at_the_code(): void
    {
        $source = $this->source();

        // What a SERVER row's controls say when there is no signal. Plan 05
        // put this on screen; plan 06 is what makes it a ruling rather than a
        // limitation nobody explained.
        $this->assertStringContainsString('Needs a connection', $source);

        // The reasoning itself, at the code. A pointer to a plan file is not a
        // ruling — the plan file will not be open when somebody next wonders.
        $this->assertStringContainsString('WHAT QUEUES OFFLINE, AND WHAT DOES NOT', $source);
        $this->assertStringContainsString('A QUEUED ADD HAS NO SERVER ID', $source);
        $this->assertStringContainsString('CORRECTING A QUEUED ROW NEEDS NO SERVER', $source);
        $this->assertStringContainsString('THE ENGINEER IS ALWAYS TOLD WHICH CASE THEY ARE IN', $source);
        $this->assertStringContainsString('SILENTLY', $source);
    }

    public function test_only_add_is_queued_and_a_queued_row_can_be_fixed_on_the_device(): void
    {
        $source = $this->source();

        // ADD queues — kind 'kit', no blob.
        $this->assertStringContainsString("kind:  'kit',", $source);
        $this->assertStringContainsString('blob:  undefined,', $source);

        // A queued row's own controls. Edit and Discard, NOT Correct and
        // Mark for deletion: nothing reached the office, so there is nothing
        // to amend and no reason to record (this is not a D-08 mark).
        $this->assertStringContainsString('data-kit-edit', $source);
        $this->assertStringContainsString('data-kit-discard', $source);
        $this->assertStringContainsString('Discard this item?', $source);

        // update() is additive and refuses a row that is already the server's.
        $this->assertStringContainsString('OfflineQueue.update = function (id, patch)', $source);
        $this->assertStringContainsString("OfflineQueue._uploadingIds.has(id)", $source);

        // And the one unacceptable outcome is closed off: an edit that cannot
        // land must SAY so.
        $this->assertStringContainsString('has already uploaded', $source);
    }

    public function test_the_page_still_loads_no_framework_and_leaks_no_raw_echo(): void
    {
        $source = $this->source();

        // Carried from 46.4-05 and re-measured here, because plan 06 edited the
        // same file: engineer-typed part descriptions are unauthenticated input
        // on a page the CLIENT SIGNS.
        $this->assertSame(1, substr_count($source, '{!!'));
        $this->assertStringContainsString('{!! $skipRestoreAttr !!}', $source);
    }
}
