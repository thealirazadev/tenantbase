@extends('layouts.tenant')

@section('title', 'Edit project')

@section('content')
    <div class="card card-narrow">
        <h1>Edit project</h1>

        <form method="POST" action="{{ url('/projects/'.$project->id) }}">
            @method('PUT')
            @include('projects._form')
            <button type="submit" class="btn">Save changes</button>
            <a class="btn btn-secondary" href="{{ url('/projects') }}">Cancel</a>
        </form>
    </div>
@endsection
