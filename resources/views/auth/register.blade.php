@extends('layouts.central')

@section('title', 'Create an account')

@section('content')
    <div class="card card-narrow">
        <h1>Create an account</h1>

        <form method="POST" action="{{ url('/register') }}">
            @csrf

            <div class="field @error('name') has-error @enderror">
                <label for="name">Name</label>
                <input id="name" type="text" name="name" value="{{ old('name') }}" required autofocus>
                @error('name')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field @error('email') has-error @enderror">
                <label for="email">Email</label>
                <input id="email" type="email" name="email" value="{{ old('email') }}" required>
                @error('email')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field @error('password') has-error @enderror">
                <label for="password">Password</label>
                <input id="password" type="password" name="password" required>
                <span class="help">At least 8 characters.</span>
                @error('password')
                    <span class="error">{{ $message }}</span>
                @enderror
            </div>

            <div class="field">
                <label for="password_confirmation">Confirm password</label>
                <input id="password_confirmation" type="password" name="password_confirmation" required>
            </div>

            <button type="submit" class="btn">Create account</button>
        </form>

        <p class="small muted">Already registered? <a href="{{ url('/login') }}">Log in</a>.</p>
    </div>
@endsection
