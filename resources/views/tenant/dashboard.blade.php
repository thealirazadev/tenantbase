@extends('layouts.tenant')

@section('title', 'Dashboard')

@section('content')
    <h1>Dashboard</h1>

    <div class="card">
        <h2>{{ $tenant->name }}</h2>
        <p class="muted small">
            Workspace <code>{{ $tenant->slug }}</code> on the {{ $tenant->plan }} plan.
        </p>
    </div>
@endsection
