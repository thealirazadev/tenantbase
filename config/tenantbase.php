<?php

use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Central domain
    |--------------------------------------------------------------------------
    |
    | Tenants are reached at {slug}.domain. The central domain itself serves
    | authentication, the tenant picker, and workspace creation.
    |
    */

    'domain' => env('APP_DOMAIN', 'localhost'),

    /*
    |--------------------------------------------------------------------------
    | Models
    |--------------------------------------------------------------------------
    |
    | The Tenancy package never references application models directly; it
    | resolves them through these class names.
    |
    */

    'models' => [
        'tenant' => Tenant::class,
        'membership' => Membership::class,
        'user' => User::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Slug rules
    |--------------------------------------------------------------------------
    |
    | A slug is one DNS label: lowercase letters, digits and hyphens, starting
    | with a letter or digit. Reserved labels are refused so a workspace can
    | never shadow an infrastructure hostname.
    |
    */

    'slug' => [
        'min' => 3,
        'max' => 32,
        'pattern' => '/^[a-z0-9][a-z0-9-]*$/',
        'reserved' => [
            'admin',
            'api',
            'app',
            'assets',
            'billing',
            'cdn',
            'dashboard',
            'docs',
            'ftp',
            'help',
            'mail',
            'smtp',
            'static',
            'status',
            'support',
            'www',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Roles
    |--------------------------------------------------------------------------
    |
    | Membership roles, most privileged first. Owners are promoted, never
    | invited, so invitations may only offer the invitable subset.
    |
    */

    'roles' => ['owner', 'admin', 'member'],

    'invitable_roles' => ['admin', 'member'],

    /*
    |--------------------------------------------------------------------------
    | Invitations
    |--------------------------------------------------------------------------
    */

    'invitation_ttl_days' => (int) env('INVITATION_TTL_DAYS', 7),

];
