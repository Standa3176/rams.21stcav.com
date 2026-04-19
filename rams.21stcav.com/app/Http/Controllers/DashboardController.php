<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\ProjectPackage;
use App\Models\RamsDocument;
use App\Models\SiteSurvey;
use App\Services\ProjectHealthService;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Serves the operational dashboard (GET /dashboard).
 *
 * Phase 08, plan 08-01 (DASH-01a / DASH-01b).
 *
 * Replaces the closure that was previously in routes/web.php. Performs a single
 * eager-load query for all non-archived projects and delegates health derivation
 * to ProjectHealthService.
 *
 * @see \App\Services\ProjectHealthService  Per-project health derivation.
 * @see \App\DTO\ProjectHealth              Value object surfaced to the view.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly ProjectHealthService $healthService,
    ) {
    }

    // ═════════════════════════════════════════════════════════════════════════
    // index
    // ═════════════════════════════════════════════════════════════════════════

    /**
     * Render the dashboard. Loads all non-archived projects with relations,
     * computes a per-project health map, and exposes the summary stats the
     * existing dashboard blade template consumes.
     */
    public function index(): View
    {
        Log::info('DashboardController: loading dashboard', [
            'user_id' => auth()->id(),
        ]);

        // ── Single query: all non-archived projects with required relations ──
        $projects = Project::with([
            'owner',
            'ramsDocuments',
            'siteSurveys',
            'activeInstallProgramme.tasks',
        ])
            ->whereNotIn('status', [Project::STATUS_ARCHIVED])
            ->orderByDesc('updated_at')
            ->get();

        // ── Health map: keyed by project ID ──────────────────────────────────
        $healthMap = $projects->keyBy('id')->map(
            fn (Project $p) => $this->healthService->assess($p)
        );

        // ── Status counts for the filter strip (no extra query) ──────────────
        $statusCounts = $projects->groupBy('status')->map->count();

        // ── Stat card values ─────────────────────────────────────────────────
        // Active projects are already in the loaded collection; others are
        // small totals served by lightweight count() queries.
        $statActiveProjects = $projects->count();
        $statAllProjects    = Project::count();
        $statRams           = RamsDocument::count();
        $statSurveys        = SiteSurvey::count();
        $statImports        = ProjectPackage::count();

        // Recent RAMS for the existing panel (kept from original dashboard).
        $recentRams = RamsDocument::with('project')
            ->orderByDesc('created_at')
            ->limit(6)
            ->get();

        // Recent projects panel — kept for compatibility with the current blade.
        // Wave 2 (view rewrite) replaces this panel with the full health grid
        // driven by $projects + $healthMap.
        $recentProjects = $projects->take(6);

        return view('dashboard', compact(
            'projects',
            'healthMap',
            'statusCounts',
            'statActiveProjects',
            'statAllProjects',
            'statRams',
            'statSurveys',
            'statImports',
            'recentProjects',
            'recentRams',
        ));
    }
}
