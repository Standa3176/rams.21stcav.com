---
name: 260725-rd1-tier1-rams-design-and-content-parity
status: complete
completed: 2026-07-25
branch: feat/worksheet-classifier-universal
commits:
  - 5aef79b  # refactor(docx) shift RAMS palette teal to brand blue + font Arial to Poppins
  - ee75f46  # feat(docx) 6.11 6.12 Exclusions block + Standards & Guidance table
  - 92cf99f  # feat(prompt) method statement per-room + kit-specific + RA-ID cross-refs
migrations: 0
npm_build: false
deploy_steps:
  - git pull
  - php artisan optimize:clear
  - php artisan config:cache
---

## RAMS DOCX design + content parity with hand-crafted Tilda reference

Bring the auto-generated RAMS DOCX up to parity with the hand-crafted
`21CQ29531-05-OPS Tilda RAMs Rev1.1.docx` reference. Three atomic areas of
change: design palette, missing structural sections, method-statement AI prompt
depth.

## What shipped

### Task 1 — Palette + font shift (refactor(docx) — 5aef79b)

- **Colour:** heading colour flipped from teal `#007B8A` to brand blue `#2E74B5`.
  The `TEAL` constant name is preserved (mass-rename of ~30 call-sites would
  inflate the diff for no functional gain — cosmetic follow-up); its VALUE now
  holds brand blue. Three new named constants co-exist for explicit callers:
  `BRAND_BLUE`, `BRAND_BLUE_DARK`, `BRAND_BLUE_TINT`.
- **Alt-row shading:** `ROW_ALT` value shifted `F0FBFC` (light teal) to
  `DEEBF7` (light blue). Every alt-shaded table in the docx picks up the new
  tint automatically.
- **Font:** `setDefaultFontName('Arial')` to `'Poppins'`, and the `font()`
  helper's hard-coded `'name' => 'Arial'` follows suit. Word substitutes if
  Poppins is not installed on the reader's machine — no font-file bundling.
- **Test:** new `DocxBuilderPaletteFontTest` opens the rendered `document.xml`
  and asserts (a) no `007B8A` present; (b) `2E74B5` present; (c) no `F0FBFC`
  present; (d) `DEEBF7` present; (e) `Poppins` present, no `Arial`
  in run properties.

### Task 2 — Missing structural sections (feat(docx) — ee75f46)

Four additions to match the hand-crafted reference:

- **6.11 Coordination with Other Trades** — always renders. Prose paragraph +
  5 coordination touch-points (ceiling grid, partitions, electrical isolation,
  Principal Contractor permits, client IT tie-in). Content adapted from
  `reference/section_dumps.txt`.
- **6.12 IT / Network Integration Safety** — always renders. Intro prose + 6
  IT-integration principles (verify network, label cables, firewall rules,
  test-before-connect, PoE budget, coordinated cutover) + closing prose on
  power-cycle / network-fail recovery verification. Content adapted from
  `reference/section_dumps.txt`.
