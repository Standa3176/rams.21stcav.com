<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDeviceStencilPortsRequest;
use App\Models\DeviceStencil;
use App\Models\DeviceStencilAudit;
use App\Models\DevicePort;
use App\Services\Drawings\AutoGenericStencilGenerator;
use App\Services\Drawings\StencilXmlToSvgRenderer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Phase 24 Plan 03 (DRAW-50) — admin curation queue for device_stencils.
 * Phase 24 Plan 04 (DRAW-51, D-16) — server-rendered ports preview.
 * Phase 24 Plan 05 (DRAW-51) — port-table edit screen + batched Save, with
 * the D-17 curated-artwork guard (confirm-to-proceed against an
 * `engineer-curated` stencil, audited before/after via device_stencil_audits).
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
    // edit / update (Plan 24-05, DRAW-51)
    // ─────────────────────────────────────────────────────────────────────────

    public function edit(DeviceStencil $deviceStencil): View
    {
        return view('admin.device-stencils.edit', ['stencil' => $deviceStencil->load('ports')]);
    }

    /**
     * Batched port-table save (D-01: the port table is always the source of
     * truth). Replaces every device_ports row for this stencil and
     * regenerates mxgraph_xml via AutoGenericStencilGenerator in the SAME
     * request, so the two never drift (RESEARCH.md Pitfall 2).
     *
     * ⚠ D-17 GUARD — must run BEFORE any write. Saving regenerates
     * mxgraph_xml through the template generator, which REPLACES any
     * hand-built artwork. The five hand-curated spike stencils (ClickShare
     * Bar Pro, Neat Bar Pro, Netgear GS312TP, Samsung QM65C-T, Sennheiser
     * TCC2) are the only stencils in the catalogue with genuine hand-built
     * mxGraph art, and are also the most likely to be opened and edited.
     * An unconfirmed save against an `engineer-curated` stencil persists
     * NOTHING and bounces back with a warning flash instead.
     *
     * When the guard passes (source is not engineer-curated, OR the
     * engineer explicitly confirmed via `confirm_regenerate`), the prior
     * mxgraph_xml + ports are captured BEFORE mutation and written into a
     * device_stencil_audits row (D-03) — every successful save is audited,
     * not just the curated-artwork-replacement case, so the prior state is
     * always recoverable. Known and intended consequence (D-08): any audit
     * row makes this stencil permanently ineligible for
     * `stencils:reapply-templates` — once a human has touched a stencil's
     * ports, automated re-templating must never reach it again.
     *
     * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-CONTEXT.md (D-17)
     */
    public function update(UpdateDeviceStencilPortsRequest $request, DeviceStencil $deviceStencil, AutoGenericStencilGenerator $generator): RedirectResponse
    {
        if ($deviceStencil->source === DeviceStencil::SOURCE_ENGINEER_CURATED && ! $request->boolean('confirm_regenerate')) {
            return redirect()
                ->route('admin.device-stencils.edit', $deviceStencil)
                ->withInput()
                ->with('warning', 'This stencil is engineer-curated. Saving will replace its existing artwork with a generated shape. Re-submit with confirmation to proceed.');
        }

        $validatedPorts = $request->validated('ports') ?? [];

        DB::transaction(function () use ($deviceStencil, $generator, $validatedPorts) {
            // Captured BEFORE mutation — the "before" half of the audit
            // snapshot, which is what makes the prior artwork recoverable
            // (D-17's resolved treatment: confirm-to-proceed, not a hard
            // block, because this snapshot exists).
            $beforeSnapshot = [
                'mxgraph_xml' => $deviceStencil->mxgraph_xml,
                'ports'       => $deviceStencil->ports()->get()->toArray(),
            ];

            $deviceStencil->ports()->delete();

            if ($validatedPorts !== []) {
                // Bulk insert — mirrors QuoteImportStencilStubber's raw
                // DevicePort::insert() (Plan 24-02), one statement for the
                // whole batch. device_ports.label/connector_type/signal_type
                // are NOT NULL columns with no default (migration
                // 2026_05_10_120000), while this FormRequest's Save-time
                // rules deliberately allow them to be blank (D-01: the
                // table is the source of truth even before every field is
                // filled in — the D-04 hard gate that requires them is a
                // separate, stricter Promote-time check). Coerce null to ''
                // / 0 here so an incomplete row persists as blank rather
                // than 500ing on the NOT NULL constraint.
                DevicePort::insert(array_map(
                    static fn (array $port): array => [
                        'device_stencil_id' => $deviceStencil->id,
                        'label'             => (string) ($port['label'] ?? ''),
                        'side'              => $port['side'],
                        'connector_type'    => (string) ($port['connector_type'] ?? ''),
                        'signal_type'       => (string) ($port['signal_type'] ?? ''),
                        'direction'         => $port['direction'],
                        'sort_order'        => $port['sort_order'] ?? 0,
                        'port_id'           => $port['port_id'],
                        'y_pct'             => $port['y_pct'] ?? null,
                        'x_pct'             => $port['x_pct'] ?? null,
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ],
                    $validatedPorts
                ));
            }

            $payload = $generator->build([
                'manufacturer' => $deviceStencil->manufacturer,
                'model'        => $deviceStencil->model,
                'name'         => $deviceStencil->display_name,
                'part_number'  => $deviceStencil->part_number,
                'ports'        => $validatedPorts,
            ]);

            $deviceStencil->update(['mxgraph_xml' => $payload['mxgraph_xml']]);

            DeviceStencilAudit::create([
                'device_stencil_id' => $deviceStencil->id,
                'user_id'           => auth()->id(),
                'action'            => DeviceStencilAudit::ACTION_EDIT,
                'before_snapshot'   => $beforeSnapshot,
                'after_snapshot'    => [
                    'mxgraph_xml' => $payload['mxgraph_xml'],
                    'ports'       => $validatedPorts,
                ],
            ]);
        });

        Log::info('Admin: device stencil ports updated', [
            'stencil_id' => $deviceStencil->id,
            'admin_id'   => auth()->id(),
        ]);

        return redirect()
            ->route('admin.device-stencils.edit', $deviceStencil)
            ->with('success', 'Ports saved.');
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
