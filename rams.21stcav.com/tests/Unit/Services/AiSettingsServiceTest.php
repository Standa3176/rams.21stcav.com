<?php

namespace Tests\Unit\Services;

use App\Services\AiSettingsService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Unit tests for AiSettingsService.
 *
 * Covers connection-testing (pinging Claude/OpenAI) and validates that
 * invalid provider names throw the correct exception. The .env-writing
 * path is covered implicitly by the integration via RamsController tests.
 */
class AiSettingsServiceTest extends TestCase
{
    private AiSettingsService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new AiSettingsService();
    }

    // ── testConnection() ──────────────────────────────────────────────────────

    public function test_test_connection_succeeds_for_claude_on_200_response(): void
    {
        config([
            'ai.providers.claude.api_key'  => 'test-key',
            'ai.providers.claude.model'    => 'claude-sonnet-4-6',
            'ai.providers.claude.endpoint' => 'https://api.anthropic.com/v1/messages',
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response(['content' => [['text' => 'OK']]], 200),
        ]);

        // Must not throw
        $this->service->testConnection('claude');

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'anthropic.com');
        });
    }

    public function test_test_connection_throws_on_claude_api_error(): void
    {
        config([
            'ai.providers.claude.api_key'  => 'bad-key',
            'ai.providers.claude.model'    => 'claude-sonnet-4-6',
            'ai.providers.claude.endpoint' => 'https://api.anthropic.com/v1/messages',
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::response(['error' => ['message' => 'Unauthorized']], 401),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/Claude API error 401/');

        $this->service->testConnection('claude');
    }

    public function test_test_connection_throws_when_claude_key_is_missing(): void
    {
        config(['ai.providers.claude.api_key' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No Claude API key/');

        $this->service->testConnection('claude');
    }

    public function test_test_connection_succeeds_for_openai_on_200_response(): void
    {
        config([
            'ai.providers.openai.api_key'  => 'test-openai-key',
            'ai.providers.openai.model'    => 'gpt-4o',
            'ai.providers.openai.endpoint' => 'https://api.openai.com/v1/chat/completions',
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response(['choices' => [['message' => ['content' => 'OK']]]], 200),
        ]);

        $this->service->testConnection('openai');

        Http::assertSent(function (Request $request) {
            return str_contains($request->url(), 'openai.com');
        });
    }

    public function test_test_connection_throws_on_openai_api_error(): void
    {
        config([
            'ai.providers.openai.api_key'  => 'bad-key',
            'ai.providers.openai.model'    => 'gpt-4o',
            'ai.providers.openai.endpoint' => 'https://api.openai.com/v1/chat/completions',
        ]);

        Http::fake([
            'api.openai.com/*' => Http::response(['error' => ['message' => 'Unauthorized']], 401),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/OpenAI API error 401/');

        $this->service->testConnection('openai');
    }

    public function test_test_connection_throws_when_openai_key_is_missing(): void
    {
        config(['ai.providers.openai.api_key' => '']);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/No OpenAI API key/');

        $this->service->testConnection('openai');
    }

    public function test_test_connection_throws_for_unknown_provider(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown provider/');

        $this->service->testConnection('unknown-provider');
    }
}
