---
quick_id: 260816-rdz
slug: room-detect-and-d2-supply-only
date: 2026-08-16
status: planned
---

# Quick Task 260816-rdz — Room-detection prose fragments + D2 schematic supply-only leak

Two independent defects found while investigating a blank schematic on project 21CQ30698 (package 147).

---

## Defect A — Room detection harvests prose fragments as room names

### Evidence (production, package 147)

`extracted_data['rooms']` is literally:

```json
["Boardroom Rack Reconfiguratio", "s Boardroom currently contains"]
```

29 and 30 characters. Neither is a room. The first is cut mid-word; the second starts mid-word. These became the project's only two "rooms", and because no equipment carried a matching `area`, the generated schematic rendered blank. The truncated name also became the drawing's title: *"Signal Flow — Boardroom Rack Reconfiguratio"*.

### Mechanism (traced, not inferred)

`app/Services/QuoteParserService.php` (~line 911), keyword-scan fallback:

```php
$pattern = '/([A-Za-z0-9\s\-\/]{0,50}' . preg_quote($kw, '/') . '[A-Za-z0-9\s\-\/]{0,20})/i';
```

Captures up to 50 chars before a room keyword and **up to 20 after**, with no word-boundary awareness. That reproduces both strings exactly:

- `"Boardroom"` + 20 trailing chars → `" Rack Reconfiguratio"` — cut mid-word at exactly 20.
- `"…client's Boardroom currently contains…"` — the apostrophe is not in the character class, so the leading run begins at `"s "`, yielding `"s Boardroom currently contains"`.

The only guard is:

```php
// Hard cap: room names longer than 60 chars are likely full
// sentence fragments caught by the pattern, not room names.
if (strlen($room) > 60) { break; }
```

The failure mode was anticipated; the threshold is simply too loose. A 29-char fragment passes untouched.

### Tasks

#### A1 — Trim captures to whole words

**File:** `app/Services/QuoteParserService.php` (~line 913, immediately after `$room = trim($m[1]);`)

**Action:** Before any other check, drop a trailing partial word when the capture ended mid-word (the `{0,20}` window ran out), and drop a leading partial word when the capture began mid-word (a non-class character such as an apostrophe broke the run). Concretely: if the character in the source line immediately after the captured span is alphanumeric, remove the final whitespace-delimited token; if the character immediately before the span is alphanumeric, remove the first token. Re-`trim()` afterwards.

This alone converts `"Boardroom Rack Reconfiguratio"` → `"Boardroom Rack"` and `"s Boardroom currently contains"` → `"Boardroom currently contains"`, which A2 then rejects.

**Acceptance criteria:**
- No captured room name ends or begins with a partial word
- A line containing `"Boardroom Rack Reconfiguration Project"` no longer yields a name ending `"Reconfiguratio"`

#### A2 — Reject prose fragments

**File:** same, alongside the existing 60-char cap

**Action:** After A1's trim, reject the candidate when any of these hold:

1. **Sentence words present** — the candidate contains any of a small fixed stop-list indicating prose rather than a label: `currently`, `contains`, `existing`, `should`, `will`, `would`, `there`, `these`, `their`, `which`, `that`, `been`, `have`, `requires`, `required`. Case-insensitive, whole-word matching (so `"Board"` inside `"Boardroom"` can never trip it).
2. **Too many words** — more than 5 whitespace-delimited tokens. Real room names in this codebase's own fixtures are short ("Boardroom", "Meeting Room 1", "Digital Production Studio").
3. **Starts with a 1-2 character lowercase token** — e.g. `"s Boardroom …"`, the signature of a capture that began mid-word.

Keep the existing 60-char cap and the existing `[a-zA-Z]{2,}` check — these are additive.

Put the stop-list in a named `private const` beside `ROOM_KEYWORDS`, with a comment explaining it exists to stop narrative text being harvested as rooms, and citing package 147 as the real-world case.

**Acceptance criteria:**
- `"s Boardroom currently contains"` is rejected
- `"Boardroom currently contains"` is rejected
- `"Boardroom"`, `"Meeting Room 1"`, `"Digital Production Studio"`, `"Boardroom Rack"` are all still ACCEPTED
- The existing QuoteParser suite passes unchanged — this must not reduce genuine room detection

#### A3 — Regression test

**File:** extend the existing QuoteParser test suite (find its established home first; do not create a parallel file if one exists)

**Action:** Add cases driving the real package-147 strings through the parser's room detection, asserting they are rejected, plus positive cases proving legitimate names still parse. Reference package 147 in a comment so the origin is traceable.

**Acceptance criteria:** test fails if either A1 or A2 is reverted.

---

## Defect B — Supply-only hardware still appears in the D2 schematic

### Evidence

The user's locked decision (2026-08-15, quick task 260815-sup) was that `hardware_supply_only` appears in **O&M only** — explicitly not RAMS, not drawings, not surveys. Every other surface honours this because they filter exclusively (`!== 'hardware'`), so a new value is excluded by construction.

`app/Services/Drawings/DrawingDataResolverService.php` (~line 40) is the exception — it filters by an **exclusion allowlist**, i.e. inclusive by default:

```php
private const EXCLUDED_CATEGORIES = ['cables', 'consumables', 'services', 'option'];
if ($category !== '' && in_array($category, self::EXCLUDED_CATEGORIES, true)) { … }
```

`hardware_supply_only` is not listed, so it is **included** in the v1.3 D2 schematic. This was missed in the 260815-sup audit, which grepped for `=== 'hardware'` and never matched this file's `in_array(...)` shape.

Scope note: the Phase 23 XTEN-AV renderer (`Project::devicesWithStencils()`) already excludes it correctly. Only this older D2 path leaks.

### Task

#### B1 — Exclude supply-only from the D2 schematic

**File:** `app/Services/Drawings/DrawingDataResolverService.php`

**Action:** Add `hardware_supply_only` to `EXCLUDED_CATEGORIES`, with a comment recording that supply-only kit is deliberately absent from drawings per the 260815-sup decision, and that this list is inclusive-by-default so **any** future category must be added here explicitly to stay out of drawings.

Do **not** change `customer_supplied` or `service_contracts` handling. They are also absent from this list, which looks like the same class of oversight, but altering them is a separate product decision the user has not made — note it in the SUMMARY as an observation rather than acting on it.

**Acceptance criteria:**
- A project with one `hardware` and one `hardware_supply_only` line yields only the `hardware` line as a schematic device node
- The `hardware` line is unaffected
- Existing drawings tests pass unchanged

---

## Constraints

- No migration. No new packages.
- PHPUnit 11, NOT Pest. `extends Tests\TestCase`, `use RefreshDatabase;` where DB access is needed.
- Lint: `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l <file>`
- **`QuoteParserService` is large and heavily tested.** Defect A changes a heuristic that real imports depend on. Run the full QuoteParser suite and treat ANY newly-failing test as a blocker, not as a fixture to update.
- Local-edit-then-upload (Phase 21 D-13) → `php artisan optimize:clear` after upload. No migration.
- Known pre-existing failures: 2 `DrawIoSpikeController` constructor-arity tests in `deferred-items.md` — not regressions.

## Explicitly out of scope

- Repairing package 147's stored data — that is a user-side data fix on the review screen (replace the two junk rooms, assign areas to the 14 equipment lines).
- Any "no equipment matched a room" warning on the drawings screen — worth doing, but a separate change.
