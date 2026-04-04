<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin Dashboard')</title>
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    @stack('styles')
</head>
<body class="admin-body">
    <div class="admin-wrapper">
        <aside class="admin-sidebar">
            <div class="admin-logo"><h2>NearbyPost</h2></div>
            <nav class="admin-nav">
                <a href="{{ route('admin.dashboard') }}" class="active">📊 Dashboard</a>
                <form method="POST" action="{{ route('admin.logout') }}">
                    @csrf
                    <button type="submit" style="background:none;border:none;color:#fff;cursor:pointer;padding:8px 0;text-align:left;">🚪 Logout</button>
                </form>
            </nav>
        </aside>
        <div class="admin-main">
            <header class="admin-header">
                <div style="display:flex;align-items:center;gap:12px;">
                    <span style="font-weight:600;color:#1c5a7f;">NearbyPost Admin</span>
                </div>
            </header>
            <main class="admin-content">
                @yield('content')
            </main>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
    <script src="{{ asset('js/admin.js') }}"></script>
    @stack('scripts')
</body>
</html>
