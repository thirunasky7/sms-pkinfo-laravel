@extends('layouts.app')
@section('title', 'Customers')
@section('heading', 'Customer Management')
@section('nav')
    <a href="{{ route('super.dashboard') }}">Dashboard</a>
    <a href="{{ route('super.users') }}" class="active">Customers</a>
    <a href="{{ route('super.devices') }}">Devices</a>
    <a href="{{ route('super.messages') }}">Messages</a>
    <a href="{{ route('super.plans') }}">Plans</a>
@endsection
@section('content')
<div class="card">
    <h3>Create customer</h3>
    <form method="POST" action="{{ route('super.users.store') }}">
        @csrf
        <div class="grid">
            <div><label>Name</label><input name="name" required></div>
            <div><label>Email</label><input type="email" name="email" required></div>
            <div><label>Password</label><input type="password" name="password" required></div>
            <div><label>Company</label><input name="company"></div>
            <div>
                <label>Plan</label>
                <select name="plan_id">
                    <option value="">None</option>
                    @foreach($plans as $plan)
                        <option value="{{ $plan->id }}">{{ $plan->name }} ({{ $plan->sms_limit }} SMS)</option>
                    @endforeach
                </select>
            </div>
        </div>
        <button class="btn" type="submit">Create</button>
    </form>
</div>
<div class="card">
    <table>
        <thead><tr><th>Name</th><th>Email</th><th>Status</th><th>Plan</th><th>Action</th></tr></thead>
        <tbody>
        @foreach($users as $user)
            <tr>
                <td>{{ $user->name }}</td>
                <td>{{ $user->email }}</td>
                <td><span class="badge {{ $user->status === 'active' ? 'ok' : 'bad' }}">{{ $user->status }}</span></td>
                <td>{{ $user->activeSubscription->plan->name ?? '-' }}</td>
                <td>
                    <form method="POST" action="{{ route('super.users.status', $user) }}">
                        @csrf @method('PATCH')
                        <select name="status" onchange="this.form.submit()">
                            <option value="active" @selected($user->status==='active')>active</option>
                            <option value="suspended" @selected($user->status==='suspended')>suspended</option>
                        </select>
                    </form>
                </td>
            </tr>
        @endforeach
        </tbody>
    </table>
    {{ $users->links() }}
</div>
@endsection
