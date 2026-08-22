# Phase 260822-esf: Project Deliverables Selection - Research

**Researched:** 2026-08-22
**Domain:** Laravel lifecycle state machine + document-generation surface reconciliation (internal RAMS/AV-documentation app)
**Confidence:** MEDIUM-HIGH — status machine, storage/adapter lists, and audit precedent are HIGH (read directly from code with line numbers). Import-default derivation (D-15) is MEDIUM (signal exists and is verified, but its precision is bounded). Retrofit counts (D-17) are LOW (local DB has no representative data — see Open Questions).

## Summary

D-11 (skip `STATUS_SURVEY_PENDING` entirely) is the highest-risk item, and the sweep below confirms why: `Project::TRANSITIONS` / `TRANSITIONS_BACKWARD` are **static class constants** with a fixed `quote_imported → survey_pending → engineering` shape, `canTransitionTo()` is pure array-membership logic with no per-project awareness, and there are **two live auto-advance hooks** (`QuoteImportService::confirm()`, `SurveyService::complete()`/`submitPublic()`) that call it. A not-required Survey needs `quote_imported → engineering` to become a *valid, conditional* transition — which means `canTransitionTo()`/`TRANSITIONS` can no longer be pure constants; they need to become deliverables-aware (project-instance methods, not static lookups). Beyond the transition table itself, the project-show stepper (`show.blade.php:702-742`) renders `Project::LIFECYCLE` unconditionally and marks any status with a lower array index than the current one as "done" (`$isPast = $i < $currentIdx`) — a skipped Survey stage would render with a false checkmark unless this is fixed in the same pass. A hard-coded "Next Step" decision chain (`show.blade.php:410-483`) also assumes Survey → RAMS → Worksheet → O&M happen in that fixed order with no not-required awareness, and is arguably the single most user-visible place D-11/D-12 must land correctly.

D-07's "three disagreeing lists" undercounts the problem: this sweep found **five**, not three (see below), including one that's dead code. Reconciling only the three named in CONTEXT.md will leave two more inconsistent.

D-15's signal is real and verified: `EquipmentCategoryClassifier::classify()` (`app/Services/Imports/EquipmentCategoryClassifier.php`) already buckets any equipment row whose part-number or description mentions install/labour/commissioning/programming/RAMS/site-survey SKU tokens into a single `services` category, and this classifier runs for **both** the QuoteWerks and PDF-parse import paths by the time the review screen loads. But it's a single collapsed bucket — there's no way to distinguish "no install line" from "no RAMS line" from "no survey line" once persisted, so D-15's rule ("no labour/install lines → RAMS, Worksheet, Survey **all** default Not required") maps cleanly onto this signal, but a *more granular* per-deliverable default is not achievable without deeper classifier changes.

D-03's precedent (`device_stencil_audits`, Phase 24) is a clean, directly-reusable shape but is missing the `reason` free-text column D-03 requires — it needs to be added, not just copied.

**Primary recommendation:** Treat this phase as three separable concerns with different risk profiles — (1) schema + CRUD for the deliverables record itself (low risk, new table), (2) the status-machine change (highest risk, touches a static const-based state machine used by 2 auto-advance hooks + a stepper view + ~15 tests), (3) the "next step"/tab-strip UI reconciliation across 5 disagreeing lists (medium risk, mostly additive). Plan and sequence them as separate waves.

## Architectural Responsibility Map

| Capability | Primary Tier | Secondary Tier | Rationale |
|------------|-------------|----------------|-----------|
| Deliverables required/not-required/undecided record | Database / Storage | API/Backend (Eloquent model + service) | New persisted state, one row set per project |
| Audit trail (who/when/why) | Database / Storage | API/Backend | Append-only history table, written by the service layer on every flag change |
| Status-machine skip logic (D-11) | API/Backend | — | `Project::canTransitionTo()` / `ProjectService::transition()` — must become deliverables-aware |
| Health calculation filter (D-12) | API/Backend | — | `ProjectHealthService::assess()` — pure function over eager-loaded relations, single caller (`DashboardController`) |
| Tab strip / "Not required" grouping (D-08/D-09) | Browser / Client (Blade + Alpine) | API/Backend (counts) | Presentation-only; counts already computed server-side per request |
| Import confirm-flow checklist step (D-15/D-16) | API/Backend (defaults) | Browser/Client (form step) | Defaults derived server-side from `extracted_data['equipment']`; UI is a new step in an existing multi-step confirm flow |
| Manual-create checklist (D-18) | API/Backend + Browser/Client | — | `ProjectController::store()` → `ProjectService::create()`; note the create form is currently unreachable via the primary "New Project" link (see Common Pitfalls) |
| Retrofit / backfill (D-17) | Database / Storage | API/Backend (migration) | One-time inference pass over existing `RamsDocument`/`SiteSurvey`/etc. relations, written as a data migration |

## Phase Requirements

No `.planning/REQUIREMENTS.md` entries exist for this phase — it is an out-of-milestone track (`milestone: none` per `.planning/STATE.md` front-matter), same as `260726-rf3` / `260727-wt1`. Requirement coverage is defined entirely by `260822-CONTEXT.md`'s 18 locked decisions; there is no separate requirement-ID table to cross-reference.

## Standard Stack

No new external packages. This phase is pure application code: one new Eloquent model + migration (deliverables record), one new audit-trail model + migration (mirroring `DeviceStencilAudit`), edits to `Project`, `ProjectHealthService`, `ProjectService`, `QuoteImportService`, `SurveyService`, and several Blade views. No `composer require` is anticipated.

