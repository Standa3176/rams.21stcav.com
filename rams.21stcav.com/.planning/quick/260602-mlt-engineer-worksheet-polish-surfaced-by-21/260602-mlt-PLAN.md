---
phase: 260602-mlt
plan: 01
type: execute
wave: 1
depends_on: []
files_modified:
  - app/Services/QuoteParserService.php
  - tests/Unit/Rams/QuoteParserServiceTest.php
  - resources/views/partials/_engineer-reference-drawer.blade.php
  - resources/views/worksheets/public-show.blade.php
  - resources/views/surveys/show.blade.php
  - app/Http/Controllers/PublicWorksheetController.php
  - tests/Feature/Worksheets/PublicWorksheetHeaderContactTest.php
autonomous: true
requirements:
  - MLT-01-multi-line-desc
  - MLT-02-drawer-amber-open
  - MLT-03-header-site-contact

must_haves:
  truths:
    - "MT300-shape PARTDESCSTART…QTYEND tuple followed by a 5-line description AFTER QTYEND produces a single equipment row whose `description` field contains ALL 5 lines joined by single spaces (not just the first line)."
    - "RamsRenderRegressionTest stays GREEN — every existing parser-driven document still renders byte-identically against its fixture."
    - "When the worksheet project has ≥1 reference file, the Engineer Reference Files drawer renders open-by-default with an amber `#C07000` left-border, amber title-bar tint, and amber icon accents — visually distinct from the teal kit-list drawer."
    - "When the worksheet project has 0 reference files, the drawer renders nothing (existing behaviour preserved)."
    - "When the package's parsed `extracted_data['project']['ship_contact']` or `ship_phone` is non-empty, the worksheet public-show header renders a `Site contact: {name} · {tel-link}` line BELOW the existing ws-header__meta row, with the phone as a clickable `tel:` link (UK `0` → `+44` normalisation, original formatting preserved in the visible label)."
    - "The same Site-contact line renders on the survey public-show page below the site_address paragraph, sourcing from the same shared parser keys (with graceful fallback to SiteSurvey.site_contact_name / site_contact_phone columns when the package data is empty)."
    - "When BOTH ship_contact AND ship_phone are empty/missing, NO Site-contact line renders (no dangling `Site contact: ·` with empty pieces)."
  artifacts:
    - path: "app/Services/QuoteParserService.php"
      provides: "Multi-line post-QTYEND description capture (extractDescriptionAfterTuple smarter page-break handling) + new SHIPCONT/SHIPPHONE → project.ship_contact / project.ship_phone extraction in the tagged-PDF return shape"
    - path: "tests/Unit/Rams/QuoteParserServiceTest.php"
      provides: "New test method asserting full multi-line desc capture for the MT300 fixture + new test method asserting ship_contact / ship_phone land in the project return-shape keys"
    - path: "resources/views/partials/_engineer-reference-drawer.blade.php"
      provides: "open-by-default amber-themed drawer (#C07000) when $files->isNotEmpty(); colour swap is the ONLY change — file count + PDF iframe + chip rendering identical"
    - path: "resources/views/worksheets/public-show.blade.php"
      provides: "New ws-header__contact div directly below ws-header__meta (line ~420), conditional render via @if(ship_contact || ship_phone), inline phoneToTelHref helper logic"
    - path: "resources/views/surveys/show.blade.php"
      provides: "New site-contact paragraph in the sticky header (after line 41) with identical conditional + tel:-link logic, sourcing from $survey->project->latestPackage->extracted_data['project'] with SiteSurvey column fallback"
    - path: "tests/Feature/Worksheets/PublicWorksheetHeaderContactTest.php"
      provides: "Feature test covering 3 cases: (1) both present → contact line renders with tel: href, (2) name-only present → line renders without tel-link, (3) both empty → no contact line at all"
  key_links:
    - from: "app/Services/QuoteParserService.php (parseTaggedPdf return)"
      to: "ExtractQuoteJob → ProjectPackage.extracted_data['project']['ship_contact'|'ship_phone']"
      via: "tagged extractTagContent('SHIPCONTSTART','SHIPCONTEND') + extractTagContent('SHIPPHONESTART','SHIPPHONEEND') added to the tagged-PDF return array (1941-1955)"
      pattern: "extractTagContent.*SHIPCONT"
    - from: "resources/views/worksheets/public-show.blade.php (ws-header)"
      to: "$worksheet->project->latestPackage->extracted_data['project']['ship_contact'/'ship_phone']"
      via: "@php pulls package; @if renders Site contact div"
      pattern: "ws-header__contact"
    - from: "resources/views/surveys/show.blade.php (sticky header)"
      to: "$survey->project->latestPackage->extracted_data['project']['ship_contact'/'ship_phone'] ?? $survey->site_contact_name / site_contact_phone"
      via: "@php fallback chain + @if(name || phone) render"
      pattern: "site-contact"
    - from: "resources/views/partials/_engineer-reference-drawer.blade.php"
      to: "Both worksheets/public-show.blade.php (line 581) AND surveys/show.blade.php (line 121)"
      via: "Shared @include partial — one colour edit covers both surfaces"
      pattern: "_engineer-reference-drawer"
