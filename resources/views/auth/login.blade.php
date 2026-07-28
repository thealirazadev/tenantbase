@extends('layouts.central')

@section('title', 'Log in')

@section('content')
    <div class="card card-narrow">
        <h1>Log in</h1>

        <form method="POST" action="{{ url('/login') }}">
            @csrf

            <div class="field @error('email') has-error @enderror">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required autofocus>
                @error('email')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field @error('password') has-error @enderror">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required>
                @error('password')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <button type="submit" class="btn">Log in</button>
        </form>

        <p class="small muted">No account yet? <a href="{{ url('/register') }}">Create one</a>.</p>
    </div>
@endsection
