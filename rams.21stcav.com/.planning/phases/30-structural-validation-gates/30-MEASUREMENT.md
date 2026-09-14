# Phase 30 — Measurement and Arming Runbook

**Status:** Procedure written 2026-09-13 (Plan 30-05). **Not yet run.** All five gates ship
disarmed (D-03) and none of GATE-04/GATE-13/GATE-14's bodies exist on disk yet (Plans 30-06,
30-07, 30-08 are still open). This document is the runbook the operator follows once all nine
Phase 30 plans have shipped and been deployed — it is written now so "verify live then flip"
(D-03) has an owner and a definition of done before that day arrives, per the same reasoning
`29-MEASUREMENT.md` recorded for Phase 29.

Format follows `29-MEASUREMENT.md` exactly: operator-run, on the VPS, as `stcav`,
`php artisan tinker --execute="..."`, `select` plus in-memory filter, **zero writes** for every
measurement command. The one exception — the corpus regeneration required before arming — is
called out explicitly as NOT read-only, in its own section below.

> ⚠️ **`php` is NOT on the Bash tool's PATH on this machine.** Every command in this file must be
> run through PowerShell / an interactive VPS SSH session, never piped through the Bash tool —
> `php … | tail` exits 0 while executing nothing, silently faking a green result.

---

## Per-gate read-only measurement

Run each block with `php artisan tinker --execute="..."` on the VPS as `stcav`. Each is a
`select` plus an in-memory filter. None of these commands writes to the database.

### GATE-01 — orphan-control trigger coverage

**What to count:** documents whose text contains a trigger phrase from the shipped
`config('rams_tier1.structural_gate_triggers')` vocabulary, and of those, how many lack
hazard-side support, client-responsibility-side support, or both.

```php
$triggers = array_keys(config('rams_tier1.structural_gate_triggers', []));
$rows = \App\Models\RamsDocument::query()->select('id', 'generated_data')->get();
$hits = [];
foreach ($rows as $r) {
    $data = (array) $r->generated_data;
    $haystack = strtolower(json_encode($data['method_statement'] ?? []) . ' ' . json_encode($data['hazards'] ?? []));
    foreach ($triggers as $t) {
        if (str_contains($haystack, strtolower($t))) {
            $hits[] = ['id' => $r->id, 'trigger' => $t];
        }
    }
}
echo count($hits) . " trigger hit(s) across " . $rows->count() . " document(s)\n";
```

**Why it decides arming:** this sets the vocabulary's initial contents (D-06). The vocabulary is
config-resident data, not code, specifically so it is tunable without a redeploy once this number
is known — a trigger phrase that matches too broadly can be narrowed or removed the same day it
is measured.

### GATE-02 — area/method-step coverage

**What to count:** documents with at least one `reviewed_data.room_overviews[*].room`, and of
those, how many carry an area name matching no phase title or step.

```php
$rows = \App\Models\RamsDocument::query()->select('id', 'reviewed_data', 'generated_data')->get();
$withAreas = 0;
$unmatched = 0;
foreach ($rows as $r) {
    $areas = array_values(array_filter(array_map(
        fn ($x) => is_array($x) ? trim((string) ($x['room'] ?? '')) : '',
        (array) (($r->reviewed_data ?? [])['room_overviews'] ?? [])
    ), fn ($s) => $s !== ''));
    if (empty($areas)) continue;
    $withAreas++;
    $phaseText = strtolower(json_encode((array) (($r->generated_data ?? [])['method_statement'] ?? [])));
    foreach ($areas as $a) {
        if (!str_contains($phaseText, strtolower($a))) { $unmatched++; break; }
    }
}
echo "$withAreas document(s) carry an area list; $unmatched of those have at least one unmatched area\n";
```

**Why it decides arming:** this is the only way to answer "are there production documents with
areas but no method steps" — unanswerable from source (30-RESEARCH.md Finding 3). It is also the
strongest false-positive risk in the phase: name-based matching against generic room names
("AV Rack", "Room 1") can collide.

### GATE-04 — residual-vs-initial scoring

**What to count:** hazard rows where `post_l*post_s > pre_l*pre_s` (would error) and rows where
`post_severity < pre_severity` (would warn).

