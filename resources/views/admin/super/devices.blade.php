@extends('layouts.app')
@section('title', 'Devices')
@section('heading', 'All Devices')
@section('nav')
    <a href="{{ route('super.dashboard') }}">Dashboard</a>
    <a href="{{ route('super.users') }}">Customers</a>
    <a href="{{ route('super.devices') }}" class="active">Devices</a>
    <a href="{{ route('super.messages') }}">Messages</a>
    <a href="{{ route('super.plans') }}">Plans</a>
@endsection
@section('content')
<div class="card">
    <table>
        <thead><tr><th>ID</th><th>Name</th><th>Customer</th><th>SIM</th><th>Status</th><th>Last sync</th><th>Action</th></tr></thead>
        <tbody>
        @foreach($devices as $device)
            <tr>
                <td>{{ $device->id }}</td>
                <td>{{ $device->name }}</td>
                <td>{{ $device->user->email ?? '-' }}</td>
                <td>{{ $device->sim_number }}</td>
                <td><span class="badge">{{ $device->status }}</span></td>
                <td>{{ $device->last_sync_at }}</td>
                <td>
                    <form method="POST" action="{{ route('super.devices.status', $device) }}">
                        @csrf @method('PATCH')
                        <select name="status" onchange="this.form.submit()">
                            @foreach(['online','offline','paused','disabled'] as $st)
                                <option value="{{ $st }}" @selected($device->status===$st)>{{ $st }}</option>
                            @endforeach
                        </select>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    {{ $devices->links() }}
</div>
@endsection
