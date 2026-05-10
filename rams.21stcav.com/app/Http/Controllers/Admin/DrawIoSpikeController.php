<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\ProjectDrawing;
use App\Services\Drawings\DrawingService;
use App\Services\Drawings\DrawIoBuilderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Quick task 260509-ibx — admin-only draw.io embed spike controller.
 *
 * D-LOCK-7: admin middleware-gated, NOT linked from any user-facing page.
 * D-LOCK-1: spike Blade loads /vendor/drawio/index.html?embed=1 (self-hosted).
 *           Modern draw.io (v20+) routes embed mode through index.html with
 *           ?embed=1 query param — there is no separate embed.html in the
 *           bundle. Earlier docs referencing embed.html are stale.
 * D-LOCK-2: saveXml delegates to DrawingService::saveSpikeXml (Task 5)
 *           which honours lock-on-edit + archive-prior.
 * D-LOCK-6: builder reads real ProjectPackage::extracted_data.
 * D-LOCK-7: route prefix admin/drawings/draw-io-spike — admin middleware.
 *
 * @see app/Services/Drawings/DrawIoSpikeBuilderService.php
 * @see resources/views/admin/drawings/draw-io-spike.blade.php
 */
class DrawIoSpikeController extends Controller
{
    public function __construct(
        private readonly DrawIoBuilderService $builder,
        private readonly DrawingService $drawings,
    ) {}

    public function show(Project $project): View
    {
        $drawing = $this->resolveOrCreateSpikeDrawing($project);

        // Initial XML: persisted canvas_state if engineer has edited
        // (post-lock state), else freshly-built from project data.
        $xml = $drawing->canvas_state ?: $this->builder->build($project);

        return view('admin.drawings.draw-io-spike', [
            'project' => $project,
            'drawing' => $drawing,
            'xml' => $xml,
            'is_locked' => ! empty($drawing->canvas_state),
            'embed_url' => '/vendor/drawio/index.html?embed=1&proto=json&libraries=1&spin=1',
        ]);
    }

    public function saveXml(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'xml' => ['required', 'string', 'min:50', 'max:5242880'], // 5 MB cap
        ]);

        $drawing = $this->resolveOrCreateSpikeDrawing($project);
        $newRow = $this->drawings->saveSpikeXml($drawing, $validated['xml'], (int) Auth::id());

        return response()->json([
            'ok' => true,
            'drawing_id' => $newRow->id,
            'version' => $newRow->version,
            'previous_locked' => $drawing->id !== $newRow->id,
            'redirect' => null,
        ]);
    }

    public function exportSvg(Request $request, Project $project): JsonResponse
    {
        $validated = $request->validate([
            'svg' => ['required', 'string', 'min:50', 'max:5242880'],
        ]);

        $drawing = $this->resolveOrCreateSpikeDrawing($project);
        $path = $this->drawings->saveSpikeSvg($drawing, $validated['svg']);

        return response()->json(['ok' => true, 'svg_path' => basename($path)]);
    }

    /**
     * One spike drawing row per project. source_data['spike'] = true is the
     * discriminator that excludes it from the user-facing index page (which
     * the index controller filters by superseded_by_id IS NULL — but the
     * spike row simply isn't linked from anywhere — D-LOCK-7).
     *
     * Implementation note: avoid touching DrawingService::createForProject
     * because that would burn a sheet number from the AVIXA allocator —
     * wasteful for a sandbox row. Use ProjectDrawing::create directly.
     */
    private function resolveOrCreateSpikeDrawing(Project $project): ProjectDrawing
    {
        $existing = ProjectDrawing::query()
            ->where('project_id', $project->id)
            ->whereNull('superseded_by_id')
            ->where('kind', ProjectDrawing::KIND_SCHEMATIC)
            ->whereJsonContains('source_data->spike', true)
            ->first();

        if ($existing) {
            return $existing;
        }

        $row = ProjectDrawing::create([
            'project_id' => $project->id,
            'site_survey_room_id' => null,
            'kind' => ProjectDrawing::KIND_SCHEMATIC,
            'version' => 1,
            'status' => ProjectDrawing::STATUS_DRAFT,
            'source_data' => ['spike' => true, 'spike_id' => '260509-ibx'],
            'generated_by' => Auth::id(),
        ]);

        Log::info('DrawIoSpikeController: spike drawing created', [
            'drawing_id' => $row->id,
            'project_id' => $project->id,
        ]);

        return $row;
    }
}
