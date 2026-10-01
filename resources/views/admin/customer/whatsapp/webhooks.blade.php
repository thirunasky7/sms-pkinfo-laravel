@extends('layouts.app')
@section('title', 'WhatsApp Webhooks')
@section('heading', 'WhatsApp Webhooks')
@section('nav')
    @include('admin.customer.whatsapp._nav')
@endsection
@section('content')
@include('admin.customer.whatsapp._tabs')

<div class="card">
    <h3>Add webhook</h3>
    <form method="POST" action="{{ route('customer.whatsapp.webhooks.store') }}">
        @csrf
        <div class="grid">
            <div>
                <label>Event</label>
                <select name="event_type">
                    @foreach($events as $ev)
                        <option value="{{ $ev }}">{{ $ev }}</option>
                    @endforeach
                </select>
            </div>
            <div style="grid-column:span 2"><label>URL (HTTPS)</label><input name="url" type="url" required placeholder="https://example.com/hooks/whatsapp"></div>
        </div>
        <button class="btn" type="submit">Add webhook</button>
    </form>
</div>

<div class="card">
    <table>
        <thead><tr><th>Event</th><th>URL</th><th>Active</th><th></th></tr></thead>
        <tbody>
        @forelse($webhooks as $wh)
            <tr>
                <td>{{ $wh->event_type }}</td>
                <td>{{ $wh->url }}</td>
                <td>{{ $wh->is_active ? 'yes' : 'no' }}</td>
                <td>
                    <form method="POST" action="{{ route('customer.whatsapp.webhooks.destroy', $wh) }}" onsubmit="return confirm('Delete webhook?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" style="color:var(--muted)">No WhatsApp webhooks.</td></tr>
        @endforelse
        </tbody>
    </table>
    <p style="color:var(--muted);font-size:.85rem;margin-top:1rem">
        Each delivery is a JSON POST with headers <code>X-SMS-Gateway-Event</code> and <code>X-SMS-Gateway-Signature</code>
        (HMAC-SHA256 of the raw body using the webhook secret). Failed deliveries are retried with backoff.
    </p>
    <pre style="background:var(--bg);padding:1rem;border-radius:8px;overflow:auto">{
  "event": "whatsapp.message.delivered",
  "message": {
    "message_id": "wam_01j…",
    "direction": "outgoing",
    "connector_type": "cloud_api",
    "message_type": "text",
    "account_id": 3,
    "device_id": null,
    "sender": "14155550100",
    "recipient": "14155550123",
    "body": "Your order shipped",
    "status": "delivered",
    "retry_count": 0,
    "last_attempt_at": "2026-09-30T10:00:01+00:00",
    "error_code": null,
    "failure_reason": null,
    "delivered_at": "2026-09-30T10:00:04+00:00",
    "timestamp": "2026-09-30T10:00:00+00:00"
  }
}</pre>
</div>
@endsection
