<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class UserController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // index
    // ─────────────────────────────────────────────────────────────────────────

    public function index(): View
    {
        $users = User::orderBy('name')->paginate(20);

        return view('admin.users.index', compact('users'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // create
    // ─────────────────────────────────────────────────────────────────────────

    public function create(): View
    {
        return view('admin.users.form', ['user' => null]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // store
    // ─────────────────────────────────────────────────────────────────────────

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:100'],
            'email'    => ['required', 'email', 'max:254', 'unique:users,email'],
            'role'     => ['required', 'in:admin,user'],
            'password' => ['required', Password::min(8)->letters()->numbers()],
        ]);

        $user = User::create([
            'name'      => $validated['name'],
            'email'     => $validated['email'],
            'role'      => $validated['role'],
            'password'  => Hash::make($validated['password']),
            'is_active' => true,
        ]);

        Log::info('Admin: user created', [
            'new_user_id'  => $user->id,
            'new_user'     => $user->email,
            'admin_id'     => auth()->id(),
        ]);

        return redirect()->route('admin.users.index')
            ->with('success', "User {$user->name} created successfully.");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // edit
    // ─────────────────────────────────────────────────────────────────────────

    public function edit(User $user): View
    {
        return view('admin.users.form', compact('user'));
    }

    // ─────────────────────────────────────────────────────────────────────────
    // update
    // ─────────────────────────────────────────────────────────────────────────

    public function update(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:100'],
            'email'    => ['required', 'email', 'max:254', Rule::unique('users')->ignore($user->id)],
            'role'     => ['required', 'in:admin,user'],
            'password' => ['nullable', Password::min(8)->letters()->numbers()],
        ]);

        // Prevent an admin from demoting themselves
        if ($user->id === auth()->id() && $validated['role'] !== 'admin') {
            return back()->with('error', 'You cannot remove your own admin role.');
        }

        $updates = [
            'name'  => $validated['name'],
            'email' => $validated['email'],
            'role'  => $validated['role'],
        ];

        if (! empty($validated['password'])) {
            $updates['password'] = Hash::make($validated['password']);
        }

        $user->update($updates);

        Log::info('Admin: user updated', [
            'target_user_id' => $user->id,
            'admin_id'       => auth()->id(),
        ]);

        return redirect()->route('admin.users.index')
            ->with('success', "User {$user->name} updated.");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // toggleActive — suspend / reactivate
    // ─────────────────────────────────────────────────────────────────────────

    public function toggleActive(User $user): RedirectResponse
    {
        // Prevent suspending yourself
        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot suspend your own account.');
        }

        $user->update(['is_active' => ! $user->is_active]);

        $action = $user->is_active ? 'reactivated' : 'suspended';

        Log::info("Admin: user {$action}", [
            'target_user_id' => $user->id,
            'admin_id'       => auth()->id(),
        ]);

        return back()->with('success', "User {$user->name} {$action}.");
    }

    // ─────────────────────────────────────────────────────────────────────────
    // destroy — permanent delete
    // ─────────────────────────────────────────────────────────────────────────

    public function destroy(User $user): RedirectResponse
    {
        // Prevent deleting yourself
        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $name = $user->name;
        $user->delete();

        Log::info('Admin: user deleted', [
            'target_user_id' => $user->id,
            'admin_id'       => auth()->id(),
        ]);

        return redirect()->route('admin.users.index')
            ->with('success', "User {$name} permanently deleted.");
    }
}
