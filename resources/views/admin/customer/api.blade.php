@extends('layouts.app')
@section('title', 'API')
@section('heading', 'API Integration')
@section('nav')
    <a href="{{ route('customer.dashboard') }}">Dashboard</a>
    <a href="{{ route('customer.devices') }}">Devices</a>
    <a href="{{ route('customer.messages') }}">Messages</a>
    <a href="{{ route('customer.compose') }}">Send SMS</a>
    <a href="{{ route('customer.api') }}" class="active">API & Webhooks</a>
    <a href="{{ route('customer.tickets') }}">Support</a>
@endsection
@section('content')
<div class="card">
    <h3>Send endpoint</h3>
    <pre style="background:var(--bg);padding:1rem;border-radius:8px;overflow:auto">POST {{ url('/api/v1/messages/send') }}
Headers:
  X-API-Key: smk_...
  X-API-Secret: ...
Body JSON:
  { "to": "+91...", "body": "Hello", "device_id": null }</pre>
</div>
<div class="card">
    <h3>API Keys</h3>
    <form method="POST" action="{{ route('customer.api.keys') }}" style="margin-bottom:1rem">
        @csrf
        <input name="name" placeholder="Key name" style="max-width:260px;display:inline-block">
        <button class="btn" type="submit">Generate key</button>
    </form>
    <table>
        <thead><tr><th>Name</th><th>Key</th><th>Rate/min</th><th>Status</th></tr></thead>
        <tbody>
        @foreach($keys as $key)
            <tr>
                <td>{{ $key->name }}</td>
                <td><code>{{ $key->key }}</code></td>
                <td>{{ $key->rate_limit }}</td>
                <td>{{ $key->revoked_at ? 'revoked' : 'active' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
<div class="card">
    <h3>Webhooks</h3>
    <form method="POST" action="{{ route('customer.api.webhooks') }}">
        @csrf
        <label>Event</label>
        <select name="event_type">
            @foreach(['message.sent','message.delivered','message.failed','message.incoming'] as $ev)
                <option value="{{ $ev }}">{{ $ev }}</option>
            @endforeach
        </select>
        <label>URL</label>
        <input name="url" type="url" required placeholder="https://example.com/hooks/sms">
        <button class="btn" type="submit">Add webhook</button>
    </form>
    <table style="margin-top:1rem">
        <thead><tr><th>Event</th><th>URL</th><th>Active</th></tr></thead>
        <tbody>
        @foreach($webhooks as $wh)
            <tr>
                <td>{{ $wh->event_type }}</td>
                <td>{{ $wh->url }}</td>
                <td>{{ $wh->is_active ? 'yes' : 'no' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
    <p style="color:var(--muted);font-size:0.85rem;margin-top:1rem">
        Verify authenticity with HMAC-SHA256 of the raw body using the webhook secret.
        Header: <code>X-SMS-Gateway-Signature</code>
    </p>
</div>
@endsection
