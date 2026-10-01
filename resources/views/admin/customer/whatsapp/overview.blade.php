@extends('layouts.app')
@section('title', 'WhatsApp')
@section('heading', 'WhatsApp Overview')
@section('nav')
    @include('admin.customer.whatsapp._nav')
@endsection
@section('content')
@include('admin.customer.whatsapp._tabs')

<div class="card">
    <form method="GET" style="display:flex;gap:1rem;align-items:end;flex-wrap:wrap">
        <div><label>From</label><input type="date" name="from" value="{{ $from->toDateString() }}"></div>
        <div><label>To</label><input type="date" name="to" value="{{ $to->toDateString() }}"></div>
        <div>
            <label>Account</label>
            <select name="account">
                <option value="">All accounts</option>
                @foreach($accounts as $a)
                    <option value="{{ $a->id }}" @selected(request('account') == $a->id)>{{ $a->name }} ({{ $a->connector_type }})</option>
                @endforeach
            </select>
        </div>
        <div><button class="btn" type="submit" style="margin-bottom:.8rem">Apply</button></div>
    </form>
</div>

<div class="grid">
    @foreach([
        'total' => 'Total messages',
        'sent' => 'Sent',
        'delivered' => 'Delivered',
        'read' => 'Read',
        'failed' => 'Failed',
        'pending' => 'Pending',
        'received' => 'Received',
        'templates_used' => 'Template messages',
    ] as $key => $label)
        <div class="card stat"><h3>{{ number_format($stats[$key]) }}</h3><p>{{ $label }}</p></div>
    @endforeach
</div>

<div class="grid" style="grid-template-columns:repeat(auto-fit,minmax(320px,1fr))">
    <div class="card">
        <h3>Device vs Cloud API</h3>
        <table>
            <thead><tr><th>Connector</th><th>Direction</th><th>Messages</th></tr></thead>
            <tbody>
            @forelse($byConnector as $row)
                <tr><td>{{ $row->connector_type === 'cloud_api' ? 'Cloud API' : 'Device' }}</td><td>{{ $row->direction }}</td><td>{{ $row->total }}</td></tr>
            @empty
                <tr><td colspan="3" style="color:var(--muted)">No messages in this period.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card">
        <h3>By API key</h3>
        <table>
            <thead><tr><th>Source</th><th>Sent</th></tr></thead>
            <tbody>
            @forelse($byApiKey as $row)
                <tr><td>{{ $row['name'] }}</td><td>{{ $row['total'] }}</td></tr>
            @empty
                <tr><td colspan="2" style="color:var(--muted)">No outgoing messages.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card">
        <h3>Failure reasons</h3>
        <table>
            <thead><tr><th>Code</th><th>Example reason</th><th>Count</th></tr></thead>
            <tbody>
            @forelse($failureReasons as $row)
                <tr><td><code>{{ $row->error_code ?? 'UNKNOWN' }}</code></td><td>{{ \Illuminate\Support\Str::limit($row->reason, 70) }}</td><td>{{ $row->total }}</td></tr>
            @empty
                <tr><td colspan="3" style="color:var(--muted)">No failures.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card">
        <h3>Templates used</h3>
        <table>
            <thead><tr><th>Template</th><th>Sent</th></tr></thead>
            <tbody>
            @forelse($topTemplates as $row)
                <tr><td>{{ $row['name'] }}</td><td>{{ $row['total'] }}</td></tr>
            @empty
                <tr><td colspan="2" style="color:var(--muted)">No template messages.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h3>By date</h3>
    <table>
        <thead><tr><th>Date</th><th>Outgoing</th><th>Incoming</th></tr></thead>
        <tbody>
        @forelse($byDate as $day => $row)
            <tr><td>{{ $day }}</td><td>{{ $row['outgoing'] }}</td><td>{{ $row['incoming'] }}</td></tr>
        @empty
            <tr><td colspan="3" style="color:var(--muted)">No activity.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
