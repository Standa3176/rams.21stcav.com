<?php

namespace Tests\Feature\Surveys;

use App\Models\Project;
use App\Models\SiteSurvey;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * 261011-tax — the survey engineer link's offline draft queue was a SEND queue
 * with no restore half.
 *
 * Reported from a real airplane-mode walk on 2026-10-11: "in airplane mode,
 * engineer can see the link if it was loaded before airplane mode, can fill in
 * the fields but loses everything if they accidentally refresh".
 *
 * The queue's own docblock says it was "Ported from the worksheet's DraftStore
 * ... BY SHAPE" — and only the write-and-drain half was ported. The worksheet's
 * _notesInit (resources/views/worksheets/public-show.blade.php) does three
 * things this view did not:
 *
 *   1. puts a held draft BACK INTO THE FIELD on load,
 *   2. drains on load, not only on the 'online' event,
 *   3. retries on a timer.
 *
 * Missing (2) is the severe one and is NOT what the engineer reported: refresh
 * offline, close the tab, reopen ALREADY ONLINE and no 'online' event ever
 * fires, so the draft never sends at all. It sits in localStorage until it is
 * overwritten or cleared.
 *
 * WHAT THIS TEST CAN AND CANNOT PROVE
 *
 * It CANNOT prove an engineer keeps their typing. PHPUnit has no JavaScript
 * runtime, no Alpine, no localStorage and no layout engine; nothing in this
 * file executes a reload, a restore, or a step-save fetch. All that is asserted
 * below is the SOURCE CONTRACT — that the view ships a restore, a drain on
 * load, and a retry timer; that the restore inverts buildStepPayload explicitly
 * per step for the steps that carry data; that it only writes keys a draft
 * actually carries; that it lands before the watch registrations so it cannot
 * trigger its own autosave storm; and that the stored entry shape and key are
 * untouched. That an engineer's typing actually comes back after an offline
 * reload, and that the held draft actually reaches the server on a cold online
 * load, was measured separately in real headless Chrome (puppeteer) — and that
 * measurement, not this file, is the proof the defect is fixed. See this quick
 * task's SUMMARY.
 *
 * D-01 IS A HARD CONSTRAINT WITH A TEST OF ITS OWN: engineers may have real
 * queued drafts in localStorage right now. The entry shape
 * { room_index, step, data, queuedAt } keyed "<roomIndex>:<step>" under
 * svDraft_<SURVEY_ID> must not change, and there must be no migration of it.
 *
 * WHICH VIEW THIS IS — the same two-file trap the sibling 46.6 tests guard:
 * GET survey/{token} resolves to SurveyController show, rendering
 * resources/views/surveys/show.blade.php. The dead
 * resources/views/public-survey/show.blade.php has no GET route and is never
 * touched here.
 */
class SurveyDraftRestoreTest extends TestCase
{
    use RefreshDatabase;

    private function rawSource(): string
    {
        return file_get_contents(resource_path('views/surveys/show.blade.php'));
    }

    private function renderedPage(): string
    {
        $user    = User::factory()->create();
        $token   = (string) Str::uuid();
        $project = Project::factory()->create([
            'name'         => 'Draft Restore Fixture',
            'site_address' => '2 Draft Restore Road, Leeds',
        ]);

        $survey = SiteSurvey::create([
            'user_id'      => $user->id,
            'project_id'   => $project->id,
            'project_name' => 'Draft Restore Fixture',
            'site_address' => '2 Draft Restore Road, Leeds',
            'status'       => 'draft',
        ]);
        $survey->forceFill(['access_token' => $token])->save();

        foreach (['Board Room', 'Huddle 1'] as $i => $name) {
            $survey->rooms()->create([
                'room_name'  => $name,
                'space_type' => 'general',
                'sort_order' => $i,
            ]);
        }

        return $this->get(route('survey.show', ['token' => $token]))
            ->assertOk()
            ->getContent();
    }

