<?php

namespace Tests\Unit;

use Carbon\Carbon;
use Tests\TestCase;

/**
 * Unit tests for RAMS PDF room_overviews rendering.
 *
 * Locks the fix for the duplicated-heading artifact where
 * reviewed_data['room_overviews'][n]['overview'] begins with a line that
 * repeats the room name — producing output like:
 *
 *   **VC Room (22) - Primary Left:** VC Room (22) - Primary Left VC Room 22
 *   (Left on plan/Right as entering) 65" Samsung dual with wall mount...
 *
 * where the first line of the paragraph body duplicates the bold heading.
 */
class RamsPdfRoomOverviewsTest extends TestCase
{
    /**
     * Minimal $rams stub that the PDF blade view reads from.
     * `reviewed_data` is the key field for this test — it carries
     * room_overviews.
     */
    private function ramsStub(array $roomOverviews): object
    {
        $stub                = new \stdClass();
        $stub->project_name  = 'Test Project';
        $stub->project_ref   = 'TEST-001';
        $stub->form_data     = [];
        $stub->client_name   = 'Test Client';
        $stub->site_address  = 'Test Site Address';
        $stub->created_at    = Carbon::create(2026, 4, 1);
        $stub->reviewed_data = ['room_overviews' => $roomOverviews];

        return $stub;
    }

    private function renderWithOverviews(array $roomOverviews): string
    {
        $data = [
            'scope_of_works'  => 'Works scope placeholder.',
            'project'         => [
                'name'         => 'Test Project',
                'ref'          => 'TEST-001',
                'client'       => 'Test Client',
                'site_address' => 'Test Site Address',
            ],
            'hazards'          => [],
            'ppe'              => [],
            'persons_at_risk'  => [],
            'team'             => [],
            'method_statement' => ['phases' => []],
            'quote'            => [],
            'site_logistics'   => [],
        ];

        return view('pdf.rams', [
            'data' => $data,
            'rams' => $this->ramsStub($roomOverviews),
        ])->render();
    }

    public function test_duplicate_room_name_first_line_is_stripped_from_overview(): void
    {
        $html = $this->renderWithOverviews([
            [
                'room'     => 'VC Room (22) - Primary Left',
                'overview' => "VC Room (22) - Primary Left\nVC Room 22 (Left on plan/Right as entering)\n65\" Samsung dual with wall mount",
            ],
        ]);

        // Extract the paragraph that holds this room's overview.
        preg_match('/<p class="body-para"><strong>VC Room \(22\) - Primary Left:<\/strong>([^<]*)/u', $html, $m);
        $this->assertNotEmpty($m, 'Expected the VC Room heading paragraph in rendered HTML.');

        $body = html_entity_decode(trim($m[1] ?? ''));

        // Heading renders once via <strong>; the body must NOT begin with the
        // same room name line (that would be the bug).
        $this->assertStringNotContainsString(
            'VC Room (22) - Primary Left VC Room 22',
            $body,
            'Room name was duplicated into the body text — the first-line strip did not fire.'
        );
        // The next, genuinely user-authored line should lead the body.
        $this->assertStringContainsString(
            'VC Room 22 (Left on plan/Right as entering)',
            $body,
            'User-authored second line must be preserved as the new first line.'
        );
    }

    public function test_strip_is_punctuation_insensitive_for_unclosed_parens(): void
    {
        // Storage drift: room name is saved without a closing paren, but the
        // user typed the overview with the paren closed. The canonicaliser
        // (strip non-alphanumerics) must still match so the strip fires.
        $html = $this->renderWithOverviews([
            [
                'room'     => 'Conference room (23) - Secondary (Right',
                'overview' => "Conference room (23) - Secondary (Right)\nConference Room 23\n75\" display using existing unicol floor mount",
            ],
        ]);

        preg_match('/<p class="body-para"><strong>Conference room \(23\) - Secondary \(Right:<\/strong>([^<]*)/u', $html, $m);
        $this->assertNotEmpty($m, 'Expected the Conference room heading paragraph.');
        $body = html_entity_decode(trim($m[1] ?? ''));

        $this->assertStringNotContainsString(
            'Conference room (23) - Secondary (Right)',
            $body,
            'Duplicate room name (with closing paren) should be stripped even when stored name has no closing paren.'
        );
        $this->assertStringContainsString('Conference Room 23', $body);
    }

    public function test_overview_without_duplicate_is_untouched(): void
    {
        // No leading room-name line — body should render verbatim.
        $html = $this->renderWithOverviews([
            [
                'room'     => 'Breakout Area',
                'overview' => "Remove existing 65 display and pendant speakers\n9 new pendant speakers",
            ],
        ]);

        preg_match('/<p class="body-para"><strong>Breakout Area:<\/strong>([^<]*)/u', $html, $m);
        $body = html_entity_decode(trim($m[1] ?? ''));

        $this->assertStringStartsWith('Remove existing 65 display', ltrim($body));
    }

    public function test_single_line_overview_is_untouched_even_if_it_matches_name(): void
    {
        // Edge case: the "strip first line if it matches" logic MUST NOT fire
        // when the overview is just a single line — that would blank out a
        // legitimate one-line description. Guarded by the `count($lines) >= 2`
        // check.
        $html = $this->renderWithOverviews([
            [
                'room'     => 'Reception',
                'overview' => 'Reception',
            ],
        ]);

        preg_match('/<p class="body-para"><strong>Reception:<\/strong>([^<]*)/u', $html, $m);
        $body = html_entity_decode(trim($m[1] ?? ''));

        $this->assertSame('Reception', $body, 'Single-line overview must render as-is.');
    }
}
