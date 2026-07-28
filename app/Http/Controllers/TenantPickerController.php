<?php

namespace App\Http\Controllers;

use App\Models\Membership;
use Illuminate\Http\Request;
use Illuminate\View\View;
use TenantBase\Tenancy\Tenancy;

class TenantPickerController extends Controller
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function index(Request $request): View
    {
        // Sanctioned bypass: the picker is by definition a cross-tenant read,
        // and it runs on the central domain where no context exists.
        $memberships = $this->tenancy->withoutTenancy(
            'tenant picker: list memberships for user',
            fn () => Membership::with('tenant')
                ->where('user_id', $request->user()->getKey())
                ->get()
                ->sortBy(fn (Membership $membership): string => $membership->tenant->name)
                ->values(),
        );

        return view('home', ['memberships' => $memberships]);
    }
}