```php
$rows = \App\Models\RamsDocument::query()->select('id', 'generated_data')->get();
$errors = 0; $warns = 0; $total = 0;
foreach ($rows as $r) {
    foreach ((array) (($r->generated_data ?? [])['hazards'] ?? []) as $h) {
        if (!array_key_exists('pre_likelihood', $h) || !array_key_exists('pre_severity', $h)) continue;
        $total++;
        $preL = max(1, min(5, (int) $h['pre_likelihood']));
        $preS = max(1, min(5, (int) $h['pre_severity']));
        $postL = max(1, min(5, (int) ($h['post_likelihood'] ?? 1)));
        $postS = max(1, min(5, (int) ($h['post_severity'] ?? 1)));
        if ($postL * $postS > $preL * $preS) $errors++;
        if ($postS < $preS) $warns++;
    }
}
echo "$total scored hazard row(s): $errors would ERROR, $warns would WARN\n";
```

**Why it decides arming:** if warns are corpus-wide the panel is noise the moment it ships armed;
if errors are non-zero, arming `RAMS_STRUCTURAL_GATES` blocks live regeneration on those documents
until corrected. The committed golden fixture (`tilda-21cq29531`) already contributes one known
warn — this pass tells us whether that is typical or rare.

### GATE-13 — hot-works assertion presence

**What to count:** documents whose free text asserts absence of hot works (the negation-aware
`hot_works_assertion` detector added to `ControlTextRuleViolations` by Plan 30-07).

```php
$rows = \App\Models\RamsDocument::query()->select('id', 'generated_data')->get();
$asserts = 0;
foreach ($rows as $r) {
    $text = strtolower(json_encode((array) (($r->generated_data ?? [])['hazards'] ?? [])) . ' ' . json_encode((array) (($r->generated_data ?? [])['exclusions'] ?? [])));
    if (str_contains($text, 'no hot work')) $asserts++;
}
echo "$asserts document(s) assert absence of hot works\n";
```

**Why it decides arming:** confirms D-02's blocker empirically. D-02 already established (from
source, not measurement) that `addPermitAndIsolation()`'s unconditional permit line and
`Tier1RamsDefaultsService`'s unconditional COSHH baseline mean a naive GATE-13 fires on 100% of
the corpus; this count says how many documents also carry the assertion half, i.e. how many
would actually throw if GATE-13 were armed today without the conditional-wording exception
30-07 builds in.

### GATE-14 — missing risk-reference implication rate

**What to count:** phases whose implication map (`config('rams_tier1.missing_risk_implications')`,
shipped by Plan 30-01) predicts a hazard present in the register but absent from
`associated_risks`.

```php
$map = config('rams_tier1.missing_risk_implications', []);
$rows = \App\Models\RamsDocument::query()->select('id', 'generated_data')->get();
$flags = 0; $phases = 0;
foreach ($rows as $r) {
    $hazardNames = array_map(fn ($h) => strtolower((string) ($h['hazard'] ?? '')), (array) (($r->generated_data ?? [])['hazards'] ?? []));
    foreach ((array) (($r->generated_data ?? [])['method_statement'] ?? []) as $phase) {
        $phases++;
        $stepText = strtolower(implode(' ', (array) ($phase['steps'] ?? [])));
        $cited = (array) ($phase['associated_risks'] ?? []);
        foreach ($map as $phrase => $signal) {
            if (!str_contains($stepText, strtolower((string) $phrase))) continue;
            $impliedPresent = false;
            foreach ($hazardNames as $idx => $hn) {
                if (str_contains($hn, strtolower((string) $signal)) && !in_array($idx, $cited, true)) {
                    $impliedPresent = true;
                    break;
                }
            }
            if ($impliedPresent) { $flags++; break; }
        }
    }
}
echo "$flags of $phases phase(s) would flag under the implication map\n";
```

**Why it decides arming:** this is the highest-variance number in the phase (30-RESEARCH.md
Finding 7) — GATE-14's implication map is a heuristic, not an exact reproduction of
`crossReferenceMethodStatementRisks()`'s intersection rule, so its live hit rate is the single
best evidence for whether the config map needs tuning before `RAMS_MISSING_RISK_REF_GATE` flips.

---

## No backfill migration is needed (explicit, reasoned conclusion)

**Conclusion: none of Phase 30's five gates needs a backfill migration before its flag can flip.**
This differs from Phase 29, deliberately, for a structural reason rather than an oversight:

Phase 29's GATE-11 needed `2026_09_11_180000_backfill_cdm_duty_holder_placeholder` because the
*content* the gate policed — the literal `[To be confirmed]` placeholder string — was **persisted**
in 46 of 54 production rows, written by a prior build and never revisited.

Phase 30's five gates police **relationships between sections that are recomputed on every
`upgrade()` run**: `crossReferenceMethodStatementRisks()` (associated-risk derivation),
`addPermitAndIsolation()` (the hot-works permit line), the hazard-score normaliser (residual vs
initial), and the two Plan 30-02 mirrors (`client_responsibilities_expanded`, `areas_for_gate`)
are all recomputed from `reviewed_data`/`form_data` every time `upgrade()` runs. A **regeneration
IS the backfill** — there is no separate persisted defect to correct with a migration, because the
relationships themselves are never stored independently of the array `upgrade()` receives.

