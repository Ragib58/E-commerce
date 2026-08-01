<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow">

    {{-- The panel title uses the admin-managed company name, so the admin
         panel is itself branded by the same dynamic settings. --}}
    <title>@yield('title', 'Dashboard') — {{ $companyName ?? config('app.name') }} Admin</title>

    <style>
        :root {
            --admin-bg: #f8fafc;
            --admin-surface: #ffffff;
            --admin-border: #e2e8f0;
            --admin-text: #0f172a;
            --admin-muted: #64748b;
            --admin-primary: #2563eb;
            --admin-success: #16a34a;
            --admin-warning: #d97706;
            --admin-danger: #dc2626;
            --admin-radius: 10px;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --admin-bg: #0b1120;
                --admin-surface: #111827;
                --admin-border: #1f2937;
                --admin-text: #e5e7eb;
                --admin-muted: #9ca3af;
            }
        }

        * { box-sizing: border-box; }

        body {
            margin: 0;
            font-family: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
            background: var(--admin-bg);
            color: var(--admin-text);
            line-height: 1.5;
        }

        .admin-shell { display: flex; min-height: 100vh; }

        .admin-sidebar {
            width: 260px;
            flex-shrink: 0;
            background: var(--admin-surface);
            border-right: 1px solid var(--admin-border);
            padding: 1.5rem 1rem;
        }

        .admin-brand {
            font-weight: 700;
            font-size: 1.05rem;
            padding: 0 0.5rem 1.25rem;
            border-bottom: 1px solid var(--admin-border);
            margin-bottom: 1rem;
        }

        .admin-brand small { display: block; font-weight: 500; color: var(--admin-muted); font-size: 0.75rem; }

        .admin-nav a {
            display: block;
            padding: 0.55rem 0.75rem;
            border-radius: var(--admin-radius);
            color: var(--admin-text);
            text-decoration: none;
            font-size: 0.9rem;
        }

        .admin-nav a:hover { background: var(--admin-bg); }
        .admin-nav a[aria-current="page"] { background: var(--admin-primary); color: #fff; }
        .admin-nav a[aria-disabled="true"] { color: var(--admin-muted); cursor: not-allowed; }

        .admin-nav .nav-section {
            font-size: 0.7rem;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            color: var(--admin-muted);
            padding: 1rem 0.75rem 0.35rem;
        }

        .admin-main { flex: 1; padding: 2rem; min-width: 0; }

        .admin-header { margin-bottom: 1.75rem; }
        .admin-header h1 { margin: 0 0 0.25rem; font-size: 1.5rem; }
        .admin-header p { margin: 0; color: var(--admin-muted); font-size: 0.9rem; }

        .card {
            background: var(--admin-surface);
            border: 1px solid var(--admin-border);
            border-radius: var(--admin-radius);
            padding: 1.25rem;
        }

        .grid { display: grid; gap: 1rem; }
        .grid-4 { grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); }

        .stat-label { font-size: 0.75rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--admin-muted); }
        .stat-value { font-size: 1.75rem; font-weight: 700; margin-top: 0.35rem; }

        table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        th, td { text-align: left; padding: 0.6rem 0.5rem; border-bottom: 1px solid var(--admin-border); }
        th { font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; color: var(--admin-muted); }
        tr:last-child td { border-bottom: none; }

        .badge {
            display: inline-block;
            padding: 0.15rem 0.55rem;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 600;
        }

        .badge-ok { background: rgba(22, 163, 74, 0.12); color: var(--admin-success); }
        .badge-degraded { background: rgba(217, 119, 6, 0.12); color: var(--admin-warning); }
        .badge-down { background: rgba(220, 38, 38, 0.12); color: var(--admin-danger); }

        code {
            font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
            font-size: 0.82em;
            background: var(--admin-bg);
            padding: 0.1rem 0.35rem;
            border-radius: 4px;
        }
    </style>
</head>
<body>
<div class="admin-shell">
    <aside class="admin-sidebar">
        <div class="admin-brand">
            {{ $companyName ?? config('app.name') }}
            <small>Admin Panel</small>
        </div>

        <nav class="admin-nav">
            <a href="{{ route('admin.dashboard') }}" aria-current="page">Dashboard</a>

            {{-- Modules delivered in later phases. Rendered disabled rather
                 than hidden so the intended surface is visible. --}}
            <div class="nav-section">Storefront</div>
            <a href="#" aria-disabled="true">Settings</a>
            <a href="#" aria-disabled="true">Menus</a>
            <a href="#" aria-disabled="true">Banners</a>

            <div class="nav-section">Catalog</div>
            <a href="#" aria-disabled="true">Products</a>
            <a href="#" aria-disabled="true">Categories</a>
            <a href="#" aria-disabled="true">Brands</a>

            <div class="nav-section">Commerce</div>
            <a href="#" aria-disabled="true">Orders</a>
            <a href="#" aria-disabled="true">Payments</a>
            <a href="#" aria-disabled="true">Shipping</a>
        </nav>
    </aside>

    <main class="admin-main">
        @yield('content')
    </main>
</div>
</body>
</html>
