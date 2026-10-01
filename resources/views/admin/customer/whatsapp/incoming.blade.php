@extends('layouts.app')
@section('title', 'WhatsApp Incoming')
@section('heading', 'Incoming WhatsApp Messages')
@section('nav')
    @include('admin.customer.whatsapp._nav')
@endsection
@section('content')
@include('admin.customer.whatsapp._tabs')

<div class="card">
    <form method="GET" style="display:flex;gap:1rem;flex-wrap:wrap;align-items:end">
        <div><label>Search</label><input name="q" value="{{ request('q') }}" placeholder="Sender number"></div>
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
        <thead><tr><th>From</th><th>To (account)</th><th>Type</th><th>Message</th><th>Connector</th><th>Received</th></tr></thead>
        <tbody>
        @forelse($messages as $m)
            <tr>
                <td>{{ str_starts_with((string) $m->sender, '+') || ! ctype_digit((string) $m->sender) ? $m->sender : '+'.$m->sender }}</td>
                <td>{{ $m->account->name ?? '—' }}</td>
                <td>{{ $m->message_type }}</td>
                <td>
                    {{ \Illuminate\Support\Str::limit($m->body, 120) }}
                    @if($m->media_url)<div><a href="{{ $m->media_url }}" target="_blank" rel="noopener">media</a></div>@endif
                </td>
                <td>{{ $m->connector_type }}</td>
                <td>{{ $m->received_at ?? $m->created_at }}</td>
            </tr>
        @empty
            <tr><td colspan="6" style="color:var(--muted)">No incoming messages.</td></tr>
        @endforelse
        </tbody>
    </table>
    {{ $messages->links() }}
</div>
@endsection