The only persisted artefact Phase 30 introduces is `generated_data['compliance_warnings']`
(Plan 30-01), and UI-SPEC's own data contract requires it to be **recomputed and overwritten
wholesale on every run, never appended** — i.e. deliberately backfill-free by design. There is
nothing for a migration to touch.

---

## Hard precondition on arming `RAMS_STRUCTURAL_GATES`

**`RAMS_STRUCTURAL_GATES` MUST NOT be flipped until a corpus regeneration has persisted the
Plan 30-02 mirrors across every live document.**

Plan 30-02 mirrors `client_responsibilities_expanded` and `areas_for_gate` at exactly three
sites: `RamsBuilderService::buildFromReview()`, `buildFromForm()`, and
`RamsController::updateAndDownload()` (Save Review). The three other `upgrade()` call sites —
`RamsController.php:701` (DOCX rebuild-on-download), `:857` (`downloadPdf()`), and
`RamsRefreshComplianceCommand.php:185` (`rams:refresh-compliance`) — all pass
`$rams->generated_data` verbatim and therefore inherit the mirrors **only by persistence**: only
if the document currently in the database was last written by a build that ran *after* Phase 30's
mirror code shipped.

**On a legacy document, arming the structural trio produces both failure directions at once:**

1. `downloadPdf()` runs GATE-02 against an **empty** `areas_for_gate` list (the mirror never
   wrote it) and reports **clean** — the exact "worse than no gate" failure this phase exists to
   prevent, because the engineer sees no error and assumes the document was checked.
2. GATE-01's `client_responsibilities_expanded` half is simultaneously **blind** on the same
   legacy document, so a document whose only asbestos client-responsibility lives in that bucket
   throws a **false positive** — an orphan-control error the engineer cannot resolve, because the
   supporting entry is present in the database but never reached the array GATE-01 reads.

**The vehicle that closes this gap is `php artisan rams:refresh-compliance` run WITHOUT
`--dry-run`.** `RamsRefreshComplianceCommand.php:190-197` returns *before* persisting when
`--dry-run` is passed — use `--dry-run` for the read-only measurement passes above. Only the
un-flagged run reaches `:198`'s `$rams->update(['generated_data' => $upgraded])` and actually
writes the mirrors into every row. **Note the un-flagged run also re-renders the DOCX for every
document, so it is not a read-only operation** — treat it as a deploy-adjacent maintenance action,
not a measurement command.

**Sequence, in order:**

1. Measure with `php artisan rams:refresh-compliance --dry-run` (read-only) — confirms the command
   runs clean against the current corpus before committing to a real write pass.
2. Regenerate with `php artisan rams:refresh-compliance` (no flag) — persists the mirrors and
   `compliance_warnings` across every live document; re-renders every DOCX.
3. Verify — re-run the GATE-01/GATE-02 read-only measurement blocks above against the
   now-regenerated corpus; confirm `areas_for_gate` and `client_responsibilities_expanded` are
   non-empty on documents that should carry them.
4. Only then flip `RAMS_STRUCTURAL_GATES`.

---

## The arming runbook

Per D-04, the three flags are independent by doctrine — one gate's rollback must never disarm
another's. Flip them as three separate one-line `.env` changes, in this order:

| Step | Flag | When | Gates | Precondition |
|---|---|---|---|---|
| 1 | `RAMS_STRUCTURAL_GATES=true` | After this phase's live verification | GATE-01 + GATE-02 + GATE-04 | Corpus regeneration above (mandatory) |
| 2 | `RAMS_MISSING_RISK_REF_GATE=true` | Independently, once GATE-14's measured hit rate looks sane | GATE-14 | GATE-14 measurement pass above; no mirror dependency |
| 3 | `RAMS_HOT_WORKS_GATE` | **NOT in this phase** | GATE-13 | Phase 31 — RULE-05/GATE-10 must make the COSHH table job-conditional first (D-02) |

Each flip is a single-line `.env` edit on the VPS. Never flip more than one flag as part of the
same verification pass — if a regression appears, the independent-flag doctrine (D-04) must let
the operator roll back exactly the failing gate group without touching the others.

**Config-cache step — do not skip.** The live VPS runs `config:cache` for this application. An
`.env` edit alone may silently do nothing if the cached config is not invalidated. After **each**
individual flip:

```
php artisan config:clear
php artisan config:cache
```

