{{-- resources/views/layouts/admin.blade.php --}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin Dashboard')</title>
    
    <!-- Styles -->
    <link rel="stylesheet" href="{{ asset('css/app.css') }}">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}">
    @stack('styles')
</head>
<body class="admin-body">
    <div class="admin-wrapper">
        {{-- Sidebar --}}
        <aside class="admin-sidebar">
            @yield('sidebar')
        </aside>

        <div class="admin-main">
            {{-- Header / Navbar --}}
            <header class="admin-header">
                @yield('header')
            </header>

            {{-- Content --}}
            <main class="admin-content">
                @yield('content')
            </main>

            {{-- Footer --}}
            <footer class="admin-footer">
                @yield('footer')
            </footer>
        </div>
    </div>

    <!-- Scripts -->
    <script src="{{ asset('js/app.js') }}"></script>
    @stack('scripts')
</body>
</html>
