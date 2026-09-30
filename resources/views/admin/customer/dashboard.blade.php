@extends('layouts.app')
@section('title', 'Customer Dashboard')
@section('heading', 'Dashboard')
@section('nav')
    <a href="{{ route('customer.dashboard') }}" class="active">Dashboard</a>
    <a href="{{ route('customer.devices') }}">Devices</a>
    <a href="{{ route('customer.messages') }}">Messages</a>
    <a href="{{ route('customer.compose') }}">Send SMS</a>
    <a href="{{ route('customer.api') }}">API & Webhooks</a>
    <a href="{{ route('customer.tickets') }}">Support</a>
@endsection
@section('content')
<div class="grid">
    <div class="card stat"><h3>{{ $devices }}</h3><p>Devices ({{ $online }} online)</p></div>
    <div class="card stat"><h3>{{ $sentToday }}</h3><p>Sent today</p></div>
    <div class="card stat"><h3>{{ $receivedToday }}</h3><p>Received today</p></div>
    <div class="card stat">
        <h3>{{ $subscription->sms_used ?? 0 }}/{{ $subscription->plan->sms_limit ?? '-' }}</h3>
        <p>{{ $subscription->plan->name ?? 'No plan' }} · {{ $subscription->status ?? 'n/a' }}</p>
    </div>
</div>
<div class="card">
    <h3>Recent activity</h3>
    <table>
        <thead><tr><th>Dir</th><th>Party</th><th>Body</th><th>Status</th><th>When</th></tr></thead>
        <tbody>
        @foreach($recentMessages as $m)
            <tr>
                <td>{{ $m->direction }}</td>
                <td>{{ $m->direction === 'outgoing' ? $m->recipient : $m->sender }}</td>
                <td>{{ \Illuminate\Support\Str::limit($m->body, 50) }}</td>
                <td>{{ $m->status }}</td>
                <td>{{ $m->created_at }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
