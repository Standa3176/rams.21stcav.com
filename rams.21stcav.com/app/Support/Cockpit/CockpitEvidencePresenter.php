<?php

namespace App\Support\Cockpit;

use App\Models\Project;
use App\Models\Visit;

/**
 * CockpitEvidencePresenter — the view-ready shape of "what came back on this
 * visit" (Phase 46.1, Plan 46.1-01, Task 2).
 *
 * A THIN DERIVATION over `VisitEvidence`. It issues no query of its own: it
 * asks `VisitEvidence::for()` once and reshapes what that already loaded. The
 * split exists so Plan 46.1-02 (the ZIP) and Plan 46.1-03 (the Returned tab)
 * build against ONE resolution of the evidence rather than two that drift
 * apart the first time either is touched.
 *
 * READ LIVE (D-04): nothing is memoised between calls. Call it, let the
 * engineer's record change, call it again and the new value is what you get —
 * which is exactly what `test_it_reads_live_*` asserts.
 *
 * WRITES NOTHING, to any table. Same contract as `CockpitPanelPresenter`;
 * proved here over nine tables.
 *
 * RV-03: `device_label_photos.captured_by`, `worksheet_signoffs.ip_address`
 * and `worksheet_signoffs.user_agent` are absent from everything returned
 * here, because they are absent from `VisitEvidence`. The absence is asserted
 * against the SERIALISED output of this method with realistic values present
 * in the fixture, so the proof cannot pass vacuously and a key added anywhere
 * in the tree later trips a red test. See the `VisitEvidence` docblock for the
 * leak history that makes this a hard rule.
 *
 * @see app/Support/Cockpit/VisitEvidence.php
 * @see .planning/phases/46.1-visit-review/46.1-CONTEXT.md (D-03, D-04, D-05)
 */
final class CockpitEvidencePresenter
{
    /**
     * The Returned tab's whole payload, or null when there is nothing this
     * project is allowed to be shown — a visit belonging to another project,
     * or a visit with no engineer record behind it at all. Exactly the
     * conditions under which `VisitEvidence::for()` returns null.
     *
     * @return array<string, mixed>|null
     */
    public function evidence(Project $project, Visit $visit): ?array
    {
        $source = VisitEvidence::for($project, $visit);

        if ($source === null) {
            return null;
        }

        $photos  = $source->photos();
        $rooms   = $source->rooms();
        $serials = $source->serials();
        $signoff = $source->signoff();

        $byBucket = [];

        // Fixed bucket order (before, after, label) rather than whatever order
        // the photos happened to arrive in — the tab's headings must not move
        // between two visits. Empty buckets are DROPPED so the view never has
        // to render a heading over nothing.
        foreach (VisitEvidence::BUCKETS as $bucket) {
            $inBucket = $photos
                ->filter(fn (array $photo): bool => $photo['bucket'] === $bucket)
                ->values()
                ->all();

            if ($inBucket === []) {
                continue;
            }

            $byBucket[$bucket] = $inBucket;
        }

        $hasAnything = $photos->isNotEmpty()
            || $rooms->isNotEmpty()
            || $serials->isNotEmpty()
            || $signoff !== null;

        return [
            'has_anything'     => $hasAnything,
            'source_missing'   => $source->sourceMissing(),
            'photos_by_bucket' => $byBucket,
            'photo_count'      => $photos->count(),
            'rooms'            => $rooms->values()->all(),
            'serials'          => $serials->values()->all(),
            'signoff'          => $signoff,
            'returned_at'      => $source->returnedAt(),
        ];
    }
}