---

<objective>
Ship three small engineer-worksheet polish items surfaced by live project `21CQ30362-01-OPS - Reading Borough Council` (id 75):

A. **Parser fix** — gather full multi-line item descriptions when a QuoteWerks tuple's description sits AFTER `QTYEND` and spans a page boundary (MT300 evidence: only line 1 of 5 currently lands; the page-break truncator in `extractDescriptionAfterTuple()` line 3064 fires too eagerly).

B. **Drawer reskin** — flip the shared `_engineer-reference-drawer.blade.php` partial to open-by-default + amber accent (`#C07000`) so engineers actually NOTICE the drawer; today's collapsed teal drawer is being missed in the field. User-locked decision (b).

C. **Header site contact** — render `Site contact: {name} · {tel-link}` on both the worksheet public link AND the survey public link, sourcing from the parser's tagged extraction (SHIPCONT/SHIPPHONE tags). User-locked decision (c) — name + phone ONLY, no email.

Retroactive (executed AFTER this plan lands by orchestrator queue, NOT a task here): re-extract project 75 so its kit list picks up the multi-line MT300 description per the same recipe used for project 76.

Purpose: Three independent micro-improvements that share the same surface area (engineer worksheet view) — bundling avoids three round trips through CI + deploy while keeping each change atomic at the test level.

Output: 4 atomic commits; 1 plan; no schema changes; no auth changes; no new routes.
</objective>

<execution_context>
@$HOME/.claude/get-shit-done/workflows/execute-plan.md
@$HOME/.claude/get-shit-done/templates/summary.md
</execution_context>

<context>
@.planning/STATE.md
@./CLAUDE.md
</context>

<interfaces>
<!-- Key contracts extracted during planning so the executor doesn't need to re-explore. -->

**`app/Services/QuoteParserService.php` — tagged-PDF return shape (lines 1941-1955):**
```php
return [
    'client'         => $client,
    'site_name'      => $siteName,
    'site'           => $site,
    'ref'            => $ref,
    'overview'       => $overview,
    'equipment'      => $equipment,
    'prepared_by'    => $preparedBy,
    'tasks'          => $tasks,
    'rooms'          => $rooms,
    'room_overviews' => $roomOverviews,
    'project_name'   => '',
    'works_summary'  => '',
    'confidence'     => $confidence,
];
```
**Action: add two new keys** here — `'ship_contact' => $this->extractTagContent($rawText, 'SHIPCONTSTART', 'SHIPCONTEND')` and `'ship_phone' => $this->extractTagContent($rawText, 'SHIPPHONESTART', 'SHIPPHONEEND')`. The helper already exists at line 1963.

