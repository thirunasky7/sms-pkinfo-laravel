@extends('layouts.app')
@section('title', 'WhatsApp Messages')
@section('heading', 'WhatsApp Messages')
@section('nav')
    @include('admin.customer.whatsapp._nav')
@endsection
@section('content')
@include('admin.customer.whatsapp._tabs')

<div class="card">
    <h3>Send a message</h3>
    @if($accounts->isEmpty())
        <p style="color:var(--muted)">Connect a WhatsApp number under <a href="{{ route('customer.whatsapp.connection') }}">Connection</a> first.</p>
    @else
        <form method="POST" action="{{ route('customer.whatsapp.messages.send') }}">
            @csrf
            <div class="grid">
                <div><label>To (international format)</label><input name="to" required value="{{ old('to') }}" placeholder="+14155550123"></div>
                <div>
                    <label>From</label>
                    <select name="account_id">
                        <option value="">Default account</option>
                        @foreach($accounts as $a)
                            <option value="{{ $a->id }}" @selected(old('account_id') == $a->id)>{{ $a->name }} ({{ $a->connector_type }})</option>
                        @endforeach
                    </select>
                </div>
                <div><label>Schedule (optional)</label><input type="datetime-local" name="scheduled_at" value="{{ old('scheduled_at') }}"></div>
            </div>
            <label>Message</label>
            <textarea name="body" rows="3" required maxlength="4096">{{ old('body') }}</textarea>
            <p style="color:var(--muted);font-size:.8rem;margin-top:0">Cloud API: free-form text only reaches contacts who messaged you in the last 24 hours. Use an approved template otherwise.</p>
            <button class="btn" type="submit">Send</button>
        </form>
    @endif
</div>

<div class="card">
    <form method="GET" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:end">
        <div><label>Search</label><input name="q" value="{{ request('q') }}" placeholder="Number or message id"></div>
        <div>
            <label>Status</label>
            <select name="status">
                <option value="">All</option>
                @foreach(['queued','sending','sent','delivered','read','failed'] as $s)
                    <option value="{{ $s }}" @selected(request('status') === $s)>{{ $s }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label>Connector</label>
            <select name="connector">
                <option value="">All</option>
                <option value="device" @selected(request('connector') === 'device')>Device</option>
                <option value="cloud_api" @selected(request('connector') === 'cloud_api')>Cloud API</option>
            </select>
        </div>
        <div><label>From</label><input type="date" name="from" value="{{ request('from') }}"></div>
        <div><label>To</label><input type="date" name="to" value="{{ request('to') }}"></div>
        <div><button class="btn" type="submit" style="margin-bottom:.8rem">Filter</button></div>
    </form>
</div>

<div class="card" style="overflow-x:auto">
    <table>
        <thead><tr><th>Message ID</th><th>To</th><th>Type</th><th>Body</th><th>Account</th><th>Status</th><th>Retries</th><th>Last attempt</th><th>Created</th><th></th></tr></thead>
        <tbody>
        @forelse($messages as $m)
            <tr>
                <td><code style="font-size:.75rem">{{ $m->message_id }}</code></td>
                <td>+{{ $m->recipient }}</td>
                <td>{{ $m->message_type }}@if($m->template) <div style="color:var(--muted);font-size:.75rem">{{ $m->template->name }}</div>@endif</td>
                <td>{{ \Illuminate\Support\Str::limit($m->body, 60) }}</td>
                <td>{{ $m->account->name ?? '—' }}<div style="color:var(--muted);font-size:.75rem">{{ $m->connector_type }}</div></td>
                <td>
                    <span class="badge {{ in_array($m->status, ['delivered','read','sent']) ? 'ok' : ($m->status === 'failed' ? 'bad' : 'warn') }}">{{ $m->status }}</span>
                    @if($m->status === 'failed')
                        <div style="color:var(--muted);font-size:.75rem"><code>{{ $m->error_code }}</code> {{ \Illuminate\Support\Str::limit($m->failure_reason, 60) }}</div>
                    @endif
                    @if($m->scheduled_at && $m->status === 'queued')
                        <div style="color:var(--muted);font-size:.75rem">at {{ $m->scheduled_at }}</div>
                    @endif
                </td>
                <td>{{ $m->retry_count }}</td>
                <td>{{ $m->last_attempt_at ?? '—' }}</td>
                <td>{{ $m->created_at }}</td>
                <td>
                    @if($m->status === 'failed')
                        <form method="POST" action="{{ route('customer.whatsapp.messages.retry', $m) }}">
                            @csrf
                            <button class="btn btn-secondary" type="submit">Retry</button>
                        </form>
                    @endif
                </td>
            </tr>
        @empty
            <tr><td colspan="10" style="color:var(--muted)">No messages.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $messages->links() }}
</div>
@endsection
