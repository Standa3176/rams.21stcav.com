# Phase 46.5 — Deferred Items

Recorded 2026-09-27 by Plan 46.5-07. **Nothing here was fixed by this phase**, and nothing here is a
GCW requirement — Group GCW closed eight for eight. Full reasoning in `46.5-LEDGER.md` §1.

## Raised by this phase

- **F-46.5-07-01 — step 2 is the heaviest screen, and it is not only "notes".** Measured off the
  field map: site survey steps hold **6 / 7 / 5** controls, because step 2 carries *Notes* **plus**
  *Access and logistics* (5 fields) **plus** *Client report only*. RAMS is **4 / 10 / 5**, so its
  step 2 is the biggest screen in the phase. **Not a defect, deliberately not changed** — only the
  user can say whether "Access and logistics" belongs with the notes or wants a step of its own.
  **On the checkpoint (question 3). Do not move it without asking.**

- **R-5 — a RAMS comment names the wrong key.** `RamsBuilderService.php:714` heads its block
  *"Auto-generate a scope summary when works_description is blank"* while the line beneath reads
  `$reviewedData['method_statement_notes']`. **The fallback is real; only the comment is wrong.**
  Surfaced by Plan 46.5-05 and deliberately not acted on: D-04 forbids editing the RAMS process
  (*"RAMs will use existing RAM process"*). Re-recorded here so it is not lost.

- **R-4 — stale field counts in prose.** 46.5-04 calls the site survey a "13-field wall" in its
  summary and a "17-field wall" in the docblock it shipped. The code says **20 mapped, 4 read-only,
  2 on no step, 14 asked**. Documentation only; no code change proposed.

## Carried in from earlier phases, still open

- **DC-06** — the Worksheet retitle (46.2). Out of this phase's boundary.
- **DC-07** — no worksheet PDF (46.2). There is no worksheet PDF Blade;
  `worksheets.engineer-report-pdf` is a different document that `abort_if`s 404 for exactly the PM
  the panel serves. The form **says so on screen** rather than offering a control that fails.
- **IC-03** (46.4).
- **46.3's checkpoint** — the drawer row now toggles. Whether the page is calm, and whether three
  ways back is one too many, is **still unanswered by the user**.
- **46.4's airplane-mode walk** — **DELIVERED BUT NOT VERIFIED.** Nothing in the repo can prove an
  offline capture; it needs a person, a phone and no signal.

## Pre-existing, surfaced by 46.4, ruled out of 46.5 by name in `46.5-CONTEXT.md` `<deferred>`

- **`uploadPhoto` has no room-name inclusion guard.** ⚠ A real hole on a token-only surface, being
  carried rather than closed.
- **Re-signing is UI-unreachable** — a blanket `<fieldset disabled>`, since May.
- **A send-back does not unlock a signed worksheet.**
- **No unmark / restore for a kit row.**

## Out of scope by the phase boundary, not deferred

- The **Review report tab** and the **completed-survey report PDF** — **Phase 46.6**.
- An **O&M wizard** — every O&M group is still `step => null`.
- Snags (Phase 47), sending anything to a client (Phase 48).
