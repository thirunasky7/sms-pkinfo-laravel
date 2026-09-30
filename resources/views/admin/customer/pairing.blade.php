@extends('layouts.app')
@section('title', 'Pair Device')
@section('heading', 'Pair Android Device')
@section('nav')
    <a href="{{ route('customer.dashboard') }}">Dashboard</a>
    <a href="{{ route('customer.devices') }}" class="active">Devices</a>
    <a href="{{ route('customer.messages') }}">Messages</a>
    <a href="{{ route('customer.compose') }}">Send SMS</a>
    <a href="{{ route('customer.api') }}">API & Webhooks</a>
    <a href="{{ route('customer.tickets') }}">Support</a>
@endsection
@section('content')
<div class="card" style="text-align:center">
    <p>Scan this QR with the SMS Gateway app (expires {{ $pairing->expires_at }})</p>
    <img src="{{ $qrUrl }}" alt="Pairing QR" width="280" height="280" style="border-radius:12px;background:#fff;padding:12px">
    <p style="margin-top:1rem"><strong>Token:</strong> <code>{{ $pairing->token }}</code></p>
    <p style="color:var(--muted);font-size:0.85rem">Or enter the token manually in the app.</p>
    <pre style="text-align:left;background:var(--bg);padding:1rem;border-radius:8px;overflow:auto">{{ $payload }}</pre>
</div>
@endsection
