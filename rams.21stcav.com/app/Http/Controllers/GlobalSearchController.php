<?php

namespace App\Http\Controllers;

use App\Models\OmManual;
use App\Models\Project;
use App\Models\RamsDocument;
use App\Models\SiteSurvey;
use App\Models\Worksheet;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Global search across projects and their document artefacts.
 *
 * Backs the ⌘K command palette rendered by layouts/app.blade.php. Returns a
 * flat, group-labelled JSON payload the front-end renders as a scannable list.
 *
 * Ranking is intentionally simple — LIKE across a small set of columns per
 * model, limited to 5 hits per group. The command palette is a jump-to
 * affordance, not a full-text search UI; if a user wants exhaustive results
 * they land on the section index (Projects / RAMS / etc.) which has its own
 * filter bar.
 *
 * Endpoint: GET /search?q={term} — auth-gated (registered inside the
 *   `auth` middleware group in routes/web.php). Empty / whitespace-only /
 *   sub-2-char queries return an empty result array so the palette shows an
 *   empty state rather than every row in the workspace.
 *
 * Rate-limited via throttle:60,1 at the route layer (60 rpm per user is
 * generous for a palette that debounces keystrokes at 200ms).
 */
class GlobalSearchController extends Controller
{
    /** Per-group result cap. Keeps the payload small and the UI scannable. */
    private const GROUP_LIMIT = 5;

    /** Below this length the palette shows the empty-state prompt. */
    private const MIN_QUERY_LENGTH = 2;

