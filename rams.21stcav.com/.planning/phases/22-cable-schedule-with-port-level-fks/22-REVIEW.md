---
phase: 22-cable-schedule-with-port-level-fks
reviewed: 2026-05-12T00:00:00Z
depth: standard
files_reviewed: 20
files_reviewed_list:
  - app/Console/Commands/BackfillCablePortFksCommand.php
  - app/Http/Controllers/CableScheduleController.php
  - app/Models/CableScheduleItem.php
  - app/Services/Cable/CableConnectorCompatibilityService.php
  - app/Services/Cable/CablePortFkResolverService.php
  - config/cables.php
  - database/migrations/2026_05_15_000000_add_port_fks_to_cable_schedule_items.php
  - resources/views/cable-schedule/_port-picker-modal.blade.php
  - resources/views/cable-schedule/edit.blade.php
  - tests/Feature/Cable/BackfillCablePortFksCommandTest.php
  - tests/Feature/Cable/CableScheduleCrossProjectFkInjectionTest.php
  - tests/Feature/Cable/CableScheduleItemMigrationTest.php
  - tests/Feature/Cable/CableScheduleUpdatePersistsOverrideNoteTest.php
  - tests/Feature/Cable/CableScheduleUpdatePersistsPortFksTest.php
  - tests/Feature/Cable/CableScheduleXlsxRegressionTest.php
  - tests/Feature/Drawings/SchematicGeneratorServiceTest.php
  - tests/Unit/Models/CableScheduleItemRelationsTest.php
  - tests/Unit/Services/Cable/CableConnectorCompatibilityServiceTest.php
  - tests/Unit/Services/Cable/CablePortFkResolverServiceTest.php
findings:
  critical: 0
  warning: 4
  info: 5
  total: 9
status: issues_found
---

# Phase 22: Code Review Report

**Reviewed:** 2026-05-12
**Depth:** standard
**Files Reviewed:** 20
**Status:** issues_found

## Summary

Phase 22 is a well-scoped, strictly-additive port-FK extension to the cable schedule. The implementation honours every locked decision (D-01..D-04, D-10) and the security threat model (T-22-A1..A6) with explicit guardrails:

- **T-22-A1 mass-assignment** — `CableScheduleItem::$fillable` is a tight whitelist; no `$guarded = []`. Verified by unit test `test_unknown_keys_are_dropped_by_fillable`.
- **T-22-A2 XSS** — `connector_override_note` is only ever rendered via Blade `{{ }}` escaping. No `{!! !!}` anywhere in the cable-schedule views; the only `{!!` substring is inside a Blade `{{-- ... --}}` comment in `_port-picker-modal.blade.php` line 30 (the safety policy documentation itself).
- **T-22-A4 cross-project FK injection** — `CableScheduleController@update` walks all submitted `source_device_id` / `dest_device_id` and asserts `project_id` match in a single SQL count query (line 222-252). The guard fires BEFORE `items()->delete()` so failed validation cannot wipe pre-seeded rows. Locked by feature test `test_cross_project_source_device_returns_422_t22_a4`.
- **T-22-A5 SQL injection** — `BackfillCablePortFksCommand::handle` casts `(int) $this->argument('project')` before passing to Eloquent. Verified by `test_sql_injection_via_project_arg_neutralised_t22_a5`.
- **T-22-A6 wrong-tenant write** — Resolver iteration loads devices scoped to each schedule's project_id; cross-project text matches are impossible by construction. Locked by `test_cross_project_match_impossible_by_construction_t22_a6`.
- **D-10 v1.3 invariant** — `CableScheduleItem::$with` is empty (asserted by reflection-based unit test). Eager-loading happens only at the picker call site in `CableScheduleController@edit`. A source-level canary test (`test_v13_surface_files_have_zero_phase22_column_references`) forbids the 5 new column names from appearing in any v1.3 surface file.
- **W5 partial-write prevention** — `BackfillCablePortFksCommand` writes FKs ONLY when `$tag === 'matched'`. The "source matched / dest ambiguous" case leaves all four FKs NULL, locked by `test_ambiguous_overall_leaves_all_four_fks_null`.
- **CSRF** — `edit.blade.php` line 38 has `@csrf` directive; preserved.
- **Migration reversibility** — `down()` drops the compound index FIRST, then drops the four FK columns and the text column. Order is correct.
- **Connector matrix bidirectionality** — Both `isCompatible('hdmi','dp')` and `isCompatible('dp','hdmi')` traverse the same allowlist loop (service line 73). Locked by `test_allowlist_reverse_direction_compatible_bidirectional`.
- **N+1 prevention** — Picker payload eager-loads `stencil.ports` per-device (controller line 141).

