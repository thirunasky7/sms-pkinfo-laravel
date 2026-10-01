@extends('layouts.app')
@section('title', 'API')
@section('heading', 'API Integration')
@section('nav')
    <a href="{{ route('customer.dashboard') }}">Dashboard</a>
    <a href="{{ route('customer.devices') }}">Devices</a>
    <a href="{{ route('customer.messages') }}">Messages</a>
    <a href="{{ route('customer.compose') }}">Send SMS</a>
    <a href="{{ route('customer.whatsapp.overview') }}">WhatsApp</a>
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
@if($creds = session('new_api_credentials'))
<div class="card" style="border-color:#22c55e">
    <h3 style="margin-top:0">Your API credentials — {{ $creds['name'] }}</h3>
    <p style="color:#fde68a;font-size:.9rem;margin-top:0">
        Keep the secret private — anyone with both values can send messages from your account.
        You can view it again any time in the table below.
    </p>
    <label>API Key (header <code>X-API-Key</code>)</label>
    <div style="display:flex;gap:.5rem;align-items:center">
        <input id="cred-key" readonly value="{{ $creds['key'] }}" style="font-family:monospace">
        <button class="btn btn-secondary" type="button" onclick="copyField('cred-key', this)" style="margin-bottom:.45rem">Copy</button>
    </div>
    <label>API Secret (header <code>X-API-Secret</code>)</label>
    <div style="display:flex;gap:.5rem;align-items:center">
        <input id="cred-secret" readonly value="{{ $creds['secret'] }}" style="font-family:monospace">
        <button class="btn btn-secondary" type="button" onclick="copyField('cred-secret', this)" style="margin-bottom:.45rem">Copy</button>
    </div>
    <label>Example request</label>
    <pre id="cred-curl" style="background:var(--bg);padding:1rem;border-radius:8px;overflow:auto;margin:.35rem 0">curl -X POST {{ url('/api/v1/messages/send') }} \
  -H "X-API-Key: {{ $creds['key'] }}" \
  -H "X-API-Secret: {{ $creds['secret'] }}" \
  -H "Content-Type: application/json" \
  -d '{"to": "+919800000001", "body": "Hello from the API"}'</pre>
    <button class="btn btn-secondary" type="button" onclick="copyText(document.getElementById('cred-curl').innerText, this)">Copy example</button>
</div>
@endif

