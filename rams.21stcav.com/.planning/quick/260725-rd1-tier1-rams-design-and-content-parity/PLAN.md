---
name: 260725-rd1-tier1-rams-design-and-content-parity
description: Bring the app's RAMS DOCX output to parity with the hand-crafted "21CQ29531-05-OPS Tilda RAMs Rev1.1.docx" reference — design (blue palette + Poppins font), structure (add missing §6.11, §6.12, Exclusions block, Standards & Guidance table), and content depth (per-room prompt granularity + kit-specific detail + risk-ID cross-references).
status: in-progress
tasks: 5
---

# Tier-1 RAMS design + content parity with hand-crafted reference

## Why

The 21CAV team has a hand-crafted Tier-1 RAMS example — `21CQ29531-05-OPS Tilda RAMs Rev1.1.docx` — that represents the target quality. Gap analysis vs the current auto-generated docx:

**Design gaps:**
- Body font: app uses Arial 10pt, reference uses **Poppins 10.5pt**
- Heading palette: app uses teal `#007B8A`, reference uses **blue `#2E74B5`** (H1/H2) + **dark blue `#1F4D78`** (H3)
- Alt-row shading: app uses light teal `#F0FBFC`, reference uses standard Word grey

**Structure gaps (3 missing sections):**
- §6.11 Coordination with Other Trades
- §6.12 IT / Network Integration Safety
- Explicit Exclusions block under Scope of Works (currently prose-only)
- Standards & Guidance table under H&S Policy (BS 7671, BS 6701, CDM 2015, HSG 47/273, AVIXA F502.01)

