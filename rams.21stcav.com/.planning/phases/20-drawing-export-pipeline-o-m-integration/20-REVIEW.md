---
phase: 20-drawing-export-pipeline-o-m-integration
reviewed: 2026-05-03T12:00:00Z
depth: standard
files_reviewed: 28
files_reviewed_list:
  - .env.example
  - app/Console/Commands/AuditDrawingLicensesCommand.php
  - app/Console/Commands/PdfSmokeTestCommand.php
  - app/Http/Controllers/ProjectDrawingController.php
  - app/Jobs/BuildBoundPdfJob.php
  - app/Mail/BoundPdfReadyMail.php
  - app/Models/ProjectDrawing.php
  - app/Services/Drawings/BoundPdfBuilderService.php
  - app/Services/Drawings/DrawingService.php
  - app/Services/Drawings/SheetNumberAllocator.php
  - composer.json
  - config/queue.php
  - database/migrations/2026_05_03_000001_add_sheet_number_to_project_drawings_table.php
  - docs/runbooks/drawings-queue-runbook.md
  - public/fonts/.gitkeep
  - resources/views/emails/bound-pdf-ready.blade.php
  - resources/views/pdf/drawings/_title-block.blade.php
  - resources/views/pdf/drawings/bound-cover.blade.php
  - resources/views/pdf/drawings/rack.blade.php
  - resources/views/pdf/drawings/schematic.blade.php
  - resources/views/projects/drawings/index.blade.php
  - routes/web.php
  - tests/Feature/Console/AuditDrawingLicensesTest.php
  - tests/Feature/Console/PdfSmokeTestRackTest.php
  - tests/Feature/Drawings/BoundPdfDownloadTest.php
  - tests/Feature/Drawings/OmManualEmbedsRackTest.php
  - tests/Feature/Drawings/ZipBundleDownloadTest.php
  - tests/Unit/Services/Drawings/BoundPdfBuilderServiceTest.php
  - tests/Unit/Services/Drawings/SheetNumberAllocatorTest.php
findings:
  critical: 0
  warning: 4
  info: 6
  total: 10
status: issues_found
---

# Phase 20: Code Review Report

**Reviewed:** 2026-05-03T12:00:00Z
**Depth:** standard
**Files Reviewed:** 28
**Status:** issues_found

## Summary

Phase 20 ships the v1.3-final drawing-export pipeline (DRAW-21 bound PDF, DRAW-23 sheet numbering, DRAW-28 ZIP bundle) plus production hardening (MOD-01/10/12, CRIT-03/04). Overall the implementation is solid, conforms to project conventions (CLAUDE.md `ClassName:` log prefixes, `DocumentArtifactStorage` for all artifact paths, `{{ }}` escaping for user-controlled data with explicit trust-source comments where `{!! !!}` is used), and has thoughtful threat-model coverage with backing tests.