**`extractDescriptionAfterTuple()` — current page-break truncator (lines 3060-3066):**
```php
// Truncate at the first page-break line ("1 of 5", "2 of 5", etc.).
if (preg_match('/\r?\n[^\r\n]*\b\d+\s+of\s+\d+\b[^\r\n]*/i', $chunk, $pm, PREG_OFFSET_CAPTURE)) {
    $chunk = substr($chunk, 0, (int) $pm[0][1]);
}
```
**Root cause:** when a desc spans a page, this cuts at line 1 + page-marker, dropping lines 2-5 which sit AFTER the page banner on page 2. Fix: instead of truncating, EXCISE the page-banner block (page-number line PLUS the next N lines of repeating header noise — SHIPCONT/SITENAME/QUOTENUM tags + reference line) and continue gathering. The next-PARTSTART boundary (already computed at line 3044-3049) is the real terminator.

**`ExtractQuoteJob.php` consumer (lines 100-145):** consumes `$extracted['site_address']`, `$extracted['client_name']`, `$extracted['project_name']`, `$extracted['qw_number']`. It does NOT currently touch `ship_contact` / `ship_phone`, BUT it DOES persist the full `$extracted` blob into `ProjectPackage.extracted_data` (line 141 — `'extracted_data' => $extracted`). So once the parser emits the new keys, they land in `$package->extracted_data['ship_contact']` automatically — no controller/job change needed. **BUT** the Blade views ask for `extracted_data['project']['ship_contact']` per the prior investigation; the parser's flat return shape has NO nested `project` key. Resolve by reading from the FLAT keys: `$package->extracted_data['ship_contact']` and `['ship_phone']` (top-level). Update the views accordingly.

**`PublicWorksheetController::show()` (lines 46-57):** already eager-loads `'project.referenceFiles'`. We need it to ALSO eager-load `'project.latestPackage'` so the view can read `$worksheet->project->latestPackage->extracted_data['ship_contact']` without an N+1. One-line addition.

**`Worksheet` model:** has NO ship_contact / site_contact columns. Source MUST be the project's latestPackage.

**`SiteSurvey` model (line 24-25):** already has `site_contact_name` + `site_contact_phone` fillable columns — use as fallback when package data is empty on the survey view.

**Shared partial coverage** (grep-confirmed): `_engineer-reference-drawer.blade.php` is `@include`d from EXACTLY TWO files:
- `resources/views/worksheets/public-show.blade.php:581`
- `resources/views/surveys/show.blade.php:121`

→ ONE edit to the partial covers BOTH surfaces. (d) confirmed.

**Insertion points** (line numbers — verified by Read):
- Worksheet header (public-show.blade.php): insert NEW `<div class="ws-header__contact">` immediately AFTER closing `</div>` of `ws-header__meta` on **line 420**, BEFORE the closing `</div>` of `ws-header__inner` on line 421.
- Survey header (surveys/show.blade.php): insert NEW `<p class="text-xs text-white/60 ...">` immediately AFTER the existing site_address paragraph on **line 41**, BEFORE the step progress `<div x-show="screen === 'step'">` on line 44.
</interfaces>

<tasks>

