@extends('layouts.central')

@section('title', 'Your workspaces')

@section('content')
    <div class="row-between">
        <h1>Your workspaces</h1>
        <a class="btn" href="{{ url('/tenants/create') }}">Create a workspace</a>
    </div>

    <div class="card">
        @if ($memberships->isEmpty())
            <p class="empty">You are not a member of any workspace yet.</p>
        @else
            <ul class="list-links">
                @foreach ($memberships as $membership)
                    <li>
                        <a href="{{ $membership->tenant->url() }}">
                            <span>
                                <strong>{{ $membership->tenant->name }}</strong>
                                <span class="muted small mono">{{ $membership->tenant->slug }}</span>
                            </span>
                            <span>
                                <span class="badge badge-{{ $membership->role }}">{{ $membership->role }}</span>
                                <span class="muted small">{{ $membership->tenant->plan }}</span>
                            </span>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endsection