Specifically verified:
- **CRIT-04 ZIP path traversal:** `basename($realPath)` is applied to every `addFile` call in `ProjectDrawingController::downloadBundle`. `ZipBundleDownloadTest::test_zip_entry_names_have_no_path_traversal` asserts no `..`, no leading `/`, and no leading `\` in any entry name.
- **CRIT-03 per-drawing failure isolation:** `BoundPdfBuilderService::build` wraps each per-drawing render in a try/catch (line 87-101); failure logs `BoundPdfBuilderService:` warning, marks the register row with `[render failed]`, skips concat. `BoundPdfBuilderServiceTest::test_failed_drawing_is_skipped_but_register_still_lists_it` locks this contract.
- **Authorization on new routes:** `downloadBoundPdf` / `downloadBundle` / `regenerateBoundPdf` all gate via `$this->authorize('view', $project)` → `ProjectPolicy::view` (owner-or-admin). `BoundPdfDownloadTest::test_non_owner_gets_403` and `ZipBundleDownloadTest::test_non_owner_gets_403` exercise the deny path.
- **Migration safety:** Single nullable `string('sheet_number', 20)` column added after `version`. Down() reverses it cleanly. Pre-Phase-20 rows (Phase 17/18 schematics + racks) keep working because every read path uses `$drawing->sheet_number ?? '—'`.
- **License footprint:** `setasign/fpdi` (v2.6.6, MIT) + `setasign/fpdf` (1.8.6, MIT) verified in composer.lock — no GPL/AGPL/LGPL introduction. The `drawings:audit-licenses` command + tests gate this on every deploy.
- **Job constructor shape:** `BuildBoundPdfJob::__construct(public readonly int $projectId)` is project-level (not drawing-level), assigns the queue via `$this->onQueue('drawings')` inside the constructor (the documented workaround for the typed `public string $queue` PHP fatal vs the untyped Queueable trait property).
- **Logging conventions:** Every Log call inspected uses the `ClassName:` prefix per CLAUDE.md (`'BuildBoundPdfJob: …'`, `'BoundPdfBuilderService: …'`, `'ProjectDrawingController: …'`, `'DrawingService: …'`).
- **Documentation:** `docs/runbooks/drawings-queue-runbook.md` is informational and well-structured; not flagged as an issue.

The findings below are correctness/UX issues, not v1.3-blockers.

## Warnings

### WR-01: `regenerateBoundPdf` uses `view` authorization for a state-mutating action

**File:** `app/Http/Controllers/ProjectDrawingController.php:550-564`
**Issue:** `regenerateBoundPdf` dispatches `BuildBoundPdfJob` (a job that consumes Chrome RAM, writes to disk, and sends an email) but only authorizes `$this->authorize('view', $project)`. Functionally OK today because `ProjectPolicy::view` and `::update` resolve to the same owner-or-admin set, but the intent of `view` (read-only) and the action (write/dispatch) are mismatched. If a future reviewer-role lands that grants `view` but not `update`, this endpoint would let reviewers enqueue queue work. The same `view`-vs-write smell exists on `downloadBoundPdf` because it can trigger an inline build for projects with ≤3 drawings (CPU + disk write inside the response).
**Fix:** Switch to `update` authorization on the explicit-regenerate POST route, and consider `update` for `downloadBoundPdf` when the inline-build branch fires. Minimum change:
```php
public function regenerateBoundPdf(Request $request, Project $project): RedirectResponse
{
    $this->authorize('update', $project);   // not 'view'
    BuildBoundPdfJob::dispatch((int) $project->id);
    // …
}
```

### WR-02: `BoundPdfBuilderService::concat()` does not isolate FPDI parse failures per source PDF

**File:** `app/Services/Drawings/BoundPdfBuilderService.php:189-206`
**Issue:** Per-drawing **render** failures are isolated at line 87-101 (good — CRIT-03), but per-drawing **parse** failures inside FPDI are not. If the upstream `renderer->renderPdf($drawing)` succeeds but writes a malformed PDF (early-aborted Browsershot render that flushes a partial header, disk-full truncation, etc.), `$pdf->setSourceFile($src)` throws `\setasign\Fpdi\PdfParser\PdfParserException` and the whole bound-PDF assembly aborts before `Output()` is called. The cover sheet plus all already-imported pages are lost, and `BuildBoundPdfJob::handle` catches and rethrows → retry → same failure. The CRIT-03 mitigation only protects the render half, not the concat half.
**Fix:** Wrap each `setSourceFile`/`importPage` block in its own try/catch and append the failure to a separate "concat-failed" list that the cover sheet can surface in the same `[render failed]` style:
```php
private function concat(string $outPath, array $sourcePdfPaths): void
{
    $pdf = new Fpdi();
    foreach ($sourcePdfPaths as $src) {
        if (! is_file($src)) {
            continue;
        }
        try {
            $pageCount = $pdf->setSourceFile($src);
            for ($p = 1; $p <= $pageCount; $p++) {
                $tplId  = $pdf->importPage($p);
                $size   = $pdf->getTemplateSize($tplId);
                $orient = ($size['width'] > $size['height']) ? 'L' : 'P';
                $pdf->AddPage($orient, [$size['width'], $size['height']]);
                $pdf->useTemplate($tplId);
            }
        } catch (\Throwable $e) {
            Log::warning('BoundPdfBuilderService: concat parse failure (skipping source)', [
                'src'   => basename($src),
                'error' => $e->getMessage(),
            ]);
        }
    }
    $pdf->Output($outPath, 'F');
}
```

### WR-03: `downloadBoundPdf` triggers a synchronous build for projects with zero drawings

**File:** `app/Http/Controllers/ProjectDrawingController.php:505-543`
**Issue:** When `$drawings->isEmpty()`, `$maxDrawingTs` is `0` and the latest-bound-pdf staleness check on line 521-526 always falls through. The "≤3 drawings" inline branch (line 529) fires unconditionally because `$drawings->count() === 0` is `≤ 3`. The result: hitting `GET projects/{id}/drawings/bound-pdf` for a project with zero drawings runs Browsershot synchronously to render only the cover sheet + empty register. The Blade index hides the button when `$renderableDrawings->isEmpty()`, but the route is reachable directly (admin tooling, deep links from emails for an emptied project, etc.). Mild DoS surface — Chrome startup is ~1-2s per hit, and the response stream a useless cover-only PDF.
**Fix:** Short-circuit before touching the builder:
```php
if ($drawings->isEmpty()) {
    return redirect()
        ->route('projects.drawings.index', $project)
        ->withErrors(['bound_pdf' => 'No drawings in this project — create a schematic or rack first.']);
}
```

### WR-04: `BuildBoundPdfJob::handle()` always rebuilds, even if disk artifact is fresher than every drawing

**File:** `app/Jobs/BuildBoundPdfJob.php:91-157`
**Issue:** Unlike `ProjectDrawingController::downloadBoundPdf` which checks `filemtime($latestPath) >= $maxDrawingTs` and short-circuits to a re-stream, the async job path always calls `$builder->build($project)`. Two clicks on the explicit "Regenerate Bound PDF" button 65 seconds apart (past the WithoutOverlapping releaseAfter window) result in two full builds of an unchanged project — Chrome render, FPDI concat, email send — even though nothing has changed. Not a correctness bug but a pointless cost on a Chrome-RAM-constrained worker (per the runbook: 200-400 MB per render, capped at `--memory=512`).
**Fix:** Apply the same staleness short-circuit at the top of `handle()`:
```php
public function handle(BoundPdfBuilderService $builder): void
{
    $project = Project::find($this->projectId);
    if (! $project) { /* … */ return; }

    $drawings = $project->drawings()
        ->whereNull('superseded_by_id')
        ->whereIn('kind', [ProjectDrawing::KIND_SCHEMATIC, ProjectDrawing::KIND_RACK])
        ->get();
    $maxDrawingTs = $drawings->map(fn ($d) => $d->updated_at?->getTimestamp() ?? 0)->max() ?? 0;
    $latest = $builder->latestBoundPdfPath($this->projectId);
    if ($latest !== null && is_file($latest) && filemtime($latest) >= $maxDrawingTs) {
        Log::info('BuildBoundPdfJob: existing bound PDF is fresh — skipping rebuild', [
            'project_id' => $this->projectId, 'path' => $latest,
        ]);
        // Optionally still send the completion email pointing at $latest.
        return;
    }
    // … continue with build
}
```

## Info

### IF-01: Sheet-number allocator races are possible under concurrent draft creation

**File:** `app/Services/Drawings/SheetNumberAllocator.php:54-71`
**Issue:** Two near-simultaneous `createForProject(KIND_SCHEMATIC)` calls for the same project both compute the count, both observe the same `existing` value, and both insert AV-201. There's no DB unique index on `(project_id, kind, sheet_number)` to prevent the duplicate, no `lockForUpdate`, and no transactional guard inside the allocator. In practice this is unlikely (engineers click "Create Drawing" sequentially), but worth flagging since the migration is additive and a unique index could land in a follow-up without rewriting the allocator.
**Fix:** Either (a) add a unique constraint in a follow-up migration `unique(['project_id', 'kind', 'sheet_number'])` so duplicates fail loudly and surface for retry, or (b) wrap allocation in `DB::transaction` + `lockForUpdate` on the project row.

### IF-02: ZIP per-drawing render in `downloadBundle` runs synchronously inside the HTTP response

**File:** `app/Http/Controllers/ProjectDrawingController.php:579-690`
**Issue:** Unlike `downloadBoundPdf` which offloads to a job past the 3-drawing threshold, `downloadBundle` always builds the ZIP synchronously regardless of project size. A 30-drawing project renders 30 PDFs + 30 SVGs + 30 PNGs in one HTTP request — easily past PHP's `max_execution_time` and any reverse-proxy timeout (typically 60-120s). The bound-PDF inline-build inside is also forced when stale. Document the practical upper bound or apply the same async dispatch pattern.
**Fix:** Either time-bound the ZIP path (defer to a job past N drawings, mirror `downloadBoundPdf`'s pattern) or document the limit in the runbook and add a Browsershot-render concurrency cap.

### IF-03: `extractVersion` regex is greedy on the project ID portion

**File:** `app/Services/Drawings/BoundPdfBuilderService.php:234-241`
**Issue:** `preg_match('/-v(\d+)-/', basename($absPath), $m)` matches the first `-vN-` substring it finds. With the canonical filename `bound-{projectId}-v{N}-{ulid}.pdf` this is unambiguous, but if a future filename convention adds a `-vN-` segment elsewhere (e.g. a config-version suffix) the wrong number could match. Anchor the regex to the suffix shape:
```php
if (preg_match('/-v(\d+)-[0-9a-z]{26}\.pdf$/', basename($absPath), $m)) {
    return (int) $m[1];
}
```

### IF-04: `BoundPdfReadyMail::attachments()` silently drops the attachment when the bound PDF was deleted between job dispatch and email send

**File:** `app/Mail/BoundPdfReadyMail.php:54-65`
**Issue:** If the bound PDF file is removed between the job completing and the queued mail being processed (cleanup script, manual delete, race with regenerate), `attachments()` returns `[]` — the email goes out body-only with no PDF and no warning. Since the body says "The bound PDF is attached to this email", the recipient gets a misleading message.
**Fix:** When `is_file($this->boundPdfPath)` is false, log a warning at minimum (`Log::warning('BoundPdfReadyMail: attachment file missing at send time', [...])`) so operators can correlate "no attachment" reports back to the missing file.

### IF-05: `downloadBundle` filename sanitisation could squash UTF-8 ref characters

**File:** `app/Http/Controllers/ProjectDrawingController.php:676-677`
**Issue:** `preg_replace('/[^A-Za-z0-9_\-.]/', '_', $rawName)` replaces every non-ASCII character (including legitimate UTF-8 ref characters used by some clients) with `_`. For a project ref like `21CQ-Wörley-EU-V6` the user gets `21CQ-W_rley-EU-V6-drawings-…`. Functional but cosmetically lossy. Consider `preg_replace('/[^\p{L}\p{N}_\-.]/u', '_', $rawName)` to keep multibyte letters/digits while stripping path-traversal characters.

### IF-06: Verbose CONTEXT.md / planning references inside production source

**File:** `app/Services/Drawings/BoundPdfBuilderService.php` (PHPDoc), `app/Jobs/BuildBoundPdfJob.php` (PHPDoc), `config/queue.php:47-72` (comment), `app/Console/Commands/AuditDrawingLicensesCommand.php` (PHPDoc), `database/migrations/2026_05_03_000001_add_sheet_number_to_project_drawings_table.php` (PHPDoc), and many Blade comment blocks
**Issue:** Several files carry multi-paragraph references to `CONTEXT.md`, `Plan 20-01 SUMMARY`, "Warning 9 fix", "T-20-02", etc. These are excellent during development but couple production code to ephemeral planning artifacts that may be archived or removed. Once Phase 20 ships, the cross-references will rot. Not a v1.3 blocker — flagging as a maintenance note. Recommend a follow-up sweep that distills the still-useful guidance into in-tree docs (e.g. promote the queue rationale into a permanent `docs/queues.md` and tighten the inline comment).

---

_Reviewed: 2026-05-03T12:00:00Z_
_Reviewer: Claude (gsd-code-reviewer)_
_Depth: standard_
