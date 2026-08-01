@extends('admin.layouts.app')

@section('title', 'Dashboard')

@section('content')
    <header class="admin-header">
        <h1>Dashboard</h1>
        <p>Foundation phase. Storefront, catalog, and commerce modules are delivered in later phases.</p>
    </header>

    <section class="grid grid-4" style="margin-bottom: 1.5rem;">
        <div class="card">
            <div class="stat-label">Settings</div>
            <div class="stat-value">{{ $settingsCount }}</div>
        </div>
        <div class="card">
            <div class="stat-label">Publicly Exposed</div>
            <div class="stat-value">{{ $publicSettingsCount }}</div>
        </div>
        <div class="card">
            <div class="stat-label">Menus</div>
            <div class="stat-value">{{ $menuCount }}</div>
        </div>
        <div class="card">
            <div class="stat-label">System Status</div>
            <div class="stat-value" style="font-size: 1.15rem; padding-top: 0.5rem;">
                @php($status = $health['status']->value)
                <span class="badge badge-{{ $status }}">{{ strtoupper($status) }}</span>
            </div>
        </div>
    </section>

    <section class="grid" style="grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));">
        <div class="card">
            <h2 style="margin-top: 0; font-size: 1rem;">Dependency Health</h2>
            <table>
                <thead>
                <tr>
                    <th>Dependency</th>
                    <th>Status</th>
                    <th>Latency</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($health['checks'] as $name => $check)
                    <tr>
                        <td>
                            {{ ucfirst($name) }}
                            @unless ($check['critical'])
                                <span style="color: var(--admin-muted); font-size: 0.75rem;">(optional)</span>
                            @endunless
                        </td>
                        <td><span class="badge badge-{{ $check['status'] }}">{{ $check['status'] }}</span></td>
                        <td>{{ $check['latency_ms'] }} ms</td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2 style="margin-top: 0; font-size: 1rem;">Setting Groups</h2>
            <table>
                <thead>
                <tr>
                    <th>Group</th>
                    <th>Public API</th>
                </tr>
                </thead>
                <tbody>
                @foreach ($groups as $group)
                    <tr>
                        <td>
                            {{ $group->label() }}
                            <div style="color: var(--admin-muted); font-size: 0.78rem;">{{ $group->description() }}</div>
                        </td>
                        <td>
                            @if ($group->isPubliclyExposable())
                                <span class="badge badge-ok">exposed</span>
                            @else
                                <span class="badge badge-down">private</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>

        <div class="card">
            <h2 style="margin-top: 0; font-size: 1rem;">API Endpoints</h2>
            <table>
                <tbody>
                <tr><td><code>GET /api/v1/health</code></td><td>Liveness probe</td></tr>
                <tr><td><code>GET /api/v1/health/ready</code></td><td>Readiness probe</td></tr>
                <tr><td><code>GET /api/v1/settings/public</code></td><td>Storefront configuration</td></tr>
                </tbody>
            </table>
        </div>
    </section>
@endsection
