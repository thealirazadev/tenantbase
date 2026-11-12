@extends('layouts.tenant')

@section('title', 'New project')

@section('content')
    <div class="card card-narrow">
        <h1>New project</h1>

        <form method="POST" action="{{ url('/projects') }}">
            @include('projects._form')
            <button type="submit" class="btn">Create project</button>
            <a class="btn btn-secondary" href="{{ url('/projects') }}">Cancel</a>
        </form>
    </div>
@endsection