<task type="auto" tdd="true">
  <name>Task 1: Parser — capture full multi-line post-QTYEND description AND emit ship_contact / ship_phone</name>
  <files>app/Services/QuoteParserService.php, tests/Unit/Rams/QuoteParserServiceTest.php</files>
  <behavior>
    Test A (multi-line desc capture — MT300 shape):
    - Input fixture: a tagged-PDF rawText string containing one PARTSTART…PARTEND…PARTDESCSTART (qty+partno only, e.g. "1.00 T300")…paARTDESCEND…QTYSTART…QTYEND tuple, FOLLOWED by 5 lines of description text starting "The MT300 intelligently connects AVer cameras with…" with a typical QuoteWerks page-banner block in between (a "Page 1 of 5" line + 3 lines of repeated SHIPCONT/SITENAME/QUOTENUM tagged header text), THEN the next item's PARTSTART line.
    - Expected: parseTaggedPdf() returns equipment[0]['description'] = all 5 description lines joined by single spaces (NOT truncated at line 1, NOT containing the page-banner SHIPCONT/SITENAME tag debris, NOT bleeding into the next item's part-number text).

    Test B (single-line desc — regression guard):
    - Input fixture: existing single-line PARTDESC fixture (lift one verbatim from current passing test at line 702 — "Samsung 65 inch display"); assert behaviour UNCHANGED.

    Test C (ship_contact / ship_phone extraction):
    - Input fixture: tagged-PDF rawText containing SHIPCONTSTART…John Smith…SHIPCONTEND and SHIPPHONESTART…0118 937 3787…SHIPPHONEEND plus minimum valid tagged structure (one OVERVIEWTITLE + one PART tuple) so parseTaggedPdf path is taken.
    - Expected: result['ship_contact'] === 'John Smith' AND result['ship_phone'] === '0118 937 3787'.

    Test D (ship_contact / ship_phone absent → empty strings):
    - Same fixture WITHOUT SHIPCONT/SHIPPHONE tags.
    - Expected: result['ship_contact'] === '' AND result['ship_phone'] === '' (NOT missing keys, NOT null — keep extractTagContent contract).
  </behavior>
  <action>
    Modify `extractDescriptionAfterTuple()` (~line 3040-3082) to EXCISE page-banner blocks instead of truncating at them. Replace the existing `preg_match('/\r?\n[^\r\n]*\b\d+\s+of\s+\d+\b[^\r\n]*/i', ...)` truncation (~lines 3064-3066) with a `preg_replace_callback` (or `preg_replace`) that removes the page-marker line PLUS any immediately-following repeated-header tag block (SHIPCONT/SHIPPHONE/SHIPCOMP/SITENAME/QUOTENUM/PREPAREDBY tag-pair runs) — leaving the description text on either side joined. The next-PARTSTART boundary already computed at lines 3044-3049 remains the hard terminator. The existing tag-strip + whitespace-collapse on lines 3068-3079 then naturally folds the multi-line description into a clean single-space-joined string.

    Be conservative: the excision regex must only match the OCR page-banner pattern (page-marker line + ≤6 contiguous lines containing tagged-header markers); if the gap between two desc lines doesn't look like a page banner, leave it alone (preg_replace simply makes no substitution). Cap the page-banner removal at ONE occurrence per chunk so we never silently swallow legitimate description text that happens to contain digits.

    Add SHIPCONT / SHIPPHONE extraction to the tagged-PDF return shape: in `parseTaggedPdf()`'s return array (lines 1941-1955), add two new keys BEFORE 'confidence':
      'ship_contact' => $this->extractTagContent($rawText, 'SHIPCONTSTART', 'SHIPCONTEND'),
      'ship_phone'   => $this->extractTagContent($rawText, 'SHIPPHONESTART', 'SHIPPHONEEND'),
    The `extractTagContent` helper at line 1963 already returns '' for missing tags — no nil-handling needed at call site.

    Do NOT touch the heuristic (non-tagged) return at lines 179-192 — those keys aren't expected for tagged QuoteWerks PDFs and adding them there is out of scope. Tests cover the tagged path only.

    Write the 4 new test methods (A/B/C/D above) in `tests/Unit/Rams/QuoteParserServiceTest.php` following the existing file's `$rawText = implode("\n", [...])` fixture style (search line 702 / 731 / 780 for the pattern). Name them `test_multi_line_description_after_qtyend_across_page_break_captures_all_lines`, `test_single_line_partdesc_still_captured_unchanged`, `test_ship_contact_and_ship_phone_extracted_into_tagged_return_shape`, `test_ship_contact_and_ship_phone_default_to_empty_string_when_tags_absent`.
  </action>
  <verify>
    <automated>php artisan test --filter='QuoteParserServiceTest' && php artisan test tests/Feature/Rams/RamsRenderRegressionTest.php</automated>
  </verify>
  <done>4 new test methods PASS, all pre-existing QuoteParserServiceTest methods STILL PASS, RamsRenderRegressionTest 3/3 / 9 assertions STILL GREEN (D-12 byte-equivalence canary).</done>
</task>

<task type="auto">
  <name>Task 2: Drawer — open-by-default + amber #C07000 accent (single partial covers both surfaces)</name>
  <files>resources/views/partials/_engineer-reference-drawer.blade.php</files>
  <action>
    Three surgical edits to the partial:

    1. Add `open` attribute to the outer `<details>` on line 62 — change `<details class="erf-drawer"` to `<details class="erf-drawer" open` so the drawer renders expanded when `$files->isNotEmpty()` (the outer `@if` at line 61 already gates on isNotEmpty, so the `open` attribute never applies to a 0-files state).

    2. Colour swap — replace ALL occurrences of teal with amber across the inline styles (each occurrence appears in `style="..."` attributes; use Edit's find-replace per literal — no regex needed since the colours are unique tokens):
       - `#178A95` → `#C07000` (occurs 5 times: lines 63 left-border, 67 chev colour, 75 chip uppercase label, 85 inner PDF chev, 93 download button bg, 119 chip in non-pdf rows, 122 "Tap to download" link colour). Count via Grep before editing.
       - `rgba(23,138,149,.35)` → `rgba(192,112,0,.35)` (line 63 outer border).
       - `rgba(23,138,149,.06)` → `rgba(192,112,0,.15)` (line 65 title-bar tint — bumped from .06 to .15 for stronger amber wash per the prior-investigation guidance).
       Leave `#0B3C45` (brand-dark text colour) and `#FAFAFA` / `#E5E7EB` / `#6B7280` UNCHANGED — those are neutral chrome, not accent colour.

    3. Update the docblock comment on lines 19-21 — change "minimal inline styling using the brand teal #178A95" to "minimal inline styling using accent amber #C07000 (visually distinct from the teal kit-list drawer)". One-line edit. Leave the rest of the docblock untouched.

    Do NOT change the partial's parameter contract ($files / $serveRouteName / $token), the `@php $kindOf` / `$chipFor` / `$humanSize` helpers, the file count display logic, the PDF iframe markup, the image grid layout, or the non-PDF row layout. ONLY the open attribute + 5 colour token swaps + 1 docblock line.
  </action>
  <verify>
    <automated>grep -c '#178A95' resources/views/partials/_engineer-reference-drawer.blade.php; grep -c '#C07000' resources/views/partials/_engineer-reference-drawer.blade.php; grep -c 'rgba(23,138,149' resources/views/partials/_engineer-reference-drawer.blade.php; grep -c 'rgba(192,112,0' resources/views/partials/_engineer-reference-drawer.blade.php; grep -c 'open' resources/views/partials/_engineer-reference-drawer.blade.php</automated>
  </verify>
  <done>Greps show 0 occurrences of `#178A95`, ≥5 of `#C07000`, 0 of `rgba(23,138,149`, 2 of `rgba(192,112,0` (one .35 + one .15), ≥1 of `open` (the `<details>` attribute). View renders without PHP errors when included from public-show.blade.php in a smoke render (verified by Task 4's e2e).</done>
</task>

<task type="auto" tdd="true">
  <name>Task 3: Header site-contact line on worksheet + survey public views (tel:-link + UK normalisation)</name>
  <files>resources/views/worksheets/public-show.blade.php, resources/views/surveys/show.blade.php, app/Http/Controllers/PublicWorksheetController.php, tests/Feature/Worksheets/PublicWorksheetHeaderContactTest.php</files>
  <behavior>
    Three feature-test cases (single new file `tests/Feature/Worksheets/PublicWorksheetHeaderContactTest.php`):

    Test 1 (both present): create a Project with a ProjectPackage whose extracted_data['ship_contact'] = 'John Smith' and extracted_data['ship_phone'] = '0118 937 3787'; create a Worksheet linked to that project; GET /worksheet/{token}; assert response contains 'Site contact: John Smith' AND contains '<a href="tel:+441189373787"' AND contains '>0118 937 3787</a>'.

    Test 2 (name-only): same setup but extracted_data['ship_phone'] empty; assert response contains 'Site contact: John Smith' AND does NOT contain 'tel:' (no phone → no link, just the name).

    Test 3 (both empty): same setup but BOTH keys empty/missing; assert response does NOT contain the literal 'Site contact:' anywhere (no dangling separator).
  </behavior>
  <action>
    Step 1 — Controller eager-load. In `PublicWorksheetController::show()` (line 49), extend the eager-load chain from `'signoffs', 'photos', 'project.referenceFiles'` to `'signoffs', 'photos', 'project.referenceFiles', 'project.latestPackage'` so the new header @php block doesn't trigger an N+1.

    Step 2 — Worksheet header. In `resources/views/worksheets/public-show.blade.php`, AFTER the closing `</div>` of `ws-header__meta` (line 420) and BEFORE the closing `</div>` of `ws-header__inner` (line 421), insert:
      @php
          $pkg = optional($worksheet->project)->latestPackage;
          $ed = is_array($pkg?->extracted_data) ? $pkg->extracted_data : [];
          $siteContactName = trim((string) ($ed['ship_contact'] ?? ''));
          $siteContactPhone = trim((string) ($ed['ship_phone'] ?? ''));
          $telHref = '';
          if ($siteContactPhone !== '') {
              $digits = preg_replace('/\s+/', '', $siteContactPhone);
              $telHref = (str_starts_with($digits, '0')) ? '+44' . substr($digits, 1) : $digits;
          }
      @endphp
      @if($siteContactName !== '' || $siteContactPhone !== '')
          <div class="ws-header__meta ws-header__contact" style="margin-top:.2rem;">
              Site contact:
              @if($siteContactName !== ''){{ $siteContactName }}@endif
              @if($siteContactName !== '' && $siteContactPhone !== '') · @endif
              @if($siteContactPhone !== '')<a href="tel:{{ $telHref }}" style="color:inherit;text-decoration:underline;">{{ $siteContactPhone }}</a>@endif
          </div>
      @endif
    Reuse the existing `ws-header__meta` class so the styling (font-size .82rem, white/70 colour) is inherited; the new `ws-header__contact` class is added solely for future-targeted CSS hooks (no rules defined now). The `margin-top:.2rem` inline pushes the line just below the address.

    Step 3 — Survey header. In `resources/views/surveys/show.blade.php`, AFTER the existing site_address `<p>` on line 41 and BEFORE the `<div x-show="screen === 'step'">` on line 44, insert the SAME @php block (with package fallback to survey columns):
      @php
          $pkg = optional($survey->project)->latestPackage;
          $ed = is_array($pkg?->extracted_data) ? $pkg->extracted_data : [];
          $siteContactName = trim((string) ($ed['ship_contact'] ?? $survey->site_contact_name ?? ''));
          $siteContactPhone = trim((string) ($ed['ship_phone'] ?? $survey->site_contact_phone ?? ''));
          $telHref = '';
          if ($siteContactPhone !== '') {
              $digits = preg_replace('/\s+/', '', $siteContactPhone);
              $telHref = (str_starts_with($digits, '0')) ? '+44' . substr($digits, 1) : $digits;
          }
      @endphp
      @if($siteContactName !== '' || $siteContactPhone !== '')
          <p class="text-xs text-white/60 mt-0.5 truncate">
              Site contact:
              @if($siteContactName !== ''){{ $siteContactName }}@endif
              @if($siteContactName !== '' && $siteContactPhone !== '') · @endif
              @if($siteContactPhone !== '')<a href="tel:{{ $telHref }}" class="underline text-white/80">{{ $siteContactPhone }}</a>@endif
          </p>
      @endif
    Use Tailwind classes consistent with the existing `text-white/60` paragraph above (not the inline-style approach used in the worksheet view, which uses raw CSS classes).

    Step 4 — Survey controller eager-load. Find the `SurveyController`/`PublicSurveyController` `show()` method that powers `surveys/show.blade.php`; if it doesn't already eager-load `project.latestPackage`, add it. (Verify by grep first; skip the edit if already loaded — the survey controller may already pull the package for other reasons.)

    Step 5 — New feature test file `tests/Feature/Worksheets/PublicWorksheetHeaderContactTest.php`. Use the 3 test scenarios from `<behavior>`. Use the existing Worksheet + Project + ProjectPackage factories (search `tests/Feature/Worksheets/` for an existing public-route test as a template — e.g. PublicWorksheetSignoffTest if it exists; otherwise model after a similar `tests/Feature/Surveys/PublicSurveyShowTest`-shape test). The test only needs Worksheet coverage; survey-view coverage is implicit via the shared logic + manual Task 4 e2e smoke.
  </action>
  <verify>
    <automated>php artisan test --filter='PublicWorksheetHeaderContactTest'</automated>
  </verify>
  <done>3 feature tests PASS, no N+1 (single package query per page render — Laravel's `getEagerLoads` lists `project.latestPackage` on the worksheet show response).</done>
</task>

<task type="checkpoint:human-verify" gate="blocking">
  <what-built>
    Tasks 1-3 complete:
    - Parser captures full multi-line MT300-style descriptions across page-banner breaks
    - Parser populates ship_contact / ship_phone in extracted_data for downstream Blade reads
    - Drawer renders amber + open-by-default on both worksheet and survey public views
    - Site-contact line renders in worksheet header (with tel:-link) when data present
    - Site-contact line mirrors on survey header
    Plus the orchestrator-queued retroactive re-extract of project 75 should have run by this point — confirm the kit list now shows the full MT300 description.
  </what-built>
  <how-to-verify>
    Run full canary + visual checks:

    1. CI canary (must pass before manual checks):
       `php artisan test --filter='QuoteParserServiceTest|PublicWorksheetHeaderContactTest|RamsRenderRegressionTest'`
       Expected: all green, RamsRenderRegression 3/3 / 9 assertions still byte-identical.

    2. After deploy + retroactive re-extract of project 75:
       Open https://rams.21stcav.com/projects/75 → "Engineer Link" → public worksheet URL.
       Verify visually:
       (a) Header shows "Site contact: {name} · {phone}" line below the address line (clickable tel: link on mobile).
       (b) Engineer Reference Files drawer is OPEN by default, with an amber left-border (#C07000) and amber title-tint — visibly different from the teal Kit List drawer below it.
       (c) Kit List → find the MT300 row → description now reads the full 5-line text starting "The MT300 intelligently connects AVer cameras with…" not truncated at line 1.

    3. Same checks on the survey link for the same project (https://rams.21stcav.com/survey/{token}) — header shows site-contact line, drawer is amber + open.

    4. Cross-check a project with NO reference files and NO ship_contact — the drawer renders nothing AND the site-contact line renders nothing (no empty "Site contact: ·" debris).
  </how-to-verify>
  <resume-signal>Type "approved" once all 4 visual checks pass, or describe regressions.</resume-signal>
</task>

</tasks>

<verification>
- All new unit + feature tests pass.
- RamsRenderRegressionTest stays green (D-12 byte-equivalence preserved — parser change is upstream of renderer; the regression fixture's existing descriptions either don't hit the multi-line page-break path OR if they do, the new excise-then-continue logic is strictly additive to the captured text, which means the regression renderer would surface ANY mid-fixture page-banner debris that was previously truncated. If RamsRenderRegression breaks, the failing fixture's "before" state was hiding a parser bug we just fixed — bring the failure to the user before regenerating the fixture).
- No new routes, no migrations, no auth changes.
- Authorisation surface from 260525-pyu/s8b shared-workspace shipping is UNTOUCHED.
- Mobile-first: the new header contact line uses the same wrap behaviour as the existing meta line (no new flex/grid).
</verification>

<success_criteria>
- 4 atomic tasks completed (3 implementation + 1 checkpoint).
- Parser correctly captures the full MT300 description on the re-extracted project 75 worksheet.
- Drawer is amber + open-by-default on both worksheet and survey public views; the partial change touches BOTH surfaces from a single edit (confirmed: `_engineer-reference-drawer.blade.php` is `@include`d from `worksheets/public-show.blade.php:581` AND `surveys/show.blade.php:121`).
- Site-contact line renders correctly across all three conditional states (both present / name-only / both empty).
- Phone tel:-link uses UK `+44` normalisation when input starts with `0`; preserves original formatting in the visible label.
- Email is OUT OF SCOPE (per user-locked decision c) — no email markup in the new contact line.
- RamsRenderRegression byte-equivalence canary stays GREEN.
</success_criteria>

<output>
Create `.planning/quick/260602-mlt-engineer-worksheet-polish-surfaced-by-21/260602-mlt-SUMMARY.md` when all tasks complete, following the GSD summary template (what shipped, files changed, test counts, byte-equivalence status, any deviations).
</output>
