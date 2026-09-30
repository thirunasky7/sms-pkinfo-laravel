@extends('layouts.app')
@section('title', 'Support')
@section('heading', 'Support Tickets')
@section('nav')
    <a href="{{ route('customer.dashboard') }}">Dashboard</a>
    <a href="{{ route('customer.devices') }}">Devices</a>
    <a href="{{ route('customer.messages') }}">Messages</a>
    <a href="{{ route('customer.compose') }}">Send SMS</a>
    <a href="{{ route('customer.api') }}">API & Webhooks</a>
    <a href="{{ route('customer.tickets') }}" class="active">Support</a>
@endsection
@section('content')
<div class="card">
    <form method="POST" action="{{ route('customer.tickets.store') }}">
        @csrf
        <label>Subject</label>
        <input name="subject" required>
        <label>Priority</label>
        <select name="priority">
            <option value="normal">normal</option>
            <option value="low">low</option>
            <option value="high">high</option>
            <option value="urgent">urgent</option>
        </select>
        <label>Details</label>
        <textarea name="body" rows="4" required></textarea>
        <button class="btn" type="submit">Submit ticket</button>
    </form>
</div>
<div class="card">
    <table>
        <thead><tr><th>Subject</th><th>Priority</th><th>Status</th><th>Created</th></tr></thead>
        <tbody>
        @foreach($tickets as $t)
            <tr>
                <td>{{ $t->subject }}</td>
                <td>{{ $t->priority }}</td>
                <td>{{ $t->status }}</td>
                <td>{{ $t->created_at }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
