@extends('layouts.app')
@section('title', 'WhatsApp Connection')
@section('heading', 'WhatsApp Connection')
@section('nav')
    @include('admin.customer.whatsapp._nav')
@endsection
@section('content')
@include('admin.customer.whatsapp._tabs')

<div class="card">
    <h3>Connected numbers</h3>
    <table>
        <thead><tr><th>Name</th><th>Connector</th><th>Number</th><th>Status</th><th>Last seen</th><th>Token</th><th></th></tr></thead>
        <tbody>
        @forelse($accounts as $a)
            <tr>
                <td>{{ $a->name }} @if($a->is_default)<span class="badge ok">default</span>@endif</td>
                <td>{{ $a->isCloudApi() ? 'Cloud API' : 'Device ('.($a->device->name ?? 'unlinked').')' }}</td>
                <td>{{ $a->phone_number ? '+'.$a->phone_number : '—' }}</td>
                <td>
                    <span class="badge {{ $a->status === 'connected' ? 'ok' : ($a->status === 'error' ? 'bad' : 'warn') }}">{{ $a->status }}</span>
                    @if($a->last_error)<div style="color:var(--muted);font-size:.8rem">{{ \Illuminate\Support\Str::limit($a->last_error, 80) }}</div>@endif
                </td>
                <td>{{ $a->last_seen_at?->diffForHumans() ?? '—' }}</td>
                <td>
                    @if($a->isCloudApi())
                        {{ $a->hasToken() ? 'configured' : 'missing' }}
                        @if($a->token_expires_at) <div style="color:var(--muted);font-size:.8rem">expires {{ $a->token_expires_at->toDateString() }}</div>@endif
                    @else
                        n/a
                    @endif
                </td>
                <td style="white-space:nowrap">
                    @unless($a->is_default)
                        <form method="POST" action="{{ route('customer.whatsapp.accounts.default', $a) }}" style="display:inline">
                            @csrf
                            <button class="btn btn-secondary" type="submit">Make default</button>
                        </form>
                    @endunless
                    <form method="POST" action="{{ route('customer.whatsapp.accounts.revoke', $a) }}" style="display:inline"
                          onsubmit="return confirm('Disconnect this number and revoke its credentials? Pending messages will fail.')">
                        @csrf @method('DELETE')
                        <button class="btn btn-danger" type="submit">Disconnect</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="7" style="color:var(--muted)">No WhatsApp numbers connected yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(360px,1fr))">
    <div class="card">
        <h3>Option 1 — Device (your phone's WhatsApp)</h3>
        <ol style="color:var(--muted);line-height:1.7">
            <li>Pair the Android gateway app under <a href="{{ route('customer.devices') }}">Devices</a> (same app used for SMS).</li>
            <li>In the app open <b>Settings → WhatsApp gateway</b>.</li>
            <li>Enable the <b>accessibility service</b> and <b>notification access</b> when prompted.</li>
            <li>Enter the phone's WhatsApp number and tap <b>Connect</b>.</li>
        </ol>
        <p style="color:var(--muted);font-size:.85rem">
            Device sending automates the WhatsApp app on the phone. It is best suited for low volumes; WhatsApp may
            restrict numbers that send automated or bulk messages. Use the Cloud API for business messaging at scale.
        </p>
        <p style="font-size:.85rem">Paired devices: {{ $devices->count() }}</p>
    </div>

    <div class="card">
        <h3>Option 2 — Meta WhatsApp Cloud API</h3>
        @if($metaConfigured && $metaConfigId)
            <p style="color:var(--muted)">Connect a business number through Meta's signup. Meta decides eligibility and verification requirements during this flow.</p>
            <label>Two-step verification PIN (optional, 6 digits — used to register the number)</label>
            <input id="es-pin" inputmode="numeric" maxlength="6" placeholder="123456">
            <button class="btn" type="button" id="es-button">Connect with Facebook</button>
            <p id="es-status" style="color:var(--muted);font-size:.85rem"></p>
        @else
            <p class="error" style="font-size:.85rem">Embedded Signup is not configured. Set <code>META_APP_ID</code>, <code>META_APP_SECRET</code> and <code>META_EMBEDDED_SIGNUP_CONFIG_ID</code>.</p>
        @endif

        <details style="margin-top:1rem">
            <summary style="cursor:pointer">Connect manually with existing credentials</summary>
            <form method="POST" action="{{ route('customer.whatsapp.cloud.manual') }}" style="margin-top:.75rem" autocomplete="off">
                @csrf
                <label>Name</label>
                <input name="name" value="{{ old('name') }}" placeholder="Support line">
                <label>WhatsApp Business Account ID</label>
                <input name="waba_id" required value="{{ old('waba_id') }}">
                <label>Phone number ID</label>
                <input name="phone_number_id" required value="{{ old('phone_number_id') }}">
                <label>Access token (system user token recommended)</label>
                <input name="access_token" type="password" required autocomplete="new-password">
                <label>Registration PIN (optional)</label>
                <input name="pin" inputmode="numeric" maxlength="6">
                <button class="btn" type="submit">Connect</button>
            </form>
        </details>

        <p style="color:var(--muted);font-size:.85rem;margin-top:1rem">
            Meta webhook callback URL: <code>{{ $webhookUrl }}</code><br>
            Tokens are encrypted at rest and never shown again after saving.
        </p>
    </div>
</div>

@if($metaConfigured && $metaConfigId)
<script>
    window.fbAsyncInit = function () {
        FB.init({ appId: @json($metaAppId), autoLogAppEvents: true, xfbml: false, version: @json($graphVersion) });
    };

    (function () {
        const status = document.getElementById('es-status');
        let session = {};

        window.addEventListener('message', function (event) {
            if (!/^https:\/\/([a-z0-9-]+\.)?facebook\.com$/.test(event.origin)) return;
            try {
                const data = JSON.parse(event.data);
                if (data.type === 'WA_EMBEDDED_SIGNUP') {
                    if (data.event === 'FINISH' || data.event === 'FINISH_ONLY_WABA') {
                        session = data.data || {};
                    } else if (data.event === 'CANCEL') {
                        status.textContent = 'Signup cancelled' + (data.data && data.data.current_step ? ' at ' + data.data.current_step : '') + '.';
                    } else if (data.event === 'ERROR') {
                        status.textContent = 'Meta reported an error: ' + ((data.data && data.data.error_message) || 'unknown');
                    }
                }
            } catch (e) { /* non-JSON messages from the SDK */ }
        });

        document.getElementById('es-button').addEventListener('click', function () {
            status.textContent = 'Opening Meta signup…';
            FB.login(function (response) {
                const code = response.authResponse && response.authResponse.code;
                if (!code) { status.textContent = 'Signup was not completed.'; return; }
                // The session message can arrive just after the login callback.
                setTimeout(function () { finish(code); }, 800);
            }, {
                config_id: @json($metaConfigId),
                response_type: 'code',
                override_default_response_type: true,
                extras: { setup: {}, sessionInfoVersion: '3' }
            });
        });

        function finish(code) {
            if (!session.waba_id || !session.phone_number_id) {
                status.textContent = 'Meta did not return a phone number. Please finish adding a number in the signup flow.';
                return;
            }
            status.textContent = 'Finishing connection…';
            fetch(@json(route('customer.whatsapp.cloud.embedded')), {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': @json(csrf_token())
                },
                body: JSON.stringify({
                    code: code,
                    waba_id: session.waba_id,
                    phone_number_id: session.phone_number_id,
                    business_id: session.business_id || null,
                    pin: document.getElementById('es-pin').value || null
                })
            }).then(r => r.json().then(body => ({ ok: r.ok, body })))
              .then(({ ok, body }) => {
                  if (ok) { window.location.reload(); }
                  else { status.textContent = body.message || 'Connection failed.'; }
              })
              .catch(() => { status.textContent = 'Network error, please retry.'; });
        }
    })();
</script>
<script async defer crossorigin="anonymous" src="https://connect.facebook.net/en_US/sdk.js"></script>
@endif
@endsection