**Package Legitimacy Audit:** Not applicable — no packages are being installed.

## Project Constraints (from CLAUDE.md)

- Tech stack is Laravel/PHP + Blade + Alpine.js (this repo's `CLAUDE.md` is for a *different* project — the GSD Dashboard — and does not apply to `rams.21stcav.com`). The actually-applicable conventions are `.planning/codebase/CONVENTIONS.md`, `.planning/codebase/ARCHITECTURE.md`, `.planning/codebase/TESTING.md`, referenced throughout this document.
- PHPUnit 11 — NOT Pest. New test files must extend `Tests\TestCase` (Laravel, needs DB) or `PHPUnit\Framework\TestCase` (pure unit, no DB) per the existing split (see `ProjectTransitionTest.php` for the pure-unit style, `ProjectHealthServiceTest.php` for the Laravel-with-`setRelation()` style).
- Lint every touched PHP file with `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" -l <file>`.
- Blade files touched must be verified with `blade.compiler->compileString()`, not just `php -l` — confirmed necessary precedent: a JS comment in a shared component broke Blade compilation site-wide and `php -l` passed it clean (260817-jsg, see `.planning/STATE.md`).
- Do not touch `config/rams_tier1.php` — unrelated, awaiting H&S sign-off.
- Local dev DB is SQLite (`APP_ENV=local`, verified via `.env`: `DB_CONNECTION=sqlite`); production is MySQL/MariaDB. Any migration must be portable — this codebase has an explicit house rule (`device_stencil_audits` migration docblock) that backfill logic must be plain PHP loops, never raw `JSON_EXTRACT()`/`->>` SQL, because that syntax diverges between SQLite and MariaDB.

<user_constraints>
## User Constraints (from CONTEXT.md)

### Locked Decisions

**Selection model**
- D-01: Three states, not two — `Required` / `Not required` / `Not yet decided`.
- D-02: Soft gate that auto-flips. Creating a deliverable marked Not required flips the flag back to Required automatically. No hard block anywhere.
- D-03: Full audit trail. Every flag change records who, when, and why (reason optional free text; who/when automatic).

**Canonical deliverables list**
- D-04: Nine selectable deliverables — Site Survey, RAMS, Worksheet, O&M, Cable Schedule, Install Programme, Drawings, Snagging, **Programming**.
- D-05: Programming is a tracked flag with NO generator. Records that the project needs programming work (Crestron / Q-SYS config etc.). Building a generator is explicitly NOT in this phase.
- D-06: Quotes, Asset Register and Project Data are excluded — they are inputs, not deliverables, and must not be selectable.
- D-07: This list becomes the single source of truth. Three lists disagree today — project tabs (9), `DocumentArtifactStorage::TYPE_*` (8), `DocumentEditAdapterRegistry` (6). Planning must reconcile against D-04 rather than adding a fourth list. Consequence: Drawings and Snagging have no project tab today, and Snagging has no edit adapter. Including them means this phase adds tabs. Programming has neither and no model at all.

**Presentation**
- D-08: Muted and moved to the end — never hidden. Not-required deliverables stay in the tab strip under a "Not required" grouping, with an inline "add anyway" that flips the flag.
- D-09: A deliverable holding data is never hidden, regardless of flag state. Marking it not-required warns and leaves it fully visible.
- D-10: Edited from the Project Data tab after setup — the tab that already exists for project-level settings. No new navigation.

**Health and lifecycle**
- D-11: A not-required Survey skips `STATUS_SURVEY_PENDING` entirely. The project moves from `quote_imported` straight to `engineering`. Not a pass-through — the stage genuinely never happened and the audit trail should not claim it did. This changes the status machine; anything assuming a fixed stage sequence must be found and updated.
- D-12: Not-required deliverables drop out of the health calculation entirely — not "treated as satisfied". No red, no amber, no mention. Treating them as satisfied would overstate progress percentages by counting work that was never done. This is the direct fix for the permanently-red hardware-only project.
- D-13: "Not yet decided" goes amber after a grace period. Long enough not to shout on day one, short enough that the state cannot become a permanent parking space. Grace duration is Claude's discretion.
- D-14: Completion warns, does not block. Marking a project Completed with required-but-missing deliverables lists what is outstanding and asks for confirmation. Consistent with D-02.

**Defaults and rollout**
- D-15: Import defaults are derived from quote content. No labour/install lines → RAMS, Worksheet and Survey default to `Not required`. The import already has this information.
- D-16: The checklist is a step in the existing import confirm flow, not a modal layered on the review screen.
- D-17: Existing projects are inferred from what already exists. Has a RAMS → `Required`; none → `Not yet decided`. Explicitly rejected: defaulting the whole back-catalogue to `Not yet decided`, which combined with D-13 would turn the entire project list amber on day one.
- D-18: Manual projects get the same checklist on the create form. `ProjectService::create()` is a real second entry point; without this the two paths diverge. No quote exists there, so it needs its own sensible default (Claude's discretion).

### Claude's Discretion

- Grace-period duration before `Not yet decided` goes amber (D-13).
- Default state for manually-created projects where no quote exists (D-18).
- Schema shape — dedicated table vs JSON column on `projects`; audit storage mechanism (D-03).
- Exact copy and visual treatment of the "Not required" tab grouping.
- Whether the amber prompt appears on the project list, the project page, or both.

### Deferred Ideas (OUT OF SCOPE)

- A Programming document generator. D-05 keeps Programming as a flag only. Building a generated Programming deliverable is its own phase.
- `type=drawing` AI-edit surface is dead. Found during `260817-w4k`: `ProjectDrawing` has no `user_id`, so every document-edit endpoint 404s for drawings. Pinned by a test, deliberately unchanged. D-04 now puts Drawings on the deliverables list, which makes this more visible, not less.
- Snagging has no edit adapter. D-04 includes it as a deliverable; wiring it into the document-edit surface is separate work.
- `config/rams_tier1.php` hazard-library changes — unrelated to this phase, still awaiting the user's H&S sign-off.
</user_constraints>

## Architecture Patterns

### System Architecture Diagram

```
                        ┌─────────────────────────────────────────┐
                        │  Import confirm flow (D-16)              │
  Quote upload ────────▶│  QuoteImportService::confirm()           │
                        │  + NEW: deliverables-checklist step       │
                        │    reads extracted_data['equipment']       │
                        │    (category === 'services' signal, D-15) │
                        └─────────────┬─────────────────────────────┘
                                      │ writes ProjectDeliverable rows
                                      ▼
   ┌──────────────────────────────────────────────────────────────┐
   │  Project (Eloquent)                                            │
   │  ── status: quote_imported → [survey_pending?] → engineering →│
   │     installing → commissioning → handover → completed          │
   │  ── D-11: canTransitionTo() must consult deliverables to        │
   │     decide whether survey_pending is skippable                  │
   └───────┬───────────────────────────────────┬────────────────────┘
           │ read by                             │ read by
           ▼                                      ▼
 ┌─────────────────────────┐         ┌─────────────────────────────┐
 │ ProjectHealthService     │         │ projects/show.blade.php      │
 │ ::assess() (D-12)        │         │ ── stepper (LIFECYCLE array)  │
 │ single caller:            │         │ ── "Next Step" chain (D-11/12)│
 │ DashboardController       │         │ ── tab strip (D-04/07/08/09)  │
 └─────────────────────────┘         └─────────────────────────────┘
           │                                      │
           ▼                                      ▼
   Dashboard health chip                Project Data tab (D-10)
   (green/amber/red)                    NEW: edit deliverables form
```

### Recommended Project Structure

```
app/
├── Models/
│   ├── Project.php                       # canTransitionTo() becomes instance-aware, D-11
│   ├── ProjectDeliverable.php             # NEW — one row per (project, deliverable_key)
│   └── ProjectDeliverableAudit.php        # NEW — mirrors DeviceStencilAudit
├── Services/
│   ├── ProjectHealthService.php           # D-12 filter added, existing rule structure kept
│   └── ProjectDeliverablesService.php     # NEW — set/flip/audit, mirrors ProjectService's shape
├── Core/Modules/
│   ├── Projects/ProjectService.php        # D-18 hook in create()
│   ├── QuoteImport/QuoteImportService.php # D-11/D-15/D-16 hook in confirm()
│   └── Survey/SurveyService.php           # D-11 hook in complete()/submitPublic()
database/migrations/
├── ..._create_project_deliverables_table.php
└── ..._create_project_deliverable_audits_table.php
resources/views/projects/
└── show.blade.php                         # stepper, tab strip, Next-Step chain, Project Data tab
```

### Pattern 1: Audit-trail table (D-03) — mirror `device_stencil_audits`

**What:** Append-only history table, one row per flag change, holding before/after JSON snapshots + actor + timestamp.
**When to use:** Any time a flag/state change is a claim that must be defensible later ("we produced no O&M because it was not required").
**Example — schema** (`database/migrations/2026_08_13_140000_..._create_device_stencil_audits.php:48-60`):
```php
Schema::create('device_stencil_audits', function (Blueprint $table) {
    $table->id();
    $table->foreignId('device_stencil_id')->constrained('device_stencils')->cascadeOnDelete();
    $table->foreignId('user_id')->constrained('users');
    $table->string('action', 30);
    $table->json('before_snapshot')->nullable();
    $table->json('after_snapshot')->nullable();
    $table->timestamps();
});
```
**Gap vs D-03:** This precedent has **no `reason` column**. D-03 explicitly requires an optional free-text reason — add `$table->text('reason')->nullable();` when adapting this shape; do not copy it verbatim.

**Example — write pattern** (`app/Http/Controllers/Admin/DeviceStencilController.php:231-240`):
```php
DeviceStencilAudit::create([
    'device_stencil_id' => $deviceStencil->id,
    'user_id'           => auth()->id(),
    'action'            => DeviceStencilAudit::ACTION_EDIT,
    'before_snapshot'   => $beforeSnapshot,
    'after_snapshot'    => ['mxgraph_xml' => $payload['mxgraph_xml'], 'ports' => $validatedPorts],
]);
```

### Pattern 2: Lifecycle service — all transitions go through `ProjectService::transition()`

**What:** `ProjectService::transition(Project $project, string $toStatus, User $user, ?string $note = null)` (`app/Core/Modules/Projects/ProjectService.php:63-99`) is the single choke point: it checks `canTransitionTo()`, stamps the milestone timestamp column, and writes a `ProjectActivityLog` row. Direct `$project->update(['status' => ...])` bypasses logging.
**When to use:** Any code that changes `Project::status` — including the new D-11 skip-to-engineering path.
**Example:**
```php
// app/Core/Modules/Projects/ProjectService.php:63
public function transition(Project $project, string $toStatus, User $user, ?string $note = null): Project
{
    if (! $project->canTransitionTo($toStatus)) {
        throw new InvalidArgumentException(/* ... */);
    }
    // ... stamps milestone column, logs, returns fresh project
}
```

### Pattern 3: Two live auto-advance hooks call `canTransitionTo()` directly

**What:** Both hooks guard with `canTransitionTo()` then wrap `transition()` in try/catch, logging a warning on failure without throwing (so the primary action — confirm/survey-submit — never fails because of a lifecycle side-effect).
**Hook 1 — quote confirm → survey_pending** (`app/Core/Modules/QuoteImport/QuoteImportService.php:423-444`):
```php
if (
    $linkedProject?->status === Project::STATUS_QUOTE_IMPORTED &&
    $linkedProject->canTransitionTo(Project::STATUS_SURVEY_PENDING)
) {
    try {
        $this->projectService->transition($linkedProject, Project::STATUS_SURVEY_PENDING, $user);
    } catch (\InvalidArgumentException) { /* log + swallow */ }
}
```
**Hook 2 — survey submit → engineering** (`app/Core/Modules/Survey/SurveyService.php:451-466` and again at `:555-570` for the public-submit path — **two separate call sites, both need the D-11 change**):
```php
if ($project->canTransitionTo(Project::STATUS_ENGINEERING)) {
    try {
        $this->projects->transition($project, Project::STATUS_ENGINEERING, $user);
    } catch (\InvalidArgumentException) { /* log + swallow */ }
}
```
**D-11 implication:** Hook 1 must be extended so that when Survey is `Not required`, it transitions straight to `STATUS_ENGINEERING` instead of `STATUS_SURVEY_PENDING` (and Hook 2 becomes a no-op for that project, since it will already be past `survey_pending`). This means `Project::canTransitionTo()` can no longer be answered from static `TRANSITIONS` constants alone — it needs to know the project's deliverables state.

## Don't Hand-Roll

| Problem | Don't Build | Use Instead | Why |
|---------|-------------|-------------|-----|
| Audit trail for a flag change | A new ad-hoc "history" JSON blob or reuse of `metadata` (holds only the last edit) | A dedicated append-only table, mirroring `device_stencil_audits` | This codebase has already made and documented this exact design decision once (Phase 24 D-03); the doc explicitly warns that `metadata` "only ever holds the LAST edit, not full history" |
| Deriving "does this project need labour/install/RAMS work" from quote text | A new keyword scanner | `EquipmentCategoryClassifier::classify()` (`app/Services/Imports/EquipmentCategoryClassifier.php`) — reuse its `services`-category signal | Already runs for both import paths, already tuned against real 21CAV/QuoteWerks SKU vocabulary (see the extensive keyword lists in that file), and a second scanner would drift from it over time |
| Status-machine gating | Inline `if ($project->status === X)` checks scattered through controllers | `Project::canTransitionTo()` / `ProjectService::transition()` | Already the single choke point; the codebase's own house rule ("All status transitions MUST go through this service") is stated in the class docblock |

**Key insight:** The single biggest hand-roll risk in this phase is NOT building something new — it's editing `Project::TRANSITIONS`/`TRANSITIONS_BACKWARD` naively as if a fifth "conditional" entry could be bolted onto a static array. It cannot: `canTransitionTo()` is currently a pure function of `(fromStatus, toStatus)` with no access to the deliverables record. The D-11 implementation needs `canTransitionTo()` to become an instance method that can consult `$this->deliverables` (or take an explicit parameter), not a lookup against a class constant.

## Runtime State Inventory

This phase is additive (new table + new UI), not a rename/refactor — but D-17 (retrofit) is a one-time backfill migration over existing rows, so a lightweight version of this inventory is worth recording.

| Category | Items Found | Action Required |
|----------|-------------|------------------|
| Stored data | Existing `Project` rows have no deliverables record today — `RamsDocument`, `SiteSurvey`, `Worksheet`, `OmManual`, `CableSchedule`, `InstallProgramme`, `ProjectDrawing` relations are the only signal D-17 can infer from. | Data migration: for each existing project, infer `Required` where a relation has ≥1 row, else `Not yet decided`, per deliverable key. |
| Live service config | None. This is a pure Laravel app with no external service that independently tracks "is this deliverable required". | None. |
| OS-registered state | None. | None. |
| Secrets/env vars | None. | None. |
| Build artifacts | None — no compiled/cached artifact references a fixed list of deliverable types that would go stale. | None. |

**Local-DB caveat:** The local SQLite dev database has exactly **1** project (status `quote_imported`, no RAMS/surveys/worksheets/O&M/cable/install/drawings). Row counts for D-17's retrofit cannot be validated locally — see Open Questions.

## Common Pitfalls

### Pitfall 1: Editing `Project::TRANSITIONS` as a static constant breaks `canTransitionTo()`'s callers silently
**What goes wrong:** `TRANSITIONS`/`TRANSITIONS_BACKWARD` are `const` arrays (`app/Models/Project.php:49-58, 65-72`) consumed by `canTransitionTo()` with pure `in_array()` checks. If D-11 is implemented by just adding `STATUS_QUOTE_IMPORTED => [STATUS_SURVEY_PENDING, STATUS_ENGINEERING]` unconditionally, then **every** project — including ones where Survey *is* required — could be transitioned straight to `engineering`, silently breaking D-11's actual intent (skip only when not required) and defeating D-2's soft-gate model.
**Why it happens:** The array is a compile-time constant with no access to instance/relation state.
**How to avoid:** `canTransitionTo()` needs to become an instance method whose `quote_imported → engineering` branch is conditional on `$this->deliverables` (or take the deliverables state as a parameter). `TRANSITIONS`/`TRANSITIONS_BACKWARD` likely stay as the "structurally possible" superset, with a second guard layer for "actually reachable given this project's deliverables".
**Warning signs:** Any new test that transitions `quote_imported → engineering` for a project where Survey IS required and expects it to fail — if it silently passes, the guard is missing.

### Pitfall 2: The lifecycle stepper will show a false checkmark for a skipped stage
**What goes wrong:** `resources/views/projects/show.blade.php:361-365` builds `$lifecycle = Project::LIFECYCLE` (all 8 statuses, unconditionally) and `$currentIdx = array_search($project->status, $lifecycle)`. The render loop (`:702-742`) marks any step with `$i < $currentIdx` as "done" (green tick). If a project skips `survey_pending` (index 1) straight to `engineering` (index 2), `currentIdx = 2` and the Survey step renders with a done-tick it never earned — visually claiming survey work happened when D-11's entire point is that it explicitly did not.
**Why it happens:** The stepper has no concept of "this stage was skipped" vs "this stage was completed" — only `isPast`/`isActive`/`isFuture` based on array index.
**How to avoid:** The stepper needs a third render state ("skipped — not required") for any lifecycle stage the project's deliverables record marks Not required, distinguishable from "done".
**Warning signs:** Manually walk a not-required-Survey project through the stepper in a browser; if Survey shows a green tick, this wasn't fixed.

### Pitfall 3: The "Next Step" decision chain is a hard-coded priority order with no not-required awareness
**What goes wrong:** `show.blade.php:410-483` is a chained `if/elseif` — `$headerAwaitingRams` → generating → no package → package not reviewed → `$countSurvey === 0` → `$countRams === 0` → `$countWorksheet === 0` → `$countOm === 0`. If Survey is `Not required` with `$countSurvey === 0` (true, since none was ever created), this chain will still tell the user "Create Site Survey" as their next step — directly contradicting D-11/D-12.
**Why it happens:** The chain checks raw counts, not deliverables state; it predates this phase.
**How to avoid:** Each `$countX === 0` branch needs an additional `&& $deliverableIsRequired('survey')` (etc.) guard, or the whole chain needs to skip not-required deliverables when deciding the next action. This is also the most user-visible manifestation of D-12 ("no red, no amber, no mention") — a wrong "next step" prompt is a mention.
**Warning signs:** A hardware-only test project (Volkswagen Blakelands is the real-world acceptance case) still shows "Create Site Survey" as its hero CTA after this phase ships.

### Pitfall 4: D-07's "three lists" is actually five (one of them dead code)
**What goes wrong:** Reconciling only the three lists named in CONTEXT.md (`show.blade.php:764-774` tabs, `DocumentArtifactStorage::TYPE_*`, `DocumentEditAdapterRegistry::DEFAULT_MAP`) leaves two more inconsistent:
- `ProjectController::show()`'s `$linkedRecords` array (`app/Http/Controllers/ProjectController.php:175-241`) — 6 entries (RAMS, Survey, Worksheet, O&M, Cable Schedule, Install Programme), each driving the "Linked Records" card's generate/download/regenerate buttons. Missing Drawings, Snagging, Programming.
- `show.blade.php:487-494`'s `$outputs` array — 6 entries, same shape as `$linkedRecords` — but **this one is computed and never rendered anywhere in the file** (confirmed via grep — `$outputs` appears exactly once, at its own assignment). Dead code; don't spend planning effort reconciling it, but don't mistake it for a sixth thing that needs wiring either.

Full count of places a "which deliverables exist" list is spelled out, with their contents:

| Location | Count | Contents | Notes |
|---|---|---|---|
| `show.blade.php:764-774` (tab strip) | 9 | surveys, rams, worksheets, cable, om, install, quotes, assets, data | Quotes/Assets/Data are D-06 exclusions — not deliverables |
| `DocumentArtifactStorage::TYPE_*` (`app/Services/DocumentArtifactStorage.php:33-73`) | 8 | rams, om-manuals, worksheets, cable-schedules, snagging, drawings, site-surveys, reference-files | `reference-files` (`TYPE_REFERENCE`) doesn't map to any D-04 item — it's engineer-uploaded reference docs, a distinct concept |
| `DocumentEditAdapterRegistry::DEFAULT_MAP` (`app/Services/DocumentEdits/DocumentEditAdapterRegistry.php:20-27`) | 6 | rams, survey, worksheet, om, cable, drawing | `drawing` adapter is registered but functionally dead (see Deferred Ideas) |
| `ProjectController::show()` `$linkedRecords` (`ProjectController.php:175-241`) | 6 | RAMS, Survey, Worksheet, O&M, Cable Schedule, Install Programme | Drives the "Linked Records" card's action buttons — **not named in CONTEXT.md, must be reconciled too** |
| `show.blade.php:487-494` `$outputs` | 6 | rams, worksheet, survey, om, cable, install | **Dead code** — assigned, never rendered. Skip or delete, don't wire. |
| `show.blade.php:410-483` "Next Step" chain | 4 (implicit) | survey, rams, worksheet, om | Not a literal list but an ordered decision chain with the same 4 items hard-coded in priority order (Cable Schedule and Install Programme are absent from the chain entirely) |

**How to avoid:** Plan D-07's reconciliation against all six locations above, not the three named in CONTEXT.md. `$outputs` can be left as dead code or removed; the other five need to agree with the canonical 9-item D-04 list (with Quotes/Assets/Data staying excluded per D-06, and Programming having no adapter/storage type by design per D-05).

### Pitfall 5: The "New Project" manual-create form is currently unreachable
**What goes wrong:** `ProjectController::create()` (GET, `app/Http/Controllers/ProjectController.php:78-81`) is:
```php
public function create(): RedirectResponse
{
    return redirect()->route('quote-import.create');
}
```
It **redirects away** before `resources/views/projects/create.blade.php` can ever render. Both "New Project" links in `dashboard.blade.php` (lines 10 and 141) point at `route('projects.create')`, so the manual-entry blade view is dead in the live UI today — even though `ProjectController::store()` (POST) is fully live and does call `ProjectService::create()` (confirmed: it's exercised by `ProjectAutoAdvanceTest`-adjacent code paths and is the real D-18 entry point).
**Why it happens:** Looks like a deliberate product decision at some point (funnel everyone through quote import) that left the manual form orphaned rather than deleted.
**How to avoid:** D-18 ("Manual projects get the same checklist on the create form") needs the planner to either (a) confirm with the user whether this route should be re-enabled as part of this phase, or (b) add the checklist to `create.blade.php` regardless and note it's currently only reachable by direct URL (`/projects/create`) bypassing the dashboard link — this is a genuine scope question, not an implementation detail, and CONTEXT.md doesn't address it. Flagged in Open Questions below.
**Warning signs:** If the plan says "add the checklist step to the create form" without addressing why that form isn't linked from anywhere, this gap will resurface at UAT.

### Pitfall 6: `.well-known/resources/views/projects/show.blade.php` is a decoy, not a real view
**What goes wrong:** A grep for `show.blade.php` content will surface a second file at `.well-known/resources/views/projects/show.blade.php` with similar (but not identical, and older) stepper code. This directory is not Laravel's `resources/views/` root — it's an unrelated stray directory at the repo root that happens to mirror the path structure.
**How to avoid:** All view edits belong in `resources/views/projects/show.blade.php` (the real, routed file, confirmed via `view('projects.show', ...)` in `ProjectController::show()`). Do not edit `.well-known/...`.

## Code Examples

### Reading the "no labour/install" signal for D-15 defaults
```php
// Verified: app/Core/Modules/QuoteImport/QuoteWerksImportService.php:103-153
// and app/Http/Controllers/ProjectPackageReviewController.php:97-122 (PDF-parse path)
// Both import paths run every equipment row through EquipmentCategoryClassifier::classify(),
// which buckets install/labour/commissioning/programming/RAMS/site-survey SKU tokens into
// a single 'services' category (app/Services/Imports/EquipmentCategoryClassifier.php:224-266).

$hasServiceLine = collect($package->extracted_data['equipment'] ?? $package->equipment_list ?? [])
    ->contains(fn ($row) => strtolower(trim((string) ($row['category'] ?? ''))) === 'services');

// $hasServiceLine === false  →  RAMS, Worksheet, Survey all default to Not required (D-15)
```

### ProjectHealthService — D-12 filter shape (illustrative, not prescriptive)
```php
// app/Services/ProjectHealthService.php:32 — assess() currently has no deliverables awareness.
// Expected shape after D-12: each RED/AMBER rule that references a specific deliverable
// (RAMS failed, RAMS awaiting review, no approved RAMS in engineering, survey overdue)
// needs an added guard, e.g.:
if ($project->status === Project::STATUS_ENGINEERING
    && $this->isRequired($project, 'rams')          // NEW — D-12
    && $rams->whereIn('status', $this->approvedOrBeyond())->isEmpty()) {
    return new ProjectHealth('red', 'No approved RAMS in engineering', $overdue);
}
```
Note `assess()`'s docblock (`ProjectHealthService.php:14`) states it "MUST NOT call `$project->relation()->get()` or issue any additional DB queries" — the deliverables relation must be eager-loaded by the sole caller, `DashboardController::index()` (`app/Http/Controllers/DashboardController.php:55-63`), alongside the existing `ramsDocuments`/`siteSurveys`.

## State of the Art

Not applicable in the usual "library version" sense — this is entirely internal application code with no external dependency to track. The one relevant precedent-vs-current-practice note: the codebase's audit-trail pattern evolved once already (Phase 24, `device_stencil_audits`) specifically because `metadata` JSON columns were found inadequate for history — that evolution is directly reusable here, not something to re-derive.

## Assumptions Log

| # | Claim | Section | Risk if Wrong |
|---|-------|---------|---------------|
| A1 | `EquipmentCategoryClassifier`'s `services` category is a sufficiently reliable signal for "this project has labour/install content" to drive D-15's three-way default (RAMS/Worksheet/Survey) | Deriving defaults (D-15) | If wrong, imported projects could default core deliverables to Not required incorrectly, requiring more user correction than D-15 intends. Confidence is MEDIUM: the code path is verified and runs for both import routes, but its precision against real-world quote data beyond the one cited acceptance case (Volkswagen Blakelands) was not independently re-validated in this research pass. |
| A2 | Grace-period duration for D-13 (amber after "Not yet decided") has no existing precedent value to anchor to in this codebase | Common Pitfalls / D-13 discretion | Low risk — explicitly Claude's discretion per CONTEXT.md; the closest analogue is `ProjectHealthService`'s existing 7/14-day windows, which is a reasonable anchor but not a hard requirement. |
| A3 | The manual-create form (`projects/create.blade.php`) being unreachable via the dashboard link is a genuine product gap, not an intentional funnel that the phase should leave alone | Pitfall 5 | Medium — if this is intentional, adding a deliverables checklist to a dead view wastes effort; if unintentional, D-18 can't ship its stated UI without re-wiring the route. This needs explicit user confirmation, not an assumption either way. |

## Open Questions

1. **Should the D-18 manual-create form be re-linked, or does the checklist land on a currently-unreachable view?**
   - What we know: `ProjectController::create()` redirects to `quote-import.create`; `store()`/`ProjectService::create()` are fully live; the dashboard's two "New Project" links both point at the redirecting route.
   - What's unclear: whether this redirect was a deliberate UX decision (funnel everyone through quote import, keep manual entry as an escape hatch reachable only by direct URL) or dead code nobody has revisited.
   - Recommendation: Flag for the user before planning locks D-18's implementation surface — this is a scope decision (does the phase also re-wire routing?), not a research gap.

2. **What do real per-document-type row counts look like for the D-17 retrofit?**
   - What we know: The local SQLite dev DB has exactly 1 project with zero related documents of any kind — not representative.
   - What's unclear: How many production projects have RAMS-but-no-survey, survey-but-no-RAMS, or neither (the genuinely ambiguous "Not yet decided" bucket D-17 is designed to isolate) — this shapes how loud the amber rollout (D-13) will be on day one.
   - Recommendation: Run the equivalent `whereHas()` counts (see Code Examples pattern used in this research session) against the production MySQL/MariaDB replica before or during planning, not against local SQLite. This cannot be verified from this environment.

3. **Does `canTransitionTo()`'s redesign for D-11 need to remain a static-constant-driven check for the 6 lifecycle stages that are NOT survey-related, or does the whole method become instance-based?**
   - What we know: Only `quote_imported → survey_pending → engineering` is affected by D-11; every other transition (`engineering → installing`, etc.) is unaffected.
   - What's unclear: Whether the cleanest implementation keeps `TRANSITIONS`/`TRANSITIONS_BACKWARD` as-is for the unaffected stages and adds a narrow special case just for the `quote_imported`/`survey_pending`/`engineering` triangle, or whether the whole method should be refactored to be relation-aware everywhere for consistency.
   - Recommendation: Planner's call — either is structurally sound; the narrow special-case is lower-risk (smaller diff, per this repo's stated risk posture of minimal-diff changes) and is what this research recommends, but it's a genuine design choice worth stating explicitly in the plan rather than leaving implicit.

## Validation Architecture

### Test Framework
| Property | Value |
|----------|-------|
| Framework | PHPUnit 11.5.3+ |
| Config file | `phpunit.xml` (repo root) |
| Quick run command | `"/c/Users/sonny.tanda/.config/herd/bin/php84/php.exe" artisan test --filter=<TestClass>` |
| Full suite command | `composer test` (clears config cache, then `php artisan test`) |

### Phase Requirements → Test Map
No formal REQUIREMENTS.md IDs exist for this phase (see Phase Requirements section). Mapping against the 18 CONTEXT.md decisions instead:

| Decision | Behavior | Test Type | Automated Command | File Exists? |
|--------|----------|-----------|-------------------|-------------|
| D-11 | `quote_imported → engineering` valid iff Survey not required | Unit | `php artisan test --filter=ProjectTransitionTest` | ✅ exists, needs new cases — `test_cannot_transition_to_completely_skipped_state` (`tests/Unit/ProjectTransitionTest.php:91-97`) currently asserts this transition is **always** false and will need to become conditional |
| D-11 | Auto-advance hooks skip survey_pending when not required | Feature | `php artisan test --filter=ProjectAutoAdvanceTest` | ✅ exists, needs new cases — `tests/Feature/ProjectAutoAdvanceTest.php` currently only covers the always-required path |
| D-12 | Not-required RAMS/Survey drop out of health calc | Unit | `php artisan test --filter=ProjectHealthServiceTest` | ✅ exists, needs new cases mirroring the existing `setRelation()` pattern (`tests/Unit/ProjectHealthServiceTest.php:202-217`) |
| D-15 | Import defaults derived from `services`-category presence | Feature/Unit | new test class, e.g. `DeliverableImportDefaultsTest` | ❌ Wave 0 |
| D-03 | Audit row written on every flag change | Feature | new test class, e.g. `ProjectDeliverableAuditTest`, mirroring the (untested per TESTING.md gaps list) `DeviceStencilAudit` write pattern | ❌ Wave 0 |
| D-17 | Retrofit migration infers correct default per existing project shape | Unit/Feature | new test class exercising the migration's inference logic against seeded `RamsDocument`/`SiteSurvey` combinations | ❌ Wave 0 |

### Sampling Rate
- **Per task commit:** targeted `--filter=` run against the touched test class(es).
- **Per wave merge:** `composer test` (full suite) — this repo's `.planning/quick/` history shows the full-suite baseline is tracked precisely (e.g., "2,108 passed/1 failed (intentional)/6 skipped" as of 260817-bxc) — any new regression should be visible against that baseline.
- **Phase gate:** Full suite green (matching the known-intentional `QueueRecoverCommandTest` exception) before `/gsd:verify-work`.

### Wave 0 Gaps
- [ ] `tests/Unit/ProjectTransitionTest.php` — existing test at lines 91-97 must be updated/parameterized for D-11's conditional skip, not just extended.
- [ ] `tests/Feature/ProjectAutoAdvanceTest.php` — new cases for the not-required-Survey auto-advance path (both Hook 1 and Hook 2's two call sites).
- [ ] `tests/Unit/ProjectHealthServiceTest.php` — new cases proving a not-required RAMS/Survey never produces red/amber.
- [ ] New: `tests/Unit/Services/ProjectDeliverablesServiceTest.php` (or similar) — D-01/D-02/D-03 core CRUD + audit behaviour.
- [ ] New: migration test/seed proving D-17's inference logic against representative project shapes.
- [ ] Framework install: none — PHPUnit/Mockery already configured.

## Security Domain

### Applicable ASVS Categories

| ASVS Category | Applies | Standard Control |
|---------------|---------|-----------------|
| V2 Authentication | No | Unchanged — existing `auth` middleware group covers all touched routes |
| V3 Session Management | No | No session-handling changes |
| V4 Access Control | Yes | This is a shared-workspace app by design (see `WorksheetPolicy`, `OmManualPolicy`, etc. — every method returns `true` for any authenticated user). New deliverables-edit endpoints should follow the same permissive-but-centralized pattern: a new `ProjectPolicy`-style gate (or extend the existing `ProjectPolicy`) rather than inline `abort_unless()` checks, per the `WorksheetPolicy` docblock's stated rationale ("today every method returns true... but the surface exists so per-user rules can land in one place"). |
| V5 Input Validation | Yes | The audit "reason" free-text field (D-03) and any new form-request for the deliverables checklist need standard Laravel `FormRequest` validation (max length, string type) — no special handling needed beyond house convention. |
| V6 Cryptography | No | No secrets/crypto involved. |

### Known Threat Patterns for this stack

| Pattern | STRIDE | Standard Mitigation |
|---------|--------|---------------------|
| Mass-assignment of `status`/`deliverables` fields bypassing `ProjectService::transition()` | Tampering | Keep `status` off any new mass-assignable `$fillable` surface for the deliverables update endpoint; route all writes through the new `ProjectDeliverablesService`, mirroring the existing house rule that "all status transitions MUST go through this service" |
| Reflected/stored XSS via the free-text audit `reason` field | Tampering / Information Disclosure | Standard Blade `{{ }}` escaping (already the default throughout this codebase) — no `{!! !!}` on user-supplied reason text |

## Sources

### Primary (HIGH confidence — read directly from repo code with line numbers)
- `app/Models/Project.php` — lifecycle constants, `canTransitionTo()`, relations
- `app/Services/ProjectHealthService.php` — health assessment logic
- `app/Core/Modules/Projects/ProjectService.php` — `create()`, `transition()`, `update()`
- `app/Core/Modules/QuoteImport/QuoteImportService.php:397-449` — `confirm()` + Hook 1
- `app/Core/Modules/Survey/SurveyService.php:439-580` — `complete()`/`submitPublic()` + Hook 2
- `app/Http/Controllers/ProjectController.php` — `show()`, `create()`, `store()`
- `app/Http/Controllers/DashboardController.php` — sole caller of `ProjectHealthService::assess()`
- `resources/views/projects/show.blade.php` — stepper, tab strip, Next-Step chain, Project Data tab
- `app/Services/DocumentArtifactStorage.php`, `app/Services/DocumentEdits/DocumentEditAdapterRegistry.php` — D-07 lists
- `app/Services/Imports/EquipmentCategoryClassifier.php` — D-15 signal
- `app/Core/Modules/QuoteImport/QuoteWerksImportService.php`, `app/Http/Controllers/ProjectPackageReviewController.php` — where the classifier runs for each import path
- `database/migrations/2026_08_13_140000_..._create_device_stencil_audits.php`, `app/Models/DeviceStencilAudit.php`, `app/Http/Controllers/Admin/DeviceStencilController.php` — D-03 precedent
- `app/Policies/WorksheetPolicy.php` — shared-workspace policy pattern
- `tests/Unit/ProjectTransitionTest.php`, `tests/Feature/ProjectAutoAdvanceTest.php`, `tests/Unit/ProjectHealthServiceTest.php` — existing test coverage that will need updating
- `.planning/codebase/TESTING.md`, `.env` (local), `.planning/config.json` — house conventions and workflow flags

### Secondary (MEDIUM confidence)
- Local SQLite `tinker` query run in this session confirming the dev DB has 1 project, 0 related documents — used to establish that D-17 retrofit counts cannot be validated locally (see Open Questions).

### Tertiary (LOW confidence)
- None — every finding in this document traces to a specific file/line read during this research session.

## Metadata

**Confidence breakdown:**
- Status-machine sweep (D-11): HIGH — every call site of `canTransitionTo()`/`STATUS_SURVEY_PENDING`/`STATUS_ENGINEERING` was grepped and the relevant ones read in full.
- D-07 list reconciliation: HIGH — all five live lists (plus the one dead one) were read directly.
- D-15 default derivation: MEDIUM — signal is verified and traced through both import paths, but its real-world precision against production quote data was not independently re-validated.
- D-03 audit precedent: HIGH — schema, model, and three write call sites read directly.
- D-12 health integration: HIGH — single caller confirmed, exact rule structure read.
- D-17 retrofit: LOW — local DB has no representative data; flagged as an explicit Open Question requiring production-DB verification before/during planning.

**Research date:** 2026-08-22
**Valid until:** 30 days (stable internal codebase, no external dependency churn) — but re-verify D-17's row counts against production data before this research is used to size the retrofit migration, since that specific finding could not be confirmed in this session.
