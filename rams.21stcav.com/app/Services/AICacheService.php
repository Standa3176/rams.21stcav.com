<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stores and retrieves AI responses keyed by a SHA-256 prompt hash.
 *
 * Backed by the `ai_cache` database table so cached responses survive
 * restarts and are shared across queue workers.
 *
 * TTL: entries expire after AI_CACHE_TTL_DAYS days (default: 30).
 * Run `php artisan ai:cache-prune` to remove expired entries.
 */
class AICacheService
{
    /** Cache lifetime in days. Configurable via AI_CACHE_TTL_DAYS env var. */
    private int $ttlDays;

    /** Per-process flag so we only call Schema::hasColumn() once. */
    private static ?bool $hasTtlColumn = null;

    public function __construct()
    {
        $this->ttlDays = (int) config('ai.cache_ttl_days', 30);
    }

    /**
     * Return the cached response string for the given hash, or null on miss/expiry.
     */
    public function get(string $hash): ?string
    {
        if (! Schema::hasTable('ai_cache')) {
            return null;
        }

        $query = DB::table('ai_cache')->where('hash', $hash);

        // Only apply the TTL filter once the expires_at column exists.
        if ($this->ttlColumnExists()) {
            $query->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            });
        }

        return $query->value('response');
    }

    /**
     * Persist a prompt/response pair with an expiry timestamp.
     * Silently ignores duplicate-key conflicts (identical hash already stored).
     */
    public function store(string $hash, string $prompt, string $response, ?string $model = null): void
    {
        if (! Schema::hasTable('ai_cache')) {
            return;
        }

        $now = now();

        $row = [
            'hash'       => $hash,
            'prompt'     => $prompt,
            'response'   => $response,
            'model'      => $model,
            'created_at' => $now,
        ];

        // Only write expires_at once the column exists (i.e. migration has run).
        if ($this->ttlColumnExists()) {
            $row['expires_at'] = $now->copy()->addDays($this->ttlDays);
        }

        DB::table('ai_cache')->insertOrIgnore($row);
    }

    /**
     * Delete all expired cache entries. Called by the ai:cache-prune command.
     */
    public function pruneExpired(): int
    {
        if (! Schema::hasTable('ai_cache') || ! $this->ttlColumnExists()) {
            return 0;
        }

        return DB::table('ai_cache')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->delete();
    }

    /**
     * Produce a SHA-256 hex hash for a prompt string.
     */
    public function hash(string $prompt): string
    {
        return hash('sha256', $prompt);
    }

    /**
     * Check (once per process) whether the expires_at column exists.
     * Cached in a static property to avoid repeated schema lookups.
     */
    private function ttlColumnExists(): bool
    {
        return self::$hasTtlColumn ??= Schema::hasColumn('ai_cache', 'expires_at');
    }
}
