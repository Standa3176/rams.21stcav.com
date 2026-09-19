# Phase 45: Visit Model + Read-only Cockpit - Context

**Gathered:** 2026-09-19
**Status:** Ready for planning

<domain>
## Phase Boundary

One typed `Visit` record that wraps every trip to site the app can already evidence, and a
read-only cockpit page that reads it — both shipped alongside the existing eleven-tab project
page, behind a flag, changing nothing a user sees until that flag is on.

**In scope:** the `visits` table and model, an idempotent backfill command, the split of
`InstallProgramme` into a durable record and a regenerable task list, and the flag-gated
read-only cockpit page.

**Out of scope:** creating, editing, sending or accepting visits (Phase 46 owns the lifecycle);
per-visit RAMS and worksheets (Phase 46); snagging (Phase 47); visit costs (deferred for the whole
milestone); any new engineer-facing capture. **This phase adds no new writes from any user-facing
surface** — the only writes are the backfill command and the programme split migration.

</domain>

<decisions>
## Implementation Decisions

All taken interactively with the user on 2026-09-19.

### What a visit is, and what the backfill creates

- **D-01:** A visit is backfilled from **a `SiteSurvey` row, or a `Worksheet` row that has a
  `WorksheetSignoff`**. A `SiteSurvey` is an attendance by nature. A `Worksheet` is not — its own
  docblock calls it "one worksheet generation run per project", with a
  `pending → generating → draft → final` pipeline — so a worksheet only evidences a trip to site
  when a client signed on site. Unsigned worksheets stay documents and produce no visit.

  This deliberately narrows ROADMAP criterion 2, which says to wrap "existing `SiteSurvey` and
  `Worksheet` rows". Both relations are `hasMany` on `Project`, so the literal reading would mint
  an install visit for every regeneration of a worksheet. The criterion's intent — real history on
  the spine — is served better by the signoff test. **Planner: treat D-01 as authoritative over the
  criterion's wording.**

- **D-02:** A backfilled worksheet visit is typed **`install`** and carries an explicit
  **backfilled marker**. A signed worksheet proves attendance but not what was done — first fix,
  install and commissioning all produce the same worksheet — so `install` is an inference, and the
  marker says so. The cockpit may present reconstructed visits differently, and a later phase can
  let the PM retype them. No separate `legacy` type: an enum value meaning "we do not know" would
  have to be handled by every filter and report forever.

- **D-03:** Backfilled survey visits are typed **`site survey`** with no per-row discrimination.
  `SiteSurvey.survey_type` is a dead column — superseded by the room-level `space_type` (see
  `database/migrations/2026_04_05_210000_add_space_type_to_site_survey_rooms.php`) and defaulting
  to `general` — so it must not be used to derive a visit type.

- **D-04:** **A visit survives the record it wraps being superseded or soft-deleted**, and the
  cockpit shows it visibly marked as superseded. The trip to site happened; superseding the
  paperwork does not un-happen it. Consistent with the milestone position for Phase 50 (old project
  data is archived and viewable, not erased). Do NOT scope visits away behind the wrapped record's
  soft-delete.

- **D-05:** The backfill is a **separate, idempotent console command**, not migration-embedded.
  Re-running it must skip rows that already have a visit. Follows the Phase 22 one-shot backfill
  precedent. Rationale: the user validates on the live VPS against real project data, so deploy
  and backfill must be separate decisions, and a bad backfill must be re-runnable rather than
  unpickable.

### Ownership

- **D-06:** **Phase 45 splits `InstallProgramme`** into a durable install record and a regenerable
  task list, and visits hang off the durable half.

  `InstallProgrammeService::createForProject()` (`app/Services/InstallProgrammeService.php:45-47`)
  calls `archiveExisting($project)` then `InstallProgramme::create([...])` — every regenerate
  produces a brand-new row. Anything filed under it is orphaned the first time a PM rebuilds the
  task list. The user chose to fix the shape now rather than build on it.

  **Constraint on D-06 — this is the risk in the phase.** The split must be *behaviour-preserving*:
  ROADMAP criterion 4 promises that with the flag off the app behaves exactly as it does today, and
  the install programme is a live feature. The user was shown this tension explicitly and chose the
  split anyway. The planner must therefore treat "existing programme generate / activate / archive
  flow is unchanged, proven by tests against the current behaviour" as a hard gate on the split
  plan, not as a nice-to-have. If the split cannot be made behaviour-preserving, stop and raise it
  rather than relaxing criterion 4.

### Brand

- **D-07:** The cockpit is built in **21CAV brand** — teal `#01889F`, gold `#D4AF37` accents,
  Verdana headings, Poppins body, angled `clip-path` masthead — as drawn in sketch 002. It starts a
  brand-aligned refresh rather than being retoned to the app's current `#1E5FE0` / Inter.

  The cockpit is flag-gated and read-only, which makes it the safest possible place to introduce
  brand tokens: nothing else changes until the flag is on. Later v4.0 phases inherit the tokens.
  **Planner: introduce these as reusable tokens, not page-local styles**, because phases 46-51
  build on them. Existing pages are NOT retoned in this phase.

### Claude's Discretion

- Table and column naming, the visit type enum's storage (string column vs enum vs constants on
  the model), and how the backfilled marker is stored. D-02 requires only that it is explicit and
  queryable.
- How the durable-record / task-list split is shaped (new table vs new columns vs a status-scoped
  relation). D-06 fixes the requirement, not the mechanism.
