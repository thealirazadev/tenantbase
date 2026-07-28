<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', $tenant->name)</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<header class="site-header">
    <div class="inner">
        <a class="brand" href="{{ url('/') }}">{{ $tenant->name }}</a>
        <nav>
            @isset($role)
                <span class="badge badge-{{ $role }}">{{ $role }}</span>
            @endisset
            <a class="small" href="{{ config('app.url') }}">All workspaces</a>
        </nav>
    </div>
</header>
<main class="page">
    @include('partials.flash')
    @yield('content')
</main>
</body>
</html>
