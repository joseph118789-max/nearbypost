{{-- Public layout. Rendered on the server: the content is in the HTML. --}}
<!DOCTYPE html>
<html lang="{{ \App\Support\Loc::htmlLang() }}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

  <title>{{ $pageTitle ?? 'Local news for your area' }} | {{ config('app.name') }}</title>
  <meta name="description" content="{{ $description ?? 'Local news and hyperlocal updates from across Malaysia.' }}">
  <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large">
  <link rel="canonical" href="{{ $canonical ?? url()->current() }}">

  {{-- The same page in the other reading languages. Without these a search
       engine treats the three as competing duplicates rather than one page. --}}
  @foreach(\App\Support\Loc::alternatesForCurrent() as $alt)
    <link rel="alternate" hreflang="{{ $alt['hreflang'] }}" href="{{ $alt['url'] }}">
  @endforeach
  @if(\App\Support\Loc::alternatesForCurrent())
    <link rel="alternate" hreflang="x-default"
          href="{{ \App\Support\Loc::alternatesForCurrent()['en']['url'] ?? url()->current() }}">
  @endif

  <meta property="og:type" content="website">
  <meta property="og:site_name" content="{{ config('app.name') }}">
  <meta property="og:title" content="{{ $pageTitle ?? config('app.name') }}">
  <meta property="og:description" content="{{ $description ?? 'Local news and hyperlocal updates from across Malaysia.' }}">
  <meta property="og:url" content="{{ $canonical ?? url()->current() }}">
  <meta property="og:locale" content="en_MY">
  <meta name="twitter:card" content="summary">
  <meta name="twitter:site" content="@nearbypost">

  {{-- Geographic signals. On a place page these describe that place, so a
       search engine can tell Shah Alam coverage from Penang coverage. --}}
  <meta name="geo.region" content="MY">
  @isset($place)
    <meta name="geo.placename" content="{{ $place }}">
  @endisset
  @if(!empty($coords))
    <meta name="geo.position" content="{{ $coords['lat'] }};{{ $coords['lng'] }}">
    <meta name="ICBM" content="{{ $coords['lat'] }}, {{ $coords['lng'] }}">
  @endif

  <meta name="theme-color" content="#0f3b2c">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap">
  {{-- Versioned so an edit is not masked by the CDN's cached copy. --}}
  <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ @filemtime(public_path('css/app.css')) ?: '1' }}">

  @isset($jsonLd)
    <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
  @endisset
</head>
<body>

<header class="header">
  <a class="logo" href="{{ \App\Support\Loc::route('home') }}">{{ config('app.name') }}</a>
  <nav class="header-actions" aria-label="Quick links">
    <a class="icon-btn" href="{{ \App\Support\Loc::route('interest') }}">{{ __('site.all_news') }}</a>

    {{-- Language switcher. Real links, so each language is crawlable and a
         reader can share the version they read. Query parameters are kept so
         the radius and period survive the switch. --}}
    <div class="lang-switch" role="group" aria-label="{{ __('site.reading_language') }}">
      @foreach(\App\Support\Loc::alternatesForCurrent() as $code => $alt)
        <a class="lang-option {{ $code === \App\Support\Loc::current() ? 'active' : '' }}"
           href="{{ $alt['url'] }}"
           hreflang="{{ $alt['hreflang'] }}"
           lang="{{ $alt['hreflang'] }}">{{ $alt['label'] }}</a>
      @endforeach
    </div>
  </nav>
</header>

{{-- The third column exists only when something belongs in it. Rendering the
     section first lets an empty one remove the column instead of leaving a gap
     beside the feed. --}}
@php $asideContent = trim($__env->yieldContent('aside')); @endphp

