@extends('layouts.tenant')

@section('title', 'Projects')

@section('content')
    <div class="row-between">
        <h1>Projects</h1>
        <a class="btn" href="{{ url('/projects/create') }}">New project</a>
    </div>

    @if ($projects->isEmpty())
        <div class="card empty">
            <p>No projects yet. <a href="{{ url('/projects/create') }}">Create the first one</a>.</p>
        </div>
    @else
        <div class="card table-wrap">
            <table>
                <thead>
                    <tr>
                        <th scope="col">Name</th>
                        <th scope="col">Description</th>
                        <th scope="col">Created by</th>
                        <th scope="col">Created</th>
                        <th scope="col"><span class="sr-only">Actions</span></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($projects as $project)
                        <tr>
                            <td>{{ $project->name }}</td>
                            <td class="muted">{{ $project->description }}</td>
                            <td>{{ $project->creator?->name ?? '-' }}</td>
                            <td>{{ $project->created_at?->toDateString() }}</td>
                            <td>
                                <a href="{{ url('/projects/'.$project->id.'/edit') }}">Edit</a>
                                <form method="POST" action="{{ url('/projects/'.$project->id) }}" class="inline-confirm">
                                    @csrf
                                    @method('DELETE')
                                    <label class="small"><input type="checkbox" name="confirm" value="1" required> Confirm</label>
                                    <button type="submit" class="btn btn-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        {{ $projects->links('pagination::simple-default') }}
    @endif
@endsection
