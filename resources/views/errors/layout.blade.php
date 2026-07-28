<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title')</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
</head>
<body>
<main class="page">
    <div class="card card-narrow">
        <h1>@yield('title')</h1>
        <p class="muted">@yield('message')</p>
        <p><a href="{{ config('app.url') }}">Back to your workspaces</a></p>
    </div>
</main>
</body>
</html>