No Critical findings. Four Warnings (test/code drift, test/UX message inconsistency, a latent NULL-project bypass, and a redundant null-safe chain that hints at a misunderstanding worth fixing). Five Info items on conventions, error messages, and one test naming nit.

## Warnings

### WR-01: Cross-project guard skipped when schedule has NULL project_id — latent bypass

**File:** `app/Http/Controllers/CableScheduleController.php:222`
**Issue:** The T-22-A4 walk is wrapped in `if ($cableSchedule->project_id !== null)`. For legacy standalone schedules where `project_id` is NULL, the entire device-membership check is skipped. An engineer can therefore submit ANY `source_device_id` / `dest_device_id` that exists in the `devices` table and Eloquent will persist it (the `exists:devices,id` rule still fires, but it does not constrain ownership). The picker UI does gate this (line 138 only builds the device payload when `project_id` is non-null, so the engineer has nothing to pick from in the dropdown), but the controller-side check is what enforces the boundary against crafted POSTs — and it is currently absent for this case.

Practical exposure is bounded: cable schedules without a `project_id` are legacy standalone records, and there is no current information-disclosure path that surfaces foreign devices to the engineer. But the comment at line 213-215 ("Cable schedules without a project_id (legacy standalone) skip the check too — no project to enforce membership against") understates the consequence: a legacy schedule can act as an attach-anywhere sink for `source_device_id` / `dest_device_id`.

**Fix:** Either reject any non-null `source_device_id` / `dest_device_id` when the schedule has no project (safest — text-only is the legacy contract), or restrict the picker payload so the same behaviour is enforced server-side:
```php
if ($cableSchedule->project_id === null) {
    // Legacy standalone schedule: FK fields are disallowed.
    $hasFkInPayload = collect($request->input('items', []))
        ->contains(fn ($i) => !empty($i['source_device_id']) || !empty($i['dest_device_id']));
    if ($hasFkInPayload) {
        throw ValidationException::withMessages([
            'items.0.source_device_id' => 'Port FKs require a project-linked cable schedule.',
        ]);
    }
} else {
    // existing project_id walk
}
```

### WR-02: 422 error always keyed under `items.0.source_device_id`, even when the offender is `dest_device_id`

**File:** `app/Http/Controllers/CableScheduleController.php:247-249`
**Issue:** When the cross-project FK injection guard rejects, the `ValidationException::withMessages([...])` call hard-codes the key as `items.0.source_device_id`. If the engineer submitted only a malicious `dest_device_id` (line 224-231 collapses both sides into a single list), they receive a validation error attached to the wrong field. The session error bag attached to `source_device_id` will not light up the `dest_device_id` input in any form-driven UX.

The test `test_cross_project_dest_device_returns_422_t22_a4` (line 125 of `CableScheduleCrossProjectFkInjectionTest`) actually asserts the misattribution — it expects `items.0.source_device_id`. That's test/code coupling: the test locks in a misleading message rather than the correct field.

**Fix:** Track which side(s) were offending and report each in the error message. Either:
```php
$wrongSourceIds = collect($request->input('items', []))
    ->map(fn ($i, $k) => ['key' => "items.$k.source_device_id", 'id' => $i['source_device_id'] ?? null])
    ->filter(fn ($x) => $x['id'] && in_array($x['id'], $offendingDeviceIds, true));
$wrongDestIds   = /* ditto for dest_device_id */;
$messages = [];
foreach ($wrongSourceIds as $r) $messages[$r['key']] = '... different project ...';
foreach ($wrongDestIds   as $r) $messages[$r['key']] = '... different project ...';
throw ValidationException::withMessages($messages);
```
Then update both test assertions to expect the correct key per scenario.

### WR-03: `optional($d->stencil)?->ports` chains null-safe over an already-null-safe `optional()`

**File:** `app/Http/Controllers/CableScheduleController.php:152`
**Issue:** `optional()` returns an `Illuminate\Support\Optional` proxy that responds gracefully to any property access (returning null) when the wrapped value is null. The `?->` null-safe operator does the same thing. Combining them — `optional($d->stencil)?->ports` — is redundant and signals a misunderstanding of either operator. It also subtly hides intent for future readers ("why both?").

Worse: if `$d->stencil` is null, `optional(null)` returns an `Optional` instance (NOT null), so `?->` traversal yields `Optional::ports` (still an `Optional`-proxy), and the `?? collect()` fallback kicks in only because the proxy's `__get` returns null. The chain works by accident, not by design.

**Fix:**
```php
$ports = $d->stencil?->ports ?? collect();
```
Single null-safe access. Matches the rest of the codebase (the resolver service uses the same `$device->stencil ?? null` pattern at line 199).

### WR-04: `->filter()` with no callback also drops integer `0` and bool `false` — fine today, fragile if `Device::$primaryKey` ever changes