<div class="card">
    <h3>API Keys</h3>
    <form method="POST" action="{{ route('customer.api.keys') }}" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:end;margin-bottom:1rem">
        @csrf
        <div style="min-width:220px"><label>Key name</label><input name="name" placeholder="e.g. Website, CRM" maxlength="100"></div>
        <div style="min-width:220px">
            <label>Access</label>
            <select name="access">
                <option value="full">Full access (SMS + WhatsApp)</option>
                <option value="sms">SMS only</option>
                <option value="whatsapp">WhatsApp only</option>
            </select>
        </div>
        <div><button class="btn" type="submit" style="margin-bottom:.8rem">Generate key</button></div>
    </form>
    <table>
        <thead><tr><th>Name</th><th>API Key</th><th>Secret</th><th>Access</th><th>Rate/min</th><th>Last used</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse($keys as $key)
            <tr>
                <td>{{ $key->name }}</td>
                <td style="white-space:nowrap">
                    <code id="key-{{ $key->id }}">{{ $key->key }}</code>
                    <button class="btn btn-secondary" type="button" style="padding:.2rem .5rem;font-size:.75rem" onclick="copyText('{{ $key->key }}', this)">Copy</button>
                </td>
                <td style="white-space:nowrap">
                    @if($key->revoked_at)
                        <span style="color:var(--muted)">—</span>
                    @elseif($key->hasViewableSecret())
                        <code id="secret-{{ $key->id }}" data-masked="1">••••••••••••</code>
                        <button class="btn btn-secondary" type="button" style="padding:.2rem .5rem;font-size:.75rem"
                                onclick="toggleSecret({{ $key->id }}, this)">Show</button>
                        <button class="btn btn-secondary" type="button" style="padding:.2rem .5rem;font-size:.75rem"
                                onclick="copySecret({{ $key->id }}, this)">Copy</button>
                    @else
                        <span style="color:var(--muted);font-size:.8rem">Regenerate to view</span>
                    @endif
                </td>
                <td>
                    @if(empty($key->scopes)) Full
                    @elseif(collect($key->scopes)->every(fn ($s) => str_starts_with($s, 'whatsapp:'))) WhatsApp
                    @elseif(collect($key->scopes)->every(fn ($s) => str_starts_with($s, 'messages:'))) SMS
                    @else {{ implode(', ', $key->scopes) }}
                    @endif
                </td>
                <td>{{ $key->rate_limit }}</td>
                <td>{{ $key->last_used_at?->diffForHumans() ?? 'never' }}</td>
                <td><span class="badge {{ $key->revoked_at ? 'bad' : 'ok' }}">{{ $key->revoked_at ? 'revoked' : 'active' }}</span></td>
                <td style="white-space:nowrap">
                    @unless($key->revoked_at)
                        <form method="POST" action="{{ route('customer.api.keys.regenerate', $key) }}" style="display:inline"
                              onsubmit="return confirm('Generate a new secret for “{{ $key->name }}”? The current secret stops working immediately.')">
                            @csrf
                            <button class="btn btn-secondary" type="submit">Regenerate secret</button>
                        </form>
                        <form method="POST" action="{{ route('customer.api.keys.revoke', $key) }}" style="display:inline"
                              onsubmit="return confirm('Revoke “{{ $key->name }}”? Apps using it will stop working.')">
                            @csrf @method('DELETE')
                            <button class="btn btn-danger" type="submit">Revoke</button>
                        </form>
                    @endunless
                </td>
            </tr>
        @empty
            <tr><td colspan="8" style="color:var(--muted)">No API keys yet. Generate one above to start integrating.</td></tr>
        @endforelse
        </tbody>
    </table>
    <p style="color:var(--muted);font-size:.85rem;margin-top:1rem">
        Send both headers on every request: <code>X-API-Key</code> and <code>X-API-Secret</code>.
        Secrets are stored encrypted; each view is recorded in the audit log. Regenerate a secret if it was exposed.
    </p>
</div>

<script>
    function copyText(text, btn) {
        const done = () => { const t = btn.innerText; btn.innerText = 'Copied'; setTimeout(() => btn.innerText = t, 1500); };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(done);
        } else {
            const ta = document.createElement('textarea');
            ta.value = text; document.body.appendChild(ta); ta.select();
            document.execCommand('copy'); ta.remove(); done();
        }
    }
    function copyField(id, btn) { copyText(document.getElementById(id).value, btn); }

    const secretCache = {};
    async function fetchSecret(id) {
        if (secretCache[id]) return secretCache[id];
        const res = await fetch(@json(url('/customer/api/keys')) + '/' + id + '/secret', {
            method: 'POST',
            headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': @json(csrf_token()) },
        });
        const body = await res.json();
        if (!res.ok) { alert(body.message || 'Could not load the secret.'); return null; }
        return (secretCache[id] = body.secret);
    }
    async function toggleSecret(id, btn) {
        const el = document.getElementById('secret-' + id);
        if (el.dataset.masked === '1') {
            const secret = await fetchSecret(id);
            if (!secret) return;
            el.textContent = secret; el.dataset.masked = '0'; btn.innerText = 'Hide';
        } else {
            el.textContent = '••••••••••••'; el.dataset.masked = '1'; btn.innerText = 'Show';
        }
    }
    async function copySecret(id, btn) {
        const secret = await fetchSecret(id);
        if (secret) copyText(secret, btn);
    }
</script>
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
