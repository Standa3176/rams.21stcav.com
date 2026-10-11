# Deferred items — 261011-d62

## D62-DEF-01 — CLOSED 2026-10-11 (`f5ff0220`), not deferred after all

**Closed by the orchestrator in the same quick task.** The user asked to "fix worksheet";
leaving a known, identical 500 on the DOCX download would have been narrowing that request.
Fixed by swapping the cast for `WorksheetInstallSteps::lines()` and deleting the duplicated
split-and-strip loop at `:296-306`. Proven RED first — `ErrorException: Array to string
conversion` at `:295`, shapes (b) and (c) failing while (a) and empty passed — then green.
New guard `tests/Feature/Worksheets/WorksheetDocxInstallStepsShapeTest` reads
`word/document.xml` back out of the written `.docx` and asserts the step TEXT and the phase
TITLES, so it cannot pass on a builder that silently drops steps.
Measured: Worksheets 283 → **287 passed / 2458 assertions**, D-06 baseline 159/0/2, three
protected files byte-identical.

<details><summary>Original deferral note, kept for the record</summary>

## D62-DEF-01 (original) — `WorksheetDocxService.php:295` has the identical unguarded cast

**Found during:** Task 3, scanning for other `install_steps` readers after the two
views were fixed.

```php
// app/Services/WorksheetDocxService.php:295
$installStepsRaw = trim((string) ($room['install_steps'] ?? ''));
```

This is byte-for-byte the same defect the engineer link had: `(string) $array`
raises "Array to string conversion", which `HandleExceptions` promotes to a
thrown `ErrorException`. So the **DOCX download** throws on shape (b) (flat
array, written by `WorksheetEditAdapter`) and shape (c) (nested phase list,
written by `WorksheetGeneratorService:543`) — the same two shapes, the same
cause, a third surface.

It is sharper than it looks: `WorksheetShowViewParityTest`'s whole premise is
that the web view and the DOCX must not diverge on the same `generated_data`
payload. After 261011-d62 the two views render all three shapes and the DOCX
still throws on two of them, so the channels now diverge in the opposite
direction.

**Not fixed here, deliberately.** 261011-d62's PLAN.md declares
`files_modified` as the two Blade views plus the helper and its tests, and this
is a pre-existing failure on a surface the plan does not name — not a
regression caused by this task's changes. The fix is one line: swap the cast
for `\App\Support\WorksheetInstallSteps::lines($room['install_steps'] ?? null)`
and drop the now-duplicated split/strip loop at `:297-304`, which the helper
already does (and does better — the inline regex has no `/u`, so a 3-byte `•`
is stripped one byte at a time into mojibake).

Needs its own quick task with its own RED proof against
`WorksheetDocxService`.

## D62-DEF-02 — the engineer link's `<ol>` numbers phase headings too

Shape (c) flattens into the existing `<ol class="steps">`, so a phase heading
line gets an ordinal of its own and the drawer's count (`Install Steps (9)`)
counts headings alongside actions. Rendered and read: the heading is
unmistakably a heading (`PHASE 1 — Pre-Start & Access`, uppercase marker), so it
does not read as just another action — but the ordinals no longer correspond to
the number of actions.

Fixing it properly needs CSS counters plus a per-row class in
`public-show.blade.php`'s standalone `<style>` block, which is a materially
larger edit to a pinned 5,400-line document than D-05's "smallest possible"
allows. Left as a readability follow-up, not a correctness one.

</details>
