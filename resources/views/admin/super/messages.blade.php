@extends('layouts.app')
@section('title', 'Messages')
@section('heading', 'Message Monitor')
@section('nav')
    <a href="{{ route('super.dashboard') }}">Dashboard</a>
    <a href="{{ route('super.users') }}">Customers</a>
    <a href="{{ route('super.devices') }}">Devices</a>
    <a href="{{ route('super.messages') }}" class="active">Messages</a>
    <a href="{{ route('super.plans') }}">Plans</a>
@endsection
@section('content')
<div class="card">
    <form method="GET" class="grid">
        <div>
            <label>Direction</label>
            <select name="direction">
                <option value="">All</option>
                <option value="outgoing" @selected(request('direction')==='outgoing')>outgoing</option>
                <option value="incoming" @selected(request('direction')==='incoming')>incoming</option>
            </select>
        </div>
        <div>
            <label>Status</label>
            <input name="status" value="{{ request('status') }}" placeholder="queued/sent/...">
        </div>
        <div style="align-self:end"><button class="btn" type="submit">Filter</button></div>
    </form>
</div>
<div class="card">
    <table>
        <thead><tr><th>ID</th><th>User</th><th>Dir</th><th>Party</th><th>Body</th><th>Status</th><th>When</th></tr></thead>
        <tbody>
        @foreach($messages as $m)
            <tr>
                <td>{{ $m->id }}</td>
                <td>{{ $m->user->email ?? '-' }}</td>
                <td>{{ $m->direction }}</td>
                <td>{{ $m->direction === 'outgoing' ? $m->recipient : $m->sender }}</td>
                <td>{{ \Illuminate\Support\Str::limit($m->body, 40) }}</td>
                <td>{{ $m->status }}</td>
                <td>{{ $m->created_at }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    {{ $messages->links() }}
</div>
@endsection