...then re-run a regeneration (`php artisan rams:refresh-compliance --dry-run` first, to confirm
the new flag state is actually visible to `config()` before trusting a live document render).

**Research assumption A4 (unverified):** this `config:clear` + `config:cache` requirement is
inferred from the project's established CWP deployment pattern, not verified against this
specific VPS's actual cache state at the time of writing. If wrong (config caching is not active),
the extra clear-and-recache is harmless. If right and this step is omitted, the flag flip silently
does nothing and the operator will believe a gate is armed when it is not.

---

## Code-review findings that bear on arming (added 2026-09-14, close-out review gate)

The Phase 30 close-out code review (`30-REVIEW.md`) produced one blocker and two warnings.
The blocker is **fixed**; the two warnings are **open** and both are false-positive risks that
only manifest once a flag flips, so they belong here rather than in a backlog.

**CR-01 — FIXED (commit `f50b6de`), no action needed at arming.** `StructuralGateVocabulary::flattenAreas()`
chained `$data['areas_for_gate'] ?? $data['rooms'] ?? null`. Because all three mirror sites always
SET `areas_for_gate` (to `[]` when `room_overviews` is empty) and `??` coalesces only on null/unset,
the fallback was dead code on every live document — GATE-02 passed **vacuously** whenever
`room_overviews` was empty, even with real rooms present. Now a truthiness check. Two regression
tests added using the production fixture shape; the fix was verified by reverting it and observing
the new test go red. Had this shipped unfixed, the GATE-02 measurement below would have reported a
falsely clean corpus and the arming decision would have rested on it.

**WR-01 — OPEN. Re-check before flipping `RAMS_HOT_WORKS_GATE` (Phase 31).**
GATE-13's `permitRuleIsUnconditionalHotWorksRequirement()` (`RamsComplianceUpgradeService.php:2574`)
substring-matches conditional markers ("if" / "when" / "where") across the **whole** rule line,
unscoped. An unrelated occurrence anywhere in the sentence silently suppresses detection of a
genuinely unconditional permit requirement — a false **negative**, i.e. the gate goes quiet rather
than noisy. Note this compounds with the already-recorded fact that GATE-13's permit half is
architecturally unreachable through the real pipeline; between them, only the COSHH half carries
real weight today.

**WR-02 — OPEN. Resolve with the GATE-01 measurement below, before flipping `RAMS_STRUCTURAL_GATES`.**
`config/rams_tier1.php:271-281` maps the "permit to work", "isolation certificate" and
"hot-works permit" triggers onto the `ceiling_void_access` / `mains_connection` signals. The config
comments themselves describe these as unrelated approximations. Once armed, a document mentioning a
permit but carrying neither of those signals would throw a GATE-01 error it does not deserve. The
GATE-01 measurement pass is exactly the evidence needed to either re-map or drop these three
triggers — do not guess a replacement mapping ahead of that data.

---

## Known limitations carried forward (for Phase 31's arming task and this plan's SUMMARY)

**(a) `RamsController::review()` never calls `upgrade()`.** (`:307-326`). A document last saved
before Phase 30 shipped shows no warnings and is not gate-checked until the next Save Review or a
`rams:refresh-compliance` regeneration runs `upgrade()` and persists the result. This is accepted
behaviour, not a defect — calling `upgrade()` from a read-only GET request would turn a page that
currently cannot fail into a failure surface for nine gates (four shipped, five from this phase).
`30-UI-SPEC.md` already records and accepts this gap.

**(b) The Blade-side hot-works permit derivation is a GATE-13 bypass surface, out of scope here.**
`pdf/rams.blade.php:407-409` and `pdf/rams-v2.blade.php:463-465` derive a `'Hot Works Permit'` row
**in the Blade template itself**, from a `preg_match('/(solder|heat shrink|hot work)/', $scopeBlob)`
regex against the scope text. This derivation is invisible to `RamsComplianceUpgradeService::upgrade()`
— a document can display a hot-works permit requirement on the rendered PDF that GATE-13 never
sees, because GATE-13 only inspects the `upgrade()`-side array. This is out of scope for Phase 30
because GATE-13 ships disarmed; it must be revisited before or during Phase 31's arming task.

**(c) The mirror-liveness split is why the corpus regeneration is a precondition, not a nicety.**
Sites 4/5/6 (`RamsController.php:701`, `:857`, `RamsRefreshComplianceCommand.php:185`) inherit the
Plan 30-02 mirrors only by persistence — see the hard-precondition section above. Any future phase
that reads `generated_data` on one of these three sites and assumes the Phase 30 mirror keys are
always present must re-verify that assumption against the corpus regeneration state, not assume it
from the code alone.
