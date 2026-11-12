@extends('layouts.tenant')

@section('title', 'Members')

@section('content')
    <h1>Members</h1>

    <div class="card table-wrap">
        <table>
            <thead>
                <tr>
                    <th scope="col">Name</th>
                    <th scope="col">Email</th>
                    <th scope="col">Role</th>
                    <th scope="col">Joined</th>
                    <th scope="col"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>
            <tbody>
                @foreach ($memberships as $membership)
                    <tr>
                        <td>{{ $membership->user->name }}</td>
                        <td>{{ $membership->user->email }}</td>
                        <td><span class="badge badge-{{ $membership->role }}">{{ $membership->role }}</span></td>
                        <td>{{ $membership->created_at?->toDateString() }}</td>
                        <td>
                            @if ($membership->isOwner() && $ownerCount === 1)
                                <span class="muted small">sole owner</span>
                            @else
                                @can('update', $membership)
                                    <form method="POST" action="{{ url('/members/'.$membership->id) }}" class="inline-confirm">
                                        @csrf
                                        @method('PUT')
                                        <label class="sr-only" for="role-{{ $membership->id }}">Role for {{ $membership->user->name }}</label>
                                        <select id="role-{{ $membership->id }}" name="role">
                                            @foreach (\App\Models\Membership::ROLES as $role)
                                                <option value="{{ $role }}" @selected($membership->role === $role)>{{ $role }}</option>
                                            @endforeach
                                        </select>
                                        <button type="submit" class="btn btn-secondary">Change role</button>
                                    </form>
                                @endcan
                                @can('delete', $membership)
                                    <form method="POST" action="{{ url('/members/'.$membership->id) }}" class="inline-confirm">
                                        @csrf
                                        @method('DELETE')
                                        <label class="small"><input type="checkbox" name="confirm" value="1" required> Confirm</label>
                                        <button type="submit" class="btn btn-danger">Remove</button>
                                    </form>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endsection
