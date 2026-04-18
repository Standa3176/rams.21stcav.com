<?php

namespace Tests\Feature\Worksheet;

use App\Services\Worksheet\WorksheetTextNormalizer;
use App\Services\WorksheetGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Regression tests for the pre-install-answer keying bug.
 *
 * Before the fix:
 *   - survey side built keys from raw room labels:          "meeting room (ground floor"
 *   - generator side looked up using normaliser output:     "meeting room (ground floor)"
 *   - apostrophe variance had the same problem:             "comm\u{2019}s room" vs "comm's room"
 *   Result: lookup missed, pre_install_answers vanished, blocker promotion skipped.
 *
 * After the fix: both sides route through WorksheetGeneratorService::canonicalRoomKey()
 * which normalises + lowercases + strips trailing close-paren so the keys always agree.
 *
 * Tests invoke the private helper via reflection — the lookup contract is what matters;
 * we don't need to spin the full generator pipeline.
 */
class WorksheetPreInstallKeyingTest extends TestCase
{
    use RefreshDatabase;

    private function canonicalKey(string $name): string
    {
        $svc = app(WorksheetGeneratorService::class);
        $method = new ReflectionMethod($svc, 'canonicalRoomKey');
        $method->setAccessible(true);
        return $method->invoke($svc, $name);
    }

    public function test_survey_label_without_close_paren_matches_normalised_room(): void
    {
        // Survey arrived with unmatched "(". Normaliser added ")" for display.
        $surveySide  = $this->canonicalKey('Meeting Room (Ground Floor');
        $generatorSide = $this->canonicalKey(
            app(WorksheetTextNormalizer::class)->normalize('Meeting Room (Ground Floor'),
        );
        $this->assertSame($surveySide, $generatorSide);
        $this->assertNotSame('', $surveySide);
    }

    public function test_curly_and_straight_apostrophe_collide_to_same_key(): void
    {
        $curly    = $this->canonicalKey("Comm\u{2019}s Room");
        $straight = $this->canonicalKey("Comm's Room");
        $this->assertSame($curly, $straight);
    }

    public function test_case_and_whitespace_variance_collide(): void
    {
        $a = $this->canonicalKey("  Boardroom  ");
        $b = $this->canonicalKey("BOARDROOM");
        $c = $this->canonicalKey("boardroom");
        $this->assertSame($a, $b);
        $this->assertSame($b, $c);
    }

    public function test_lookup_round_trip_pre_fix_would_miss_post_fix_hits(): void
    {
        // Simulate the full flow: build answers dict from the survey label,
        // look it up using the normaliser-produced label.
        $surveyLabel    = 'Meeting Room (Ground Floor';
        $generatorLabel = app(WorksheetTextNormalizer::class)->normalize($surveyLabel);

        $preInstall = [
            $this->canonicalKey($surveyLabel) => [
                ['question' => 'Power outlets available at display wall?', 'answer' => 'No'],
            ],
        ];

        // Generator-side lookup must find the answers.
        $this->assertNotEmpty($preInstall[$this->canonicalKey($generatorLabel)] ?? []);
    }

    public function test_blocker_promoter_can_promote_via_canonicalised_room_key(): void
    {
        // End-to-end integration of the fix: answers keyed via canonicalKey
        // flow into the promoter and produce a typed blocker.
        $promoter = app(\App\Services\Worksheet\BlockerPromoter::class);

        $answers = [
            $this->canonicalKey('Meeting Room (Ground Floor') => [
                ['question' => 'Power outlets available at display wall?', 'answer' => 'No'],
            ],
        ];

        $blockers = $promoter->promoteFromAnswers($answers);
        $this->assertNotEmpty($blockers, 'BlockerPromoter must see answers when keys agree');
        $this->assertSame('power', $blockers[0]['type']);
    }
}
