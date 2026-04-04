<?php

namespace Tests\Unit\Services;

use App\Services\AICacheService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Tests for AICacheService TTL and pruning logic.
 *
 * Requires a database (uses RefreshDatabase) because the service
 * reads/writes the ai_cache table directly.
 */
class AICacheServiceTest extends TestCase
{
    use RefreshDatabase;

    private AICacheService $cache;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cache = new AICacheService();
    }

    // ── hash() ────────────────────────────────────────────────────────────────

    public function test_hash_returns_64_char_hex_string(): void
    {
        $hash = $this->cache->hash('some prompt text');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    public function test_same_prompt_always_produces_same_hash(): void
    {
        $prompt = 'Generate a RAMS document for a school install';

        $this->assertSame($this->cache->hash($prompt), $this->cache->hash($prompt));
    }

    public function test_different_prompts_produce_different_hashes(): void
    {
        $this->assertNotSame(
            $this->cache->hash('prompt one'),
            $this->cache->hash('prompt two')
        );
    }

    // ── store() / get() ───────────────────────────────────────────────────────

    public function test_stored_entry_is_retrievable_before_expiry(): void
    {
        $hash = $this->cache->hash('test prompt');
        $this->cache->store($hash, 'test prompt', '{"result":"ok"}', 'claude');

        $result = $this->cache->get($hash);

        $this->assertSame('{"result":"ok"}', $result);
    }

    public function test_get_returns_null_for_unknown_hash(): void
    {
        $this->assertNull($this->cache->get('unknown_hash_that_does_not_exist'));
    }

    public function test_expired_entry_is_not_returned(): void
    {
        $hash = $this->cache->hash('expired prompt');
        $this->cache->store($hash, 'expired prompt', '{"result":"stale"}', 'claude');

        // Manually expire the entry
        DB::table('ai_cache')
            ->where('hash', $hash)
            ->update(['expires_at' => now()->subMinute()]);

        $this->assertNull($this->cache->get($hash));
    }

    public function test_entry_without_expires_at_is_always_returned(): void
    {
        $hash = $this->cache->hash('legacy prompt');
        $this->cache->store($hash, 'legacy prompt', '{"result":"legacy"}', 'claude');

        // Simulate a legacy row that has no expires_at (pre-TTL migration)
        DB::table('ai_cache')
            ->where('hash', $hash)
            ->update(['expires_at' => null]);

        $this->assertSame('{"result":"legacy"}', $this->cache->get($hash));
    }

    public function test_duplicate_store_is_ignored(): void
    {
        $hash = $this->cache->hash('dup prompt');

        $this->cache->store($hash, 'dup prompt', '{"result":"first"}', 'claude');
        $this->cache->store($hash, 'dup prompt', '{"result":"second"}', 'openai');

        // First stored value should win (insertOrIgnore)
        $this->assertSame('{"result":"first"}', $this->cache->get($hash));
    }

    // ── pruneExpired() ────────────────────────────────────────────────────────

    public function test_prune_removes_expired_entries(): void
    {
        $hash1 = $this->cache->hash('fresh');
        $hash2 = $this->cache->hash('stale');

        $this->cache->store($hash1, 'fresh', '{"r":1}');
        $this->cache->store($hash2, 'stale', '{"r":2}');

        DB::table('ai_cache')
            ->where('hash', $hash2)
            ->update(['expires_at' => now()->subMinute()]);

        $deleted = $this->cache->pruneExpired();

        $this->assertSame(1, $deleted);
        $this->assertNotNull($this->cache->get($hash1));
        $this->assertNull($this->cache->get($hash2));
    }

    public function test_prune_returns_zero_when_nothing_to_prune(): void
    {
        $hash = $this->cache->hash('valid entry');
        $this->cache->store($hash, 'valid entry', '{"r":1}');

        $this->assertSame(0, $this->cache->pruneExpired());
    }

    public function test_prune_does_not_remove_entries_without_expiry(): void
    {
        $hash = $this->cache->hash('no expiry');
        $this->cache->store($hash, 'no expiry', '{"r":1}');
        DB::table('ai_cache')->where('hash', $hash)->update(['expires_at' => null]);

        $this->assertSame(0, $this->cache->pruneExpired());
        $this->assertNotNull($this->cache->get($hash));
    }
}