- **Explicit Exclusions block** under Scope of Works. Reads
  `reviewed_data['exclusions']` (populated by the `add_exclusion` op from
  260723-rr1, or by RamsDisplayPatchService's default seed). Falls through to
  `generated_data['exclusions']` when a fixture supplies one. Empty state
  prints `"No exclusions declared for this project."`
- **Standards & Guidance Applicable to This Works** table under H&S Policy.
  Reads `$data['standards_references']` (populated by
  `Tier1RamsDefaultsService` from `config('rams_tier1.standards_references')`).
  Config shape uses `ref`/`title`/`applies_to`; the docx table tolerates the
  alternative `reference`/`name`/`scope` shape too, so a future reviewer that
  writes either form still renders correctly.
- **Test:** new `DocxBuilderNewSectionsTest` (6 cases) asserts all four
  sections appear with the expected headings and content substrings.
- **Config:** no edits to `config/rams_tier1.php` — the 10 standards it already
  defines flow through automatically.

### Task 3 — Prompt tuning: per-room + kit-specific + RA-ID cross-refs (feat(prompt) — 92cf99f)

`MethodStatementPrompt`:

- **systemMessage** gains three new rules:
  1. Per-room granularity — every step that touches a physical space MUST name
     the specific room(s) it applies to.
  2. Kit-specific detail — steps reference specific make + model from the
     supplied equipment list, not generic terms.
  3. Risk-ID cross-references — each phase MUST end with a final line in the
     form `"Associated Risks: RA01, RA02, RA03"` using the RA-IDs from the
     supplied risk register.
- **build()** emits a `"Risk register (use these RA-IDs verbatim when
  cross-referencing):"` context block enumerating hazards with stable RA{NN}
  IDs matching `DocxBuilderService::buildRiskAssessment`'s numbering. Block is
  omitted when `hazards[]` is empty.
- **build()** requirements list gains three matching rules paralleling the
  systemMessage.

`MethodStatementService`:

- **generate()** forwards the full `hazards` array (not just the summary
  string) into the prompt context so the risk register can be enumerated.

`DocxBuilderService::buildRiskAssessment`:

- No change needed. The renderer has assigned RA-IDs (`RA01`, `RA02`, ...) as
  the first column of the risk-assessment table since the 260514
  docx-builder-pdf-parity work (line 1012). The AI's cross-references now
  resolve into the same numbering scheme.
- **Test:** `MethodStatementPromptTest` gains 6 cases — systemMessage rule
  presence (3 cases), build() risk-register emission with correct RA-IDs,
  omission when hazards[] is empty, per-phase Associated-Risks requirement
  in the body.

## Test tally

- **Baseline** (`--filter Docx|RamsBuilder`): 37 tests / 191 assertions
- **After Task 1** (palette + font): 40 tests / 206 assertions (3 new)
- **After Task 2** (6.11 + 6.12 + Exclusions + Standards): 46 tests /
  244 assertions (6 new)
- **After Task 3** (`--filter Docx|RamsBuilder|MethodStatement|RiskAssessment`):
  89 tests / 352 assertions (6 new prompt cases on top of the pre-existing
  MethodStatement / RamsBuilder / etc. suites)
- **Full regression sweep:** all green.
- `php -l` clean on every edited file.

## Files touched

**Created (3):**
- `tests/Feature/Rams/DocxBuilderPaletteFontTest.php`
- `tests/Feature/Rams/DocxBuilderNewSectionsTest.php`
- `.planning/quick/260725-rd1-tier1-rams-design-and-content-parity/SUMMARY.md`

**Modified (4):**
- `app/Services/DocxBuilderService.php` — palette + font (Task 1); Exclusions
  block + Standards & Guidance table + wire 6.11 + 6.12 into build() (Task 2);
  two new builder methods `buildCoordinationWithOtherTrades` +
  `buildITNetworkIntegrationSafety`
- `app/Core/AI/Prompts/MethodStatementPrompt.php` — systemMessage + build()
  updates for per-room + kit-specific + RA-ID cross-refs (Task 3)
- `app/Services/MethodStatementService.php` — forward `hazards[]` into prompt
  context (Task 3)
- `tests/Unit/Services/MethodStatementPromptTest.php` — 6 new cases (Task 3)

**Deleted:** none.

## Deviations from PLAN.md

Small pragmatic adaptations, none impacting the delivered behaviour:

1. **Config shape confirmed.** The PLAN flagged that
   `rams_tier1.standards_references` might use `reference`/`title`/`applies_to`
   vs `code`/`name`/`scope`. Actual shape is `ref`/`title`/`applies_to`
   (10 entries: BS 7671, BS 6701, BS EN 60849, BS 8492, CDM 2015, HSG 47,
   HSG 273, AVIXA F502.01, PUWER 1998, BS EN 60825-1). Table iteration reads
   `ref` first with a fallback chain through `reference` then `code`; same for
   `title` then `name` and `applies_to` then `scope`. Config not touched.
2. **Exclusions data source.** The PLAN said "reads
   `reviewed_data.exclusions[]`". Implementation reads that first, then falls
   through to `generated_data['exclusions']` if the fixture / caller supplies
   one that way. Deterministic for unit fixtures that don't want a full
   `RamsDocument` record.
3. **Risk-ID cross-references — no builder change needed.**
   `DocxBuilderService::buildRiskAssessment` has been rendering RA{NN} as the
   first column since 260514 (line 1012). The Task 3 scope reduces to prompt
   changes only + surfacing the RA-ID list to the AI via new context.
4. **`associated_risks_label` render path.** Existing render at line 1312
   already prints `"Associated Risks: ..."` from either a
   `RamsComplianceUpgradeService`-supplied field or an AI-supplied one — no
   Blade / docx changes required to accept the AI output.
5. **No live AI regression run.** User is on rate-limited Anthropic credits
   per the 2026-07-25 memory. Prompt-change verification via unit tests
   asserting the systemMessage phrases + build() context block only. Live
   regen against the Tilda project will be verified post-deploy.

No architectural deviations. No `RULE 4` STOPs. No auth gates hit.

## Blockers

None encountered.

## Deploy

No migrations. No npm build. On the VPS as `stcav`:

```bash
cd /home/stcav/rams.21stcav.com
git pull
php artisan optimize:clear
php artisan config:cache
```

## Sanity checks after deploy

1. Regenerate `21CQ29531-05-OPS` (Tilda) RAMS from the review page — download
   — open in Word.
2. **Design:** confirm brand-blue headings (not teal), light-blue alt-row
   shading (not light teal), Poppins body font.
3. **Structure:** confirm 6.11 Coordination with Other Trades and 6.12
   IT / Network Integration Safety render as their own sections; Exclusions
   block appears at the end of Scope of Works; Standards & Guidance table
   appears under H&S Policy.
4. **Content depth (needs Anthropic credits):** open 6.6 Method of Works,
   confirm steps name specific rooms, name specific kit make + model, and end
   with `Associated Risks: RA01, RA02, ...` cross-references that resolve into
   Section 5 Risk Assessment.

## Related

- **260514 docx-builder-pdf-parity** — set up the DocxBuilder + PDF blade
  parity, including the `RA{NN}` ref column in `buildRiskAssessment` that this
  task now cross-references
- **260723-rr1** — added the `add_exclusion` op that writes to
  `reviewed_data['exclusions']` (this task renders that data)
- **260712-twi (baseline hazards + standards + COSHH)** — introduced
  `Tier1RamsDefaultsService` + `config/rams_tier1.standards_references` that
  this task now surfaces in the docx

## Explicit non-goals (deferred)

- Font-file bundling — Poppins is a Google font, Word substitutes if not
  installed. No embedded font.
- Complete RA-ID scheme in the database — RA-IDs live in the rendered docx +
  AI output only. Prior renders unaffected.
- Retroactive re-render of already-approved RAMS docs — new template affects
  new/regenerated docs only.
- Reference docx byte-for-byte match — Word docx XML has many auto-inserted
  revision IDs / timestamps / machine-specific settings. Structural + visual
  parity is the goal.
