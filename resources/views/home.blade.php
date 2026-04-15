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

  <style>
    :root { --color-bg: #ffffff; --color-bg-secondary: #fafaf9; --color-bg-tertiary: #f5f5f4; --color-text-primary: #1c1917; --color-text-secondary: #44403c; --color-text-tertiary: #78716c; --color-border: #e7e5e4; --color-border-light: #f5f5f4; --color-accent: #0f3b2c; --color-accent-light: #ecfdf5; --color-accent-muted: #2d5a4a; --color-nearby: #0f3b2c; --color-error: #991b1b; --font-sans: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', system-ui, Helvetica, sans-serif; --space-xs: 4px; --space-sm: 8px; --space-md: 16px; --space-lg: 24px; --space-xl: 32px; --space-2xl: 48px; --radius-sm: 6px; --radius-md: 12px; --radius-lg: 16px; --shadow-sm: 0 1px 2px rgba(0, 0, 0, 0.03); --shadow-md: 0 2px 4px rgba(0, 0, 0, 0.05); --shadow-hover: 0 8px 24px rgba(0, 0, 0, 0.08); --transition: all 0.2s cubic-bezier(0.2, 0.9, 0.4, 1.1); }
    * { margin: 0; padding: 0; box-sizing: border-box; }
    body { background: linear-gradient(180deg, #fafaf9 0%, #f5f5f4 100%); font-family: var(--font-sans); color: var(--color-text-primary); line-height: 1.5; -webkit-font-smoothing: antialiased; }
    h1, h2, h3, h4, h5, h6 { font-weight: 500; line-height: 1.3; letter-spacing: -0.01em; }
    .logo { font-size: 1.35rem; font-weight: 600; color: var(--color-text-primary); letter-spacing: -0.03em; }
    .mobile-layout, .desktop-layout { min-height: 100vh; }
    .header { display: flex; justify-content: space-between; align-items: center; padding: 14px 20px; background: rgba(255, 255, 255, 0.96); border-bottom: 1px solid rgba(231, 229, 228, 0.95); position: sticky; top: 0; z-index: 40; backdrop-filter: blur(14px); box-shadow: 0 1px 0 rgba(28,25,23,0.02); }
    .header-actions { display: flex; gap: 10px; }
    .icon-btn { background: #fff; border: 1px solid var(--color-border); border-radius: 999px; padding: 9px 15px; font-size: 0.875rem; font-weight: 500; color: var(--color-text-secondary); cursor: pointer; transition: var(--transition); font-family: inherit; box-shadow: var(--shadow-sm); }
    .icon-btn:hover { background: var(--color-bg-secondary); border-color: #d6d3d1; color: var(--color-text-primary); }
    .feed-container { padding: 28px 20px 96px; max-width: 860px; margin: 0 auto; }
    .story-card, .desktop-story-card { background: linear-gradient(180deg, rgba(255,255,255,0.98) 0%, #ffffff 100%); border-radius: 22px; padding: 22px; margin-bottom: 16px; transition: var(--transition); border: 1px solid #ece9e6; cursor: pointer; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.04); }
    .story-card:hover, .desktop-story-card:hover { transform: translateY(-3px); box-shadow: 0 18px 40px rgba(15, 23, 42, 0.08); border-color: #ddd6d0; }
    .story-meta { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; font-size: 0.72rem; font-weight: 600; text-transform: uppercase; letter-spacing: 0.08em; }
    .story-source { font-weight: 700; color: var(--color-accent-muted); }
    .story-category { color: var(--color-text-tertiary); background: var(--color-bg-secondary); padding: 4px 9px; border-radius: 999px; }
    .story-nearby { color: var(--color-nearby); font-weight: 700; background: var(--color-accent-light); padding: 4px 10px; border-radius: 999px; font-size: 0.68rem; }
    .story-title { font-size: clamp(1.2rem, 2vw, 1.55rem); font-weight: 600; line-height: 1.35; margin-bottom: 10px; color: var(--color-text-primary); letter-spacing: -0.02em; }
    .story-summary { font-size: 0.97rem; color: var(--color-text-secondary); line-height: 1.65; margin-bottom: 14px; }
    .story-footer { display: flex; gap: 16px; flex-wrap: wrap; font-size: 0.78rem; color: var(--color-text-tertiary); padding-top: 12px; border-top: 1px solid var(--color-border-light); }
    .empty-state { text-align: center; padding: 56px 24px; color: var(--color-text-tertiary); font-size: 0.95rem; background: linear-gradient(180deg, rgba(255,255,255,0.98) 0%, #ffffff 100%); border-radius: 22px; border: 1px solid #ece9e6; box-shadow: 0 10px 30px rgba(15, 23, 42, 0.04); }
    .bottom-nav { position: fixed; bottom: 16px; left: 16px; right: 16px; background: rgba(255, 255, 255, 0.96); backdrop-filter: blur(16px); border: 1px solid rgba(231, 229, 228, 0.98); display: flex; justify-content: center; gap: 10px; padding: 10px; padding-bottom: calc(10px + env(safe-area-inset-bottom, 0px)); z-index: 50; border-radius: 22px; box-shadow: 0 18px 45px rgba(15, 23, 42, 0.12); }
    .nav-item { background: none; border: none; padding: 10px 14px; font-size: 0.88rem; font-weight: 500; color: var(--color-text-tertiary); cursor: pointer; transition: var(--transition); font-family: inherit; border-radius: 999px; }
    .nav-item.active { color: white; font-weight: 600; background: var(--color-accent); box-shadow: 0 8px 20px rgba(15,59,44,0.25); }
    .nav-item:hover { color: var(--color-text-primary); background: var(--color-bg-tertiary); }
    .pull-to-refresh { text-align: center; padding: var(--space-sm); color: var(--color-text-tertiary); font-size: 0.75rem; transition: transform 0.2s; transform: translateY(-100%); }
    .pull-to-refresh.visible { transform: translateY(0); }
    .desktop-layout { display: grid; grid-template-columns: 280px minmax(0, 1fr) 320px; gap: 24px; max-width: 1440px; margin: 0 auto; padding: 24px; }
    .desktop-sidebar, .desktop-right { position: sticky; top: 24px; align-self: start; }
    .desktop-sidebar { background: linear-gradient(180deg, rgba(255,255,255,0.98) 0%, #ffffff 100%); border: 1px solid #ece9e6; border-radius: 24px; padding: 24px; box-shadow: 0 12px 34px rgba(15, 23, 42, 0.05); }
    .sidebar-logo h1 { font-size: 1.5rem; letter-spacing: -0.03em; margin-bottom: 10px; }
    .desktop-nav { display: flex; flex-direction: column; gap: 10px; }
    .desktop-nav-item, .desktop-action-btn, .desktop-report-btn { width: 100%; border-radius: 16px; border: 1px solid var(--color-border); background: #fff; padding: 12px 14px; text-align: left; font: inherit; cursor: pointer; transition: var(--transition); color: var(--color-text-secondary); }
    .desktop-nav-item.active { background: var(--color-accent); color: #fff; border-color: var(--color-accent); box-shadow: 0 12px 25px rgba(15,59,44,0.18); }
    .desktop-main { min-width: 0; }
    .desktop-right { display: flex; flex-direction: column; gap: 16px; }
    .info-card { background: linear-gradient(180deg, rgba(255,255,255,0.98) 0%, #ffffff 100%); border: 1px solid #ece9e6; border-radius: 20px; padding: 18px; box-shadow: 0 10px 28px rgba(15, 23, 42, 0.04); }
    .info-card h3 { font-size: 0.95rem; margin-bottom: 12px; }
    .info-row { display: flex; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--color-border-light); font-size: 0.88rem; color: var(--color-text-secondary); }
    .info-row:last-child { border-bottom: 0; padding-bottom: 0; }
    .filter-chips { display: flex; flex-wrap: wrap; gap: 8px; }
    .filter-chip { border: 1px solid var(--color-border); background: #fff; color: var(--color-text-secondary); border-radius: 999px; padding: 8px 12px; font-size: 0.82rem; cursor: pointer; transition: var(--transition); }
    .filter-chip.active { background: var(--color-accent); color: #fff; border-color: var(--color-accent); }
    .legal-footer, .desktop-legal-footer { color: var(--color-text-tertiary); font-size: 0.78rem; text-align: center; padding: 8px 20px 120px; }
    .legal-links { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 10px; justify-content: center; }
    .legal-link { color: var(--color-text-tertiary); cursor: pointer; text-decoration: none; }
    .dropdown-trigger, .modal-input, .modal-select, .modal-textarea { width: 100%; border: 1px solid var(--color-border); border-radius: 14px; background: #fff; padding: 12px 14px; font: inherit; }
    .modal-overlay { position: fixed; inset: 0; background: rgba(28,25,23,0.42); backdrop-filter: blur(6px); display: flex; align-items: center; justify-content: center; padding: 20px; z-index: 2000; }
    .modal { width: min(560px, 100%); max-height: min(82vh, 760px); overflow: hidden; background: #fff; border: 1px solid #ece9e6; border-radius: 24px; box-shadow: 0 28px 80px rgba(15, 23, 42, 0.22); padding: 22px; display: flex; flex-direction: column; }
    .modal h3 { font-size: 1.1rem; margin-bottom: 14px; letter-spacing: -0.02em; }
    .modal-scrollable { overflow: auto; padding-right: 4px; }
    .modal-label { display: block; font-size: 0.84rem; font-weight: 600; color: var(--color-text-secondary); margin: 14px 0 8px; }
    .modal-buttons { display: flex; justify-content: flex-end; gap: 10px; margin-top: 18px; }
    .modal-btn { border: 1px solid var(--color-border); background: #fff; color: var(--color-text-secondary); border-radius: 14px; padding: 10px 16px; font: inherit; cursor: pointer; transition: var(--transition); }
    .modal-btn.primary { background: var(--color-accent); color: #fff; border-color: var(--color-accent); box-shadow: 0 10px 24px rgba(15,59,44,0.22); }
    .modal-btn:hover { background: var(--color-bg-secondary); color: var(--color-text-primary); }
    .modal-btn.primary:hover { background: #124534; }
    .dropdown-menu { margin-top: 10px; border: 1px solid var(--color-border); border-radius: 16px; background: #fff; box-shadow: 0 18px 36px rgba(15,23,42,0.08); }
    .dropdown-option { padding: 10px 14px; cursor: pointer; }
    .dropdown-option.selected, .dropdown-option:hover { background: var(--color-accent-light); }
    .skeleton-card { background: linear-gradient(180deg, rgba(255,255,255,0.98) 0%, #ffffff 100%); border-radius: 22px; padding: 22px; margin-bottom: 16px; border: 1px solid #ece9e6; }
    .skeleton-line { height: 12px; background: linear-gradient(90deg, var(--color-border-light) 25%, var(--color-border) 50%, var(--color-border-light) 75%); background-size: 200% 100%; animation: shimmer 1.5s infinite; border-radius: var(--radius-sm); margin-bottom: var(--space-sm); }
    @keyframes shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
    .skeleton-title { width: 75%; height: 24px; }
    .skeleton-text { width: 100%; height: 60px; }
    .skeleton-text.short { width: 60%; height: 40px; }
    @media (max-width: 1024px) { .desktop-layout { grid-template-columns: 1fr; padding: 0; } .desktop-sidebar, .desktop-right { position: static; } .desktop-right { order: 3; padding: 0 20px 100px; } .desktop-main { order: 2; } }
    @media (max-width: 768px) { .header { padding: 14px 16px; } .feed-container { padding: 20px 16px 108px; } .story-card { margin-bottom: 12px; padding: 20px; } .story-title { font-size: 1.12rem; } .bottom-nav { left: 12px; right: 12px; bottom: 12px; } }
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
      feedEndpoint: '/api/feed/default',
      apiEndpoints: { ipGeolocation: 'https://ipapi.co/json/', reverseGeocode: 'https://nominatim.openstreetmap.org/reverse' }
    };

    const DATA = {
      stories: [],
      interests: ["All categories", "Sports", "Sports / Badminton", "Sports / Football", "Property", "Property / Condo", "Lifestyle", "Lifestyle / Food", "Transport", "Business", "Crime"],
      timeFilters: [ { label: "24h", hours: 24 }, { label: "3d", hours: 72 }, { label: "7d", hours: 168 }, { label: "30d", hours: 720 } ],
      categories: ["All", "Transport", "Property", "Lifestyle", "Business", "Crime", "Sports"],
      radiusFilters: ["Both", "Nearby only", "Broader only"],
      legalContent: {
        terms: { title: "Terms of Use", content: "<p>By using Nearbypost, you agree to our terms. Content is for informational purposes only.</p><p style='margin-top:16px'>Last updated: March 31, 2026</p>" },
        privacy: { title: "Privacy Policy", content: "<p>We value your privacy. Location data is used only to show relevant content and is not shared with third parties.</p><p style='margin-top:16px'>Last updated: March 31, 2026</p>" },
        disclaimer: { title: "Disclaimer", content: "<p>Content is aggregated from third-party sources. We do not independently verify all information.</p><p style='margin-top:16px'>Last updated: March 31, 2026</p>" }
      }
    };

    const Utils = {
      escapeHtml(str) { if (!str) return ''; const div = document.createElement('div'); div.textContent = str; return div.innerHTML; },
      generateId() { return Date.now().toString(36) + Math.random().toString(36).substr(2); },
      formatTimeAgo(hours) { if (hours < 1) return 'Just now'; if (hours === 1) return '1 hour ago'; if (hours < 24) return hours + ' hours ago'; return Math.floor(hours / 24) + ' days ago'; }
    };

    const StoryService = {
      async loadStories() {
        const response = await fetch(CONFIG.feedEndpoint, { headers: { Accept: 'application/json' } });
        if (!response.ok) throw new Error(`Feed request failed (${response.status})`);
        const items = await response.json();
        DATA.stories = (Array.isArray(items) ? items : []).map((item, index) => ({
          id: item.id ?? index + 1,
          title: item.title || 'Untitled story',
          summary: item.summary || 'No summary available.',
          category: this.prettyCategory(item.primary_category || 'others'),
          interestTag: this.interestTag(item.primary_category, item.secondary_category),
          source: item.source || 'Nearbypost',
          hoursAgo: this.hoursAgo(item.published_at),
          type: this.storyType(item),
          locationName: item.location_label || item.primary_category || 'Kuala Lumpur',
          url: item.url || ''
        }));
      },
      hoursAgo(publishedAt) {
        const ts = new Date(publishedAt).getTime();
        if (Number.isNaN(ts)) return 0;
        return Math.max(0, Math.round((Date.now() - ts) / 36e5));
      },
      prettyCategory(cat) {
        return String(cat || 'others').replace(/_/g, ' ').replace(/\b\w/g, s => s.toUpperCase());
      },
      interestTag(primary, secondary) {
        const p = this.prettyCategory(primary);
        const s = secondary ? ` / ${this.prettyCategory(secondary)}` : '';
        return `${p}${s}`.replace(/\s+\/\s+$/, '');
      },
      storyType(item) {
        const mode = String(item.relevance_mode || '').toLowerCase();
        if (mode === 'location_only' || item.distance_km !== undefined) return 'nearby';
        if (mode === 'category_only' || !mode) return 'interest';
        return 'broader';
      }
    };

    class Store {
      constructor(initialState) { this.state = { ...initialState }; this.listeners = []; this.cache = new Map(); }
      getState() { return { ...this.state }; }
      setState(updates) { this.state = { ...this.state, ...updates }; this.cache.clear(); this._notify(); }
      _notify() { this.listeners.forEach(listener => listener(this.state)); }
      subscribe(listener) { this.listeners.push(listener); return () => { this.listeners = this.listeners.filter(l => l !== listener); }; }
      getFilterKey() { const { activeTab, timeHours, selectedCategory, radiusFilter, selectedInterest, locationName } = this.state; return [activeTab, timeHours, selectedCategory, radiusFilter, selectedInterest, locationName].join('|'); }
      getFilteredStories() {
        const key = this.getFilterKey();
        if (this.cache.has(key)) return this.cache.get(key);
        let stories = DATA.stories.filter(s => s.hoursAgo <= this.state.timeHours);
        if (this.state.activeTab === "nearme") stories = this._filterNearby(stories);
        else if (this.state.activeTab === "interest") stories = this._filterInterest(stories);
        else stories = [];
        this.cache.set(key, stories);
        return stories;
      }
      _filterNearby(stories) {
        let filtered = stories.filter(s => s.type === "nearby" || s.type === "broader");
        if (this.state.radiusFilter === "Nearby only") filtered = filtered.filter(s => s.type === "nearby");
        else if (this.state.radiusFilter === "Broader only") filtered = filtered.filter(s => s.type === "broader");
        if (this.state.selectedCategory !== "All") filtered = filtered.filter(s => s.category === this.state.selectedCategory);
        return filtered.sort((a, b) => { if (a.type === "nearby" && b.type !== "nearby") return -1; if (a.type !== "nearby" && b.type === "nearby") return 1; return b.hoursAgo - a.hoursAgo; });
      }
      _filterInterest(stories) {
        let filtered = stories.filter(s => s.type === "interest");
        if (this.state.selectedInterest !== "All categories") filtered = filtered.filter(s => s.interestTag === this.state.selectedInterest);
        return filtered.sort((a, b) => a.hoursAgo - b.hoursAgo);
      }
    }

    const GeolocationService = {
      async detectLocation() {
        const cached = this._getCached();
        if (cached) return cached;
        try { const location = await this.getBrowserLocation(); if (location) { this._cacheLocation(location); return location; } } catch (e) { console.warn('Browser geolocation failed:', e); }
        try { const location = await this._fetchIPLocation(); if (location) { this._cacheLocation(location); return location; } } catch (e) { console.warn('IP geolocation failed:', e); }
        return CONFIG.defaultLocation;
      },
      _getCached() { const saved = localStorage.getItem('nearbypost_location'); const timestamp = localStorage.getItem('nearbypost_location_timestamp'); if (saved && timestamp && (Date.now() - parseInt(timestamp)) < CONFIG.cacheDuration) return saved; return null; },
      _cacheLocation(location) { localStorage.setItem('nearbypost_location', location); localStorage.setItem('nearbypost_location_timestamp', Date.now().toString()); },
      async _fetchIPLocation() { const controller = new AbortController(); const timeoutId = setTimeout(() => controller.abort(), CONFIG.geolocationTimeout); try { const response = await fetch(CONFIG.apiEndpoints.ipGeolocation, { signal: controller.signal }); const data = await response.json(); clearTimeout(timeoutId); return data?.city || null; } catch (error) { clearTimeout(timeoutId); throw error; } },
      async getBrowserLocation() { return new Promise((resolve) => { if (!("geolocation" in navigator)) { resolve(null); return; } navigator.geolocation.getCurrentPosition(async (position) => { try { const url = CONFIG.apiEndpoints.reverseGeocode + '?format=json&lat=' + position.coords.latitude + '&lon=' + position.coords.longitude + '&zoom=10'; const response = await fetch(url); const data = await response.json(); resolve(data.address?.city || data.address?.town || data.address?.suburb || null); } catch { resolve(null); } }, () => resolve(null)); }); }
    };

    class ModalManager {
      constructor() { this.modals = new Map(); }
      open(config) {
        const id = Utils.generateId();
        const modal = document.createElement('div');
        modal.className = 'modal-overlay';
        modal.innerHTML = '<div class="modal"><h3>' + Utils.escapeHtml(config.title) + '</h3><div class="modal-scrollable">' + config.content + '</div><div class="modal-buttons">' + config.buttons.map(btn => '<button class="modal-btn ' + (btn.className || '') + '" data-action="' + btn.action + '">' + Utils.escapeHtml(btn.label) + '</button>').join('') + '</div></div>';
        document.body.appendChild(modal);
        this.modals.set(id, modal);
        modal.addEventListener('click', e => { if (e.target === modal) this.close(id); });
        modal.querySelectorAll('[data-action]').forEach(btn => btn.addEventListener('click', () => config.buttonHandlers?.[btn.dataset.action]?.(modal, id)));
        return id;
      }
      close(id) { const modal = this.modals.get(id); if (modal) { modal.remove(); this.modals.delete(id); } }
    }

    const Components = {
      createStoryCard(story, isDesktop = false) {
        const card = document.createElement('article');
        card.className = isDesktop ? "desktop-story-card" : "story-card";
        const nearbyBadge = story.type === 'nearby' ? '<span class="story-nearby">Nearby</span>' : '';
        card.innerHTML = '<div class="story-meta"><span class="story-source">' + Utils.escapeHtml(story.source) + '</span><span class="story-category">' + Utils.escapeHtml(story.category) + '</span>' + nearbyBadge + '</div><h3 class="story-title">' + Utils.escapeHtml(story.title) + '</h3><p class="story-summary">' + Utils.escapeHtml(story.summary) + '</p><div class="story-footer"><span>' + Utils.formatTimeAgo(story.hoursAgo) + '</span><span>' + Utils.escapeHtml(story.locationName) + '</span></div>';
        card.addEventListener('click', () => story.url && window.open(story.url, '_blank', 'noopener'));
        return card;
      },
      createSkeleton() { return '<div class="skeleton-card"><div class="skeleton-line" style="width:30%;height:12px;"></div><div class="skeleton-line skeleton-title"></div><div class="skeleton-line skeleton-text"></div><div class="skeleton-line skeleton-text short"></div></div>'; }
    };

    class NearbypostApp {
      constructor() {
        this.store = new Store({ activeTab: "nearme", locationName: "Loading...", radiusKm: CONFIG.defaultRadius, timeHours: 168, selectedCategory: "All", selectedInterest: "All categories", radiusFilter: "Both", isLoading: true });
        this.modalManager = new ModalManager();
      }
      async init() {
        this.bindEvents();
        this.render();
        await Promise.allSettled([this.loadLocation(), StoryService.loadStories()]);
        this.store.setState({ isLoading: false });
      }
      async loadLocation() { const location = await GeolocationService.detectLocation(); this.store.setState({ locationName: location }); }
      render() {
        const state = this.store.getState();
        const isDesktop = window.innerWidth > 768;
        if (isDesktop) this.renderDesktop(state); else this.renderMobile(state);
        this.renderFeed();
        if (isDesktop) this.renderDesktopSidebar();
      }
      renderMobile(state) {
        document.getElementById('app').innerHTML = '<div class="mobile-layout"><div class="header"><div><div class="logo">' + CONFIG.appName + '</div><div style="font-size:0.78rem;color:var(--color-text-tertiary);margin-top:4px;">Hyperlocal updates that feel less chaotic</div></div><div class="header-actions" id="headerActions"></div></div><div class="pull-to-refresh" id="pullToRefresh">Pull down to refresh</div><div class="feed-container" id="feedContainer"></div><div class="bottom-nav" id="bottomNav"><button class="nav-item ' + (state.activeTab === 'nearme' ? 'active' : '') + '" data-tab="nearme">Near Me</button><button class="nav-item ' + (state.activeTab === 'interest' ? 'active' : '') + '" data-tab="interest">By Interest</button><button class="nav-item ' + (state.activeTab === 'marketplace' ? 'active' : '') + '" data-tab="marketplace">Marketplace</button></div><div class="legal-footer"><div class="legal-links"><a class="legal-link" onclick="app.openReportModal()">Report Content</a><a class="legal-link" onclick="app.openLegalModal(\'terms\')">Terms</a><a class="legal-link" onclick="app.openLegalModal(\'privacy\')">Privacy</a><a class="legal-link" onclick="app.openLegalModal(\'disclaimer\')">Disclaimer</a></div><div>© 2026 ' + CONFIG.appName + '</div></div></div>';
        this.renderHeaderButtons();
      }
      renderDesktop(state) {
        document.getElementById('app').innerHTML = '<div class="desktop-layout"><div class="desktop-sidebar"><div class="sidebar-logo"><h1>' + CONFIG.appName + '</h1><p style="color:var(--color-text-tertiary);font-size:0.9rem;line-height:1.5;margin-bottom:20px;">A calmer, cleaner local news feed.</p></div><div class="desktop-nav"><button class="desktop-nav-item ' + (state.activeTab === 'nearme' ? 'active' : '') + '" data-tab="nearme">Near Me</button><button class="desktop-nav-item ' + (state.activeTab === 'interest' ? 'active' : '') + '" data-tab="interest">By Interest</button><button class="desktop-nav-item ' + (state.activeTab === 'marketplace' ? 'active' : '') + '" data-tab="marketplace">Marketplace</button></div><div class="desktop-legal-footer"><button class="desktop-report-btn" onclick="app.openReportModal()">Report Content</button><div class="legal-links" style="flex-direction:column;gap:8px;margin-top:16px;"><a class="legal-link" onclick="app.openLegalModal(\'terms\')">Terms of Use</a><a class="legal-link" onclick="app.openLegalModal(\'privacy\')">Privacy Policy</a><a class="legal-link" onclick="app.openLegalModal(\'disclaimer\')">Disclaimer</a></div><div style="margin-top:16px;font-size:0.75rem;color:var(--color-text-tertiary);">© 2026 ' + CONFIG.appName + '</div></div></div><div class="desktop-main" id="desktopFeedContainer"></div><div class="desktop-right" id="desktopRightSidebar"></div></div>';
      }
      renderFeed() {
        const state = this.store.getState();
        const isDesktop = window.innerWidth > 768;
        const container = document.getElementById(isDesktop ? "desktopFeedContainer" : "feedContainer");
        if (!container) return;
        if (state.isLoading) { container.innerHTML = Array(3).fill(Components.createSkeleton()).join(''); return; }
        if (state.activeTab === "marketplace") { container.innerHTML = '<div class="empty-state">Marketplace coming soon</div>'; return; }
        const stories = this.store.getFilteredStories();
        if (stories.length === 0) { container.innerHTML = '<div class="empty-state">No stories match your filters</div>'; return; }
        container.innerHTML = '';
        stories.forEach(story => container.appendChild(Components.createStoryCard(story, isDesktop)));
      }
      renderDesktopSidebar() {
        const sidebar = document.getElementById('desktopRightSidebar');
        if (!sidebar) return;
        const state = this.store.getState();
        const timeOpt = DATA.timeFilters.find(t => t.hours === state.timeHours);
        sidebar.innerHTML = '<div class="info-card"><h3>Current Settings</h3><div class="info-row"><span>Location</span><span>' + Utils.escapeHtml(state.locationName) + '</span></div>' + (state.activeTab === "nearme" ? '<div class="info-row"><span>Radius</span><span>' + state.radiusKm + ' km</span></div>' : '') + '<div class="info-row"><span>Time range</span><span>' + (timeOpt?.label || '7d') + '</span></div></div><button class="desktop-action-btn" onclick="app.openLocationModal()">Change Location</button>';
        if (state.activeTab === 'nearme') {
          sidebar.insertAdjacentHTML('beforeend', '<div class="info-card"><h3>Categories</h3><div class="filter-chips" id="desktopCategories"></div></div><div class="info-card"><h3>Story Radius</h3><div class="filter-chips" id="desktopRadius"></div></div>');
          this.renderChips('desktopCategories', DATA.categories, state.selectedCategory, (v) => this.store.setState({ selectedCategory: v }));
          this.renderChips('desktopRadius', DATA.radiusFilters, state.radiusFilter, (v) => this.store.setState({ radiusFilter: v }));
        } else if (state.activeTab === 'interest') {
          sidebar.insertAdjacentHTML('beforeend', '<div class="info-card"><h3>Interests</h3><div class="searchable-dropdown" id="interestDropdown"></div></div>');
          this.renderInterestDropdown();
        }
        sidebar.insertAdjacentHTML('beforeend', '<div class="info-card"><h3>Time Range</h3><div class="filter-chips" id="desktopTime"></div></div>');
        this.renderChips('desktopTime', DATA.timeFilters.map(t => t.label), timeOpt?.label || '7d', (label) => {
          const selected = DATA.timeFilters.find(t => t.label === label);
          if (selected) this.store.setState({ timeHours: selected.hours });
        });
      }
      renderChips(targetId, items, selected, onClick) {
        const el = document.getElementById(targetId);
        if (!el) return;
        el.innerHTML = items.map(item => '<button class="filter-chip ' + (item === selected ? 'active' : '') + '" data-v="' + Utils.escapeHtml(item) + '">' + Utils.escapeHtml(item) + '</button>').join('');
        el.querySelectorAll('[data-v]').forEach(btn => btn.addEventListener('click', () => onClick(btn.dataset.v)));
      }
      renderInterestDropdown() {
        const container = document.getElementById('interestDropdown');
        if (!container) return;
        const state = this.store.getState();
        container.innerHTML = '<div class="dropdown-trigger" id="interestTrigger"><span>' + Utils.escapeHtml(state.selectedInterest) + '</span><span>▼</span></div><div class="dropdown-menu" id="interestMenu"><div class="dropdown-search"><input type="text" id="interestSearch" placeholder="Search interests..."></div><div class="dropdown-options" id="interestOptions"></div></div>';
        const menu = document.getElementById('interestMenu');
        const search = document.getElementById('interestSearch');
        const opts = document.getElementById('interestOptions');
        const renderOpts = (term = '') => {
          opts.innerHTML = DATA.interests.filter(i => !term || i.toLowerCase().includes(term.toLowerCase())).map(i => '<div class="dropdown-option ' + (state.selectedInterest === i ? 'selected' : '') + '" data-v="' + Utils.escapeHtml(i) + '">' + Utils.escapeHtml(i) + '</div>').join('');
          opts.querySelectorAll('[data-v]').forEach(el => el.addEventListener('click', () => { this.store.setState({ selectedInterest: el.dataset.v }); menu.classList.remove('open'); }));
        };
        document.getElementById('interestTrigger').addEventListener('click', (e) => { e.stopPropagation(); menu.classList.toggle('open'); if (menu.classList.contains('open')) { setTimeout(() => search.focus(), 100); renderOpts(''); search.value = ''; } });
        search.addEventListener('input', e => renderOpts(e.target.value));
        document.addEventListener('click', () => menu.classList.remove('open'), { once: true });
        renderOpts('');
      }
      renderHeaderButtons() { const a = document.getElementById("headerActions"); if (a) a.innerHTML = '<button class="icon-btn" onclick="app.openLocationModal()">Location</button><button class="icon-btn" onclick="app.openFilterModal()">Filter</button>'; }
      openLocationModal() {
        const state = this.store.getState();
        this.modalManager.open({ title: 'Location', content: '<label class="modal-label">Your location</label><input type="text" id="locationInput" class="modal-input" value="' + Utils.escapeHtml(state.locationName) + '"><button id="useCurrentLocation" class="modal-btn" style="margin-top:8px;">Use my current location</button><div id="radiusSection" style="' + (state.activeTab === 'nearme' ? 'display:block' : 'display:none') + '"><label class="modal-label">Radius (km)</label><select id="radiusSelect" class="modal-select"><option value="5">5 km</option><option value="10">10 km</option><option value="20"' + (state.radiusKm === 20 ? ' selected' : '') + '>20 km</option><option value="30">30 km</option><option value="50">50 km</option></select></div>', buttons: [{ label: 'Cancel', action: 'cancel' }, { label: 'Save', action: 'save', className: 'primary' }], buttonHandlers: { cancel: (m, id) => this.modalManager.close(id), save: (m, id) => { const loc = document.getElementById('locationInput').value.trim(); if (loc) this.store.setState({ locationName: loc }); if (state.activeTab === 'nearme') { const r = parseInt(document.getElementById('radiusSelect').value); if (!isNaN(r)) this.store.setState({ radiusKm: r }); } this.modalManager.close(id); } } });
        setTimeout(() => { const btn = document.getElementById('useCurrentLocation'); if (btn) btn.onclick = async () => { const loc = await GeolocationService.getBrowserLocation(); const inp = document.getElementById('locationInput'); if (loc && inp) inp.value = loc; }; }, 100);
      }
      openFilterModal() {
        const state = this.store.getState();
        if (state.activeTab === 'nearme') {
          this.modalManager.open({ title: 'Filter Nearby Stories', content: '<label class="modal-label">Category</label><div id="categoryList" class="filter-chips"></div><label class="modal-label" style="margin-top:16px;">Story radius</label><div id="radiusList" class="filter-chips"></div><label class="modal-label" style="margin-top:16px;">Time range</label><div id="timeList" class="filter-chips"></div>', buttons: [{ label: 'Done', action: 'done', className: 'primary' }], buttonHandlers: { done: (m, id) => this.modalManager.close(id) } });
          setTimeout(() => {
            const rerenderNearbyFilterModal = () => {
              const liveState = this.store.getState();
              this.renderChips('categoryList', DATA.categories, liveState.selectedCategory, (v) => {
                this.store.setState({ selectedCategory: v });
                rerenderNearbyFilterModal();
              });
              this.renderChips('radiusList', DATA.radiusFilters, liveState.radiusFilter, (v) => {
                this.store.setState({ radiusFilter: v });
                rerenderNearbyFilterModal();
              });
              this.renderChips('timeList', DATA.timeFilters.map(t => t.label), DATA.timeFilters.find(t => t.hours === liveState.timeHours)?.label || '7d', (label) => {
                const selected = DATA.timeFilters.find(t => t.label === label);
                if (selected) this.store.setState({ timeHours: selected.hours });
                rerenderNearbyFilterModal();
              });
            };
            rerenderNearbyFilterModal();
          }, 50);
        } else if (state.activeTab === 'interest') {
          this.modalManager.open({ title: 'Filter by Interest', content: '<label class="modal-label">Interest</label><input id="interestSearchModal" class="modal-input" placeholder="Search interests..."><div id="interestListModal" class="filter-chips" style="margin-top:12px;max-height:220px;overflow:auto;"></div><label class="modal-label" style="margin-top:16px;">Time range</label><div id="timeList" class="filter-chips"></div>', buttons: [{ label: 'Done', action: 'done', className: 'primary' }], buttonHandlers: { done: (m, id) => this.modalManager.close(id) } });
          setTimeout(() => {
            const lc = document.getElementById('interestListModal');
            const renderList = (term = '') => {
              lc.innerHTML = DATA.interests
                .filter(i => !term || i.toLowerCase().includes(term.toLowerCase()))
                .map(i => '<button class="filter-chip ' + (state.selectedInterest === i ? 'active' : '') + '" data-v="' + Utils.escapeHtml(i) + '">' + Utils.escapeHtml(i) + '</button>').join('');
              lc.querySelectorAll('[data-v]').forEach(btn => btn.addEventListener('click', () => this.store.setState({ selectedInterest: btn.dataset.v })));
            };
            document.getElementById('interestSearchModal').addEventListener('input', e => renderList(e.target.value));
            renderList('');
            this.renderChips('timeList', DATA.timeFilters.map(t => t.label), DATA.timeFilters.find(t => t.hours === state.timeHours)?.label || '7d', (label) => {
              const selected = DATA.timeFilters.find(t => t.label === label);
              if (selected) this.store.setState({ timeHours: selected.hours });
            });
          }, 50);
        }
      }
      openReportModal() {
        this.modalManager.open({
          title: 'Report Content',
          content: '<label class="modal-label">Select news to report</label><select id="reportStorySelect" class="modal-select">' + DATA.stories.map(s => '<option value="' + s.id + '">' + Utils.escapeHtml(s.title) + '</option>').join('') + '</select><label class="modal-label">Reason</label><select id="reportReason" class="modal-select"><option value="Misinformation">Misinformation / Fake news</option><option value="Spam">Spam or promotional content</option><option value="Inappropriate">Inappropriate content</option><option value="Harassment">Harassment</option><option value="Other">Other</option></select><label class="modal-label">Additional details (optional)</label><textarea id="reportDetails" class="modal-textarea" placeholder="Please provide additional context..."></textarea>',
          buttons: [{ label: 'Cancel', action: 'cancel' }, { label: 'Submit', action: 'submit', className: 'primary' }],
          buttonHandlers: {
            cancel: (m, id) => this.modalManager.close(id),
            submit: async (m, id) => {
              const payload = {
                news_item_id: document.getElementById('reportStorySelect').value,
                reason: document.getElementById('reportReason').value,
                details: document.getElementById('reportDetails').value.trim() || null,
              };
              try {
                await fetch('/api/report-content', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' }, body: JSON.stringify(payload) });
                this.modalManager.close(id);
              } catch (e) {
                alert('Unable to submit report right now.');
              }
            }
          }
        });
      }
      openLegalModal(key) {
        const doc = DATA.legalContent[key];
        if (!doc) return;
        this.modalManager.open({ title: doc.title, content: doc.content, buttons: [{ label: 'Close', action: 'close', className: 'primary' }], buttonHandlers: { close: (m, id) => this.modalManager.close(id) } });
      }
      bindEvents() { this.store.subscribe(() => this.render()); window.addEventListener('resize', () => this.render()); document.addEventListener('click', e => { const tab = e.target.closest('[data-tab]'); if (tab) this.store.setState({ activeTab: tab.dataset.tab }); }); }
    }

    const app = new NearbypostApp();
    app.init();
    window.app = app;
  </script>
</body>
</html>
