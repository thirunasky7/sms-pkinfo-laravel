@extends('layouts.app')
@section('title', 'Super Admin')
@section('heading', 'Dashboard')
@section('nav')
    <a href="{{ route('super.dashboard') }}" class="active">Dashboard</a>
    <a href="{{ route('super.users') }}">Customers</a>
    <a href="{{ route('super.devices') }}">Devices</a>
    <a href="{{ route('super.messages') }}">Messages</a>
    <a href="{{ route('super.plans') }}">Plans</a>
@endsection
@section('content')
<div class="grid">
    <div class="card stat"><h3>{{ $customers }}</h3><p>Customers</p></div>
    <div class="card stat"><h3>{{ $devices }}</h3><p>Devices ({{ $onlineDevices }} online)</p></div>
    <div class="card stat"><h3>{{ $sent }}</h3><p>SMS Sent</p></div>
    <div class="card stat"><h3>{{ $received }}</h3><p>SMS Received</p></div>
</div>
<div class="card">
    <h3>Recent messages</h3>
    <table>
        <thead><tr><th>ID</th><th>User</th><th>Dir</th><th>To/From</th><th>Status</th><th>When</th></tr></thead>
        <tbody>
        @foreach($recentMessages as $m)
            <tr>
                <td>{{ $m->id }}</td>
                <td>{{ $m->user->email ?? '-' }}</td>
                <td>{{ $m->direction }}</td>
                <td>{{ $m->direction === 'outgoing' ? $m->recipient : $m->sender }}</td>
                <td><span class="badge">{{ $m->status }}</span></td>
                <td>{{ $m->created_at }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
