<?php

namespace App\Http\Controllers;

use Illuminate\View\View;

/**
 * SpikeSchematicController — throwaway React Flow discovery spike.
 *
 * Feature-flagged via SPIKE_SCHEMATIC_ENABLED (default false) so the
 * route 404s in prod until an admin flips it. Also admin-gated. All
 * spike surface (routes, views, JS) is namespaced under `spike/*`
 * — one `rm -rf` operation deletes it entirely.
 *
 * 2-week review deadline: 2026-07-27.
 */
class SpikeSchematicController extends Controller
{
    public function show(): View
    {
        abort_unless(config('services.spike_schematic_enabled'), 404);
        abort_unless(auth()->user()?->isAdmin(), 403);

        return view('spike.canvas');
    }
}
