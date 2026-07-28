<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreTenantRequest;
use App\Models\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class TenantSignupController extends Controller
{
    public function create(): View
    {
        return view('tenants.create');
    }

    public function store(StoreTenantRequest $request): RedirectResponse
    {
        try {
            $tenant = Tenant::provision(
                $request->string('name')->toString(),
                $request->string('slug')->toString(),
                config('plans.default'),
                $request->user(),
            );
        } catch (UniqueConstraintViolationException) {
            // Two signups raced for the same slug; the loser gets a field error.
            throw ValidationException::withMessages(['slug' => 'That address is already taken.']);
        }

        return redirect()->away($tenant->url());
    }
}
