<?php

namespace App\Http\Controllers;

use App\Exceptions\LastOwnerException;
use App\Models\Membership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class MembershipController extends Controller
{
    public function update(Request $request, Membership $membership): RedirectResponse
    {
        Gate::authorize('update', $membership);
        $role = $request->validate(['role' => ['required', Rule::in(Membership::ROLES)]])['role'];

        try {
            $membership->changeRole($role);
        } catch (LastOwnerException $e) {
            return redirect('/members')->with('error', $e->getMessage());
        }

        return redirect('/members')->with('status', 'Role updated.');
    }

    public function destroy(Membership $membership): RedirectResponse
    {
        Gate::authorize('delete', $membership);

        try {
            $membership->removeFromWorkspace();
        } catch (LastOwnerException $e) {
            return redirect('/members')->with('error', $e->getMessage());
        }

        return redirect('/members')->with('status', 'Member removed.');
    }
}
