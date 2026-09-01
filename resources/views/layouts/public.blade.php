{{-- Public layout. Rendered on the server: the content is in the HTML. --}}
<!DOCTYPE html>
<html lang="{{ \App\Support\Loc::htmlLang() }}">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">

  <title>{{ $pageTitle ?? 'Local news for your area' }} | {{ config('app.name') }}</title>
  <meta name="description" content="{{ $description ?? 'Local news and hyperlocal updates from across Malaysia.' }}">
  {{-- Overridable: the per-story pages exist to be shared, not to be found,
       and inviting a crawler onto nine thousand two-sentence pages would work
       against the place and topic pages that are meant to rank. --}}
  <meta name="robots" content="{{ $robots ?? 'index, follow, max-snippet:-1, max-image-preview:large' }}">
  <link rel="canonical" href="{{ $canonical ?? url()->current() }}">

  {{-- Sized files rather than one bitmap scaled by the browser: at 16 pixels
       the three bars inside the pin smear into a grey block unless the icon was
       drawn for that size. --}}
  <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
  <link rel="icon" type="image/png" sizes="32x32" href="{{ asset('favicon-32.png') }}">
  <link rel="icon" type="image/png" sizes="16x16" href="{{ asset('favicon-16.png') }}">
  <link rel="apple-touch-icon" sizes="180x180" href="{{ asset('apple-touch-icon.png') }}">

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

  {{-- Without this a link shared to WhatsApp or Facebook renders as a grey box
       with a URL under it. $ogImage lets a page that has its own picture - a
       reader's photograph on their own post - use it instead of the brand card. --}}
  <meta property="og:image" content="{{ $ogImage ?? asset('og-image.png') }}">
  @empty($ogImage)
    {{-- Only for the brand card, whose size we know. Declaring 1200x630 over a
         reader's portrait photograph would be a measurement we made up, and the
         scrapers that trust it would crop the picture to it. --}}
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
  @endempty
  <meta property="og:image:alt" content="{{ $pageTitle ?? config('app.name') }}">
  <meta name="twitter:image" content="{{ $ogImage ?? asset('og-image.png') }}">

  {{-- summary_large_image, because there is now an image worth the space. --}}
  <meta name="twitter:card" content="summary_large_image">
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

{{-- The bar is deliberately almost empty. On a phone every pixel above the
     first headline is a pixel of news the reader cannot see, so the language
     picker and the account are single characters wide and everything else has
     gone. --}}
<header class="topbar">
  <a class="brand" href="{{ \App\Support\Loc::route('home') }}">{{ config('app.name') }}</a>

  <div class="topbar-right">
    {{-- A <details> menu rather than a script: it opens on a phone with no
         JavaScript, and closes when something else is tapped. --}}
    <details class="menu lang">
      <summary aria-label="{{ __('site.reading_language') }}">
        @include('partials.flag')
      </summary>
      <div class="menu-body">
        @foreach(\App\Support\Loc::alternatesForCurrent() as $code => $alt)
          <a class="{{ $code === \App\Support\Loc::current() ? 'on' : '' }}"
             href="{{ $alt['url'] }}" hreflang="{{ $alt['hreflang'] }}" lang="{{ $alt['hreflang'] }}">
            @include('partials.flag', ['code' => $code]) {{ $alt['label'] }}
          </a>
        @endforeach
      </div>
    </details>

    @auth('web')
      <a class="icon-link" href="{{ route('contribute.index') }}" aria-label="{{ __('site.my_posts') }}" title="{{ __('site.my_posts') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-5 0-9 2.5-9 5.5V21h18v-1.5c0-3-4-5.5-9-5.5Z"/></svg>
      </a>
    @else
      <a class="icon-link" href="{{ route('login') }}" aria-label="{{ __('site.login') }}" title="{{ __('site.login') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 12a5 5 0 1 0 0-10 5 5 0 0 0 0 10Zm0 2c-5 0-9 2.5-9 5.5V21h18v-1.5c0-3-4-5.5-9-5.5Z"/></svg>
      </a>
    @endauth
  </div>
</header>

<nav class="modes" aria-label="{{ __('site.sections') }}">
  <a class="{{ ($tab ?? '') === 'nearme' ? 'on' : '' }}" href="{{ \App\Support\Loc::route('home') }}">{{ __('site.near_me') }}</a>
  <a class="{{ ($tab ?? '') === 'interest' ? 'on' : '' }}" href="{{ \App\Support\Loc::route('interest') }}">{{ __('site.by_interest') }}</a>
  <a class="{{ ($tab ?? '') === 'marketplace' ? 'on' : '' }}" href="{{ \App\Support\Loc::route('marketplace') }}">{{ __('site.marketplace') }}</a>
</nav>

@isset($windows)
  @include('partials.controls')
@endisset

<main id="main">
  @yield('main')
</main>

<footer class="foot">
  <a href="{{ \App\Support\Loc::route('legal', ['page' => 'terms']) }}">{{ __('site.terms') }}</a>
  <a href="{{ \App\Support\Loc::route('legal', ['page' => 'privacy']) }}">{{ __('site.privacy') }}</a>
  <a href="{{ \App\Support\Loc::route('legal', ['page' => 'disclaimer']) }}">{{ __('site.disclaimer') }}</a>
  <span>&copy; {{ date('Y') }} {{ config('app.name') }}</span>
</footer>

@yield('after')

</body>
</html>
