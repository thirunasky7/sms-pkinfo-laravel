<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'SMS Gateway')</title>
    <style>
        :root {
            --bg: #0f172a;
            --panel: #1e293b;
            --panel-2: #334155;
            --text: #e2e8f0;
            --muted: #94a3b8;
            --accent: #38bdf8;
            --accent-2: #22c55e;
            --danger: #f87171;
            --border: #475569;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: "Segoe UI", system-ui, sans-serif;
            background: radial-gradient(1200px 600px at 10% -10%, #1d4ed8 0%, transparent 50%),
                        radial-gradient(900px 500px at 100% 0%, #0ea5e9 0%, transparent 45%),
                        var(--bg);
            color: var(--text);
            min-height: 100vh;
        }
        a { color: var(--accent); text-decoration: none; }
        .shell { display: grid; grid-template-columns: 240px 1fr; min-height: 100vh; }
        .sidebar {
            background: rgba(15, 23, 42, 0.9);
            border-right: 1px solid var(--border);
            padding: 1.5rem 1rem;
        }
        .brand { font-size: 1.1rem; font-weight: 700; margin-bottom: 1.5rem; letter-spacing: 0.02em; }
        .nav a {
            display: block;
            padding: 0.65rem 0.8rem;
            border-radius: 0.5rem;
            color: var(--muted);
            margin-bottom: 0.25rem;
        }
        .nav a:hover, .nav a.active { background: var(--panel); color: var(--text); }
        .main { padding: 1.5rem 2rem; }
        .top {
            display: flex; justify-content: space-between; align-items: center;
            margin-bottom: 1.5rem;
        }
        .card {
            background: rgba(30, 41, 59, 0.85);
            border: 1px solid var(--border);
            border-radius: 0.85rem;
            padding: 1.25rem;
            margin-bottom: 1rem;
        }
        .grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); }
        .stat h3 { margin: 0; font-size: 1.8rem; }
        .stat p { margin: 0.35rem 0 0; color: var(--muted); font-size: 0.9rem; }
        table { width: 100%; border-collapse: collapse; }
        th, td { text-align: left; padding: 0.7rem 0.5rem; border-bottom: 1px solid var(--panel-2); font-size: 0.92rem; }
        th { color: var(--muted); font-weight: 600; }
        .btn {
            display: inline-block;
            background: var(--accent);
            color: #0f172a;
            border: none;
            border-radius: 0.45rem;
            padding: 0.55rem 0.9rem;
            font-weight: 600;
            cursor: pointer;
        }
        .btn-secondary { background: var(--panel-2); color: var(--text); }
        .btn-danger { background: var(--danger); color: #111; }
        input, select, textarea {
            width: 100%;
            background: var(--bg);
            border: 1px solid var(--border);
            color: var(--text);
            border-radius: 0.45rem;
            padding: 0.55rem 0.7rem;
            margin: 0.35rem 0 0.8rem;
        }
        label { font-size: 0.85rem; color: var(--muted); }
        .flash { background: rgba(34, 197, 94, 0.15); border: 1px solid #22c55e; padding: 0.75rem 1rem; border-radius: 0.5rem; margin-bottom: 1rem; }
        .error { background: rgba(248, 113, 113, 0.15); border: 1px solid var(--danger); padding: 0.75rem 1rem; border-radius: 0.5rem; margin-bottom: 1rem; }
        .badge { display: inline-block; padding: 0.15rem 0.5rem; border-radius: 999px; font-size: 0.75rem; background: var(--panel-2); }
        .badge.ok { background: rgba(34,197,94,.2); color: #86efac; }
        .badge.warn { background: rgba(251,191,36,.2); color: #fde68a; }
        .badge.bad { background: rgba(248,113,113,.2); color: #fecaca; }
        .login-wrap { max-width: 420px; margin: 10vh auto; }
        @media (max-width: 860px) {
            .shell { grid-template-columns: 1fr; }
            .sidebar { border-right: none; border-bottom: 1px solid var(--border); }
        }
    </style>
</head>
<body>
@if (request()->routeIs('login'))
    @yield('content')
@else
<div class="shell">
    <aside class="sidebar">
        <div class="brand">SMS Gateway</div>
        <nav class="nav">
            @yield('nav')
        </nav>
    </aside>
    <main class="main">
        <div class="top">
            <h1 style="margin:0;font-size:1.4rem;">@yield('heading')</h1>
            <div>
                <span style="color:var(--muted);margin-right:1rem;">{{ auth()->user()->name ?? '' }}</span>
                <form action="{{ route('logout') }}" method="POST" style="display:inline">
                    @csrf
                    <button class="btn btn-secondary" type="submit">Logout</button>
                </form>
            </div>
        </div>
        @if (session('success'))
            <div class="flash">{{ session('success') }}</div>
        @endif
        @if ($errors->any())
            <div class="error">
                <ul style="margin:0;padding-left:1.1rem;">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
        @yield('content')
    </main>
</div>
@endif
</body>
</html>
