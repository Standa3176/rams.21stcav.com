<?php

namespace Tests\Unit\Rams;

use App\Services\MethodStatementService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Verifies MethodStatementService returns the static 6-phase fallback when
 * the AI provider is unavailable (HTTP 500) or returns undecodable JSON.
 */
class MethodStatementFallbackTest extends TestCase
{
    private MethodStatementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(MethodStatementService::class);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function minimalParsedQuote(): array
    {
        return [
            'site'      => 'Test Site',
            'tasks'     => ['Install display screens'],
            'equipment' => [],
            'rooms'     => [],
        ];
    }

    private function minimalClassified(): array
    {
        return [
            'activities'        => ['display_installation'],
            'categories'        => [],
            'summary'           => 'Display installation',
            'heavy_items'       => [],
            'drilling_required' => false,
        ];
    }

    // ── Tests ─────────────────────────────────────────────────────────────────

    public function test_returns_six_phases_when_ai_returns_http_500(): void
    {
        Http::fake(['*' => Http::response('Internal Server Error', 500)]);

        $result = $this->service->generate($this->minimalParsedQuote(), $this->minimalClassified());

        $this->assertArrayHasKey('phases', $result);
        $this->assertCount(6, $result['phases']);
    }

    public function test_returns_six_phases_when_ai_returns_invalid_json(): void
    {
        Http::fake(['*' => Http::response([
            'content'     => [['type' => 'text', 'text' => 'not-valid-json{{{']],
            'stop_reason' => 'end_turn',
        ], 200)]);

        $result = $this->service->generate($this->minimalParsedQuote(), $this->minimalClassified());

        $this->assertArrayHasKey('phases', $result);
        $this->assertCount(6, $result['phases']);
    }

    public function test_returns_six_phases_when_ai_returns_empty_phases_array(): void
    {
        Http::fake(['*' => Http::response([
            'content'     => [['type' => 'text', 'text' => json_encode(['phases' => []])]],
            'stop_reason' => 'end_turn',
        ], 200)]);

        $result = $this->service->generate($this->minimalParsedQuote(), $this->minimalClassified());

        $this->assertArrayHasKey('phases', $result);
        $this->assertCount(6, $result['phases']);
    }

    public function test_each_fallback_phase_has_a_title_and_steps(): void
    {
        Http::fake(['*' => Http::response('Service Unavailable', 503)]);

        $result = $this->service->generate($this->minimalParsedQuote(), $this->minimalClassified());

        foreach ($result['phases'] as $i => $phase) {
            $this->assertArrayHasKey('title', $phase, "Phase {$i} missing title");
            $this->assertArrayHasKey('steps', $phase, "Phase {$i} missing steps");
            $this->assertNotEmpty($phase['title'], "Phase {$i} title is empty");
            $this->assertNotEmpty($phase['steps'], "Phase {$i} steps are empty");
        }
    }

    public function test_uses_ai_response_when_valid(): void
    {
        $aiPhases = [
            ['title' => 'Phase 1: Site Prep', 'steps' => ['Put on PPE', 'Sign in at reception']],
            ['title' => 'Phase 2: Installation', 'steps' => ['Mount bracket', 'Hang screen', 'Route cable']],
        ];

        // Mock the ClaudeProvider in the container so AIManager::make() returns it.
        // This bypasses the AI cache and the live HTTP call entirely.
        $mockProvider = $this->createMock(\App\Core\AI\Contracts\AIProviderContract::class);
        $mockProvider->method('execute')->willReturn(['phases' => $aiPhases]);
        $mockProvider->method('completeJson')->willReturn(['phases' => $aiPhases]);
        $mockProvider->method('getProviderKey')->willReturn('claude');

        $this->app->bind(\App\Core\AI\Providers\ClaudeProvider::class, fn () => $mockProvider);

        // Also return null from cache so the live call is always attempted.
        $mockCache = $this->createMock(\App\Services\AICacheService::class);
        $mockCache->method('hash')->willReturn('test-hash');
        $mockCache->method('get')->willReturn(null);
        $mockCache->method('store'); // void return — no willReturn needed
        $this->app->instance(\App\Services\AICacheService::class, $mockCache);

        $result = $this->service->generate($this->minimalParsedQuote(), $this->minimalClassified());

        $this->assertCount(2, $result['phases']);
        $this->assertSame('Phase 1: Site Prep', $result['phases'][0]['title']);
    }
}
