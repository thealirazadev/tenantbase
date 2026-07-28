<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', config('app.name'))</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<header class="site-header">
    <div class="inner">
        <a class="brand" href="{{ url('/') }}">{{ config('app.name') }}</a>
        <nav>
            @auth
                <span class="muted small">{{ auth()->user()->email }}</span>
                <form method="POST" action="{{ url('/logout') }}">
                    @csrf
                    <button type="submit" class="btn-link">Log out</button>
                </form>
            @endauth
        </nav>
    </div>
</header>
<main class="page">
    @include('partials.flash')
    @yield('content')
</main>
</body>
</html>
