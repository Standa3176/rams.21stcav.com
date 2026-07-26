<?php

namespace Tests\Unit\Services;

use App\Core\AI\Contracts\AIProviderContract;
use App\Core\AI\Providers\ClaudeProvider;
use App\Services\AICacheService;
use App\Services\RoomOverviewSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Quick task 260726-fx4 Task 4 — the fallbackSummary path must return an
 * EMPTY STRING (not the old "Works: <first sentence>" pseudo-summary) and
 * must mark the row with _summary_fallback = true so the review UI can
 * badge "AI unavailable — click Generate to retry".
 *
 * Prior shape: fallbackSummary() returned a "Works: <first sentence>" line
 * that looked like AI-generated bullet content to reviewers but was actually
 * just the PM's own phrased overview cut short.
 */
class RoomOverviewSummaryFallbackTest extends TestCase
{
    private RoomOverviewSummaryService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RoomOverviewSummaryService();

        // Bypass the AI cache so the "live" call is always attempted.
        $cache = $this->createMock(AICacheService::class);
        $cache->method('hash')->willReturn('test-hash');
        $cache->method('get')->willReturn(null);
        $this->app->bind(AICacheService::class, fn () => $cache);
    }

    // ── AI failure path: empty works_summary + fallback marker ──────────────

    public function test_ai_failure_returns_empty_works_summary_with_fallback_marker(): void
    {
        // Bind a ClaudeProvider that throws so AIManager::run() bubbles an
        // AIGenerationException into the service's catch branch.
        $mock = $this->createMock(AIProviderContract::class);
        $mock->method('execute')->willThrowException(
            new \RuntimeException('provider unreachable')
        );
        $mock->method('completeJson')->willThrowException(
            new \RuntimeException('provider unreachable')
        );
        $mock->method('getProviderKey')->willReturn('claude');
        $this->app->bind(ClaudeProvider::class, fn () => $mock);

        $rows = [
            ['room' => 'Boardroom', 'overview' => 'Install a Samsung QM75B display and Logitech Rally Bar.'],
            ['room' => 'Huddle A',  'overview' => 'Small meeting room refresh with a 55" panel.'],
        ];

        $out = $this->service->summarize($rows);

        $this->assertCount(2, $out);
        foreach ($out as $row) {
            $this->assertSame('', $row['works_summary'],
                'works_summary must be empty on AI failure — no "Works: ..." masquerade');
            $this->assertTrue($row['_summary_fallback'] ?? false,
                '_summary_fallback marker must be true so review UI can badge the row');
            $this->assertStringNotContainsString('Works:', $row['works_summary'],
                'the deprecated "Works: <first sentence>" prefix must not appear');
        }
    }

    // ── AI returns empty per-row → fallback marker set for that row only ────

    public function test_ai_empty_summary_per_row_marks_only_that_row_as_fallback(): void
    {
        $mock = $this->createMock(AIProviderContract::class);
        $mock->method('completeJson')->willReturn([
            'summaries' => [
                ['room' => 'Boardroom', 'summary' => '- Real AI bullets'],
                ['room' => 'Huddle A',  'summary' => ''],
            ],
        ]);
        $mock->method('execute')->willReturn([
            'summaries' => [
                ['room' => 'Boardroom', 'summary' => '- Real AI bullets'],
                ['room' => 'Huddle A',  'summary' => ''],
            ],
        ]);
        $mock->method('getProviderKey')->willReturn('claude');
        $this->app->bind(ClaudeProvider::class, fn () => $mock);

        $rows = [
            ['room' => 'Boardroom', 'overview' => 'A'],
            ['room' => 'Huddle A',  'overview' => 'B'],
        ];

        $out = $this->service->summarize($rows);

        $this->assertSame('- Real AI bullets', $out[0]['works_summary']);
        $this->assertFalse($out[0]['_summary_fallback']);

        $this->assertSame('', $out[1]['works_summary']);
        $this->assertTrue($out[1]['_summary_fallback']);
    }

    // ── No overviews to summarise: no fallback marker, no empty masquerade ─

    public function test_no_overviews_returns_empty_works_summary_without_fallback_marker(): void
    {
        // Bind a provider that would fail hard if called — verifies the AI
        // is NOT invoked when there's nothing to summarise.
        $mock = $this->createMock(AIProviderContract::class);
        $mock->method('execute')->willThrowException(
            new \RuntimeException('AI should not have been called with empty overviews')
        );
        $mock->method('completeJson')->willThrowException(
            new \RuntimeException('AI should not have been called with empty overviews')
        );
        $mock->method('getProviderKey')->willReturn('claude');
        $this->app->bind(ClaudeProvider::class, fn () => $mock);

        $rows = [
            ['room' => 'Boardroom', 'overview' => ''],
        ];

        $out = $this->service->summarize($rows);

        $this->assertSame('', $out[0]['works_summary']);
        $this->assertArrayNotHasKey('_summary_fallback', $out[0]);
    }
}