    public function __invoke(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < self::MIN_QUERY_LENGTH) {
            return response()->json(['groups' => [], 'query' => $q]);
        }

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $q) . '%';

        $groups = [];

        // ── Projects ──────────────────────────────────────────────────────────
        // Search across name / ref / client_name / site_address. Ordered by
        // last-updated so the row a user is actively working on floats up.
        $projects = Project::query()
            ->where(function ($query) use ($like) {
                $query->where('name', 'like', $like)
                    ->orWhere('ref', 'like', $like)
                    ->orWhere('quote_reference', 'like', $like)
                    ->orWhere('client_name', 'like', $like)
                    ->orWhere('site_address', 'like', $like);
            })
            ->orderByDesc('updated_at')
            ->limit(self::GROUP_LIMIT)
            ->get(['id', 'name', 'ref', 'client_name', 'status', 'updated_at']);

        if ($projects->isNotEmpty()) {
            $groups[] = [
                'key'   => 'projects',
                'label' => 'Projects',
                'items' => $projects->map(fn (Project $p): array => [
                    'id'       => $p->id,
                    'title'    => $p->name,
                    'subtitle' => trim(implode(' · ', array_filter([
                        $p->ref,
                        $p->client_name,
                        Project::STATUS_LABELS[$p->status] ?? $p->status,
                    ]))),
                    'url'      => route('projects.show', $p),
                    'kind'     => 'project',
                ])->all(),
            ];
        }

        // ── RAMS documents ───────────────────────────────────────────────────
        // The RamsDocument schema uses `project_name` + `project_ref` for its
        // human-scannable columns (not `title` — the initial ship of this
        // controller assumed a title column that doesn't exist; live 500'd,
        // 2026-07-08 evening fix).
        $rams = RamsDocument::query()
            ->with('project:id,name')
            ->where(function ($query) use ($like) {
                $query->where('project_name', 'like', $like)
                    ->orWhere('project_ref', 'like', $like)
                    ->orWhere('client_name', 'like', $like)
                    ->orWhere('site_address', 'like', $like);
            })
            ->orderByDesc('updated_at')
            ->limit(self::GROUP_LIMIT)
            ->get(['id', 'project_name', 'project_ref', 'client_name', 'status', 'project_id', 'updated_at']);

        if ($rams->isNotEmpty()) {
            $groups[] = [
                'key'   => 'rams',
                'label' => 'RAMS',
                'items' => $rams->map(fn (RamsDocument $r): array => [
                    'id'       => $r->id,
                    'title'    => $r->project_name ?: ('RAMS #' . $r->id),
                    'subtitle' => trim(implode(' · ', array_filter([
                        $r->project_ref,
                        $r->project?->name,
                        $r->client_name,
                    ]))),
                    'url'      => route('rams.review', $r),
                    'kind'     => 'rams',
                ])->all(),
            ];
        }

        // ── Site surveys ─────────────────────────────────────────────────────
        // site_surveys carries `project_ref` (not `quote_reference` — that
        // column lives on `projects` only, 2026-07-08 evening fix).
        $surveys = SiteSurvey::query()
            ->with('project:id,name')
            ->where(function ($query) use ($like) {
                $query->where('project_name', 'like', $like)
                    ->orWhere('client_name', 'like', $like)
                    ->orWhere('site_address', 'like', $like)
                    ->orWhere('project_ref', 'like', $like);
            })
            ->orderByDesc('updated_at')
            ->limit(self::GROUP_LIMIT)
            ->get(['id', 'project_name', 'client_name', 'project_id', 'updated_at']);

        if ($surveys->isNotEmpty()) {
            $groups[] = [
                'key'   => 'surveys',
                'label' => 'Site surveys',
                'items' => $surveys->map(fn (SiteSurvey $s): array => [
                    'id'       => $s->id,
                    'title'    => $s->project_name ?: ('Survey #' . $s->id),
                    'subtitle' => $s->client_name ?: ($s->project?->name ?? ''),
                    'url'      => route('site-surveys.show', $s),
                    'kind'     => 'survey',
                ])->all(),
            ];
        }

        // ── O&M manuals ──────────────────────────────────────────────────────
        $oms = OmManual::query()
            ->with('project:id,name')
            ->where(function ($query) use ($like) {
                $query->where('project_name', 'like', $like)
                    ->orWhere('client_name', 'like', $like)
                    ->orWhere('site_address', 'like', $like);
            })
            ->orderByDesc('updated_at')
            ->limit(self::GROUP_LIMIT)
            ->get(['id', 'project_name', 'client_name', 'project_id', 'updated_at']);

        if ($oms->isNotEmpty()) {
            $groups[] = [
                'key'   => 'om',
                'label' => 'O&M manuals',
                'items' => $oms->map(fn (OmManual $o): array => [
                    'id'       => $o->id,
                    'title'    => $o->project_name ?: ('O&M #' . $o->id),
                    'subtitle' => $o->client_name ?: ($o->project?->name ?? ''),
                    'url'      => route('om-manuals.edit', $o),
                    'kind'     => 'om',
                ])->all(),
            ];
        }

        // ── Worksheets ───────────────────────────────────────────────────────
        $worksheets = Worksheet::query()
            ->with('project:id,name')
            ->where(function ($query) use ($like) {
                $query->where('project_name', 'like', $like)
                    ->orWhere('client_name', 'like', $like)
                    ->orWhere('site_address', 'like', $like)
                    ->orWhere('project_ref', 'like', $like);
            })
            ->orderByDesc('updated_at')
            ->limit(self::GROUP_LIMIT)
            ->get(['id', 'project_name', 'client_name', 'project_id', 'updated_at']);

        if ($worksheets->isNotEmpty()) {
            $groups[] = [
                'key'   => 'worksheets',
                'label' => 'Worksheets',
                'items' => $worksheets->map(fn (Worksheet $w): array => [
                    'id'       => $w->id,
                    'title'    => $w->project_name ?: ('Worksheet #' . $w->id),
                    'subtitle' => $w->client_name ?: ($w->project?->name ?? ''),
                    'url'      => route('worksheets.show', $w),
                    'kind'     => 'worksheet',
                ])->all(),
            ];
        }

        return response()->json(['groups' => $groups, 'query' => $q]);
    }
}
