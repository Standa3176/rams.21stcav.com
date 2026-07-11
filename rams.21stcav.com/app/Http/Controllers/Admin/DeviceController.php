<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\DeviceUpdateRequest;
use App\Models\Device;
use App\Models\Project;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Tier 4 admin surface for the Device asset register (quick task 260711-q7q).
 *
 * Follow-up to 260711-oh4 which shipped the new Device columns
 * (`is_critical`, `pse_budget_w`, `pd_load_w`) as consumers of the
 * cable-schedule DAG but exposed no admin UI. This controller closes
 * the UX gap so engineers can flip signal_role / is_critical / PoE
 * metadata / room_name without a developer.
 *
 * Devices are CREATED by the label-photo capture flow + import
 * pipelines — this surface is edit-only (index / edit / update). No
 * create / store / destroy actions.
 */
class DeviceController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // index
    // ─────────────────────────────────────────────────────────────────────────

    public function index(Request $request): View
    {
        $query = Device::query();

        // ── ?project_id={id} filter ───────────────────────────────────────
        $projectId = $request->integer('project_id');
        $project   = null;
        if ($projectId > 0) {
            $project = Project::find($projectId);
            if ($project !== null) {
                $query->where('project_id', $projectId);
            }
        }

        // ── ?q={term} search across manufacturer / model / part_no ────────
        $term = trim((string) $request->input('q', ''));
        if ($term !== '') {
            $query->where(function ($sub) use ($term) {
                $sub->where('manufacturer', 'like', "%{$term}%")
                    ->orWhere('model', 'like', "%{$term}%")
                    ->orWhere('part_no', 'like', "%{$term}%");
            });
        }

        $devices = $query
            ->orderBy('project_id')
            ->orderBy('room_name')
            ->orderBy('id')
            ->paginate(15)
            ->appends($request->query());

        // Populate the filter dropdown — small internal tool, no need to
        // paginate the select. Fetch id + name only.
        $projects = Project::orderBy('name')->get(['id', 'name']);

        return view('admin.devices.index', [
            'devices'   => $devices,
            'projects'  => $projects,
            'project'   => $project,
            'projectId' => $projectId,
            'q'         => $term,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // edit
    // ─────────────────────────────────────────────────────────────────────────

    public function edit(Device $device): View
    {
        return view('admin.devices.edit', compact('device'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // update
    // ─────────────────────────────────────────────────────────────────────────

    public function update(DeviceUpdateRequest $request, Device $device): RedirectResponse
    {
        $validated = $request->validated();

        $device->update([
            'room_name'    => $validated['room_name'] ?? null,
            'signal_role'  => $validated['signal_role'] ?? null,
            'is_critical'  => (bool) ($validated['is_critical'] ?? false),
            'pse_budget_w' => $validated['pse_budget_w'] ?? null,
            'pd_load_w'    => $validated['pd_load_w'] ?? null,
        ]);

        Log::info('Admin: device updated', [
            'device_id' => $device->id,
            'admin_id'  => auth()->id(),
        ]);

        return redirect()
            ->route('admin.devices.index', ['project_id' => $device->project_id])
            ->with('success', 'Device updated.');
    }
}
