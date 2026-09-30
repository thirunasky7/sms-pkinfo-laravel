@extends('layouts.app')
@section('title', 'Plans')
@section('heading', 'Subscription Plans')
@section('nav')
    <a href="{{ route('super.dashboard') }}">Dashboard</a>
    <a href="{{ route('super.users') }}">Customers</a>
    <a href="{{ route('super.devices') }}">Devices</a>
    <a href="{{ route('super.messages') }}">Messages</a>
    <a href="{{ route('super.plans') }}" class="active">Plans</a>
@endsection
@section('content')
<div class="card">
    <h3>Create plan</h3>
    <form method="POST" action="{{ route('super.plans.store') }}">
        @csrf
        <div class="grid">
            <div><label>Name</label><input name="name" required></div>
            <div><label>SMS limit</label><input type="number" name="sms_limit" required></div>
            <div><label>Price</label><input type="number" step="0.01" name="price" required></div>
            <div><label>Duration (days)</label><input type="number" name="duration_days" value="30" required></div>
        </div>
        <button class="btn" type="submit">Save plan</button>
    </form>
</div>
<div class="card">
    <table>
        <thead><tr><th>Name</th><th>Limit</th><th>Price</th><th>Days</th><th>Active</th></tr></thead>
        <tbody>
        @foreach($plans as $plan)
            <tr>
                <td>{{ $plan->name }}</td>
                <td>{{ $plan->sms_limit }}</td>
                <td>{{ $plan->currency }} {{ $plan->price }}</td>
                <td>{{ $plan->duration_days }}</td>
                <td>{{ $plan->is_active ? 'yes' : 'no' }}</td>
            </tr>
        @endforeach
        </tbody>
    </table>
</div>
@endsection
