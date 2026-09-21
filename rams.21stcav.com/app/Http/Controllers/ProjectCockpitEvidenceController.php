<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Visit;
use App\Support\Cockpit\VisitEvidence;
use App\Support\Cockpit\VisitPhotoZipBuilder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * ProjectCockpitEvidenceController — the cockpit's TWO reads of returned
 * evidence (Phase 46.1, Plan 46.1-02; RV-04).
 *
 * WHY A THIRD CONTROLLER. `ProjectCockpitController`'s docblock says it exposes
 * exactly one action, and `ProjectCockpitActionController`'s says it is the
 * cockpit's one WRITE surface. A download is neither, so it gets its own file
 * rather than weakening either statement. A reader can still tell, from the
 * class alone, that rendering the cockpit writes nothing and that every write
 * lives in one place.
 *
 * ── BOTH ACTIONS WRITE NOTHING. THIS IS A RULING (T-46.1-09) ────────────
 *
 * There is deliberately NO `ProjectActivityLog` row on a download, and no
 * `touch()`. SCC logs its equivalent download; this application does not, and
 * the reason is recorded here rather than in a plan file so a later phase
 * changes it ON PURPOSE rather than by accident:
 *
 *   `project_activity_logs` is one of the tables the cockpit's read-only fence
 *   holds still in its GET row-count invariance tests. "A GET on this page
 *   writes nothing" is a stronger and more checkable invariant than a download
 *   audit trail nobody has asked for. A phase that genuinely needs that audit
 *   must first move the table out of that list, deliberately and by name.
 *
 * ── THE GUARDS, IN THIS ORDER ───────────────────────────────────────────
 *
 * flag 404 → auth 403 → `$visit->project_id === $project->id` 404 →
 * `VisitEvidence::for()` null 404. The first three are the same three, in the
 * same order, as `ProjectCockpitActionController::guard()`. Route-model
 * binding proves a row exists; it does NOT prove the relationship, so the
 * third check is load-bearing (T-46-06-01).
 *
 * ── NO ENUMERATION (T-46.1-08) ──────────────────────────────────────────
 *
 * `{photo}` is NEVER passed to a `find()`. It is COMPARED against
 * `VisitEvidence::photoIds()` for the route-bound project + visit, so a photo
 * id belonging to another visit, another project, or nothing at all is the
 * same indistinguishable 404. `{kind}` is resolved by membership against three
 * constants and is never echoed back into a response.
 *
 * ── SERVING USER-UPLOADED BYTES (T-46.1-10) ─────────────────────────────
 *
 * The inline photo is a file an UNAUTHENTICATED public token page accepted,
 * served same-origin behind auth: `X-Content-Type-Options: nosniff` and
 * `Content-Security-Policy: sandbox`. The ZIP is `Content-Disposition:
 * attachment`, and its filename goes through `Str::slug()` inside the builder
 * — the raw project name is user data and never enters a header.
 *
 * @see \App\Support\Cockpit\VisitPhotoZipBuilder — the archive and the containment check
 * @see \App\Support\Cockpit\VisitEvidence — relative paths only; the membership list
 */
class ProjectCockpitEvidenceController extends Controller
{
    /** The only `{kind}` values that exist. Membership, never a lookup key. */
    private const KINDS = [
        VisitEvidence::KIND_SURVEY,
        VisitEvidence::KIND_WORKSHEET,
        VisitEvidence::KIND_LABEL,
    ];

    /**
     * GET — every photo on this visit as one ZIP, grouped
     * `{Room}/{before|after|label}/` (D-03, the Bitrix hand-off).
     *
     * Writes nothing.
     */
    public function zip(Project $project, Visit $visit, VisitPhotoZipBuilder $builder): BinaryFileResponse
    {
        $evidence = $this->evidence($project, $visit);

        $tmpZip = $builder->build($project, $visit, $evidence);

        return response()
            ->download($tmpZip, $builder->filename($project, $visit), [
                'Content-Type'        => 'application/zip',
                'Content-Disposition' => 'attachment; filename="'.$builder->filename($project, $visit).'"',
            ])
            ->deleteFileAfterSend(true);
    }

    /**
     * GET — ONE photo, inline, for the gallery.
     *
     * Writes nothing.
     */
    public function photo(
        Project $project,
        Visit $visit,
        string $kind,
        string $photo,
        VisitPhotoZipBuilder $builder,
    ): BinaryFileResponse {
        $evidence = $this->evidence($project, $visit);

        // Membership, not reflection: an unrecognised kind is a 404 and the
        // submitted value never reaches a response body.
        abort_unless(in_array($kind, self::KINDS, true), 404);

        // A non-numeric id is simply not a member of any photoIds() pair.
        abort_unless(ctype_digit($photo), 404);

        $id = (int) $photo;

        $entry = $evidence->photos()->first(
            fn (array $candidate): bool => $candidate['kind'] === $kind && (int) $candidate['id'] === $id,
        );

        // T-46.1-08: a foreign visit's id and a foreign project's id both land
        // here, with the same body as an id that never existed.
        abort_if($entry === null, 404);

        $absolute = $builder->resolveWithinDisk((string) ($entry['path'] ?? ''), [
            'project_id' => $project->id,
            'visit_id'   => $visit->id,
            'kind'       => $kind,
            'photo_id'   => $id,
        ]);

        abort_if($absolute === null, 404);

        return response()->file($absolute, [
            'Content-Type'            => $entry['mime_type'] ?: 'application/octet-stream',
            'Content-Disposition'     => 'inline',
            'X-Content-Type-Options'  => 'nosniff',
            'Content-Security-Policy' => 'sandbox',
        ]);
    }

    /**
     * The four guards, in order. Mirrors
     * `ProjectCockpitActionController::guard()` and then resolves the evidence,
     * whose own scope check is a second, independent statement of the same
     * rule.
     */
    private function evidence(Project $project, Visit $visit): VisitEvidence
    {
        abort_unless(config('cockpit.enabled'), 404);
        abort_unless(auth()->check(), 403);
        abort_unless($visit->project_id === $project->id, 404);

        $evidence = VisitEvidence::for($project, $visit);

        abort_if($evidence === null, 404);

        return $evidence;
    }
}
