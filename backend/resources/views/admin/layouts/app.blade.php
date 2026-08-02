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

        /* ---------------------------------------------------------------- */
        /* Settings management                                              */
        /* ---------------------------------------------------------------- */

        .alert {
            border-radius: var(--admin-radius);
            padding: 0.75rem 1rem;
            margin-bottom: 1.25rem;
            font-size: 0.875rem;
            border: 1px solid transparent;
        }

        .alert ul { margin: 0.5rem 0 0; padding-left: 1.1rem; }

        .alert-success {
            background: rgba(22, 163, 74, 0.1);
            border-color: rgba(22, 163, 74, 0.3);
            color: var(--admin-success);
        }

        .alert-danger {
            background: rgba(220, 38, 38, 0.1);
            border-color: rgba(220, 38, 38, 0.3);
            color: var(--admin-danger);
        }

        .settings-tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 0.35rem;
            margin-bottom: 1.25rem;
        }

        .settings-tab {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.45rem 0.85rem;
            border-radius: 999px;
            border: 1px solid var(--admin-border);
            background: var(--admin-surface);
            color: var(--admin-text);
            text-decoration: none;
            font-size: 0.83rem;
        }

        .settings-tab:hover { border-color: var(--admin-primary); }
        .settings-tab.is-active { background: var(--admin-primary); border-color: var(--admin-primary); color: #fff; }

        .badge-private { background: rgba(100, 116, 139, 0.18); color: var(--admin-muted); }
        .settings-tab.is-active .badge-private { background: rgba(255, 255, 255, 0.22); color: #fff; }
        .badge-locked { background: rgba(37, 99, 235, 0.12); color: var(--admin-primary); margin-left: 0.35rem; }

        .settings-panel-header {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 1rem;
            padding-bottom: 1rem;
            margin-bottom: 1.25rem;
            border-bottom: 1px solid var(--admin-border);
        }

        .settings-panel-header h2 { margin: 0 0 0.2rem; font-size: 1.1rem; }
        .settings-panel-header p { margin: 0; color: var(--admin-muted); font-size: 0.85rem; }

        .settings-section { margin-bottom: 1.75rem; }
        .settings-section h3 { font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.06em; color: var(--admin-muted); margin: 0 0 0.85rem; }

        .settings-form { display: grid; gap: 1.35rem; }

        .field { display: grid; gap: 0.35rem; max-width: 42rem; }
        .field > label { font-size: 0.875rem; font-weight: 600; }
        .field-hint { margin: 0; font-size: 0.8rem; color: var(--admin-muted); }
        .field-error { margin: 0; font-size: 0.8rem; color: var(--admin-danger); }
        .field-key { margin: 0.1rem 0 0; font-size: 0.72rem; color: var(--admin-muted); }
        .field.has-error input, .field.has-error textarea { border-color: var(--admin-danger); }

        .field input[type="text"],
        .field input[type="email"],
        .field input[type="url"],
        .field input[type="number"],
        .field textarea {
            width: 100%;
            padding: 0.5rem 0.65rem;
            border: 1px solid var(--admin-border);
            border-radius: 8px;
            background: var(--admin-bg);
            color: var(--admin-text);
            font: inherit;
            font-size: 0.875rem;
        }

        .field textarea { resize: vertical; }
        .field .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: 0.8rem; }

        .field input:focus, .field textarea:focus {
            outline: 2px solid var(--admin-primary);
            outline-offset: 1px;
            border-color: var(--admin-primary);
        }

        .color-field { display: flex; align-items: center; gap: 0.6rem; }
        .color-swatch {
            width: 44px;
            height: 38px;
            padding: 2px;
            border: 1px solid var(--admin-border);
            border-radius: 8px;
            background: var(--admin-bg);
            cursor: pointer;
            flex-shrink: 0;
        }
        .color-input { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; max-width: 12rem; }

        .switch { display: inline-flex; align-items: center; gap: 0.5rem; font-size: 0.875rem; font-weight: 400; }
        .switch input[type="checkbox"] { width: 1.05rem; height: 1.05rem; accent-color: var(--admin-primary); }

        .settings-actions { display: flex; gap: 0.6rem; padding-top: 0.5rem; }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.5rem 1rem;
            border-radius: 8px;
            border: 1px solid transparent;
            font: inherit;
            font-size: 0.875rem;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-sm { padding: 0.35rem 0.7rem; font-size: 0.8rem; }
        .btn-primary { background: var(--admin-primary); color: #fff; }
        .btn-primary:hover { filter: brightness(1.08); }
        .btn-ghost { background: transparent; border-color: var(--admin-border); color: var(--admin-text); }
        .btn-ghost:hover { background: var(--admin-bg); }
        .btn-danger { background: transparent; border-color: rgba(220, 38, 38, 0.4); color: var(--admin-danger); }
        .btn-danger:hover { background: rgba(220, 38, 38, 0.08); }

        .media-grid { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fill, minmax(240px, 1fr)); }

        .media-card {
            display: grid;
            gap: 0.6rem;
            align-content: start;
            border: 1px solid var(--admin-border);
            border-radius: var(--admin-radius);
            padding: 1rem;
            background: var(--admin-bg);
        }

        .media-card h4 { margin: 0; font-size: 0.875rem; }
        .media-card-head { display: grid; gap: 0.25rem; }

        .media-preview {
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 96px;
            padding: 0.75rem;
            border: 1px dashed var(--admin-border);
            border-radius: 8px;
            background: #fff;
            /* Chequerboard, so a transparent PNG reads as transparent rather
               than looking like it has a white background. */
            background-image:
                linear-gradient(45deg, #eef2f7 25%, transparent 25%),
                linear-gradient(-45deg, #eef2f7 25%, transparent 25%),
                linear-gradient(45deg, transparent 75%, #eef2f7 75%),
                linear-gradient(-45deg, transparent 75%, #eef2f7 75%);
            background-size: 16px 16px;
            background-position: 0 0, 0 8px, 8px -8px, -8px 0;
        }

        .media-preview.is-dark { background: #0f172a; background-image: none; }
        .media-preview img { max-width: 100%; max-height: 80px; object-fit: contain; }
        .media-preview.is-favicon img { max-height: 48px; }
        .media-empty { font-size: 0.8rem; color: var(--admin-muted); }

        .media-form { display: grid; gap: 0.5rem; }
        .media-form input[type="file"] { font-size: 0.78rem; color: var(--admin-muted); max-width: 100%; }
        .media-actions { display: flex; gap: 0.5rem; }

        .empty { color: var(--admin-muted); font-size: 0.875rem; }
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
            <a href="{{ route('admin.dashboard') }}"
               @if (request()->routeIs('admin.dashboard')) aria-current="page" @endif>Dashboard</a>

            {{-- Modules delivered in later phases are rendered disabled rather
                 than hidden so the intended surface is visible. --}}
            <div class="nav-section">Storefront</div>
            <a href="{{ route('admin.settings.index') }}"
               @if (request()->routeIs('admin.settings.*')) aria-current="page" @endif>Settings</a>
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

<script>
    /*
     * Keeps each colour swatch and its hex text input in sync.
     *
     * Only the text input is submitted — the swatch is an input aid. Written as
     * progressive enhancement: with JavaScript unavailable the text field alone
     * remains fully usable, which is why the hex value is never derived from
     * the picker on the server.
     */
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-color-for]').forEach(function (swatch) {
            var input = document.getElementById(swatch.dataset.colorFor);

            if (!input) return;

            swatch.addEventListener('input', function () {
                input.value = swatch.value;
            });

            input.addEventListener('input', function () {
                // Assigning an invalid value to a colour input silently resets
                // it to #000000, so only well-formed hex is pushed across.
                if (/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(input.value.trim())) {
                    swatch.value = input.value.trim();
                }
            });
        });
    });
</script>
</body>
</html>
