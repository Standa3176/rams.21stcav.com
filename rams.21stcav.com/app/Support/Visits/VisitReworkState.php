<?php

namespace App\Support\Visits;

use App\Models\SiteSurvey;
use App\Models\Visit;
use App\Models\Worksheet;
use Illuminate\Support\Carbon;

/**
 * VisitReworkState — "the office sent this back", DERIVED (Phase 46, Plan 46-05).
 *
 * ── D-02, QUOTED VERBATIM ───────────────────────────────────────────────────
 *
 *   "Send back — rejects the return and reopens the engineer link for more
 *    information, rather than accepting something incomplete."
 *
 *   "Add an office note — the PM annotates the return WITHOUT CHANGING WHAT THE
 *    ENGINEER SAID. The engineer's record stays intact; the office view sits
 *    alongside it. Do NOT let an office note overwrite or edit engineer-captured
 *    data."
 *
 * ── WHY THIS IS A COMPARISON AND NOT A STORED FLAG ──────────────────────────
 *
 * The obvious way to reopen a submitted survey is to clear its `submitted_at`.
 * That is EXACTLY the D-02 violation named one line above: `submitted_at` is
 * engineer-captured data — the record of when a human said "I am done" — and an
 * office action must never rewrite it. Clearing it also destroys the only
 * evidence the work was ever returned, which is a repudiation bug (T-46-05-04)
 * on top of a doctrine one.
 *
 * So "reopened" is COMPUTED, every time, as:
 *
 *     sent_back_at > the record's last submission
 *
 * Three things fall out of that, all of them the reason for choosing it:
 *
 *  1. RESUBMITTING RELOCKS BY ITSELF. A newer submission moves the right-hand
 *     side past `sent_back_at` and the comparison flips to false on its own.
 *     There is no flag to clear, so there is no day somebody forgets to clear
 *     it and a finished survey sits editable for months with nobody able to
 *     tell why.
 *  2. NOTHING IS WRITTEN. This class has no writers. It never touches
 *     `site_surveys`, `worksheets`, `worksheet_signoffs` or `visits`.
 *  3. IT CANNOT CONTRADICT ITS OWN CAUSE. A stored boolean can disagree with
 *     the timestamps it was derived from; a comparison re-reads them.
 *
 * This is the same doctrine as `Visit::wasSentBack()` (which this class
 * delegates to rather than re-deriving) and `Visit::returnedAt()` before it.
 *
 * ── WHY IT LIVES HERE AND NOT ON THE MODEL ──────────────────────────────────
 *
 * The query lives in this class, not on `SiteSurvey`, so that `SiteSurvey` —
 * loaded by PDF generation and by the submitted-notification path — does not
 * gain a `Visit` import, and so the derivation is unit-testable without HTTP.
 */
final class VisitReworkState
{
    /**
     * The rework state of the record a visit wraps.
     *
     * Returns NULL when no visit wraps this record at all — which is the
     * common case and must behave exactly as the app did before Plan 46-05:
     * a submitted survey stays locked forever.
     *
     * @return array{reopened: bool, reason: ?string, at: ?Carbon}|null
     */
    public static function forSource(string $sourceType, int $sourceId): ?array
    {
        $visit = Visit::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->first();

        if ($visit === null) {
            return null;
        }

        // `wasSentBack()` IS the comparison: sent_back_at is set AND is later
        // than `returnedAt()` (a survey's `submitted_at`, or a worksheet's
        // latest append-only signoff). Delegated rather than re-derived — two
        // copies of this comparison would disagree the first time one changed.
        $reopened = $visit->wasSentBack();

        return [
            'reopened' => $reopened,
            // The reason and the date are only meaningful while the reopening
            // is live. Once the engineer resubmits, the banner goes away whole
            // rather than lingering as "previously sent back" history.
            'reason'   => $reopened ? $visit->send_back_reason : null,
            'at'       => $reopened ? $visit->sent_back_at : null,
        ];
    }

    /**
     * The rework state of a site survey, or null when no visit wraps it.
     *
     * @return array{reopened: bool, reason: ?string, at: ?Carbon}|null
     */
    public static function forSurvey(?SiteSurvey $survey): ?array
    {
        if ($survey === null || $survey->id === null) {
            return null;
        }

        return self::forSource(Visit::SOURCE_SITE_SURVEY, $survey->id);
    }

    /**
     * The rework state of a worksheet, or null when no visit wraps it.
     *
     * @return array{reopened: bool, reason: ?string, at: ?Carbon}|null
     */
    public static function forWorksheet(?Worksheet $worksheet): ?array
    {
        if ($worksheet === null || $worksheet->id === null) {
            return null;
        }

        return self::forSource(Visit::SOURCE_WORKSHEET, $worksheet->id);
    }

    /**
     * Has the office asked for more information, and not yet got it?
     *
     * FALSE when no visit wraps the record — the pre-46-05 behaviour.
     */
    public static function isReopened(SiteSurvey|Worksheet|null $record): bool
    {
        $state = $record instanceof SiteSurvey
            ? self::forSurvey($record)
            : self::forWorksheet($record);

        return $state !== null && $state['reopened'];
    }
}
