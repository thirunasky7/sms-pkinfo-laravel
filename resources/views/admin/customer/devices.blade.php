@extends('layouts.app')
@section('title', 'Devices')
@section('heading', 'Your Devices')
@section('nav')
    <a href="{{ route('customer.dashboard') }}">Dashboard</a>
    <a href="{{ route('customer.devices') }}" class="active">Devices</a>
    <a href="{{ route('customer.messages') }}">Messages</a>
    <a href="{{ route('customer.compose') }}">Send SMS</a>
    <a href="{{ route('customer.api') }}">API & Webhooks</a>
    <a href="{{ route('customer.tickets') }}">Support</a>
@endsection
@section('content')
<div class="card">
    <form method="POST" action="{{ route('customer.devices.pairing') }}">
        @csrf
        <button class="btn" type="submit">Generate pairing QR</button>
    </form>
</div>
<div class="card">
    <table>
        <thead><tr><th>Name</th><th>SIM</th><th>Model</th><th>Status</th><th>Last sync</th><th>Actions</th></tr></thead>
        <tbody>
        @forelse($devices as $device)
            <tr>
                <td>
                    <form method="POST" action="{{ route('customer.devices.rename', $device) }}">
                        @csrf @method('PATCH')
                        <input name="name" value="{{ $device->name }}" style="margin:0;width:70%;display:inline-block">
                        <button class="btn btn-secondary" type="submit">Save</button>
                    </form>
                </td>
                <td>{{ $device->sim_number }}</td>
                <td>{{ $device->model }}</td>
                <td><span class="badge ok">{{ $device->status }}</span></td>
                <td>{{ $device->last_sync_at }}</td>
                <td>
                    <form method="POST" action="{{ route('customer.devices.remove', $device) }}" onsubmit="return confirm('Remove device?')">
                        @csrf @method('DELETE')
                        <button class="btn btn-danger" type="submit">Remove</button>
                    </form>
                </td>
            </tr>
        @empty
            <tr><td colspan="6">No devices yet. Generate a pairing QR and scan it in the Android app.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