**File:** `app/Http/Controllers/CableScheduleController.php:228`
**Issue:** `collect([...])->filter()` drops every falsy value (`null`, `0`, `''`, `false`, `[]`). Today, MySQL auto-increment ids start at 1, so dropping `0` is moot. But the inline comment "drops nulls and falsy values" understates the contract: anyone changing the Device primary key to a UUID string later (the project has explicit plans to merge with SCC, per `CLAUDE.md` "RAMS + SCC merge planned") will see this silently accept the string `"0"` and drop the integer `0`. The guard would still function (a UUID is never falsy), but the next reviewer has to think through it.

**Fix:** Be explicit about the predicate to future-proof:
```php
->filter(fn ($id) => $id !== null && $id !== '')
```

## Info

### IN-01: ASCII art divider convention is mostly followed but not uniformly

**File:** `app/Services/Cable/CablePortFkResolverService.php` (entire file)
**Issue:** `CLAUDE.md` calls for `// ── Label ──` for subsections inside service classes. `CablePortFkResolverService` is a 247-line service that uses inline `// ── Foo ──` headers inside method bodies (line 55, 63, 68 of the compatibility service do this well) but `CablePortFkResolverService` has no section dividers between the public `resolve`, private `resolveSide`, private `normalise`, and private `connectorHintForCableType`. The class is small enough that this is a style nit, not a readability problem.
**Fix:** Optional — add `// ── Helpers ──` above `normalise()` for consistency with `CableScheduleController.php`'s `// ── generateFromProject ───` blocks. Low priority.

### IN-02: Hardcoded teal hex `#1B7A7A` repeated across Blade files instead of a CSS class

**File:** `resources/views/cable-schedule/_port-picker-modal.blade.php:52,82,211`
**File:** `resources/views/cable-schedule/edit.blade.php:93,211`
**Issue:** The teal action colour is set five times via inline `style="color:#1B7A7A;"`. `CONTEXT.md` D-03 specifies "teal matches `btn-teal` palette already used on the Save button at `cable-schedule/edit.blade.php` line 47", implying a class-based palette. Inline hex is harder to keep in sync with the `btn-teal` palette and would drift if Tailwind's teal-700 shifts.
**Fix:** Extract a CSS utility class (e.g. `.text-action-teal`) or reuse Tailwind's `text-teal-700` if available. Non-blocking.

### IN-03: Picker modal warning banner uses an emoji ("⚠") in a Blade template

**File:** `resources/views/cable-schedule/_port-picker-modal.blade.php:107`
**Issue:** `CLAUDE.md` profile section explicitly says "no emojis in code". The yellow warning banner emits `⚠ <span x-text="warningReason()"></span>`. The chain-link icon `🔗` is also used in `edit.blade.php` lines 67, 94, 140 — though that one is a UX element specified in CONTEXT D-03 ("Compact 🔗 icon between From and To columns") so it's locked.
**Fix:** Replace `⚠` with an inline SVG (or `<i class="bi bi-exclamation-triangle"></i>` if Bootstrap icons are in the project). The 🔗 chain-link icon is exempt per locked decision D-03.

### IN-04: Resolver `reason` strings interpolate raw `$text` — minor log-content concern

**File:** `app/Services/Cable/CablePortFkResolverService.php:184,193`
**Issue:** When the matcher reports `ambiguous` or `no-device-match`, it embeds the raw `from_location` / `to_location` text into the human-readable reason: `"text '{$text}' did not match any project device"`. This reason is then echoed to `$this->line()` (stdout) and written into the `Log::info` summary. If `from_location` ever contains control characters or terminal escape sequences (low probability — the input field has `maxlength="200"` and is engineer-controlled, not user-controlled), the log line could be deformed.

This is INFO not WARNING because the only writers of `from_location` are authenticated engineers and admins (CLI-only command), and Laravel's log driver typically strips control bytes anyway.
**Fix:** Sanitise before interpolation: `addslashes(substr($text, 0, 80))` — keeps the reason short, escapes quotes.

### IN-05: `BackfillCablePortFksCommand` test naming uses snake_case_with_t22 suffix — informational

**File:** `tests/Feature/Cable/BackfillCablePortFksCommandTest.php:123,143`
**Issue:** Two test methods carry T-22 suffix in their names: `test_sql_injection_via_project_arg_neutralised_t22_a5`, `test_cross_project_match_impossible_by_construction_t22_a6`. These read like "what threat" comments embedded in the name. Useful for traceability, but inconsistent with the other tests in the file (no T-22 suffix). It's a minor cohesion call. The threat-ID is preserved in the docblock above the test, which is sufficient for grep-and-trace.
**Fix:** Optional — rename to drop the suffix and rely on docblock cross-references. Or, more consistently, add `_t22_aN` to ALL phase-22 security tests. Either direction; just pick one.

---

_Reviewed: 2026-05-12_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