- The flag's name and location. The established convention is a `config/*.php` key reading
  `env('UPPER_SNAKE', false)` — see `config/rams_tier1.php` (`structural_gates_enabled`,
  `cdm_ae_gate_enabled`) and `config/services.php` (`spike_schematic_enabled`). Scope was not
  discussed; a global env flag matches every precedent in this app and is the default unless
  research finds a reason otherwise.
- Route placement for the cockpit page.
- Which traffic-light derivations to surface first, within criterion 5's limit that they come from
  data that already exists. `app/Services/ProjectHealthService.php` is the existing green/amber/red
  precedent and should be reused rather than reinvented.

</decisions>

<canonical_refs>
## Canonical References

**Downstream agents MUST read these before planning or implementing.**

### Design source of truth
- `.planning/sketches/002-install-cockpit/README.md` — the cockpit design, its decisions, and the
  five structural implications it identified. Its open questions 1, 2 and 4 are now answered by
  D-01/D-02, D-04 and Phase 46 respectively; its brand open question is answered by D-07.
- `.planning/sketches/002-install-cockpit/cockpit-sections.html` — the accepted layout (drawers
  closed at rest). `cockpit-drawers.html` and `cockpit.html` are earlier variants.
- `.planning/ROADMAP.md` section "v4.0 Project Cockpit" and "Phase 45" — scope, the five success
  criteria, and the milestone out-of-scope list
- `.planning/REQUIREMENTS.md` section "Milestone v4.0 Requirements" — VIS-xx IDs must be minted
  here at planning time; only LR-01..LR-05 exist so far

### Existing code this phase must not break
- `app/Services/InstallProgrammeService.php` — `createForProject()` / `archiveExisting()`; the
  subject of the D-06 split and its behaviour-preservation gate
- `app/Models/InstallProgramme.php` — draft / active / complete / archived status constants
- `app/Models/SiteSurvey.php` — note the `$fillable` security comments (S-02, S-03): `access_token`
  and `submitted_notification_sent_at` are deliberately NOT mass-assignable. Do not re-add them.
- `app/Models/Worksheet.php` — same pattern; `access_token` and `access_token_expires_at` are
  deliberately not fillable
- `app/Models/WorksheetSignoff.php` — the signal D-01 depends on
- Public token routes `/survey/{token}` and `/worksheet/{token}` — every live link must keep
  working unchanged (criterion 2)

### Precedent to follow
- Phase 22's one-shot backfill command — the D-05 precedent
- `app/Services/ProjectHealthService.php` — existing green/amber/red derivation
- `config/rams_tier1.php` — the flag convention, and the armed-by-default doctrine comment at
  lines 115-133
- The `21cav-brand` skill — the brand reference behind D-07 (palette, type scale, CSS variables)

</canonical_refs>

<code_context>
## Existing Code Insights

### Scouted 2026-09-19

- `Project` has `siteSurveys(): HasMany` (line 183) and `worksheets(): HasMany` (line 207) — both
  many, which is what makes D-01's signoff test necessary.
- `Worksheet` is a document-generation pipeline record, not an attendance record. Its docblock is
  explicit. `SiteSurvey` is a data-capture record with its own engineer link, site contacts and
  access notes. **The two are less alike than the roadmap summary implies** — this is sketch open
  question 1, and D-01/D-02 answer it by wrapping rather than merging: neither model changes shape.
- `SiteSurvey` uses `SoftDeletes` and also carries `superseded_at`; `Worksheet` uses `SoftDeletes`.
  D-04 depends on both.
- `SiteSurvey.survey_type` is dead (D-03).
- Both models carry deliberate `$fillable` omissions from a security re-audit, documented inline.
  Any new code touching them must preserve that.
- `LabourResource` (Phase 44) exists with `toClientSafeArray()` returning id + name only. Visits
  assign resources; **LR-04 still binds** — no client-facing visit surface may render a resource's
  email or phone. `tests/Feature/Security/LabourResourceClientSurfacePrivacyTest.php` is a
  source-guard over client-facing files and **will need the new cockpit and visit views added to
  its file list**.

### Established patterns
- Feature flags: `config/*.php` key reading `env('UPPER_SNAKE', false)`.
- Controllers carry `abort_unless(auth()->check(), 403)` — "shared workspace: any authenticated
  user has full access". Do not assume role-based auth beyond `EnsureUserIsAdmin`.

</code_context>

<specifics>
## Specific Ideas

- The sketch's framing is worth preserving in the build: **the spine is the hero**, attention is
  folded into a single gold-ruled line rather than a separate alert panel, and programming carries
  a tick rather than a traffic light because nothing can evidence it.
- The user's own phrase for the milestone's purpose: the app "is good at collecting structured
  information from engineers and bad at turning it into work." Phase 45 does not fix that — it
  builds the record that later phases hang actions off.

</specifics>

<deferred>
## Deferred Ideas

- **Retoning the existing eleven-tab pages** to the brand palette — D-07 starts the refresh at the
  cockpit only. A later phase or quick task covers the rest.
- **Letting the PM retype a backfilled visit** — needs write capability, so Phase 46 at the earliest.
- **Visit costs** — deferred for the whole milestone; belongs in the admin Hidden Functions register.
- **Linking `captured_by` free-text engineer names to `LabourResource` rows** — carried over from
  Phase 44's D-06, still not in scope.

</deferred>

---

*Phase: 45-visit-model-read-only-cockpit*
*Context gathered: 2026-09-19*
