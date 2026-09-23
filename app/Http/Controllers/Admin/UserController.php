<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class UserController extends Controller
{
    /**
     * List every platform user.
     */
    public function index(): View
    {
        $users = User::query()
            ->with('business')
            ->orderBy('name')
            ->paginate(20);

        return view('admin.users.index', ['users' => $users]);
    }

    /**
     * Show one user's detail.
     */
    public function show(string $user): View
    {
        $user = User::with('business.plan')->findOrFail($user);

        return view('admin.users.show', ['user' => $user]);
    }

    /**
     * Change a user between admin and business_owner.
     *
     * Two server-side guards that can never be bypassed from the browser:
     * an admin can't remove their own admin access, and the platform can
     * never be left with zero admins.
     */
    public function updateRole(Request $request, string $user): RedirectResponse
    {
        $user = User::findOrFail($user);

        $validated = $request->validate([
            'role' => ['required', Rule::in([User::ROLE_ADMIN, User::ROLE_BUSINESS_OWNER])],
        ]);

        $demotingSelf = $user->id === $request->user()->id && $validated['role'] !== User::ROLE_ADMIN;
        $demotingLastAdmin = $user->isAdmin()
            && $validated['role'] !== User::ROLE_ADMIN
            && User::where('role', User::ROLE_ADMIN)->count() <= 1;

        if ($demotingSelf) {
            return back()->with('error', 'You cannot remove your own admin access.');
        }

        if ($demotingLastAdmin) {
            return back()->with('error', 'At least one administrator must remain.');
        }

        $user->role = $validated['role'];
        $user->save();

        return redirect()->route('admin.users.show', $user)->with('status', 'Role updated.');
    }
}
