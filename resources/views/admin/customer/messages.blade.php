@extends('layouts.app')
@section('title', 'Messages')
@section('heading', 'Message Logs')
@section('nav')
    <a href="{{ route('customer.dashboard') }}">Dashboard</a>
    <a href="{{ route('customer.devices') }}">Devices</a>
    <a href="{{ route('customer.messages') }}" class="active">Messages</a>
    <a href="{{ route('customer.compose') }}">Send SMS</a>
    <a href="{{ route('customer.api') }}">API & Webhooks</a>
    <a href="{{ route('customer.tickets') }}">Support</a>
@endsection
@section('content')
<div class="card">
    <form method="GET">
        <select name="direction" onchange="this.form.submit()">
            <option value="">All</option>
            <option value="outgoing" @selected(request('direction')==='outgoing')>Outgoing</option>
            <option value="incoming" @selected(request('direction')==='incoming')>Incoming</option>
        </select>
    </form>
</div>
<div class="card">
    <table>
        <thead><tr><th>Dir</th><th>Party</th><th>Body</th><th>Status</th><th>Retries</th><th>When</th></tr></thead>
        <tbody>
        @foreach($messages as $m)
            <tr>
                <td>{{ $m->direction }}</td>
                <td>{{ $m->direction === 'outgoing' ? $m->recipient : $m->sender }}</td>
                <td>{{ \Illuminate\Support\Str::limit($m->body, 60) }}</td>
                <td>{{ $m->status }}</td>
                <td>{{ $m->retry_count }}</td>
                <td>{{ $m->created_at }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    {{ $messages->links() }}
</div>
@endsection
