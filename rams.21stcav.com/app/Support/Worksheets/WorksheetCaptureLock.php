<?php

namespace App\Support\Worksheets;

use App\Models\Worksheet;

/**
 * Phase 46.4 Plan 04 — D-07. THE ONE DEFINITION OF "THE CAPTURE SURFACE IS
 * CLOSED".
 *
 * ── WHY THIS CLASS EXISTS ───────────────────────────────────────────────────
 *
 * The user, verbatim: *"client cannot chage anything as they are signing to
 * confirm work is complete."*
 *
 * There is no login and no second URL (D-01 keeps engineer and client on ONE
 * page), so the app **cannot tell an engineer from a client by identity — it
 * tells them apart by STATE.** The engineer captures; then they hand the device
 * over and the client signs. From that instant the worksheet is the record the
 * client attested to, and it must not move.
 *
 * ── WHY IT IS A CLASS AND NOT A CALL TO isSigned() AT TEN SITES ─────────────
 *
 * The controller and the Blade must never be able to disagree about what
 * "locked" means, and the sentence the engineer reads when a queued row drains
 * into a signed worksheet must be the same sentence in both. It is three lines
 * and that is the point: there is exactly one.
 *
 * ── WHAT IT DOES NOT DO ─────────────────────────────────────────────────────
 *
 * It invents NO state. `Worksheet::isSigned()` already existed
 * (`app/Models/Worksheet.php:200`) and reads the append-only `signoffs`
 * relation with `exists()` — EXISTENCE, not equality, so a worksheet signed
 * twice is still locked. Nothing here touches `sign()`, `signoffs()`,
 * `latestSignoff()` or the append-only behaviour: **re-signing remains a
 * documented feature** and `POST /sign` is deliberately NOT guarded by this
 * lock.
 *
 * ── 422, NOT 423 ────────────────────────────────────────────────────────────
 *
 * 423 Locked is arguably more correct and is deliberately not used. The engineer
 * page's hand-rolled fetch handlers treat every non-2xx identically and surface
 * the response text; introducing a status code no handler on that page has ever
 * seen is a change with no benefit, and 422 is already this controller's
 * refusal idiom (the forged-room-name guard) and SCC's precedent
 * (`PublicPmvController::addCheck`).
 *
 * @see .planning/phases/46.4-engineer-link-install-capture/46.4-CONTEXT.md (D-07, D-01)
 */
final class WorksheetCaptureLock
{
    /**
     * The one sentence returned to every refused caller.
     *
     * ⚠️ IT IS A SENTENCE, NOT THE WORD "Locked", ON PURPOSE. A worksheet can
     * be signed while an engineer still has rows queued in IndexedDB on their
     * phone; those rows drain into a locked endpoint and take this 422. That is
     * correct — a client signed a record and late arrivals must not alter it —
     * **but the engineer has to be TOLD, in words, not left with a row stuck at
     * "failed".** Plan 46.4-06 displays this string VERBATIM in the queue
     * panel. Changing the wording changes what an engineer reads on a roof.
     */
    public const MESSAGE = 'This worksheet has been signed off — it can no longer be changed.';

    /**
     * Is the capture surface closed?
     *
     * Existence of any sign-off, never a count and never a comparison against
     * the latest one.
     */
    public static function isLocked(Worksheet $worksheet): bool
    {
        return $worksheet->isSigned();
    }
}
