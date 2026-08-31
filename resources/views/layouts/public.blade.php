{{-- Public layout. Rendered on the server: the content is in the HTML. --}}
<!DOCTYPE html>
<html lang="en-MY">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

  <title>{{ $pageTitle ?? 'Local news for your area' }} | {{ config('app.name') }}</title>
  <meta name="description" content="{{ $description ?? 'Local news and hyperlocal updates from across Malaysia.' }}">
  <meta name="robots" content="index, follow, max-snippet:-1, max-image-preview:large">
  <link rel="canonical" href="{{ $canonical ?? url()->current() }}">

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
  <link rel="stylesheet" href="{{ asset('css/app.css') }}">

  @isset($jsonLd)
    <script type="application/ld+json">{!! json_encode($jsonLd, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}</script>
  @endisset
</head>
<body>

<header class="header">
  <a class="logo" href="{{ route('home') }}">{{ config('app.name') }}</a>
  <nav class="header-actions" aria-label="Quick links">
    <a class="icon-btn" href="{{ route('interest') }}">All news</a>
  </nav>
</header>

<div class="desktop-layout">

  <div class="desktop-sidebar">
    <p class="site-tagline">A calmer, cleaner local news feed.</p>

    <nav class="desktop-nav" aria-label="Sections">
      <a class="desktop-nav-item {{ ($tab ?? '') === 'nearme' ? 'active' : '' }}" href="{{ route('home') }}">Near Me</a>
      <a class="desktop-nav-item {{ ($tab ?? '') === 'interest' ? 'active' : '' }}" href="{{ route('interest') }}">By Interest</a>
      <a class="desktop-nav-item {{ ($tab ?? '') === 'marketplace' ? 'active' : '' }}" href="{{ route('marketplace') }}">Marketplace</a>
    </nav>

    @if(!empty($categories))
      <section class="nav-section" aria-labelledby="nav-topics">
        <h2 id="nav-topics">Topics</h2>
        <ul class="nav-links">
          @foreach(array_slice($categories, 0, 14) as $cat)
            <li><a href="{{ route('category', ['slug' => \App\Support\Slug::make($cat)]) }}">{{ ucwords($cat) }}</a></li>
          @endforeach
        </ul>
      </section>
    @endif

    @if(!empty($places))
      <section class="nav-section" aria-labelledby="nav-places">
        <h2 id="nav-places">Places</h2>
        <ul class="nav-links">
          @foreach(array_slice($places, 0, 18) as $p)
            <li><a href="{{ route('place', ['slug' => \App\Support\Slug::make($p)]) }}">{{ $p }}</a></li>
          @endforeach
        </ul>
      </section>
    @endif

    <div class="desktop-legal-footer">
      <div class="legal-links">
        <a class="legal-link" href="{{ route('legal', ['page' => 'terms']) }}">Terms</a>
        <a class="legal-link" href="{{ route('legal', ['page' => 'privacy']) }}">Privacy</a>
        <a class="legal-link" href="{{ route('legal', ['page' => 'disclaimer']) }}">Disclaimer</a>
      </div>
      <p class="copyright">&copy; {{ date('Y') }} {{ config('app.name') }}</p>
    </div>
  </div>

  <main class="desktop-main" id="main">
    @yield('main')
  </main>

  <aside class="desktop-right" aria-label="Filters">
    @yield('aside')
  </aside>

</div>

</body>
</html>
