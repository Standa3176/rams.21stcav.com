<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateDeviceStencilPortsRequest;
use App\Http\Requests\Admin\UploadDeviceStencilLogoRequest;
use App\Models\DeviceStencil;
use App\Models\DeviceStencilAudit;
use App\Models\DevicePort;
use App\Services\Drawings\AutoGenericStencilGenerator;
use App\Services\Drawings\CategoryPortTemplateResolver;
use App\Services\Drawings\StencilPromotionValidator;
use App\Services\Drawings\StencilXmlToSvgRenderer;
use App\Services\Drawings\SvgSanitizerService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * Phase 24 Plan 03 (DRAW-50) — admin curation queue for device_stencils.
 * Phase 24 Plan 04 (DRAW-51, D-16) — server-rendered ports preview.
 * Phase 24 Plan 05 (DRAW-51) — port-table edit screen + batched Save, with
 * the D-17 curated-artwork guard (confirm-to-proceed against an
 * `engineer-curated` stencil, audited before/after via device_stencil_audits).
 * Phase 24 Plan 06 (DRAW-52, D-12/D-15) — per-stencil manufacturer logo
 * upload (PNG or SVG). Every SVG is routed through SvgSanitizerService
 * before it ever touches disk.
 * Phase 24 Plan 07 (DRAW-53, D-03/D-04) — promote()/discard() actions. The
 * D-04 two-tier hard-block/soft-warn gate (StencilPromotionValidator) is
 * re-run server-side on every promote() request regardless of client state
 * (T-24-17). Both actions write a device_stencil_audits row (D-03).
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
     * UAT Gap 2 (24-11): the guard ALSO requires the stencil to currently
     * have at least one saved `device_ports` row. UAT found 91 of the 96
     * `engineer-curated` stencils in the real catalogue are bare zero-port
     * stubs sharing that `source` value with no artwork to protect — firing
     * the guard on all 96 contradicted D-17's own "zero added friction for
     * the ordinary stub-curation path" requirement. Known, intended
     * consequence: the FIRST time an engineer adds ports to one of those 91
     * stubs, the guard does not fire (single-click save, no confirm
     * needed) — but editing that same stencil AGAIN once ports exist now
     * fires the guard on that second edit, exactly as it would for
     * genuinely hand-curated artwork, because at that point there IS saved
     * content worth protecting.
     *
     * When the guard passes (source is not engineer-curated, OR the
     * stencil has no existing ports, OR the engineer explicitly confirmed
     * via `confirm_regenerate`), the prior mxgraph_xml + ports are captured
     * BEFORE mutation and written into a device_stencil_audits row (D-03)
     * — every successful save is audited, not just the
     * curated-artwork-replacement case, so the prior state is always
     * recoverable. Known and intended consequence (D-08): any audit row
     * makes this stencil permanently ineligible for
     * `stencils:reapply-templates` — once a human has touched a stencil's
     * ports, automated re-templating must never reach it again.
     *
     * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-CONTEXT.md (D-17)
     * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-UAT.md (Gap 2)
     */
    public function update(UpdateDeviceStencilPortsRequest $request, DeviceStencil $deviceStencil, AutoGenericStencilGenerator $generator): RedirectResponse
    {
        if ($deviceStencil->source === DeviceStencil::SOURCE_ENGINEER_CURATED && $deviceStencil->ports()->exists() && ! $request->boolean('confirm_regenerate')) {
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
    // uploadLogo (Plan 24-06, DRAW-52, D-12/D-15)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Per-stencil manufacturer logo upload — PNG or SVG (D-15: stored as a
     * FILE at logo_path, the legacy inline logo_svg text column stays
     * untouched by this action).
     *
     * ⚠ D-12 GUARD — every SVG upload is mandatorily routed through
     * SvgSanitizerService::sanitize() BEFORE it is written to disk. If the
     * sanitizer can't parse the input (returns '') the upload is rejected
     * as a validation error — never persisted, never a 500. Raster formats
     * (png/jpg/jpeg) carry no script-execution surface and are stored as-is
     * once `mimes:` content-sniffing + `max:2048` (T-24-15/T-24-16) have
     * already passed in UploadDeviceStencilLogoRequest.
     *
     * @see \App\Services\Drawings\SvgSanitizerService
     * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-CONTEXT.md (D-12, D-15)
     */
    public function uploadLogo(
        UploadDeviceStencilLogoRequest $request,
        DeviceStencil $deviceStencil,
        SvgSanitizerService $svgSanitizer,
    ): RedirectResponse {
        $file = $request->file('logo');
        $extension = strtolower((string) $file->getClientOriginalExtension());

        if ($extension === 'svg') {
            $rawSvg = (string) file_get_contents($file->getRealPath());
            $sanitizedSvg = $svgSanitizer->sanitize($rawSvg);

            if ($sanitizedSvg === '') {
                return redirect()
                    ->route('admin.device-stencils.edit', $deviceStencil)
                    ->withErrors(['logo' => 'The uploaded SVG could not be parsed.']);
            }

            Storage::disk('public')->put("device-stencils/{$deviceStencil->id}/logo.svg", $sanitizedSvg);
        } else {
            // png / jpg / jpeg — no sanitization needed (raster formats
            // carry no script-execution surface). Fixed filename per
            // stencil (not a UUID, unlike DeviceLabelPhotoService) — each
            // stencil has at most one current logo, so an overwrite on
            // re-upload is the correct behaviour.
            Storage::disk('public')->putFileAs("device-stencils/{$deviceStencil->id}", $file, "logo.{$extension}");
        }

        $deviceStencil->update(['logo_path' => "/storage/device-stencils/{$deviceStencil->id}/logo.{$extension}"]);

        Log::info('Admin: device stencil logo uploaded', [
            'stencil_id' => $deviceStencil->id,
            'admin_id'   => auth()->id(),
            'format'     => $extension,
        ]);

        return redirect()
            ->route('admin.device-stencils.edit', $deviceStencil)
            ->with('success', 'Logo uploaded.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // promote / discard (Plan 24-07, DRAW-53, D-03/D-04)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * "Promote to Engineer-Curated" (DRAW-53). Flips source, clears
     * needs_review, and writes a device_stencil_audits row (D-03) — this is
     * the moment a stub's coverage becomes real AND starts propagating to
     * every project referencing the same part_number, for free, via
     * DeviceStencilCacheService::resolveForPartNumber's existing firstOrCreate
     * cache lookup (21 D-03) — no per-project migration, no extra code here.
     *
     * ⚠ T-24-17 GUARD — the FULL D-04 hard-block check is re-run here,
     * unconditionally, on every request. A disabled client-side Promote
     * button (edit.blade.php) is UX only; a hostile client POSTing directly
     * to this route against a zero-port (or otherwise structurally invalid)
     * stencil is refused exactly the same way the UI already prevented.
     *
     * @see \App\Services\Drawings\StencilPromotionValidator
     * @see .planning/phases/24-stencil-curation-ui-quote-import-auto-stub/24-CONTEXT.md (D-04)
     */
    public function promote(DeviceStencil $deviceStencil, StencilPromotionValidator $validator): RedirectResponse
    {
        $deviceStencil->load('ports');

        $result = $validator->evaluate($deviceStencil);

        if ($result['blocking'] !== []) {
            return redirect()
                ->route('admin.device-stencils.edit', $deviceStencil)
                ->withErrors(['promote' => $result['blocking']])
                ->with('error', 'This stencil cannot be promoted yet — see the blocking reasons below.');
        }

        DB::transaction(function () use ($deviceStencil) {
            $beforeSnapshot = [
                'source'       => $deviceStencil->source,
                'needs_review' => $deviceStencil->needs_review,
                'ports'        => $deviceStencil->ports->toArray(),
            ];

            $deviceStencil->update([
                'source'       => DeviceStencil::SOURCE_ENGINEER_CURATED,
                'needs_review' => false,
            ]);

            DeviceStencilAudit::create([
                'device_stencil_id' => $deviceStencil->id,
                'user_id'           => auth()->id(),
                'action'            => DeviceStencilAudit::ACTION_PROMOTE,
                'before_snapshot'   => $beforeSnapshot,
                'after_snapshot'    => [
                    'source'       => DeviceStencil::SOURCE_ENGINEER_CURATED,
                    'needs_review' => false,
                    'ports'        => $deviceStencil->ports->toArray(),
                ],
            ]);
        });

        Log::info('Admin: device stencil promoted', [
            'stencil_id' => $deviceStencil->id,
            'admin_id'   => auth()->id(),
        ]);

        $displayLabel = trim(($deviceStencil->manufacturer ?? '').' '.($deviceStencil->model ?? ''))
            ?: ($deviceStencil->display_name ?: 'Stencil #'.$deviceStencil->id);

        return redirect()
            ->route('admin.device-stencils.index')
            ->with('success', "{$displayLabel} promoted to Engineer-Curated. It now renders with full ports on every project using part number {$deviceStencil->part_number}.");
    }

    /**
     * "Discard & Regenerate" — always succeeds, regardless of the current
     * port set (it never runs StencilPromotionValidator; that gate is
     * Promote-only). Re-resolves the category template from the stencil's
     * own name/part_number, the SAME call shape stencils:reapply-templates
     * (Plan 24-08) uses, wholesale-replaces device_ports, regenerates
     * mxgraph_xml exactly like update() does (Plan 24-05), and writes a
     * device_stencil_audits row (D-03) capturing the prior state — an
     * explicit reset-to-known-good action, not a promotion, so `source` and
     * `needs_review` are left untouched.
     *
     * @see \App\Services\Drawings\CategoryPortTemplateResolver
     * @see \App\Console\Commands\StencilsReapplyTemplatesCommand — identical resolve()/build() call shape
     */
    public function discard(
        DeviceStencil $deviceStencil,
        CategoryPortTemplateResolver $resolver,
        AutoGenericStencilGenerator $generator,
    ): RedirectResponse {
        DB::transaction(function () use ($deviceStencil, $resolver, $generator) {
            $beforeSnapshot = [
                'mxgraph_xml' => $deviceStencil->mxgraph_xml,
                'ports'       => $deviceStencil->ports()->get()->toArray(),
            ];

            $portTemplate = $resolver->resolve(
                (string) ($deviceStencil->display_name ?? ''),
                (string) $deviceStencil->part_number,
            ) ?? [];

            $deviceStencil->ports()->delete();

            if ($portTemplate !== []) {
                DevicePort::insert(array_map(
                    static fn (array $row): array => array_merge($row, [
                        'device_stencil_id' => $deviceStencil->id,
                        'created_at'        => now(),
                        'updated_at'        => now(),
                    ]),
                    $portTemplate
                ));
            }

            $payload = $generator->build([
                'manufacturer' => $deviceStencil->manufacturer,
                'model'        => $deviceStencil->model,
                'name'         => $deviceStencil->display_name,
                'part_number'  => $deviceStencil->part_number,
                'ports'        => $portTemplate,
            ]);

            $deviceStencil->update(['mxgraph_xml' => $payload['mxgraph_xml']]);

            DeviceStencilAudit::create([
                'device_stencil_id' => $deviceStencil->id,
                'user_id'           => auth()->id(),
                'action'            => DeviceStencilAudit::ACTION_DISCARD_REGENERATE,
                'before_snapshot'   => $beforeSnapshot,
                'after_snapshot'    => [
                    'mxgraph_xml' => $payload['mxgraph_xml'],
                    'ports'       => $portTemplate,
                ],
            ]);
        });

        Log::info('Admin: device stencil discarded and regenerated', [
            'stencil_id' => $deviceStencil->id,
            'admin_id'   => auth()->id(),
        ]);

        return redirect()
            ->route('admin.device-stencils.edit', $deviceStencil)
            ->with('success', 'Stencil discarded and regenerated from its category template.');
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
