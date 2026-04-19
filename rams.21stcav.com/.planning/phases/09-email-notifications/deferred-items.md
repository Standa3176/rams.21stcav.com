# Phase 09 — Deferred Items

Items discovered during execution that are out of scope for the current plan.

## Pre-existing test failures (not caused by Phase 09 work)

**Discovered during:** 09-02 Task 1 (regression check after `composer require symfony/postmark-mailer symfony/http-client`).

**Status:** Present on base commit `d34600d` (before any Phase 09 code changes) — verified via `git stash` + re-run.

### `tests/Unit/Rams/MethodStatementFallbackTest.php` — 4 errors

All four tests in this file error with:

```
TypeError: Cannot assign null to property App\Core\AI\Providers\ClaudeProvider::$apiKey of type string
```

**Root cause:** `ClaudeProvider::__construct` requires `config('services.anthropic.api_key')` (env `CLAUDE_API_KEY`) to be a non-null string, but the PHPUnit test environment (`phpunit.xml`) does not set this env var. The tests instantiate `MethodStatementService` → `AIManager` → `ClaudeProvider` via the container, which throws before any test code runs.

**Out of scope for Phase 09:** The failures touch `app/Core/AI/Providers/ClaudeProvider.php` and the AI test scaffolding, not the email-notifications code path. Composer install did not introduce or worsen these failures — they exist on `d34600d` without any changes.

**Suggested follow-up (separate task):** Add `CLAUDE_API_KEY=test-key` (or similar dummy) to `phpunit.xml` `<php>` block, or make `ClaudeProvider::$apiKey` nullable and guard API calls. Not a Phase 09 concern.
