@extends('layouts.app')
@section('title', 'WhatsApp API')
@section('heading', 'WhatsApp API Documentation')
@section('nav')
    @include('admin.customer.whatsapp._nav')
@endsection
@section('content')
@include('admin.customer.whatsapp._tabs')

@php($pre = 'background:var(--bg);padding:1rem;border-radius:8px;overflow:auto;font-size:.85rem')

<div class="card">
    <h3>Authentication</h3>
    <p style="color:var(--muted)">
        Use the same API keys as the SMS API (<a href="{{ route('customer.api') }}">API & Webhooks</a>). Send
        <code>X-API-Key</code> and <code>X-API-Secret</code> headers on every request. Keys restricted with scopes need
        <code>whatsapp:send</code>, <code>whatsapp:read</code> or <code>whatsapp:templates</code>. HTTPS is required.
    </p>
    <p style="color:var(--muted)">
        Errors return <code>{"message": "...", "error_code": "..."}</code>. Send an <code>Idempotency-Key</code> header
        (or <code>idempotency_key</code> field) to make retries safe — a repeated key returns the original message with
        <code>"duplicate": true</code>.
    </p>
</div>

<div class="card">
    <h3>Connection status</h3>
    <pre style="{{ $pre }}">curl {{ $apiBase }}/whatsapp/connection \
  -H "X-API-Key: smk_..." -H "X-API-Secret: ..."</pre>
</div>

<div class="card">
    <h3>Send a text message</h3>
    <pre style="{{ $pre }}">curl -X POST {{ $apiBase }}/whatsapp/send \
  -H "X-API-Key: smk_..." -H "X-API-Secret: ..." \
  -H "Content-Type: application/json" -H "Idempotency-Key: order-1234-shipped" \
  -d '{"to": "+14155550123", "body": "Your order has shipped"}'</pre>
    <p style="color:var(--muted);font-size:.85rem">Optional: <code>account_id</code>, <code>connector</code> (<code>device</code>|<code>cloud_api</code>), <code>external_id</code>, <code>scheduled_at</code> (ISO-8601). Returns <code>202</code> with the queued message.</p>
</div>

<div class="card">
    <h3>Send media</h3>
    <pre style="{{ $pre }}"># Upload a file
curl -X POST {{ $apiBase }}/whatsapp/send \
  -H "X-API-Key: smk_..." -H "X-API-Secret: ..." \
  -F to=+14155550123 -F type=image -F body="Invoice attached" -F media=@invoice.jpg

# Or reference an HTTPS URL
curl -X POST {{ $apiBase }}/whatsapp/send \
  -H "X-API-Key: smk_..." -H "X-API-Secret: ..." -H "Content-Type: application/json" \
  -d '{"to":"+14155550123","type":"document","media_url":"https://example.com/invoice.pdf","filename":"invoice.pdf"}'</pre>
    <p style="color:var(--muted);font-size:.85rem">Types: <code>image</code>, <code>video</code>, <code>audio</code>, <code>document</code>. Max {{ (int) (config('whatsapp.media.max_kb') / 1024) }} MB.</p>
</div>

<div class="card">
    <h3>Send a template</h3>
    <pre style="{{ $pre }}">curl -X POST {{ $apiBase }}/whatsapp/template/send \
  -H "X-API-Key: smk_..." -H "X-API-Secret: ..." -H "Content-Type: application/json" \
  -d '{
    "to": "+14155550123",
    "template_name": "order_update",
    "language": "en_US",
    "params": { "body": ["Sam", "#1234"] }
  }'</pre>
    <p style="color:var(--muted);font-size:.85rem">Use <code>template_id</code> instead of name/language if preferred. Optional <code>params.header</code>, <code>params.header_media_url</code>, <code>params.buttons[] {index, sub_type, value}</code>. Only approved templates can be sent.</p>
</div>

<div class="card">
    <h3>Message status and history</h3>
    <pre style="{{ $pre }}">curl {{ $apiBase }}/whatsapp/message/wam_01j... -H "X-API-Key: smk_..." -H "X-API-Secret: ..."

curl "{{ $apiBase }}/whatsapp/messages?direction=incoming&status=&from=2026-09-01&per_page=50" \
  -H "X-API-Key: smk_..." -H "X-API-Secret: ..."

curl -X POST {{ $apiBase }}/whatsapp/check-number -H "X-API-Key: smk_..." -H "X-API-Secret: ..." \
  -H "Content-Type: application/json" -d '{"phone": "+14155550123"}'</pre>
    <p style="color:var(--muted);font-size:.85rem">
        <code>check-number</code> answers from your message history (<code>true</code>/<code>false</code>) or returns
        <code>unknown</code> — WhatsApp has no public lookup API.
    </p>
</div>

<div class="card">
    <h3>Templates</h3>
    <pre style="{{ $pre }}">GET    {{ $apiBase }}/whatsapp/templates
POST   {{ $apiBase }}/whatsapp/templates        {"name","language","category","body","examples":{"body":[...]}, ...}
GET    {{ $apiBase }}/whatsapp/templates/{id}
PUT    {{ $apiBase }}/whatsapp/templates/{id}
DELETE {{ $apiBase }}/whatsapp/templates/{id}
POST   {{ $apiBase }}/whatsapp/templates/sync</pre>
</div>

<div class="card">
    <h3>Statuses and error codes</h3>
    <p style="color:var(--muted)">Statuses: <code>queued</code> → <code>sending</code> → <code>sent</code> → <code>delivered</code> → <code>read</code>, or <code>failed</code>. Incoming messages have status <code>received</code>.</p>
    <table>
        <thead><tr><th>error_code</th><th>Meaning</th></tr></thead>
        <tbody>
        @foreach([
            'INVALID_NUMBER' => 'Recipient is not a valid international number',
            'NOT_ON_WHATSAPP' => 'Recipient is not registered on WhatsApp',
            'RECIPIENT_OPTED_OUT' => 'Recipient opted out (e.g. replied STOP)',
            'NO_WHATSAPP_ACCOUNT' => 'No connected WhatsApp number on your account',
            'ACCOUNT_NOT_CONNECTED' => 'The chosen WhatsApp account is not connected',
            'QUOTA_EXCEEDED' => 'WhatsApp quota reached or subscription expired',
            'TEMPLATE_NOT_APPROVED' => 'Template is not approved by Meta',
            'TEMPLATE_PARAM_MISMATCH' => 'Wrong number of template parameters',
            'OUTSIDE_24H_WINDOW' => 'Free-form message outside the 24h customer service window; use a template',
            'TOKEN_EXPIRED' => 'The Cloud API access token expired; reconnect the number',
            'RATE_LIMITED' => 'Provider rate limit; the message is retried automatically',
            'DEVICE_DISCONNECTED' => 'The phone was offline too long; message failed',
            'NETWORK_ERROR' => 'Temporary network error; retried automatically',
        ] as $code => $meaning)
            <tr><td><code>{{ $code }}</code></td><td>{{ $meaning }}</td></tr>
        @endforeach
        </tbody>
    </table>
</div>

<div class="card">
    <h3>Webhooks</h3>
    <p style="color:var(--muted)">
        Subscribe under <a href="{{ route('customer.whatsapp.webhooks') }}">Webhooks</a> to
        <code>whatsapp.message.sent</code>, <code>.delivered</code>, <code>.read</code>, <code>.failed</code> and <code>.received</code>.
        Verify <code>X-SMS-Gateway-Signature</code> = HMAC-SHA256(raw body, webhook secret).
    </p>
</div>
@endsection