    /**
     * The body of the Alpine component's init(), from its opening brace to the
     * matching close, found by brace depth rather than by eye.
     */
    private function initBody(string $source): string
    {
        $start = strpos($source, 'init() {');

        $this->assertNotFalse(
            $start,
            'The Alpine component no longer has an init() — every ordering assertion below '
            .'would be vacuous.'
        );

        $open  = strpos($source, '{', $start);
        $depth = 0;
        $len   = strlen($source);

        for ($i = $open; $i < $len; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open + 1, $i - $open - 1);
                }
            }
        }

        $this->fail('init() is not brace-balanced — the view will not parse.');
    }

    /**
     * The body of a named component method, same mechanical brace walk.
     */
    private function methodBody(string $source, string $signature): string
    {
        $start = strpos($source, $signature);

        $this->assertNotFalse(
            $start,
            "The component method `{$signature}` is missing from surveys/show.blade.php."
        );

        $open  = strpos($source, '{', $start);
        $depth = 0;
        $len   = strlen($source);

        for ($i = $open; $i < $len; $i++) {
            if ($source[$i] === '{') {
                $depth++;
            } elseif ($source[$i] === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $open + 1, $i - $open - 1);
                }
            }
        }

        $this->fail("`{$signature}` is not brace-balanced.");
    }

    // -- 1. NON-VACUITY ------------------------------------------------------

    public function test_the_view_really_does_ship_the_offline_draft_queue(): void
    {
        $source = $this->rawSource();

        $this->assertStringContainsString(
            "'svDraft_' + SURVEY_ID",
            $source,
            'The localStorage draft map is gone — every assertion below would be vacuous.'
        );
        $this->assertStringContainsString('_draftPut(', $source);
        $this->assertStringContainsString('_draftReadAll(', $source);
        $this->assertStringContainsString('_draftDrain(', $source);
        $this->assertStringContainsString(
            'buildStepPayload(step)',
            $source,
            'There is nothing left to invert — buildStepPayload is the thing a restore undoes.'
        );
    }

    // -- 2. D-01: THE STORED SHAPE AND KEY ARE UNTOUCHED ---------------------

    public function test_the_stored_draft_entry_shape_and_key_are_unchanged(): void
    {
        $source = $this->rawSource();

        $this->assertStringContainsString(
            "{ room_index: roomIndex, step, data, queuedAt: Date.now() }",
            $source,
            'D-01: the stored entry shape must stay { room_index, step, data, queuedAt }. '
            .'Engineers may have real queued drafts in localStorage right now; changing the '
            .'shape strands them.'
        );

        $this->assertStringContainsString(
            "roomIndex + ':' + step",
            $source,
            'D-01: the entry key must stay "<roomIndex>:<step>".'
        );

        foreach (['migrateDraft', 'draftSchemaVersion', '_draftMigrate'] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                'D-01 forbids a migration of the stored drafts. Build behaviour around the '
                .'existing shape instead.'
            );
        }
    }

    public function test_the_draft_queue_still_never_opens_the_frozen_photo_database(): void
    {
        $source = $this->rawSource();

        foreach (['indexedDB.open', 'window.indexedDB', 'IDBKeyRange', 'openDatabase('] as $forbidden) {
            $this->assertStringNotContainsString(
                $forbidden,
                $source,
                'The draft queue is localStorage only. The shared engineer offline-photo store '
                .'is schema-frozen at v1 and holds real unsent photos on engineers\' phones; '
                .'this view must never open it.'
            );
        }
    }

    // -- 3. THE RESTORE HALF EXISTS ------------------------------------------

    public function test_init_restores_held_drafts(): void
    {
        $init = $this->initBody($this->rawSource());

        $this->assertStringContainsString(
            '_draftRestoreAll()',
            $init,
            'init() does not restore held drafts. A draft held from the LAST session is newer '
            .'than whatever the server rendered — it was typed after that render — so it has to '
            .'go back into the field. Without this, an offline refresh looks like it lost '
            .'everything (the reported defect).'
        );
    }

    public function test_the_restore_lands_before_the_watch_registrations(): void
    {
        $init = $this->initBody($this->rawSource());

        $restoreAt = strpos($init, '_draftRestoreAll()');
        $firstWatch = strpos($init, '$watch(');

        $this->assertNotFalse($restoreAt, 'init() does not restore held drafts.');
        $this->assertNotFalse(
            $firstWatch,
            'init() registers no watches at all — this ordering assertion would be vacuous.'
        );

        $this->assertLessThan(
            $firstWatch,
            $restoreAt,
            'D-03: the restore must run BEFORE any watch is registered, or writing the held '
            .'values back into the model triggers the very autosaves it is recovering from — '
            .'an autosave storm on every load.'
        );
    }

    public function test_the_restore_inverts_build_step_payload_explicitly_per_step(): void
    {
        $body = $this->methodBody($this->rawSource(), '_draftApplyToRoom(');

        // Every key buildStepPayload sends for the steps that carry data must
        // be named on the way back in. The payload is NOT the room shape, so
        // the inverse has to be written out.
        $keys = [
            // step 1
            'name', 'type', 'work_type',
            // step 2
            'quick_notes', 'power_available', 'network_available',
            'access_issues', 'access_issues_notes', 'client_present',
            // step 4
            'infrastructure', 'engineer_feedback',
            // step 5
            'equipment', 'additional_items',
            // step 6
            'working_at_height', 'risks',
        ];

        foreach ($keys as $key) {
            $this->assertStringContainsString(
                "'".$key."'",
                $body,
                "The restore does not name the payload key `{$key}`, so a draft carrying it is "
                .'read back as nothing and the engineer\'s typing stays invisible.'
            );
        }

        foreach ([1, 2, 4, 5, 6] as $step) {
            $this->assertStringContainsString(
                'case '.$step.':',
                $body,
                "Step {$step} carries data and must have an explicit inverse mapping."
            );
        }
    }

    public function test_steps_with_nothing_to_restore_are_explicit_no_ops(): void
    {
        $body = $this->methodBody($this->rawSource(), '_draftApplyToRoom(');

        foreach ([3, 7, 8] as $step) {
            $this->assertStringContainsString(
                'case '.$step.':',
                $body,
                "Step {$step} has nothing to restore (3 is photos; 7 and 8 send an empty "
                .'payload), but it must be named explicitly so the omission is a decision '
                .'rather than an oversight.'
            );
        }
    }

    public function test_the_restore_only_writes_keys_the_draft_actually_carries(): void
    {
        $body = $this->methodBody($this->rawSource(), '_draftApplyToRoom(');

        $this->assertStringContainsString(
            'hasOwnProperty',
            $body,
            'The restore must only write keys the draft actually carries. A blanket assignment '
            .'clobbers a server-rendered field the draft never mentioned.'
        );
    }

    // -- 4. D-04: THE ENGINEER IS TOLD, IN THE PAGE'S EXISTING WORDS ---------

    public function test_a_restore_tells_the_engineer_using_the_pages_existing_wording(): void
    {
        $source = $this->rawSource();
        $body   = $this->methodBody($source, '_draftRestoreAll() {');

        $this->assertStringContainsString(
            'Needs a connection',
            $body,
            'D-04: a silent restore is nearly as bad as a silent loss — the engineer has to '
            .'know the values on screen are their unsent work, not the server\'s. Reuse the '
            .'page\'s existing saveError ribbon wording; invent no new visual language.'
        );

        $this->assertStringContainsString(
            'x-text="saveError"',
            $source,
            'The existing saveError ribbon is the channel this reuses; it must still be there.'
        );
    }

    // -- 5. D-05: DRAIN ON LOAD, AND A RETRY TIMER ---------------------------

    public function test_the_queue_drains_on_load_not_only_on_the_online_event(): void
    {
        $init = $this->initBody($this->rawSource());

        $this->assertMatchesRegularExpression(
            // [ \t\r] not [ \t]: this view is stored with CRLF line endings,
            // so the carriage return sits between the semicolon and the $.
            '/^[ \t]*this\._draftDrain\(\);[ \t\r]*$/m',
            $init,
            'init() has no bare drain-on-load. Without one, an engineer who refreshes offline, '
            .'closes the tab and reopens ALREADY ONLINE fires no \'online\' event, so the held '
            .'draft never sends — silent permanent loss, and the severe half of this defect.'
        );

        $this->assertStringContainsString(
            "addEventListener('online'",
            $init,
            'The drain on reconnect must stay — drain-on-load is an addition, not a replacement.'
        );
    }

    public function test_a_retry_timer_keeps_trying(): void
    {
        $source = $this->rawSource();
        $init   = $this->initBody($source);

        $this->assertStringContainsString(
            'SV_DRAFT_RETRY_MS',
            $source,
            'The retry interval must be a named constant, the way the worksheet\'s '
            .'NOTES_RETRY_MS is.'
        );

        $this->assertStringContainsString(
            'setInterval(',
            $init,
            'init() registers no retry timer. The worksheet has one; a drain that only happens '
            .'on an \'online\' event misses every case where the event never fires.'
        );

        $timer = substr($init, (int) strpos($init, 'setInterval('));

        $this->assertStringContainsString(
            '_draftDrain()',
            substr($timer, 0, 400),
            'The retry timer must actually drain.'
        );
        $this->assertStringContainsString(
            'navigator.onLine',
            substr($timer, 0, 400),
            'The retry timer must not hammer a request every interval while plainly offline — '
            .'the worksheet guards the same way.'
        );
    }

    public function test_the_restore_and_the_drain_respect_readonly_and_the_storage_probe(): void
    {
        $restore = $this->methodBody($this->rawSource(), '_draftRestoreAll() {');

        $this->assertStringContainsString(
            'this.readonly',
            $restore,
            'A superseded or locked survey must not have drafts written back into it.'
        );
        $this->assertStringContainsString(
            '_draftProbe()',
            $restore,
            'The restore must go through the same storage probe the rest of the queue uses, so '
            .'a private-browsing or quota-full phone degrades instead of throwing.'
        );
    }

    // -- 6. WHAT ACTUALLY SHIPS TO THE BROWSER -------------------------------

    public function test_the_page_served_to_an_engineer_ships_the_restore_half(): void
    {
        $html = $this->renderedPage();

        $this->assertStringContainsString('_draftRestoreAll()', $html);
        $this->assertStringContainsString('_draftApplyToRoom(', $html);
        $this->assertStringContainsString('SV_DRAFT_RETRY_MS', $html);
        $this->assertMatchesRegularExpression(
            // [ \t\r] not [ \t]: this view is stored with CRLF line endings,
            // so the carriage return sits between the semicolon and the $.
            '/^[ \t]*this\._draftDrain\(\);[ \t\r]*$/m',
            $html,
            'The page actually served to an engineer does not drain on load.'
        );
    }

    // -- 7. TODAY'S TWO SHIPPED FIXES MUST STILL BE THERE --------------------

    public function test_todays_watch_repair_and_room_panel_guard_are_not_regressed(): void
    {
        $source = $this->rawSource();

        $this->assertSame(
            2,
            substr_count($source, 'rooms[${idx}]._ui.${f}'),
            '261011-lvf: both per-room _ui watches must still use bracket notation.'
        );
        $this->assertStringContainsString(
            'rooms[${idx}].engineer_feedback',
            $source,
            '261011-lvf: the deep per-room watch must still use bracket notation.'
        );
        $this->assertStringContainsString(
            '<template x-if="activeTab !== \'site\' && currentRoomIdx !== null">',
            $source,
            '261011-n91: the room panel must still be guarded by a template x-if.'
        );
        $this->assertSame(
            0,
            substr_count($source, '{!!'),
            'This view has zero unescaped echoes and must keep zero.'
        );
        $this->assertSame(
            15,
            substr_count($source, 'sv-spine'),
            'The sv-spine count is pinned by two sibling suites. If it moves, STOP.'
        );
    }
}
