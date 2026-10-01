@extends('layouts.app')
@section('title', 'Automation Rules')
@section('heading', 'Automation Rules')
@section('nav')
    @include('admin.customer.whatsapp._nav')
@endsection
@section('content')
@include('admin.customer.whatsapp._tabs')

@php($labels = [
    'auto_reply' => 'Auto-reply to sender',
    'forward_webhook' => 'Send to webhook / CRM',
    'forward_whatsapp' => 'Forward via WhatsApp',
    'forward_sms' => 'Forward via SMS',
    'opt_out' => 'Opt the sender out',
    'opt_in' => 'Opt the sender back in',
    'sms_fallback' => 'Fall back to SMS',
    'route_whatsapp' => 'Retry from another WhatsApp account',
])

<div class="card">
    <h3>Rules</h3>
    <p style="color:var(--muted);font-size:.85rem">Rules run in priority order (lowest first) for incoming messages and permanently failed WhatsApp messages. Rules apply to both SMS and WhatsApp depending on the channel.</p>
    <table>
        <thead><tr><th>#</th><th>Name</th><th>When</th><th>Match</th><th>Action</th><th>Triggered</th><th>Status</th><th></th></tr></thead>
        <tbody>
        @forelse($rules as $r)
            <tr>
                <td>{{ $r->priority }}</td>
                <td>{{ $r->name }} @if($r->stop_processing)<span class="badge">stop</span>@endif</td>
                <td>{{ $r->trigger }} · {{ $r->channel }}</td>
                <td>{{ $r->match_type }}@if($r->keywords)<div style="color:var(--muted);font-size:.75rem">{{ implode(', ', $r->keywords) }}</div>@endif</td>
                <td>
                    {{ $labels[$r->action] ?? $r->action }}
                    <div style="color:var(--muted);font-size:.75rem">
                        {{ \Illuminate\Support\Str::limit($r->action_config['message'] ?? $r->action_config['to'] ?? $r->action_config['url'] ?? '', 50) }}
                    </div>
                </td>
                <td>{{ $r->trigger_count }}<div style="color:var(--muted);font-size:.75rem">{{ $r->last_triggered_at?->diffForHumans() }}</div></td>
                <td><span class="badge {{ $r->is_active ? 'ok' : 'warn' }}">{{ $r->is_active ? 'active' : 'paused' }}</span></td>
                <td style="white-space:nowrap">
                    <form method="POST" action="{{ route('customer.whatsapp.rules.toggle', $r) }}" style="display:inline">
                        @csrf @method('PATCH')
                        <button class="btn btn-secondary" type="submit">{{ $r->is_active ? 'Pause' : 'Enable' }}</button>
                    </form>
                    <form method="POST" action="{{ route('customer.whatsapp.rules.destroy', $r) }}" style="display:inline" onsubmit="return confirm('Delete this rule?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-danger" type="submit">Delete</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="8" style="color:var(--muted)">No rules yet. Common start: a <b>STOP</b> keyword rule with the "Opt the sender out" action.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

<div class="card">
    <h3>New rule</h3>
    <form method="POST" action="{{ route('customer.whatsapp.rules.store') }}">
        @csrf
        <div class="grid">
            <div><label>Name</label><input name="name" required value="{{ old('name') }}" placeholder="STOP opt-out"></div>
            <div>
                <label>Trigger</label>
                <select name="trigger">
                    <option value="incoming" @selected(old('trigger') === 'incoming')>Incoming message</option>
                    <option value="failed" @selected(old('trigger') === 'failed')>WhatsApp message failed</option>
                </select>
            </div>
            <div>
                <label>Channel</label>
                <select name="channel">
                    @foreach(['whatsapp' => 'WhatsApp', 'sms' => 'SMS', 'any' => 'Both'] as $v => $l)
                        <option value="{{ $v }}" @selected(old('channel', 'whatsapp') === $v)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div><label>Priority</label><input type="number" name="priority" min="1" max="1000" value="{{ old('priority', 100) }}"></div>
        </div>
        <div class="grid">
            <div>
                <label>Match (incoming only)</label>
                <select name="match_type">
                    @foreach(['keyword' => 'First word is a keyword', 'exact' => 'Whole message equals', 'contains' => 'Message contains', 'regex' => 'Regular expression', 'any' => 'Any message'] as $v => $l)
                        <option value="{{ $v }}" @selected(old('match_type', 'keyword') === $v)>{{ $l }}</option>
                    @endforeach
                </select>
            </div>
            <div style="grid-column:span 2"><label>Keywords / patterns (comma separated)</label><input name="keywords" value="{{ old('keywords') }}" placeholder="STOP, UNSUBSCRIBE"></div>
        </div>
        <div class="grid">
            <div>
                <label>Action</label>
                <select name="action">
                    @foreach($actions as $action => $triggers)
                        <option value="{{ $action }}" @selected(old('action') === $action)>{{ $labels[$action] }} ({{ implode('/', $triggers) }})</option>
                    @endforeach
                </select>
            </div>
            <div><label>Number (forward actions)</label><input name="to" value="{{ old('to') }}" placeholder="+14155550123"></div>
            <div><label>Webhook URL (HTTPS)</label><input name="url" value="{{ old('url') }}" placeholder="https://crm.example.com/hook"></div>
        </div>
        <label>Message (auto-reply, opt-out confirmation, or SMS fallback text — empty uses the original text)</label>
        <textarea name="message" rows="2" maxlength="1600">{{ old('message') }}</textarea>
        <div class="grid">
            <div>
                <label>Retry from account (route action)</label>
                <select name="account_id">
                    <option value="">Any other account</option>
                    @foreach($accounts as $a)
                        <option value="{{ $a->id }}">{{ $a->name }} ({{ $a->connector_type }})</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label>Opt-out scope</label>
                <select name="scope">
                    <option value="">Same channel</option>
                    <option value="whatsapp">WhatsApp</option>
                    <option value="sms">SMS</option>
                    <option value="all">All channels</option>
                </select>
            </div>
            <div>
                <label>Stop processing further rules</label>
                <select name="stop_processing"><option value="0">No</option><option value="1" @selected(old('stop_processing'))>Yes</option></select>
            </div>
        </div>
        <button class="btn" type="submit">Create rule</button>
    </form>
</div>

<div class="card">
    <h3>Opted-out contacts</h3>
    <table>
        <thead><tr><th>Phone</th><th>Channel</th><th>Source</th><th>When</th><th></th></tr></thead>
        <tbody>
        @forelse($optOuts as $o)
            <tr>
                <td>{{ $o->phone }}</td><td>{{ $o->channel }}</td><td>{{ $o->source }}</td><td>{{ $o->created_at->diffForHumans() }}</td>
                <td>
                    <form method="POST" action="{{ route('customer.whatsapp.optouts.destroy', $o) }}">
                        @csrf @method('DELETE')
                        <button class="btn btn-secondary" type="submit">Opt back in</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="5" style="color:var(--muted)">No opted-out contacts.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
