@extends('layouts.app')
@section('title', 'Send SMS')
@section('heading', 'Send SMS')
@section('nav')
    <a href="{{ route('customer.dashboard') }}">Dashboard</a>
    <a href="{{ route('customer.devices') }}">Devices</a>
    <a href="{{ route('customer.messages') }}">Messages</a>
    <a href="{{ route('customer.compose') }}" class="active">Send SMS</a>
    <a href="{{ route('customer.api') }}">API & Webhooks</a>
    <a href="{{ route('customer.tickets') }}">Support</a>
@endsection
@section('content')
<div class="grid">
    <div class="card">
        <h3>Single SMS</h3>
        <form method="POST" action="{{ route('customer.compose.send') }}">
            @csrf
            <label>To</label>
            <input name="to" required placeholder="+9198...">
            <label>Body</label>
            <textarea name="body" rows="4" required></textarea>
            <label>Device (optional)</label>
            <select name="device_id">
                <option value="">Auto</option>
                @foreach($devices as $device)
                    <option value="{{ $device->id }}">{{ $device->name }} ({{ $device->status }})</option>
                @endforeach
            </select>
            <label>Schedule (optional)</label>
            <input type="datetime-local" name="scheduled_at">
            <button class="btn" type="submit">Queue SMS</button>
        </form>
    </div>
    <div class="card">
        <h3>Bulk CSV</h3>
        <p style="color:var(--muted);font-size:0.9rem">CSV columns: <code>to,body</code> (no header required but first row treated as header if present — include header row).</p>
        <form method="POST" action="{{ route('customer.bulk') }}" enctype="multipart/form-data">
            @csrf
            <label>CSV file</label>
            <input type="file" name="csv" accept=".csv,text/csv" required>
            <label>Device</label>
            <select name="device_id">
                <option value="">Auto</option>
                @foreach($devices as $device)
                    <option value="{{ $device->id }}">{{ $device->name }}</option>
                @endforeach
            </select>
            <button class="btn" type="submit">Upload & queue</button>
        </form>
    </div>
</div>
@endsection
