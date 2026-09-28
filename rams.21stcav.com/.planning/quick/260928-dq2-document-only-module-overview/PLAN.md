---
phase: quick
plan: 260928-dq2
type: defect
severity: user-visible-false-statement
subsystem: cockpit
autonomous: true
---

# Quick Task 260928-dq2: a document-only module's Overview measures visits, so RAMS says "nothing recorded" forever

## Objective

The user, looking at a live cockpit with two RAMS documents on the Files tab:

> "iN OVERVIEW , TXT SAYS RAMS has nothing recorded against it yet. EVEN
> THOUGH THERE ARE 2 VERSIONS OF RAMS UNDER FILES"

and

> "ON RAMS , ONCE A DOC HAS BEEN CREATED , CAN THE BUTTON UNDER OVERVIEW
> CHANGES TO REGENERATE AND EDIT (WHICH TAKS YOU TO THE RAMS DOC INFO TO EDIT
> /SAVE AND REGEN) ?"

and, earlier:

> "once a rams is generate it defaults to review rams eventhough they have
> been created. CAn review be set under an edit function if a user needs to
> change things are rerun and the actual doc/pdf be presented ready for
> download."

Four things, one drawer: say the truth on Overview, offer Regenerate and Edit
once a document exists, reach the finished Word and PDF, and fix the status bug
that keeps a manual-form RAMS out of `completed` forever.

## Root cause — the empty sentence is the `@else` of a visit loop

`resources/views/components/cockpit/panel.blade.php:312-320`: the hint is the
`@else` arm of `@if ($visits->isNotEmpty())`. It measures **visits and only
visits**.

`VisitLinkIssuer::VISIT_MODULES` maps exactly two modules — `site_survey` and
`worksheet` — because those are the only two with an engineer link (its
docblock at `:21-27` states the rule and says it is not arbitrary).
`CockpitModulePresenter::MODULE_MAP` says the same thing in its own data:
`rams` and `om` both carry `'visit_types' => []`.

So RAMS and O&M have no visits **by design**, `$visits` is permanently empty
for both, and the drawer says "nothing recorded" however many documents the
project holds. It is not a glitch; nothing had ever rendered a document-only
module with documents in it.

## Approach

**1. The Overview reports what the module can HOLD, and the discriminator is
visit types — never a document key.**

`CockpitModulePresenter::modules()` gains one derived boolean,
`has_visits => $definition['visit_types'] !== []`, read off the map it already
owns. The Blade branches on that boolean. There is no `@if` on a module key
anywhere — the component has switched on TYPE and named no document since
46.2-05 and that property is kept.

- a module with visit types renders the visits card, exactly as today;
- a module without renders a **Documents** card built from the same per-module
  collection the Files tab already renders (`CockpitPanelPresenter::files()`,
  already wired into the panel for every tab by the controller);
- the hint appears **only when both are empty**.

O&M is fixed by the same branch and by the same line of Blade. So are the two
visit modules, whose behaviour is unchanged because the documents collection is
empty for them by construction.

**2. Regenerate and Edit, once a document exists.**

`x-cockpit.doc-form`'s CLOSED control keeps its derived-format copy and flips
its verb: `Create document — Word or PDF` when the module holds nothing,
`Regenerate — Word or PDF` when it holds something. It still posts through
`projects.cockpit.documents.store`, which is the cockpit's own generation path
and already produces a NEW document — so "Regenerate" is a true description of
what the control does, and **no new route is added**. `RamsController::regenerate`
is deliberately NOT called from here: it is a RAMS-only POST and wiring it
would put a document key back into a component that names none.

**Edit** is an anchor on each Documents row pointing at that document's own
route — `rams.review` for RAMS, `om-manuals.edit` for O&M — read from
`CockpitPanelPresenter::DOCUMENTS`, which already maps it.
`resources/views/rams/review.blade.php:541-552` is commented
`Edit & Download form` and posts to `rams.update-and-download`: the review page
IS the edit form, so Edit needs nothing built.

⚠ The Edit anchor lives on the Documents ROW, **not** inside `.cav-qa`.
`CockpitDocumentFormTest` asserts `substr_count($block, 'cav-qa__control') === 1`
when closed, and that ruling is kept rather than weakened.

**3. Reach the finished document, Word and PDF.**

Each Documents row carries one anchor per format the module actually offers,
read from `CockpitDocumentFormPresenter::documentFieldMap()[$module]['formats']`
— the same map the Generate control derives its copy from, so the row and the
control cannot promise different formats. Every `route()` is guarded with
`Route::has`, exactly as `viewRoute()` already is.

**The copy is `Word` and `PDF`, never `Download`.** The banned affordance is
NOT lifted: nothing here needs the word. Every new string — `Documents`,
`Edit`, `Word`, `PDF`, `Regenerate` — was substring-checked against all 21
`DEFERRED_AFFORDANCES` keys and both `FORBIDDEN_MARKUP` entries before use and
collides with none. (`Edit details` is Phase 49's entry; `Edit` does not
contain it.) Fence counts stay **2 / 21 / 9 / 13**.

`RamsController::downloadPdf()` can raise `RamsGenerationException` for
GATE-06/07/09. It already catches it (`:875-882`) and returns
`back()->with('error', ...)`. From the panel that lands back on the cockpit URL,
where `layouts/app.blade.php:1871` renders the flash banner. The PDF anchor
therefore surfaces a compliance failure as **a sentence on the page the PM came
from, never a 500** — and that is asserted rather than assumed.

⚠ `RamsDisplayPatchService`: the panel renders a document's NAME, DATE and
STATUS and no RAMS CONTENT, so `patchRamsForDisplay()` has nothing to patch
here. The two call sites that render content — `review()` at `:311` and
`downloadPdf()` at `:867` — still call it FIRST and are untouched.

**4. `BuildRamsDocumentJob` never completes a manual-form RAMS.**

`app/Jobs/BuildRamsDocumentJob.php:132-140` sets `STATUS_FOR_REVIEW` when the
build came from the manual create form, so the file builds and the record never
reaches `completed`. The completion email at `:148-151` is gated on
`STATUS_COMPLETED`, so **it never fires for that path either**. Both paths now
set `STATUS_COMPLETED`, and the test asserts the status AND the email — a
status fix that quietly starts sending mail is a surprise, so the mail is
named.

Two assertions in `tests/Feature/Rams/ManualRamsCreationTest.php` (`:166`,
`:188`) asserted the bug. They are re-expected BY NAME with the reason, never
deleted.

## Tasks

1. `CockpitModulePresenter::modules()` — derive `has_visits`.
2. `CockpitPanelPresenter::files()` — add a `formats` list per row.
3. New `x-cockpit.document-row` (reuses `.cav-file` classes — no CSS edit, so
   no `npm run build`).
4. `panel.blade.php` — Overview branches on `has_visits`; hint only when empty.
5. `doc-form.blade.php` — `documents` prop; closed verb flips to `Regenerate`.
6. `BuildRamsDocumentJob` — one status, both paths.
7. Tests: every state times every module, with a counted assertion.

## Verification

Every state rendered and COUNTED, because this defect existed precisely because
one render was taken to prove four: **no document / one / several / superseded,
across all four modules** — two with visit types and two without.

Gates: cockpit `tests/Feature/Cockpit` + `tests/Unit/Cockpit` (489/0 entering),
`tests/Feature/Worksheets`, `tests/Feature/Documents`, `-Filter Rams`, the D-06
baseline (`>= 159 passed AND 0 failed`), and the three sha256 pins.
`--group snapshot` is NOT required — no renderer is touched.
