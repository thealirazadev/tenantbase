@extends('layouts.central')

@section('title', 'Create a workspace')

@section('content')
    <div class="card card-narrow">
        <h1>Create a workspace</h1>

        <form method="POST" action="{{ url('/tenants') }}">
            @csrf

            <div class="field @error('name') has-error @enderror">
                <label for="name">Workspace name</label>
                <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus>
                @error('name')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field @error('slug') has-error @enderror">
                <label for="slug">Address</label>
                <input id="slug" type="text" name="slug" value="{{ old('slug') }}" required>
                <span class="help mono">{{ old('slug') ?: 'your-workspace' }}.{{ config('tenantbase.domain') }}</span>
                @error('slug')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <button type="submit" class="btn">Create workspace</button>
        </form>
    </div>
@endsection
