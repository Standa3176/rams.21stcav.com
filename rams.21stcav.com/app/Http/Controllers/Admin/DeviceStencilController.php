<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeviceStencil;
use App\Services\Drawings\AutoGenericStencilGenerator;
use App\Services\Drawings\StencilXmlToSvgRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * Phase 24 Plan 03 (DRAW-50) — admin curation queue for device_stencils.
 * Phase 24 Plan 04 (DRAW-51, D-16) — server-rendered ports preview.
 *
 * Wave 2's list-only surface: filterable/searchable index of every stencil,
 * so the queue populated by Wave 1's schema + Wave 2's QuoteImportStencilStubber
 * (24-01/24-02) is visible before anyone opens a drawing. Plans 24-04 through
 * 24-07 add edit/preview/logo/promote actions to this SAME controller file in
 * later waves (sequential — they share this class).
 *
 * D-14 (locked): explicit named routes only — no bare Route::resource, no
 * create/store/destroy. Stencils are only ever created by firstOrCreate
 * (import time or seed time), never by hand in this UI.
 *
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-CONTEXT.md
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-03-PLAN.md
 * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-04-PLAN.md
 */
class DeviceStencilController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // index
    // ─────────────────────────────────────────────────────────────────────────

    public function index(Request $request): View
    {
        $query = DeviceStencil::query()->withCount('ports');

        // ── ?source={value} filter — allow-listed against SOURCE_* (T-24-05) ──
        $source = (string) $request->input('source', '');
        $allowedSources = [
            DeviceStencil::SOURCE_AUTO_GENERATED,
            DeviceStencil::SOURCE_ENGINEER_CURATED,
            DeviceStencil::SOURCE_AI_EXTRACTED,
        ];
        if (! in_array($source, $allowedSources, true)) {
            // Garbage/unrecognised value — reject silently, no crash, no filter applied.
            $source = '';
        }
        if ($source !== '') {
            $query->where('source', $source);
        }

        // ── ?needs_review={0|1} filter — real indexed column (D-10), never LIKE ──
        if ($request->has('needs_review')) {
            $query->where('needs_review', $request->boolean('needs_review'));
        }

        // ── ?manufacturer={value} filter ────────────────────────────────────
        $manufacturer = trim((string) $request->input('manufacturer', ''));
        if ($manufacturer !== '') {
            $query->where('manufacturer', $manufacturer);
        }

        // ── ?q={term} search — part_number substring only (T-24-05: parameterised) ──
        $term = trim((string) $request->input('q', ''));
        if ($term !== '') {
            $query->where('part_number', 'like', '%'.$term.'%');
        }

        $stencils = $query
            ->orderBy('updated_at', 'desc')
            ->paginate(15)
            ->appends($request->query());

        // Filter dropdown source — small internal tool, no pagination needed.
        $manufacturers = DeviceStencil::query()
            ->whereNotNull('manufacturer')
            ->distinct()
            ->orderBy('manufacturer')
            ->pluck('manufacturer');

        return view('admin.device-stencils.index', [
            'stencils'      => $stencils,
            'manufacturers' => $manufacturers,
            'source'        => $source,
            'needsReview'   => $request->input('needs_review', ''),
            'manufacturer'  => $manufacturer,
            'q'             => $term,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // preview (Plan 24-04, D-16)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Server-rendered ports preview. Given an UNSAVED `ports` array, rebuilds
     * mxgraph_xml through the exact same generator Save (Plan 24-05) will
     * use, pipes it through StencilXmlToSvgRenderer, and returns the
     * rendered SVG (D-16's literal contract — not mxGraph XML to a
     * client-side embed). Persists NOTHING — repeated calls never mutate
     * device_stencils/device_ports (D-02's core mandate).
     *
     * Settled decision (Research Open Question 3): AutoGenericStencilGenerator
     * ONLY, never DrawIoBuilderService — the edit screen curates ONE
     * stencil's ports in isolation, no project/other-devices/cables context
     * exists to synthesise a DrawIoBuilderService::build(Project $project)
     * call for. See 24-04-PLAN.md objective for full rationale.
     *
     * @see \App\Services\Drawings\AutoGenericStencilGenerator::build()
     * @see \App\Services\Drawings\StencilXmlToSvgRenderer::render()
     */
    public function preview(
        Request $request,
        DeviceStencil $deviceStencil,
        AutoGenericStencilGenerator $generator,
        StencilXmlToSvgRenderer $renderer,
    ): Response {
        // TODO(24-05): Plan 24-05's UpdateDeviceStencilPortsRequest will
        // define this SAME array-element rule set as a shared FormRequest;
        // duplicated here for now so preview can ship in Wave 3 ahead of
        // Save. Update this action to type-hint the FormRequest once it
        // lands, so the two never drift apart.
        $validated = $request->validate([
            'ports'                  => ['present', 'array'],
            'ports.*.label'          => ['nullable', 'string', 'max:100'],
            'ports.*.side'           => ['required', 'in:left,right,top,bottom'],
            'ports.*.connector_type' => ['nullable', 'string', 'max:50'],
            'ports.*.signal_type'    => ['nullable', 'string', 'max:30'],
            'ports.*.direction'      => ['required', 'in:in,out,io'],
            'ports.*.sort_order'     => ['nullable', 'integer'],
            'ports.*.port_id'        => ['required', 'string', 'max:50', 'distinct'],
        ]);

        $payload = $generator->build([
            'manufacturer' => $deviceStencil->manufacturer,
            'model'        => $deviceStencil->model,
            'name'         => $deviceStencil->display_name,
            'part_number'  => $deviceStencil->part_number,
            'ports'        => $validated['ports'],
        ]);

        $svg = $renderer->render($payload['mxgraph_xml'], $payload['default_width'], $payload['default_height']);

        // Nothing above calls ->save()/->update() on $deviceStencil or any
        // model — it is read ONLY for manufacturer/model/part_number/
        // display_name metadata. Persist-nothing is the whole point of D-16.
        return response($svg, 200)->header('Content-Type', 'image/svg+xml');
    }
}
