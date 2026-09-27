<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LabourResource;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

/**
 * Labour resources CRUD — Phase 44 Plan 02, widened to the whole workspace by
 * quick task 260927-lr7.
 *
 * NOT ADMIN-ONLY ANY MORE. Phase 44 registered these routes inside
 * `Route::middleware('admin')`, and nothing in the UI ever linked to them, so
 * the page was effectively unreachable and live carried zero labour resources —
 * which is why the survey wizard's engineer step offered nobody but
 * "Unassigned". 260927-lr7 moved the routes to the plain `auth` group and added
 * a top-level "Labour" nav item, so any signed-in user can add, edit and
 * deactivate resources. This class holds NO gate of its own, by design: the
 * route group is the single place authorization is expressed. Do not add a
 * middleware call or an abort_unless here without moving the route too —
 * a gate in two places is a gate nobody can reason about.
 *
 * LR-04 is NOT relaxed by this. A resource carries `email` and `phone`; those
 * must never reach a client. This is a staff surface behind `auth`, and the
 * client-facing prohibition is enforced separately by
 * tests/Feature/Security/LabourResourceClientSurfacePrivacyTest.php.
 *
 * Deliberately has NO destroy() method and no delete route. D-02
 * (44-CONTEXT.md) requires deactivation, never hard-delete, because a
 * resource that attended a past visit must keep displaying correctly on
 * that history. This is a different concept to `UserController::destroy()`,
 * which hard-deletes a login account — an account has no "history" of its
 * own that a delete would corrupt, but a labour resource is referenced by
 * past visit records. `toggleActive()` is the only lifecycle transition
 * this controller exposes beyond create/edit.
 *
 * The class namespace and the view directory both still say "admin". That is
 * file organisation only, invisible to a user, and moving them was out of
 * scope for 260927-lr7 — the URL and the route names are what a person sees,
 * and both dropped the prefix.
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

        Log::info('Labour resource created', [
            'new_resource_id' => $resource->id,
            'new_resource'    => $resource->name,
            'actor_id'        => auth()->id(),
        ]);

        return redirect()->route('labour-resources.index')
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

        Log::info('Labour resource updated', [
            'target_resource_id' => $labourResource->id,
            'actor_id'           => auth()->id(),
        ]);

        return redirect()->route('labour-resources.index')
            ->with('success', "Labour resource {$labourResource->name} updated.");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // toggleActive — deactivate / reactivate
    // ─────────────────────────────────────────────────────────────────────────

    public function toggleActive(LabourResource $labourResource): RedirectResponse
    {
        $labourResource->update(['is_active' => ! $labourResource->is_active]);

        $action = $labourResource->is_active ? 'reactivated' : 'deactivated';

        Log::info("Labour resource {$action}", [
            'target_resource_id' => $labourResource->id,
            'actor_id'            => auth()->id(),
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
