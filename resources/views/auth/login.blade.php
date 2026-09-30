@extends('layouts.app')

@section('title', 'Login')
@section('content')
<div class="login-wrap">
    <div class="card">
        <h1 style="margin-top:0;">SMS Gateway</h1>
        <p style="color:var(--muted);">Sign in to Super Admin or Customer Admin</p>
        @if ($errors->any())
            <div class="error">{{ $errors->first() }}</div>
        @endif
        <form method="POST" action="{{ route('login') }}">
            @csrf
            <label>Email</label>
            <input type="email" name="email" value="{{ old('email') }}" required autofocus>
            <label>Password</label>
            <input type="password" name="password" required>
            <label><input type="checkbox" name="remember" style="width:auto"> Remember me</label>
            <div style="margin-top:1rem;">
                <button class="btn" type="submit">Login</button>
            </div>
        </form>
        <p style="margin-top:1.2rem;font-size:0.85rem;color:var(--muted);">
            Demo: admin@sms-gateway.test / password<br>
            Demo: customer@sms-gateway.test / password
        </p>
    </div>
</div>
@endsection
