<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LabourResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Phase 44 Plan 02 — admin CRUD for labour resources.
 *
 * Deliberately has NO destroy() method and no delete route. D-02
 * (44-CONTEXT.md) requires deactivation, never hard-delete, because a
 * resource that attended a past visit must keep displaying correctly on
 * that history. This is a different concept to `UserController::destroy()`,
 * which hard-deletes a login account — an account has no "history" of its
 * own that a delete would corrupt, but a labour resource is referenced by
 * past visit records. `toggleActive()` is the only lifecycle transition
 * this controller exposes beyond create/edit.
 */
class LabourResourceController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // index
    // ─────────────────────────────────────────────────────────────────────────

    public function index(): View
    {
        $resources = LabourResource::orderBy('name')->paginate(20);

        return view('admin.labour-resources.index', compact('resources'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // create
    // ─────────────────────────────────────────────────────────────────────────

    public function create(): View
    {
        return view('admin.labour-resources.form', ['resource' => null]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // store
    // ─────────────────────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $validated = $this->validated($request);

        $resource = LabourResource::create([
            'name'      => $validated['name'],
            'email'     => $validated['email'] ?? null,
            'phone'     => $validated['phone'] ?? null,
            'roles'     => $validated['roles'],
            'user_id'   => $validated['user_id'] ?? null,
            'is_active' => true,
        ]);

        Log::info('Admin: labour resource created', [
            'new_resource_id' => $resource->id,
            'new_resource'    => $resource->name,
            'admin_id'        => auth()->id(),
        ]);

        return redirect()->route('admin.labour-resources.index')
            ->with('success', "Labour resource {$resource->name} created successfully.");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // edit
    // ─────────────────────────────────────────────────────────────────────────

    public function edit(LabourResource $labourResource): View
    {
        return view('admin.labour-resources.form', ['resource' => $labourResource]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // update
    // ─────────────────────────────────────────────────────────────────────────

    public function update(Request $request, LabourResource $labourResource): RedirectResponse
    {
        $validated = $this->validated($request);

        // Does NOT touch is_active — that is toggleActive()'s job only,
        // exactly like UserController::update() never touches is_active.
        $labourResource->update([
            'name'    => $validated['name'],
            'email'   => $validated['email'] ?? null,
            'phone'   => $validated['phone'] ?? null,
            'roles'   => $validated['roles'],
            'user_id' => $validated['user_id'] ?? null,
        ]);

        Log::info('Admin: labour resource updated', [
            'target_resource_id' => $labourResource->id,
            'admin_id'           => auth()->id(),
        ]);

        return redirect()->route('admin.labour-resources.index')
            ->with('success', "Labour resource {$labourResource->name} updated.");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // toggleActive — deactivate / reactivate
    // ─────────────────────────────────────────────────────────────────────────

    public function toggleActive(LabourResource $labourResource): RedirectResponse
    {
        $labourResource->update(['is_active' => ! $labourResource->is_active]);

        $action = $labourResource->is_active ? 'reactivated' : 'deactivated';

        Log::info("Admin: labour resource {$action}", [
            'target_resource_id' => $labourResource->id,
            'admin_id'            => auth()->id(),
        ]);

        return back()->with('success', "Labour resource {$labourResource->name} {$action}.");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // validation (shared by store + update)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array{name: string, email: ?string, phone: ?string, roles: array<int, string>, user_id: ?int}
     */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name'     => ['required', 'string', 'max:150'],
            'email'    => ['nullable', 'email', 'max:254'],
            'phone'    => ['nullable', 'string', 'max:30'],
            'roles'    => ['required', 'array', 'min:1'],
            'roles.*'  => ['in:' . implode(',', LabourResource::ROLES)],
            'user_id'  => ['nullable', 'exists:users,id'],
        ]);
    }
}
