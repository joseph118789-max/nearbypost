<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

  {{-- PRIMARY META TAGS --}}
  <title>{{ config('app.name') }} — Local News & Hyperlocal Updates for Your Area</title>
  <meta name="title" content="{{ config('app.name') }} — Local News & Hyperlocal Updates for Your Area">
  <meta name="description" content="Get real-time local news, hyperlocal updates, and community stories from your neighborhood. Stay informed about what's happening near you with {{ config('app.name') }}.">
  <meta name="keywords" content="local news, hyperlocal news, neighborhood news, community updates, nearby news, local events, breaking news near me, local stories, community journalism, local updates, news near me, local headlines, community news">
  <meta name="author" content="{{ config('app.name') }}">
  <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large, max-video-preview:-1">
  <meta name="language" content="English">
  <meta name="revisit-after" content="1 days">
  <meta name="rating" content="General">
  <meta name="distribution" content="Global">

  {{-- CANONICAL URL --}}
  <link rel="canonical" href="{{ url('/') }}">

  {{-- OPEN GRAPH / FACEBOOK --}}
  <meta property="og:type" content="website">
  <meta property="og:url" content="{{ url('/') }}">
  <meta property="og:title" content="{{ config('app.name') }} — Local News & Hyperlocal Updates for Your Area">
  <meta property="og:description" content="Get real-time local news, hyperlocal updates, and community stories from your neighborhood.">
  <meta property="og:image" content="{{ asset('images/og-image.jpg') }}">
  <meta property="og:image:width" content="1200">
  <meta property="og:image:height" content="630">
  <meta property="og:image:alt" content="{{ config('app.name') }} - Your Local News Source">
  <meta property="og:site_name" content="{{ config('app.name') }}">
  <meta property="og:locale" content="en_US">
  <meta property="og:locale:alternate" content="ms_MY">

  {{-- TWITTER CARD --}}
  <meta name="twitter:card" content="summary_large_image">
  <meta name="twitter:url" content="{{ url('/') }}">
  <meta name="twitter:title" content="{{ config('app.name') }} — Local News & Hyperlocal Updates for Your Area">
  <meta name="twitter:description" content="Get real-time local news, hyperlocal updates, and community stories from your neighborhood.">
  <meta name="twitter:image" content="{{ asset('images/twitter-image.jpg') }}">
  <meta name="twitter:site" content="@nearbypost">
  <meta name="twitter:creator" content="@nearbypost">

  {{-- GEOLOCATION META TAGS --}}
  <meta name="geo.region" content="MY">
  <meta name="geo.placename" content="Kuala Lumpur">
  <meta name="geo.position" content="3.139003;101.686855">
  <meta name="ICBM" content="3.139003, 101.686855">

  {{-- MOBILE & PWA --}}
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
  <meta name="format-detection" content="telephone=no">
  <meta name="theme-color" content="#0f3b2c">
  <meta name="msapplication-TileColor" content="#0f3b2c">
  <meta name="msapplication-TileImage" content="{{ asset('images/ms-icon-144x144.png') }}">

  {{-- PWA MANIFEST --}}
  <link rel="manifest" href="{{ asset('manifest.json') }}">
  <link rel="apple-touch-icon" sizes="57x57" href="{{ asset('icons/apple-icon-57x57.png') }}">
  <link rel="apple-touch-icon" sizes="60x60" href="{{ asset('icons/apple-icon-60x60.png') }}">
  <link rel="apple-touch-icon" sizes="72x72" href="{{ asset('icons/apple-icon-72x72.png') }}">
  <link rel="apple-touch-icon" sizes="76x76" href="{{ asset('icons/apple-icon-76x76.png') }}">
  <link rel="apple-touch-icon" sizes="114x114" href="{{ asset('icons/apple-icon-114x114.png') }}">
  <link rel="apple-touch-icon" sizes="120x120" href="{{ asset('icons/apple-icon-120x120.png') }}">
  <link rel="apple-touch-icon" sizes="144x144" href="{{ asset('icons/apple-icon-144x144.png') }}">
  <link rel="apple-touch-icon" sizes="152x152" href="{{ asset('icons/apple-icon-152x152.png') }}">
  <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('icons/apple-icon-180x180.png') }}">
  <link rel="icon" type="image/png" sizes="192x192" href="{{ asset('icons/android-icon-192x192.png') }}">
  <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('icons/favicon-32x32.png') }}">
  <link rel="icon" type="image/png" sizes="96x96" href="{{ asset('icons/favicon-96x96.png') }}">
  <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('icons/favicon-16x16.png') }}">

  {{-- RSS FEED --}}
  <link rel="alternate" type="application/rss+xml" title="{{ config('app.name') }} RSS Feed" href="{{ asset('feed.xml') }}">
  <link rel="alternate" type="application/atom+xml" title="{{ config('app.name') }} Atom Feed" href="{{ asset('feed.atom') }}">

  {{-- DUBLIN CORE METADATA --}}
  <meta name="DC.title" content="{{ config('app.name') }} — Local News & Hyperlocal Updates">
  <meta name="DC.creator" content="{{ config('app.name') }}">
  <meta name="DC.subject" content="Local News, Hyperlocal Journalism, Community Updates, Neighborhood News">
  <meta name="DC.description" content="Real-time local news and hyperlocal updates from your neighborhood">
  <meta name="DC.publisher" content="{{ config('app.name') }} Media">
  <meta name="DC.contributor" content="{{ config('app.name') }} Editorial Team">
  <meta name="DC.date" content="2026-03-31">
  <meta name="DC.type" content="News Website">
  <meta name="DC.format" content="text/html">
  <meta name="DC.identifier" content="{{ url('/') }}">
  <meta name="DC.language" content="en">
  <meta name="DC.rights" content="Copyright © 2026 {{ config('app.name') }}. All rights reserved.">
  <meta name="DC.coverage" content="Malaysia">

  {{-- SCHEMA.ORG MARKUP --}}
  <meta itemprop="name" content="{{ config('app.name') }} — Local News & Hyperlocal Updates">
  <meta itemprop="description" content="Get real-time local news, hyperlocal updates, and community stories from your neighborhood.">
  <meta itemprop="image" content="{{ asset('images/og-image.jpg') }}">

  {{-- NEWS-SPECIFIC META TAGS --}}
  <meta name="news_keywords" content="local news, hyperlocal, neighborhood news, community journalism, nearby news, local updates, breaking news local">
  <meta name="article:author" content="{{ config('app.name') }} Editorial Team">
  <meta name="article:publisher" content="https://facebook.com/nearbypost">
  <meta name="classification" content="News">
  <meta name="copyright" content="Copyright © 2026 {{ config('app.name') }}">

  {{-- VERIFICATION CODES --}}
  <meta name="google-site-verification" content="your-google-verification-code">
  <meta name="msvalidate.01" content="your-bing-verification-code">
  <meta name="yandex-verification" content="your-yandex-verification-code">
  <meta name="p:domain_verify" content="your-pinterest-verification-code">

  {{-- PERFORMANCE OPTIMIZATIONS --}}
  <link rel="preconnect" href="https://ipapi.co" crossorigin>
  <link rel="preconnect" href="https://nominatim.openstreetmap.org" crossorigin>
  <link rel="dns-prefetch" href="https://ipapi.co">
  <link rel="dns-prefetch" href="https://nominatim.openstreetmap.org">

  {{-- CSP (uncomment for production) --}}
  {{-- <meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self'; connect-src 'self' https://ipapi.co https://nominatim.openstreetmap.org;"> --}}

  <style>
    :root { --color-bg: #ffffff; --color-bg-secondary: #fafaf9; --color-bg-tertiary: #f5f5f4; --color-text-primary: #1c1917; --color-text-secondary: #44403c; --color-text-tertiary: #78716c; --color-border: #e7e5e4; --color-border-light: #f5f5f4; --color-accent: #0f3b2c; --color-accent-light: #ecfdf5; --color-accent-muted: #2d5a4a; --color-nearby: #0f3b2c; --color-error: #991b1b; --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, Helvetica, sans-serif; --space-xs: 4px; --space-sm: 8px; --space-md: 16px; --space-lg: 24px; --space-xl: 32px; --space-2xl: 48px; --radius-sm: 6px; --radius-md: 12px; --radius-lg: 16px; --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.03); --shadow-md: 0 2px 4px rgba(0, 0, 0, 0.05); --shadow-hover: 0 8px 24px rgba(0, 0, 0, 0.08); --transition: all 0.2s cubic-bezier(0.2, 0.9, 0.4, 1.1); }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: var(--color-bg-secondary); font-family: var(--font-sans); color: var(--color-text-primary); line-height: 1.5; -webkit-font-smoothing: antialiased; }
    h1, h2, h3, h4, h5, h6 { font-weight: 500; line-height: 1.3; letter-spacing: -0.01em; }
    .logo { font-size: 1.25rem; font-weight: 500; color: var(--color-text-primary); letter-spacing: -0.02em; }
    .header { display: flex; justify-content: space-between; align-items: center; padding: var(--space-md) var(--space-lg); background: var(--color-bg); border-bottom: 1px solid var(--color-border); position: sticky; top: 0; z-index: 40; backdrop-filter: blur(10px); background: rgba(255, 255, 255, 0.98); }
    .header-actions { display: flex; gap: var(--space-sm); }
    .icon-btn { background: transparent; border: 1px solid var(--color-border); border-radius: var(--radius-md); padding: 8px 16px; font-size: 0.875rem; font-weight: 450; color: var(--color-text-secondary); cursor: pointer; transition: var(--transition); font-family: inherit; }
    .icon-btn:hover { background: var(--color-bg-secondary); border-color: var(--color-text-tertiary); }
    .feed-container { padding: var(--space-xl) var(--space-lg); max-width: 800px; margin: 0 auto; }
    .story-card { background: var(--color-bg); border-radius: var(--radius-lg); padding: var(--space-lg); margin-bottom: var(--space-md); transition: var(--transition); border: 1px solid var(--color-border-light); cursor: pointer; }
    .story-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-hover); border-color: var(--color-border); }
    .story-meta { display: flex; align-items: center; gap: var(--space-md); margin-bottom: var(--space-sm); font-size: 0.75rem; font-weight: 450; text-transform: uppercase; letter-spacing: 0.03em; }
    .story-source { font-weight: 500; color: var(--color-accent-muted); }
    .story-category { color: var(--color-text-tertiary); }
    .story-nearby { color: var(--color-nearby); font-weight: 500; background: var(--color-accent-light); padding: 2px 8px; border-radius: 20px; font-size: 0.7rem; }
    .story-title { font-size: 1.25rem; font-weight: 500; line-height: 1.4; margin-bottom: var(--space-sm); color: var(--color-text-primary); letter-spacing: -0.01em; }
    .story-summary { font-size: 0.9375rem; color: var(--color-text-secondary); line-height: 1.5; margin-bottom: var(--space-md); }
    .story-footer { display: flex; gap: var(--space-lg); font-size: 0.75rem; color: var(--color-text-tertiary); }
    .empty-state { text-align: center; padding: var(--space-2xl) var(--space-lg); color: var(--color-text-tertiary); font-size: 0.875rem; background: var(--color-bg); border-radius: var(--radius-lg); border: 1px solid var(--color-border-light); }
    .bottom-nav { position: fixed; bottom: 0; left: 0; right: 0; background: rgba(255, 255, 255, 0.98); backdrop-filter: blur(10px); border-top: 1px solid var(--color-border); display: flex; justify-content: center; gap: var(--space-xl); padding: var(--space-sm) var(--space-lg); padding-bottom: calc(var(--space-sm) + env(safe-area-inset-bottom, 0px)); z-index: 50; }
    .nav-item { background: none; border: none; padding: var(--space-sm) var(--space-md); font-size: 0.875rem; font-weight: 450; color: var(--color-text-tertiary); cursor: pointer; transition: var(--transition); font-family: inherit; border-radius: var(--radius-md); }
    .nav-item.active { color: var(--color-accent); font-weight: 500; background: var(--color-accent-light); }
    .nav-item:hover { color: var(--color-text-primary); background: var(--color-bg-tertiary); }
    .pull-to-refresh { text-align: center; padding: var(--space-sm); color: var(--color-text-tertiary); font-size: 0.75rem; transition: transform 0.2s; transform: translateY(-100%); }
    .pull-to-refresh.visible { transform: translateY(0); }
    .skeleton-card { background: var(--color-bg); border-radius: var(--radius-lg); padding: var(--space-lg); margin-bottom: var(--space-md); border: 1px solid var(--color-border-light); }
    .skeleton-line { height: 12px; background: linear-gradient(90deg, var(--color-border-light) 25%, var(--color-border) 50%, var(--color-border-light) 75%); background-size: 200% 100%; animation: shimmer 1.5s infinite; border-radius: var(--radius-sm); margin-bottom: var(--space-sm); }
    @keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
    .skeleton-title { width: 75%; height: 24px; }
    .skeleton-text { width: 100%; height: 60px; }
    .skeleton-text.short { width: 60%; height: 40px; }
    .modal-overlay { position: fixed; inset: 0; background: rgba(0, 0, 0, 0.5); backdrop-filter: blur(4px); display: flex; align-items: center; justify-content: center; z-index: 200; visibility: hidden; opacity: 0; transition: visibility 0.2s, opacity 0.2s; }
    .modal-overlay.active { visibility: visible; opacity: 1; }
    .modal { background: var(--color-bg); width: 90%; max-width: 520px; border-radius: var(--radius-lg); padding: var(--space-lg); max-height: 85vh; display: flex; flex-direction: column; box-shadow: var(--shadow-hover); }
    .modal h3 { font-size: 1.125rem; font-weight: 500; margin-bottom: var(--space-lg); color: var(--color-text-primary); }
    .modal-scrollable { flex: 1; overflow-y: auto; }
    .modal-label { font-size: 0.75rem; font-weight: 500; color: var(--color-text-secondary); margin: var(--space-md) 0 var(--space-sm); text-transform: uppercase; letter-spacing: 0.03em; }
    .modal-input, .modal-select, .modal-textarea { width: 100%; padding: 10px 12px; border: 1px solid var(--color-border); border-radius: var(--radius-sm); font-size: 0.875rem; font-family: inherit; background: var(--color-bg); transition: var(--transition); }
    .modal-input:focus, .modal-select:focus, .modal-textarea:focus { outline: none; border-color: var(--color-accent); box-shadow: 0 0 0 2px var(--color-accent-light); }
    .modal-textarea { resize: vertical; min-height: 80px; }
    .modal-buttons { display: flex; gap: var(--space-sm); margin-top: var(--space-lg); }
    .modal-btn { flex: 1; padding: 10px; border-radius: var(--radius-sm); font-weight: 450; font-size: 0.875rem; border: 1px solid var(--color-border); background: var(--color-bg); cursor: pointer; font-family: inherit; transition: var(--transition); }
    .modal-btn.primary { background: var(--color-accent); border-color: var(--color-accent); color: white; }
    .modal-btn.primary:hover { background: var(--color-accent-muted); }
    .modal-btn.danger { border-color: var(--color-error); color: var(--color-error); }
    .modal-btn.danger:hover { background: #fef2f2; }
    .modal-btn:hover { background: var(--color-bg-secondary); }
    .filter-chips { display: flex; flex-wrap: wrap; gap: var(--space-sm); margin: var(--space-sm) 0; }
    .filter-chip { background: transparent; border: 1px solid var(--color-border); padding: 6px 14px; border-radius: 40px; font-size: 0.75rem; cursor: pointer; transition: var(--transition); font-family: inherit; color: var(--color-text-secondary); }
    .filter-chip.active { background: var(--color-accent); border-color: var(--color-accent); color: white; }
    .filter-chip:hover:not(.active) { border-color: var(--color-text-tertiary); background: var(--color-bg-tertiary); }
    .searchable-dropdown { position: relative; margin-top: var(--space-sm); }
    .dropdown-trigger { width: 100%; padding: 10px 12px; background: var(--color-bg); border: 1px solid var(--color-border); border-radius: var(--radius-sm); cursor: pointer; display: flex; justify-content: space-between; align-items: center; font-size: 0.875rem; transition: var(--transition); }
    .dropdown-trigger:hover { border-color: var(--color-text-tertiary); }
    .dropdown-menu { position: absolute; top: 100%; left: 0; right: 0; background: var(--color-bg); border: 1px solid var(--color-border); border-radius: var(--radius-sm); box-shadow: var(--shadow-md); z-index: 100; display: none; margin-top: var(--space-xs); }
    .dropdown-menu.open { display: block; }
    .dropdown-search { padding: var(--space-sm); border-bottom: 1px solid var(--color-border); }
    .dropdown-search input { width: 100%; padding: 8px 12px; border: 1px solid var(--color-border); border-radius: var(--radius-sm); font-size: 0.75rem; font-family: inherit; }
    .dropdown-options { max-height: 240px; overflow-y: auto; }
    .dropdown-option { padding: 10px 12px; cursor: pointer; font-size: 0.875rem; transition: var(--transition); }
    .dropdown-option:hover { background: var(--color-bg-secondary); }
    .dropdown-option.selected { background: var(--color-accent-light); color: var(--color-accent); font-weight: 450; }
    .info-card { background: var(--color-bg); border-radius: var(--radius-md); padding: var(--space-lg); margin-bottom: var(--space-md); border: 1px solid var(--color-border-light); }
    .info-card h3 { font-size: 0.75rem; font-weight: 500; color: var(--color-text-secondary); margin-bottom: var(--space-md); text-transform: uppercase; letter-spacing: 0.03em; }
    .info-row { display: flex; justify-content: space-between; padding: var(--space-sm) 0; font-size: 0.875rem; border-bottom: 1px solid var(--color-border-light); }
    .info-row:last-child { border-bottom: none; }
    .desktop-action-btn { width: 100%; padding: 10px; background: var(--color-bg); border: 1px solid var(--color-border); border-radius: var(--radius-sm); font-size: 0.875rem; cursor: pointer; margin-bottom: var(--space-md); font-family: inherit; transition: var(--transition); }
    .desktop-action-btn:hover { background: var(--color-bg-secondary); border-color: var(--color-text-tertiary); }
    .desktop-report-btn { width: 100%; padding: 10px; background: var(--color-bg); border: 1px solid var(--color-error); border-radius: var(--radius-sm); font-size: 0.875rem; color: var(--color-error); cursor: pointer; margin-bottom: var(--space-md); font-family: inherit; transition: var(--transition); }
    .desktop-report-btn:hover { background: #fef2f2; }
    .legal-footer { padding: var(--space-lg) var(--space-lg); background: var(--color-bg); border-top: 1px solid var(--color-border); text-align: center; font-size: 0.75rem; color: var(--color-text-tertiary); }
    .legal-links { display: flex; justify-content: center; gap: var(--space-lg); margin-bottom: var(--space-sm); }
    .legal-link { color: var(--color-text-tertiary); cursor: pointer; text-decoration: none; transition: var(--transition); }
    .legal-link:hover { color: var(--color-text-primary); }
    @media (min-width: 769px) {
      .mobile-layout { display: none; }
      .desktop-layout { display: grid; grid-template-columns: 260px 1fr 320px; min-height: 100vh; }
      .desktop-sidebar { background: var(--color-bg); border-right: 1px solid var(--color-border); display: flex; flex-direction: column; position: sticky; top: 0; height: 100vh; overflow-y: auto; }
      .sidebar-logo { padding: var(--space-lg); border-bottom: 1px solid var(--color-border); }
      .sidebar-logo h1 { font-size: 1.125rem; font-weight: 500; color: var(--color-text-primary); }
      .desktop-nav { flex: 1; padding: var(--space-lg); }
      .desktop-nav-item { display: block; width: 100%; padding: var(--space-sm) var(--space-md); background: none; border: none; text-align: left; font-size: 0.875rem; font-weight: 450; color: var(--color-text-secondary); cursor: pointer; border-radius: var(--radius-sm); transition: var(--transition); font-family: inherit; }
      .desktop-nav-item.active { background: var(--color-accent-light); color: var(--color-accent); font-weight: 500; }
      .desktop-nav-item:hover:not(.active) { background: var(--color-bg-secondary); }
      .desktop-main { padding: var(--space-xl) var(--space-lg); background: var(--color-bg-secondary); }
      .desktop-right { background: var(--color-bg); border-left: 1px solid var(--color-border); padding: var(--space-lg); position: sticky; top: 0; height: 100vh; overflow-y: auto; }
      .desktop-legal-footer { margin-top: auto; padding: var(--space-lg); border-top: 1px solid var(--color-border); }
      .desktop-story-card { background: var(--color-bg); border-radius: var(--radius-lg); padding: var(--space-lg); margin-bottom: var(--space-md); transition: var(--transition); border: 1px solid var(--color-border-light); cursor: pointer; max-width: 720px; margin-left: auto; margin-right: auto; }
      .desktop-story-card:hover { transform: translateY(-2px); box-shadow: var(--shadow-hover); border-color: var(--color-border); }
    }
    @media (max-width: 768px) {
      .desktop-layout { display: none; }
      .bottom-nav { padding-bottom: calc(var(--space-sm) + env(safe-area-inset-bottom, 0px)); }
      .feed-container { padding: var(--space-md); margin-bottom: 70px; }
      .story-card { margin-bottom: var(--space-sm); }
    }
  </style>
</head>
<body>
  <div id="app"></div>

  <script>
    const CONFIG = {
      appName: '{{ config('app.name') }}',
      defaultLocation: 'Kuala Lumpur',
      defaultRadius: 20,
      cacheDuration: 24 * 60 * 60 * 1000,
      geolocationTimeout: 5000,
      apiEndpoints: { ipGeolocation: 'https://ipapi.co/json/', reverseGeocode: 'https://nominatim.openstreetmap.org/reverse' }
    };

    const Utils = {
      escapeHtml(str) { if (!str) return ''; const div = document.createElement('div'); div.textContent = str; return div.innerHTML; },
      generateId() { return Date.now().toString(36) + Math.random().toString(36).substr(2); },
      formatTimeAgoFromDate(dateValue) {
        if (!dateValue) return 'Just now';
        const date = new Date(dateValue);
        if (Number.isNaN(date.getTime())) return 'Just now';
        const diffHours = Math.max(0, (Date.now() - date.getTime()) / 36e5);
        if (diffHours < 1) return 'Just now';
        if (diffHours < 2) return '1 hour ago';
        if (diffHours < 24) return Math.round(diffHours) + ' hours ago';
        return Math.floor(diffHours / 24) + ' days ago';
      },
      categoryLabel(item) {
        return item.primary_category || item.category || 'general';
      }
    };

    const API = {
      async fetchStories(params = {}) {
        const qs = new URLSearchParams(params).toString();
        const url = '/api/feed/default' + (qs ? `?${qs}` : '');
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(`Feed request failed (${response.status})`);
        const data = await response.json();
        return Array.isArray(data) ? data : [];
      },
      async fetchNearby(lat, lng, radius = 20) {
        const qs = new URLSearchParams({ lat: String(lat), lng: String(lng), radius: String(radius) }).toString();
        const response = await fetch('/api/feed/nearby?' + qs, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(`Nearby request failed (${response.status})`);
        const data = await response.json();
        return Array.isArray(data) ? data : [];
      }
    };

    class Store {
      constructor(initialState) { this.state = { ...initialState }; this.listeners = []; }
      getState() { return { ...this.state }; }
      setState(updates) { this.state = { ...this.state, ...updates }; this._notify(); }
      _notify() { this.listeners.forEach(listener => listener(this.state)); }
      subscribe(listener) { this.listeners.push(listener); return () => { this.listeners = this.listeners.filter(l => l !== listener); }; }
    }

    const GeolocationService = {
      async detectLocation() {
        try { const location = await this._fetchIPLocation(); return location || CONFIG.defaultLocation; } catch { return CONFIG.defaultLocation; }
      },
      async getCoordinates() {
        return new Promise((resolve) => {
          if (!("geolocation" in navigator)) { resolve(null); return; }
          navigator.geolocation.getCurrentPosition(
            (position) => resolve({ lat: position.coords.latitude, lng: position.coords.longitude }),
            () => resolve(null),
            { timeout: CONFIG.geolocationTimeout }
          );
        });
      },
      async _fetchIPLocation() {
        const controller = new AbortController();
        const timeoutId = setTimeout(() => controller.abort(), CONFIG.geolocationTimeout);
        try {
          const response = await fetch(CONFIG.apiEndpoints.ipGeolocation, { signal: controller.signal });
          const data = await response.json();
          clearTimeout(timeoutId);
          return data?.city || null;
        } catch (error) {
          clearTimeout(timeoutId);
          throw error;
        }
      }
    };

    class ModalManager {
      constructor() { this.activeModals = new Map(); this.container = null; }
      init() { if (!this.container) { this.container = document.createElement('div'); this.container.id = 'modal-container'; document.body.appendChild(this.container); } }
      open(config) {
        const id = Utils.generateId();
        const modal = document.createElement('div');
        modal.className = 'modal-overlay';
        modal.id = id;
        modal.innerHTML = '<div class="modal"><h3>' + Utils.escapeHtml(config.title) + '</h3><div class="modal-scrollable">' + config.content + '</div><div class="modal-buttons">' + config.buttons.map(btn => '<button class="modal-btn ' + (btn.className || '') + '" data-action="' + btn.action + '">' + Utils.escapeHtml(btn.label) + '</button>').join('') + '</div></div>';
        modal.addEventListener('click', (e) => { if (e.target === modal) this.close(id); });
        this.container.appendChild(modal);
        setTimeout(() => modal.classList.add('active'), 10);
        this.activeModals.set(id, { modal, config });
        this._bindModalEvents(id, config);
        return id;
      }
      close(id) { const entry = this.activeModals.get(id); if (!entry) return; entry.modal.classList.remove('active'); setTimeout(() => entry.modal.remove(), 300); this.activeModals.delete(id); }
      _bindModalEvents(id, config) { const modal = document.getElementById(id); if (!modal) return; modal.querySelectorAll('.modal-btn').forEach(btn => { const handler = config.buttonHandlers?.[btn.dataset.action]; if (handler) btn.addEventListener('click', () => handler(modal, id)); }); }
    }

    const Components = {
      createStoryCard(story, isDesktop = false) {
        const card = document.createElement('article');
        card.className = isDesktop ? "desktop-story-card" : "story-card";
        const badge = story.distance_km !== undefined ? '<span class="story-nearby">' + story.distance_km + ' km</span>' : '';
        card.innerHTML = '<div class="story-meta"><span class="story-source">' + Utils.escapeHtml(story.source || 'Unknown') + '</span><span class="story-category">' + Utils.escapeHtml(Utils.categoryLabel(story)) + '</span>' + badge + '</div><h3 class="story-title">' + Utils.escapeHtml(story.title) + '</h3><p class="story-summary">' + Utils.escapeHtml(story.summary || '') + '</p><div class="story-footer"><span>' + Utils.formatTimeAgoFromDate(story.published_at) + '</span><span>' + Utils.escapeHtml(story.location_label || story.locationName || 'Malaysia') + '</span></div>';
        card.addEventListener('click', () => { if (story.url) window.open(story.url, '_blank', 'noopener,noreferrer'); });
        return card;
      },
      createSkeleton() { return '<div class="skeleton-card"><div class="skeleton-line" style="width:30%;height:12px;"></div><div class="skeleton-line skeleton-title"></div><div class="skeleton-line skeleton-text"></div><div class="skeleton-line skeleton-text short"></div></div>'; }
    };

    class App {
      constructor() {
        this.store = new Store({ activeTab: "nearme", locationName: "Loading...", radiusKm: CONFIG.defaultRadius, timeHours: 168, selectedCategory: "All", selectedInterest: "All categories", radiusFilter: "Both", isLoading: true, stories: [], nearbyStories: [] });
        this.modalManager = new ModalManager();
        this.modalManager.init();
        this.init();
      }
      async init() { this.render(); this.bindEvents(); await Promise.all([this.loadLocation(), this.loadStories()]); this.store.setState({ isLoading: false }); }
      async loadLocation() { const location = await GeolocationService.detectLocation(); this.store.setState({ locationName: location }); }
      async loadStories() {
        try {
          const [stories, coords] = await Promise.all([API.fetchStories(), GeolocationService.getCoordinates()]);
          const nearby = coords ? await API.fetchNearby(coords.lat, coords.lng, this.store.getState().radiusKm) : [];
          this.store.setState({ stories, nearbyStories: nearby });
        } catch (error) {
          console.error('Failed to load stories', error);
          this.store.setState({ stories: [], nearbyStories: [] });
        }
      }
      normalizeStory(item) {
        return {
          ...item,
          category: Utils.categoryLabel(item),
          locationName: item.location_label || item.locationName || 'Malaysia',
          type: Utils.storyType(item),
        };
      }
      getCurrentStories() {
        const state = this.store.getState();
        const source = state.activeTab === 'nearme' && state.nearbyStories.length ? state.nearbyStories : state.stories;
        const cutoff = state.timeHours ? Date.now() - (state.timeHours * 60 * 60 * 1000) : 0;
        return source.map(item => this.normalizeStory(item)).filter(item => !cutoff || !item.published_at || new Date(item.published_at).getTime() >= cutoff).filter(item => {
          if (state.activeTab === 'marketplace') return false;
          if (state.activeTab === 'nearme') {
            if (state.radiusFilter === 'Nearby only' && item.distance_km === undefined) return false;
            if (state.radiusFilter === 'Broader only' && item.distance_km !== undefined) return false;
            return state.selectedCategory === 'All' || item.category === state.selectedCategory;
          }
          if (state.activeTab === 'interest') {
            const q = state.selectedInterest;
            return q === 'All categories' || item.category === q || (item.secondary_category && item.secondary_category.toLowerCase().includes(q.toLowerCase()));
          }
          return true;
        }).sort((a, b) => {
          const at = a.published_at ? new Date(a.published_at).getTime() : 0;
          const bt = b.published_at ? new Date(b.published_at).getTime() : 0;
          return bt - at;
        });
      }
      render() {
        const state = this.store.getState();
        const isDesktop = window.innerWidth > 768;
        if (isDesktop) this.renderDesktop(state); else this.renderMobile(state);
        this.renderFeed();
        if (isDesktop) this.renderDesktopSidebar();
      }
      renderMobile(state) {
        document.getElementById('app').innerHTML = '<div class="mobile-layout"><div class="header"><div class="logo">' + CONFIG.appName + '</div><div class="header-actions" id="headerActions"></div></div><div class="pull-to-refresh" id="pullToRefresh">Pull down to refresh</div><div class="feed-container" id="feedContainer"></div><div class="bottom-nav" id="bottomNav"><button class="nav-item ' + (state.activeTab === 'nearme' ? 'active' : '') + '" data-tab="nearme">Near Me</button><button class="nav-item ' + (state.activeTab === 'interest' ? 'active' : '') + '" data-tab="interest">By Interest</button><button class="nav-item ' + (state.activeTab === 'marketplace' ? 'active' : '') + '" data-tab="marketplace">Marketplace</button></div><div class="legal-footer"><div class="legal-links"><a class="legal-link" onclick="app.openReportModal()">Report Content</a><a class="legal-link" onclick="app.openLegalModal('terms')">Terms</a><a class="legal-link" onclick="app.openLegalModal('privacy')">Privacy</a><a class="legal-link" onclick="app.openLegalModal('disclaimer')">Disclaimer</a></div><div>© 2026 ' + CONFIG.appName + '</div></div></div>';
        this.renderHeaderButtons();
      }
      renderDesktop(state) {
        document.getElementById('app').innerHTML = '<div class="desktop-layout"><div class="desktop-sidebar"><div class="sidebar-logo"><h1>' + CONFIG.appName + '</h1></div><div class="desktop-nav"><button class="desktop-nav-item ' + (state.activeTab === 'nearme' ? 'active' : '') + '" data-tab="nearme">Near Me</button><button class="desktop-nav-item ' + (state.activeTab === 'interest' ? 'active' : '') + '" data-tab="interest">By Interest</button><button class="desktop-nav-item ' + (state.activeTab === 'marketplace' ? 'active' : '') + '" data-tab="marketplace">Marketplace</button></div><div class="desktop-legal-footer"><button class="desktop-report-btn" onclick="app.openReportModal()">Report Content</button><div class="legal-links" style="flex-direction:column;gap:8px;margin-top:16px;"><a class="legal-link" onclick="app.openLegalModal('terms')">Terms of Use</a><a class="legal-link" onclick="app.openLegalModal('privacy')">Privacy Policy</a><a class="legal-link" onclick="app.openLegalModal('disclaimer')">Disclaimer</a></div><div style="margin-top:16px;font-size:0.75rem;color:var(--color-text-tertiary);">© 2026 ' + CONFIG.appName + '</div></div></div><div class="desktop-main" id="desktopFeedContainer"></div><div class="desktop-right" id="desktopRightSidebar"></div></div>';
      }
      renderFeed() {
        const state = this.store.getState();
        const isDesktop = window.innerWidth > 768;
        const container = document.getElementById(isDesktop ? "desktopFeedContainer" : "feedContainer");
        if (!container) return;
        if (state.isLoading) { container.innerHTML = Array(3).fill(Components.createSkeleton()).join(''); return; }
        if (state.activeTab === "marketplace") { container.innerHTML = '<div class="empty-state">Marketplace coming soon</div>'; return; }
        const stories = this.getCurrentStories();
        if (stories.length === 0) { container.innerHTML = '<div class="empty-state">No live stories match your filters</div>'; return; }
        container.innerHTML = '';
        stories.forEach(story => container.appendChild(Components.createStoryCard(story, isDesktop)));
      }
      renderDesktopSidebar() {
        if (window.innerWidth <= 768) return;
        const state = this.store.getState();
        const sidebar = document.getElementById("desktopRightSidebar");
        if (!sidebar) return;
        sidebar.innerHTML = '<div class="info-card"><h3>Current Settings</h3><div class="info-row"><span>Location</span><span>' + Utils.escapeHtml(state.locationName) + '</span></div>' + (state.activeTab === "nearme" ? '<div class="info-row"><span>Radius</span><span>' + state.radiusKm + ' km</span></div>' : '') + '<div class="info-row"><span>Time range</span><span>' + (state.timeHours >= 720 ? '30d' : state.timeHours >= 168 ? '7d' : state.timeHours >= 72 ? '3d' : '24h') + '</span></div></div><button class="desktop-action-btn" onclick="app.openLocationModal()">Change Location</button>';
        if (state.activeTab === "nearme") {
          sidebar.insertAdjacentHTML('beforeend', '<div class="info-card"><h3>Categories</h3><div class="filter-chips" id="desktopCategories"></div></div><div class="info-card"><h3>Story Radius</h3><div class="filter-chips" id="desktopRadius"></div></div>');
          ['All', 'Transport', 'Property', 'Lifestyle', 'Business', 'Crime', 'Sports'].forEach(cat => { const btn = document.createElement('button'); btn.className = 'filter-chip ' + (state.selectedCategory === cat ? 'active' : ''); btn.textContent = cat; btn.onclick = () => this.store.setState({ selectedCategory: cat }); document.getElementById('desktopCategories').appendChild(btn); });
          ['Both', 'Nearby only', 'Broader only'].forEach(r => { const btn = document.createElement('button'); btn.className = 'filter-chip ' + (state.radiusFilter === r ? 'active' : ''); btn.textContent = r; btn.onclick = () => this.store.setState({ radiusFilter: r }); document.getElementById('desktopRadius').appendChild(btn); });
        } else if (state.activeTab === "interest") {
          sidebar.insertAdjacentHTML('beforeend', '<div class="info-card"><h3>Interests</h3><div class="searchable-dropdown" id="interestDropdown"></div></div>');
          this.initInterestDropdown();
        }
      }
      initInterestDropdown() {
        const container = document.getElementById('interestDropdown');
        if (!container) return;
        const state = this.store.getState();
        const interests = ['All categories', 'Sports', 'Property', 'Lifestyle', 'Transport', 'Business', 'Crime'];
        container.innerHTML = '<div class="dropdown-trigger" id="interestTrigger"><span>' + Utils.escapeHtml(state.selectedInterest) + '</span><span>▼</span></div><div class="dropdown-menu" id="interestMenu"><div class="dropdown-search"><input type="text" id="interestSearch" placeholder="Search interests..."></div><div class="dropdown-options" id="interestOptions"></div></div>';
        const menu = document.getElementById('interestMenu');
        const search = document.getElementById('interestSearch');
        const renderOpts = (term = '') => { const opts = document.getElementById('interestOptions'); opts.innerHTML = interests.filter(i => !term || i.toLowerCase().includes(term.toLowerCase())).map(i => '<div class="dropdown-option ' + (state.selectedInterest === i ? 'selected' : '') + '" data-v="' + Utils.escapeHtml(i) + '">' + Utils.escapeHtml(i) + '</div>').join(''); opts.querySelectorAll('.dropdown-option').forEach(o => o.addEventListener('click', () => { this.store.setState({ selectedInterest: o.dataset.v }); document.querySelector('#interestTrigger span').textContent = o.dataset.v; menu.classList.remove('open'); })); };
        document.getElementById('interestTrigger').addEventListener('click', (e) => { e.stopPropagation(); menu.classList.toggle('open'); if (menu.classList.contains('open')) { setTimeout(() => search.focus(), 100); renderOpts(''); search.value = ''; } });
        search.addEventListener('input', e => renderOpts(e.target.value));
        document.addEventListener('click', e => { if (!container.contains(e.target)) menu.classList.remove('open'); });
        renderOpts('');
      }
      renderHeaderButtons() { const a = document.getElementById("headerActions"); if (a) a.innerHTML = '<button class="icon-btn" onclick="app.openLocationModal()">Location</button><button class="icon-btn" onclick="app.openFilterModal()">Filter</button>'; }
      openLocationModal() {
        const state = this.store.getState();
        this.modalManager.open({
          title: 'Location',
          content: '<label class="modal-label">Your location</label><input type="text" id="locationInput" class="modal-input" value="' + Utils.escapeHtml(state.locationName) + '"><button id="useCurrentLocation" class="modal-btn" style="margin-top:8px;">Use my current location</button><div id="radiusSection" style="' + (state.activeTab === 'nearme' ? 'display:block' : 'display:none') + '"><label class="modal-label">Radius (km)</label><select id="radiusSelect" class="modal-select"><option value="5">5 km</option><option value="10">10 km</option><option value="20"' + (state.radiusKm === 20 ? ' selected' : '') + '>20 km</option><option value="30">30 km</option><option value="50">50 km</option></select></div>',
          buttons: [{ label: 'Cancel', action: 'cancel' }, { label: 'Save', action: 'save', className: 'primary' }],
          buttonHandlers: { cancel: (m, id) => this.modalManager.close(id), save: (m, id) => { const loc = document.getElementById('locationInput').value.trim(); if (loc) this.store.setState({ locationName: loc }); if (state.activeTab === 'nearme') { const r = parseInt(document.getElementById('radiusSelect').value); if (!isNaN(r)) { this.store.setState({ radiusKm: r }); this.loadStories(); } } this.modalManager.close(id); } }
        });
        setTimeout(() => { const btn = document.getElementById('useCurrentLocation'); if (btn) btn.onclick = async () => { const loc = await GeolocationService.detectLocation(); const inp = document.getElementById('locationInput'); if (loc && inp) inp.value = loc; }; }, 100);
      }
      openFilterModal() { const state = this.store.getState(); if (state.activeTab === "nearme") this.openNearbyFilterModal(); else if (state.activeTab === "interest") this.openInterestFilterModal(); }
      openNearbyFilterModal() {
        const state = this.store.getState();
        this.modalManager.open({ title: 'Filters', content: '<label class="modal-label">Time period</label><div class="filter-chips" id="timeChips"></div><label class="modal-label">Story radius</label><div class="filter-chips" id="radiusChips"></div><label class="modal-label">Category</label><div class="filter-chips" id="categoryChips"></div>', buttons: [{ label: 'Cancel', action: 'cancel' }, { label: 'Apply', action: 'apply', className: 'primary' }], buttonHandlers: { cancel: (m, id) => this.modalManager.close(id), apply: (m, id) => this.modalManager.close(id) } });
        setTimeout(() => { ['24h', '3d', '7d', '30d'].forEach((label, idx) => { const hours = [24, 72, 168, 720][idx]; const btn = document.createElement('button'); btn.className = 'filter-chip ' + (state.timeHours === hours ? 'active' : ''); btn.textContent = label; btn.onclick = () => this.store.setState({ timeHours: hours }); document.getElementById('timeChips').appendChild(btn); }); ['Both', 'Nearby only', 'Broader only'].forEach(r => { const btn = document.createElement('button'); btn.className = 'filter-chip ' + (state.radiusFilter === r ? 'active' : ''); btn.textContent = r; btn.onclick = () => this.store.setState({ radiusFilter: r }); document.getElementById('radiusChips').appendChild(btn); }); ['All', 'Transport', 'Property', 'Lifestyle', 'Business', 'Crime', 'Sports'].forEach(c => { const btn = document.createElement('button'); btn.className = 'filter-chip ' + (state.selectedCategory === c ? 'active' : ''); btn.textContent = c; btn.onclick = () => this.store.setState({ selectedCategory: c }); document.getElementById('categoryChips').appendChild(btn); }); }, 100);
      }
      openInterestFilterModal() {
        const state = this.store.getState();
        this.modalManager.open({ title: 'Filter by Interest', content: '<label class="modal-label">Time period</label><div class="filter-chips" id="timeChips"></div><label class="modal-label">Search interests</label><input type="text" id="interestSearchModal" class="modal-input" placeholder="Search interests..."><div id="interestListModal" style="max-height:250px;overflow-y:auto;border:1px solid var(--color-border);border-radius:var(--radius-sm);margin-top:8px;"></div>', buttons: [{ label: 'Cancel', action: 'cancel' }, { label: 'Apply', action: 'apply', className: 'primary' }], buttonHandlers: { cancel: (m, id) => this.modalManager.close(id), apply: (m, id) => this.modalManager.close(id) } });
        setTimeout(() => { ['24h', '3d', '7d', '30d'].forEach((label, idx) => { const hours = [24, 72, 168, 720][idx]; const btn = document.createElement('button'); btn.className = 'filter-chip ' + (state.timeHours === hours ? 'active' : ''); btn.textContent = label; btn.onclick = () => this.store.setState({ timeHours: hours }); document.getElementById('timeChips').appendChild(btn); }); const interests = ['All categories', 'Sports', 'Property', 'Lifestyle', 'Transport', 'Business', 'Crime']; const renderList = (term = '') => { const lc = document.getElementById('interestListModal'); lc.innerHTML = interests.filter(i => !term || i.toLowerCase().includes(term.toLowerCase())).map(i => '<div class="interest-option" style="padding:12px;cursor:pointer;border-bottom:1px solid var(--color-border-light);' + (state.selectedInterest === i ? 'background:var(--color-accent-light);' : '') + '">' + Utils.escapeHtml(i) + '</div>').join(''); lc.querySelectorAll('.interest-option').forEach(o => o.addEventListener('click', () => this.store.setState({ selectedInterest: o.textContent.trim() }))); }; document.getElementById('interestSearchModal').addEventListener('input', e => renderList(e.target.value)); renderList(''); }, 100);
      }
      openReportModal() {
        const stories = this.getCurrentStories();
        this.modalManager.open({ title: 'Report Content', content: '<label class="modal-label">Select news to report</label><select id="reportStorySelect" class="modal-select">' + stories.map((s, idx) => '<option value="' + (s.id || idx) + '">' + Utils.escapeHtml(s.title) + '</option>').join('') + '</select><label class="modal-label">Reason</label><select id="reportReason" class="modal-select"><option value="inaccurate">Inaccurate</option><option value="spam">Spam or promotional content</option><option value="inappropriate">Inappropriate content</option><option value="other">Other</option></select><label class="modal-label">Additional details (optional)</label><textarea id="reportDetails" class="modal-textarea" placeholder="Please provide additional context..."></textarea>', buttons: [{ label: 'Cancel', action: 'cancel' }, { label: 'Submit Report', action: 'submit', className: 'danger' }], buttonHandlers: { cancel: (m, id) => this.modalManager.close(id), submit: (m, id) => { alert('Report submitted. Thank you.'); this.modalManager.close(id); } } });
      }
      openLegalModal(type) { const c = { terms: { title: 'Terms of Use', content: "<p>By using {{ config('app.name') }}, you agree to our terms. Content is for informational purposes only.</p>" }, privacy: { title: 'Privacy Policy', content: "<p>We value your privacy. Location data is used only to show relevant content and is not shared with third parties.</p>" }, disclaimer: { title: 'Disclaimer', content: "<p>Content is aggregated from third-party sources. We do not independently verify all information.</p>" } }[type]; if (!c) return; this.modalManager.open({ title: c.title, content: c.content, buttons: [{ label: 'Close', action: 'close', className: 'primary' }], buttonHandlers: { close: (m, id) => this.modalManager.close(id) } }); }
      bindEvents() {
        this.store.subscribe(() => this.render());
        window.addEventListener('resize', () => this.render());
        document.addEventListener('click', e => { const tab = e.target.closest('[data-tab]'); if (tab) this.store.setState({ activeTab: tab.dataset.tab }); });
        let touchStart = 0;
        const setupPullToRefresh = () => { const fc = document.getElementById('feedContainer'); if (!fc || window.innerWidth > 768) return; fc.addEventListener('touchstart', e => { if (fc.scrollTop === 0) touchStart = e.touches[0].clientY; }); fc.addEventListener('touchmove', e => { if (fc.scrollTop === 0 && touchStart) { const pull = e.touches[0].clientY - touchStart; if (pull > 0 && pull < 100) { e.preventDefault(); const ptr = document.getElementById('pullToRefresh'); if (ptr) { ptr.classList.add('visible'); ptr.style.transform = 'translateY(' + Math.min(pull * 0.5, 40) + 'px)'; } } } }); fc.addEventListener('touchend', async () => { const ptr = document.getElementById('pullToRefresh'); if (ptr && ptr.classList.contains('visible')) { ptr.textContent = 'Refreshing...'; this.store.setState({ isLoading: true }); await this.loadStories(); this.store.setState({ isLoading: false }); ptr.textContent = 'Pull down to refresh'; ptr.classList.remove('visible'); ptr.style.transform = ''; } touchStart = 0; }); };
        setTimeout(setupPullToRefresh, 100);
      }
    }
    window.app = new App();
  </script>
</body>
</html>