<div class="desktop-layout {{ $asideContent === '' ? 'no-aside' : '' }}">

  <div class="desktop-sidebar">
    <p class="site-tagline">{{ __('site.tagline') }}</p>

    <nav class="desktop-nav" aria-label="{{ __('site.sections') }}">
      <a class="desktop-nav-item {{ ($tab ?? '') === 'nearme' ? 'active' : '' }}" href="{{ \App\Support\Loc::route('home') }}">{{ __('site.near_me') }}</a>
      <a class="desktop-nav-item {{ ($tab ?? '') === 'interest' ? 'active' : '' }}" href="{{ \App\Support\Loc::route('interest') }}">{{ __('site.by_interest') }}</a>
      <a class="desktop-nav-item {{ ($tab ?? '') === 'marketplace' ? 'active' : '' }}" href="{{ \App\Support\Loc::route('marketplace') }}">{{ __('site.marketplace') }}</a>
    </nav>

    {{-- Controls only. The topics moved to the right column, where choosing
         one opens its sub-topics; keeping a copy here would be the same
         control twice again. Story radius appears only where distance is what
         the feed is sorted by. --}}
    @isset($windows)
      <form class="nav-section" method="get" action="{{ \App\Support\Loc::route('home') }}">
        <h2>{{ __('site.change_location') }}</h2>
        <label class="visually-hidden" for="place-input">{{ __('site.town_or_city') }}</label>
        <input class="modal-input" id="place-input" type="text" name="place"
               value="{{ $place ?? '' }}" placeholder="e.g. Shah Alam" maxlength="120">
        <input type="hidden" name="days" value="{{ $days }}">
        <input type="hidden" name="radius" value="{{ $radius }}">
        <button class="desktop-action-btn primary" type="submit">{{ __('site.show_news_here') }}</button>
      </form>

      @if(!empty($showRadius))
        <section class="nav-section" aria-labelledby="nav-radius">
          <h2 id="nav-radius">{{ __('site.story_radius') }}</h2>
          <div class="filter-chips">
            @foreach($radii as $r)
              <a class="filter-chip {{ $r === $radius ? 'active' : '' }}"
                 href="{{ request()->fullUrlWithQuery(['radius' => $r]) }}">{{ $r }} km</a>
            @endforeach
          </div>
        </section>
      @endif

      <section class="nav-section" aria-labelledby="nav-time">
        <h2 id="nav-time">{{ __('site.time_range') }}</h2>
        <div class="filter-chips">
          @foreach($windows as $value => $label)
            <a class="filter-chip {{ $value === $days ? 'active' : '' }}"
               href="{{ request()->fullUrlWithQuery(['days' => $value]) }}">{{ $label }}</a>
          @endforeach
        </div>
      </section>
    @endisset


    <div class="desktop-legal-footer">
      <div class="legal-links">
        <a class="legal-link" href="{{ \App\Support\Loc::route('legal', ['page' => 'terms']) }}">{{ __('site.terms') }}</a>
        <a class="legal-link" href="{{ \App\Support\Loc::route('legal', ['page' => 'privacy']) }}">{{ __('site.privacy') }}</a>
        <a class="legal-link" href="{{ \App\Support\Loc::route('legal', ['page' => 'disclaimer']) }}">{{ __('site.disclaimer') }}</a>
      </div>
      <p class="copyright">&copy; {{ date('Y') }} {{ config('app.name') }}</p>
    </div>
  </div>

  <main class="desktop-main" id="main">
    {{-- Section tabs repeated at the top of the content on small screens.
         Stacked, the sidebar would otherwise put thirty navigation links above
         the first headline. --}}
    <nav class="mobile-tabs" aria-label="{{ __('site.sections') }}">
      <a class="filter-chip {{ ($tab ?? '') === 'nearme' ? 'active' : '' }}" href="{{ \App\Support\Loc::route('home') }}">{{ __('site.near_me') }}</a>
      <a class="filter-chip {{ ($tab ?? '') === 'interest' ? 'active' : '' }}" href="{{ \App\Support\Loc::route('interest') }}">{{ __('site.by_interest') }}</a>
      <a class="filter-chip {{ ($tab ?? '') === 'marketplace' ? 'active' : '' }}" href="{{ \App\Support\Loc::route('marketplace') }}">{{ __('site.marketplace') }}</a>
    </nav>

    @yield('main')
  </main>

  @if($asideContent !== '')
    <aside class="desktop-right" aria-label="{{ __('site.side_panel') }}">
      {!! $asideContent !!}
    </aside>
  @endif

</div>

</body>
</html>
