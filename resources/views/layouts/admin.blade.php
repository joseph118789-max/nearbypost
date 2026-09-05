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
        <div class="admin-main">
            @if (!View::hasSection('own-header'))
            {{-- The admin IS the Resource centre (owner, 4 Sep 2026): the old
                 dashboard with its News, Subscribers and Intel tabs is gone. --}}
            <header class="admin-header" style="display:flex;align-items:center;justify-content:space-between;gap:16px;max-width:1400px;margin:0 auto 14px;padding:10px 16px;box-sizing:border-box;">
                <div style="display:flex;align-items:center;gap:16px;">
                    <a href="{{ route('admin.brain.index') }}" style="font-weight:700;color:#1c5a7f;text-decoration:none;">NearbyPost Admin</a>
                    <a href="{{ route('admin.brain.index') }}" style="font-size:0.85rem;color:#1f5679;text-decoration:none;">Resource centre</a>
                </div>
                <div style="display:flex;align-items:center;gap:10px;">
                    <a href="{{ url('/') }}" class="admin-settings-btn" style="text-decoration:none;display:inline-flex;align-items:center;">Main page</a>
                    <form method="post" action="{{ route('admin.logout') }}" onsubmit="return confirm('Log out of the admin?')" style="margin:0;">
                        @csrf
                        <button type="submit" class="admin-settings-btn" style="background:#bc4e2c;">Logout</button>
                    </form>
                </div>
            </header>
            @endif
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