**Content depth gaps:**
- Reference §6.6 is **10.9 KB of site-specific prose** — names actual rooms (Oregano, Cinnamon, booking-panel rooms Vanilla/Poppy/Kalonji/Nutmeg/Project Room/Cardamon), names actual kit (Sennheiser TeamConnect, Crestron Flex, AirMedia, Sony 98"), cross-references risk IDs (`Associated Risks: RA03, RA08, RA11, RA12, RA13`)
- App's current output leans generic. Needs prompt tuning to force per-room granularity, kit naming from the parsed hardware list, and stable RA-ID scheme so steps can back-reference the risk assessment table.

**Reference material in this task dir:**
- `reference/tilda_ref_plaintext.txt` — full plain-text extraction of the reference docx (44 KB)
- `reference/section_dumps.txt` — verbatim §6.11, §6.12, Exclusions, Standards, CDM, COSHH, Environmental, Welfare (~10 KB) — for copy-paste of the static language

## Global constraints

- **No DB migration.**
- **No new npm deps.**
- `php -l` after every PHP edit.
- Existing `--filter Docx|RamsBuilder|MethodStatement|RiskAssessment` tests must stay green.
- Commit prefixes: `refactor(docx)` for design shifts, `feat(docx)` for new sections, `feat(prompt)` for AI tuning, `docs(quick-…)` for closeout.
- Font "Poppins" — if the target Windows/Word install doesn't have Poppins, MS Word substitutes at open time. No embed needed. Do NOT try to download a font file.

## Task 1 — Design shift: colour palette + font

**File:** `app/Services/DocxBuilderService.php`

- Change `setDefaultFontName('Arial')` → `setDefaultFontName('Poppins')` (line 95). Keep size at 10pt (Word will render close enough to reference's 10.5).
- **Add new brand-blue constants** alongside the existing TEAL. Do NOT delete TEAL yet — many inline uses; we'll migrate call-sites in Task 2 as part of the refactor.
  ```php
  private const BRAND_BLUE       = '2E74B5';   // H1/H2 headings + accents
  private const BRAND_BLUE_DARK  = '1F4D78';   // H3 sub-headings
  private const BRAND_BLUE_TINT  = 'DEEBF7';   // Alt-row shading (very light blue)
  ```
- Change `ROW_ALT` value from `'F0FBFC'` (light teal) → `'DEEBF7'` (light blue). This is a bulk visual change that flows through every table.
- Rename `TEAL` const value from `'007B8A'` → `'2E74B5'`. Keep the const NAME as `TEAL` for now (mass-rename of ~30 call sites would inflate the diff — cosmetic follow-up). Add an inline comment noting the value is now brand blue.
- Cover-page tables that use `$tealCell = ['bgColor' => self::TEAL]` → automatically pick up the new value. Verify by manual regen.

**Tests:**
- Add a unit test that constructs `DocxBuilderService`, generates a minimal RAMS with a fixture record, opens the resulting docx as a ZIP, extracts `word/document.xml`, and asserts:
  - No occurrence of `007B8A` in the XML (old teal is gone)
  - At least one occurrence of `2E74B5` (new blue is present)
  - Body font is `Poppins` (in the styles or run properties)

**Gates:** existing docx test suite green, `php -l` clean.

**Commit:** `refactor(docx): shift RAMS palette teal → brand blue + font Arial → Poppins (260725-rd1)`

## Task 2 — Missing structure: §6.11, §6.12, Exclusions, Standards

**File:** `app/Services/DocxBuilderService.php`

Add four new sections. Content pulled from:
- Static language: copy verbatim from `reference/section_dumps.txt` — treat as fair-use inside your own hand-crafted example.
- Standards: enumerate from `config/rams_tier1.php` `standards_references` key (already exists — Tier1RamsDefaultsService populates this).

### 2a — §6.11 Coordination with Other Trades

Add `buildCoordinationWithOtherTrades(PhpWord $phpWord, array $data): void` method. Content:
- Sub-heading "6.11 Coordination with Other Trades"
- Prose paragraph explaining coordination requirements — pattern-match from `reference/section_dumps.txt` (search for "6.11")
- Bullet list of key coordination touch-points: (a) ceiling grid / structure, (b) partition walls, (c) electrical contractor for mains isolation, (d) principal contractor for permits, (e) IT contractor for network drops
- Match §6.7 / §6.8 visual template (heading style + prose para + optional bullet list)

Wire into `build()` between §6.10 (`buildSupervisionAndQA`) and `buildPermitsAndAuthorisations`.

### 2b — §6.12 IT / Network Integration Safety

Add `buildITNetworkIntegrationSafety(PhpWord $phpWord, array $data): void` method. Content:
- Sub-heading "6.12 IT / Network Integration Safety"
- Prose paragraph on IT-integration safety principles
- Bullet list: (a) verify existing network before patching, (b) label all cables at both ends, (c) confirm firewall rules with client IT, (d) test-before-connect on shared switches, (e) protect against unintended broadcast/PoE damage, (f) coordinate cutover with client IT
- Wire into `build()` immediately after §6.11.

### 2c — Explicit Exclusions block under Scope of Works

**File:** `app/Services/DocxBuilderService.php` — modify `buildScopeOfWorks`

- After the Works Activities table, before the next major section, add an "Exclusions" sub-heading (H2) followed by:
  - Bulleted list from `reviewed_data.exclusions[]` (already exists — the `applyAddExclusion` op from 260723-rr1 writes there)
  - If empty, fallback text: "No exclusions declared for this project."
- Visual template: mirror the "Works Activities" table styling — same H2 heading style, same list bullet style.

### 2d — Standards & Guidance table under H&S Policy

**File:** `app/Services/DocxBuilderService.php` — modify `buildHealthSafetyPolicy`

- After the existing H&S prose paragraph, add sub-heading "Standards & Guidance Applicable to This Works" (H2).
- Add a 3-column table: `Reference | Title | Applies To (on this project)`.
- Populate from `config('rams_tier1.standards_references', [])` — already has BS 7671, BS 6701, BS EN 60849, BS 8492, CDM 2015, HSG 47, HSG 273, AVIXA F502.01, IEC 60825 (per the qw2 audit — total 10 standards).
- Each standard has: `reference` (short code), `title` (full name), `applies_to` (project-specific applicability text).
- Table styling: header row in brand-blue, alt-row shading via `ROW_ALT` (now BRAND_BLUE_TINT), 9pt body font.

**Config check:** confirm `rams_tier1.standards_references` shape matches what `buildHealthSafetyPolicy` expects. If the config keys are `code / title / applies_to` — use those. If different (`reference`/`name`/`scope`) — adapt the table iteration to match. Do NOT alter `config/rams_tier1.php` — that's H&S-signed-off content.

**Tests:**
- Feature test regenerating a RAMS with a fixture record — assert docx contains "6.11 Coordination with Other Trades" heading, "6.12 IT / Network Integration Safety" heading, "Exclusions" heading, and "Standards & Guidance Applicable to This Works" heading (all via string search in document.xml).
- Fixture must include ≥1 exclusion (so the bullet-list branch fires) AND ≥1 standard.

**Commit:** `feat(docx): add §6.11 §6.12 Exclusions block + Standards & Guidance table (260725-rd1)`

## Task 3 — Prompt tuning: per-room granularity + kit naming + RA-ID cross-refs

**File 1:** `app/Core/AI/Prompts/MethodStatementPrompt.php`

Update systemMessage + build:

- **Per-room granularity.** Currently the prompt may produce generic steps ("Install displays in all rooms"). New rule: **each Method of Works step MUST name the specific room(s) it applies to.** Example from reference:
  > "Pull AV, USB, network, and power cables to all device positions within Oregano, including positions for the display, video bar, touch panel, occupancy sensor, and AirMedia unit."
- **Kit naming from the parsed equipment list.** New rule: **when a step references a piece of kit, name the specific make + model** from the equipment list. Example:
  > "Install the two Sennheiser TeamConnect Ceiling Mic Medium Housing units in the agreed ceiling positions within Cinnamon..."
- **Risk-ID cross-references.** New rule: **each step MUST end with an `Associated Risks: RA01, RA02, ...` line** referencing the risk assessment items relevant to that step. Example:
  > "Associated Risks: RA03, RA08, RA11, RA12, RA13"
- **Context enrichment.** In `build($context)`, ensure the equipment list + rooms list are passed to the prompt as structured context (they may already be — verify + extend). Also pass the risk list so the AI can reference existing RA-IDs.

**File 2:** `app/Core/AI/Prompts/RamsPrompt.php` (or wherever risk assessment items are generated)

- Assign each risk item a **stable RA-ID** in the format `RA{NN}` where NN is a zero-padded sequential counter (RA01, RA02, ...).
- Emit the ID as a first column in the risk assessment table.
- The MethodStatementPrompt's step-level cross-references become valid lookups.

**File 3:** `app/Services/DocxBuilderService.php` — `buildRiskAssessment`

- Render the RA-ID as the first column of the risk assessment table (leftmost). Adjust column widths if needed.
- Confirm MethodStatement §6.6 step blocks emit the "Associated Risks:" line correctly (should just be prose from the AI output — no builder changes needed unless we want special styling).

**Verification (manual — will run post-deploy):**
- Regenerate `21CQ29531-05-OPS` on live.
- Open the resulting docx.
- Spot-check: does §6.6 name Oregano/Cinnamon/Vanilla/Poppy/Kalonji/Nutmeg/Project Room/Cardamon? Does it name Sennheiser TeamConnect, Crestron Flex, AirMedia, Sony 98"? Does each step end with `Associated Risks:` cross-refs? Do those RA-IDs actually exist in §5 Risk Assessment?

**Note:** Task 3 requires Anthropic API credits. User was out on 2026-07-25 — verify balance before live regen test. Prompt code changes + unit tests can be committed without needing live AI calls to pass.

**Tests:**
- Unit test on updated `MethodStatementPrompt`: assert the systemMessage contains the phrase "Associated Risks:", "per-room", "specific make + model".
- Unit test on updated risk-assessment prompt: assert output items carry stable RA-IDs.

**Commit:** `feat(prompt): method statement — per-room + kit-specific + RA-ID cross-refs; risk items get stable RA{NN} IDs (260725-rd1)`

## Task 4 — Regression sweep + STATE + SUMMARY + push

- Run `--filter Docx|RamsBuilder|MethodStatement|RiskAssessment` and confirm all green.
- Regenerate the docx locally against a small fixture and eyeball it in Word (or LibreOffice) to catch any layout regressions (colour bleeds, table-cell widths, page-break oddities).
- STATE.md row above 260725-fx3.
- SUMMARY.md matching the prior quick-task pattern.
- Push to `live` + `origin`.

**Commit:** `docs(quick-260725-rd1): PLAN + SUMMARY + STATE for RAMS design & content parity`

## Explicit non-goals

- **Font file bundling** — Poppins is a Google font; MS Word substitutes if not installed. No embedded font (would add ~200 KB to every docx).
- **Complete rewrite of the risk-assessment ID scheme in the database** — RA-IDs live only in the rendered docx + AI output. Prior renders are unaffected.
- **Retroactive re-render of already-approved RAMS docs** — new template affects new/regenerated docs only. If the office wants a batch re-render, that's a separate artisan command follow-up.
- **Reference docx byte-for-byte match** — Word docx XML has many auto-inserted revision IDs, timestamps, and machine-specific settings. Structural + visual parity is the goal, not identical XML.
- **Auto-numbering of section headings** — reference uses "1. Document Control" / "6.11 Coordination…" — the app already does this via heading text. Don't switch to Word's auto-numbered heading styles (breaks TOC and PDF export).

## Deploy

- No migrations. No npm build.
- Server as stcav: `git pull && php artisan optimize:clear && php artisan config:cache`.
- **Anthropic API credits check first** — Task 3 prompt changes need real AI calls to verify content quality. If credits are still exhausted from 2026-07-25, the code lands but content-depth verification stalls until top-up.
- Sanity: regenerate `21CQ29531-05-OPS` (Tilda) RAMS from the review page → download → open in Word → compare side-by-side with the reference `21CQ29531-05-OPS Tilda RAMs Rev1.1.docx`. Design should match (blue palette + Poppins); §6.11 / §6.12 / Exclusions / Standards should be present; §6.6 depth should approach the reference.

## What "done" looks like

1. Colour palette: no more teal `#007B8A` anywhere; blue `#2E74B5` everywhere it used to be teal.
2. Body font: Poppins throughout.
3. Sections §6.11, §6.12, explicit Exclusions block, Standards & Guidance table all present.
4. §6.6 Method of Works — steps name actual rooms + kit + cross-reference RA-IDs.
5. §5 Risk Assessment — first column is RA-ID (RA01, RA02, …); IDs referenced from §6.6 all resolve.
6. All existing tests green + new unit + feature tests added for the design + structural changes.
7. Deployed to live + regenerated Tilda RAMS opens looking like the Rev1.1 reference.
