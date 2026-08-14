<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeviceStencil;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Phase 24 Plan 03 (DRAW-50) — admin curation queue for device_stencils.
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
}
