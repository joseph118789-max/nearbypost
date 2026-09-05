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
  {{-- The bar spans the window - it is sticky, and a background that stopped
       short of the edges would look like a mistake. Its CONTENTS belong in the
       same box as the page below, so the wordmark starts where the left column
       starts and the account icon ends where the right column ends. --}}
  <div class="topbar-inner">
  <a class="brand" href="{{ \App\Support\Loc::route('home') }}">
    <img class="brand-mark" src="/favicon-32.png" width="26" height="26" alt="" aria-hidden="true">
    <span>{{ config('app.name') }}</span>
  </a>

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
    {{-- Camera last, so posting sits at the very edge of the bar - the
         easiest place to reach with a thumb, and the owner asked for it
         there rather than boxed in between the flag and the account. --}}
    {{-- The camera: the shortcut to posting. Without JavaScript it is a plain
         link to the report form; with it, a sheet offering the two things a
         person can post here. Either choice asks for a sign-in first. --}}
    <a class="icon-link camera" id="post-camera" href="{{ route('contribute.create') }}" aria-label="{{ __('site.post_menu_title') }}" title="{{ __('site.post_menu_title') }}">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3 7.2 5H4a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V7a2 2 0 0 0-2-2h-3.2L15 3H9Zm3 5.5A4.5 4.5 0 1 1 7.5 13 4.5 4.5 0 0 1 12 8.5Zm0 2A2.5 2.5 0 1 0 14.5 13 2.5 2.5 0 0 0 12 10.5Z"/></svg>
    </a>
  </div>
  </div>
</header>

<dialog id="post-menu" class="peek post-menu">
  <article>
    <h2>{{ __('site.post_menu_title') }}</h2>
    <p class="post-menu-intro">{{ __('site.post_menu_intro') }}</p>
    <a class="post-choice" href="{{ route('contribute.create') }}">
      <span class="post-choice-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M4 4h16v12H7l-3 3V4Zm3 3v2h10V7H7Zm0 4v2h7v-2H7Z"/></svg></span>
      <span><b>{{ __('site.post_news_title') }}</b><small>{{ __('site.post_news_desc') }}</small></span>
    </a>
    <a class="post-choice" href="{{ route('marketplace.offer') }}">
      <span class="post-choice-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M3 4h18l1 5a3 3 0 0 1-2 3v8H4v-8a3 3 0 0 1-2-3l1-5Zm3 16h4v-5h4v5h4v-7.2a3 3 0 0 1-1.5-.8 3 3 0 0 1-4.5 0 3 3 0 0 1-4.5 0 3 3 0 0 1-1.5.8V20Z"/></svg></span>
      <span><b>{{ __('site.post_market_title') }}</b><small>{{ __('site.post_market_desc') }}</small></span>
    </a>
    <p class="post-menu-note">{{ __('site.post_menu_login_note') }}</p>
    <div class="peek-actions single"><button type="button" data-post-menu-close>{{ __('site.close') }}</button></div>
  </article>
</dialog>
<script>
  (function () {
    var btn = document.getElementById('post-camera'), dlg = document.getElementById('post-menu');
    if (!btn || !dlg || typeof dlg.showModal !== 'function') { return; }
    btn.addEventListener('click', function (e) { e.preventDefault(); dlg.showModal(); });
    dlg.querySelectorAll('[data-post-menu-close]').forEach(function (b) { b.addEventListener('click', function () { dlg.close(); }); });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) { dlg.close(); } });
  })();
</script>


{{-- The right column exists only when a page has something to put in it.
     Rendering the section first lets an empty one remove the column rather
     than leave a gap beside the feed. --}}
@php $asideContent = trim($__env->yieldContent('aside')); @endphp

<div class="shell {{ $asideContent === '' ? 'no-aside' : '' }} {{ isset($windows) ? '' : 'no-controls' }} {{ !empty($wide) ? 'wide' : '' }}">

    <div class="shell-left">
      {{-- Above the search box, not above the page: the mode decides what the
           box is for, so the two belong together. The tabs are on EVERY page
           (Marketplace used to lose them, and with them the way back). --}}
      <nav class="modes" aria-label="{{ __('site.sections') }}">
  <a class="{{ ($tab ?? '') === 'nearme' ? 'on' : '' }}" href="{{ \App\Support\Loc::route('home') }}">{{ __('site.near_me') }}</a>
  <a class="{{ ($tab ?? '') === 'interest' ? 'on' : '' }}" href="{{ \App\Support\Loc::route('interest') }}">{{ __('site.by_interest') }}</a>
  <a class="{{ ($tab ?? '') === 'marketplace' ? 'on' : '' }}" href="{{ \App\Support\Loc::route('marketplace') }}">{{ __('site.marketplace') }}</a>
  {{-- On a phone the search and the filter sit here, at the end of the tabs,
       which frees the place row for the sort pills. They drive the form
       below through its id; on a wide screen the form keeps its own. --}}
  @isset($windows)
  <span class="modes-tools">
    <button class="go" type="submit" form="feed-controls" aria-label="{{ __('site.show_news_here') }}">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 2a8 8 0 1 0 4.9 14.32l5.39 5.39 1.42-1.42-5.39-5.39A8 8 0 0 0 10 2Zm0 2a6 6 0 1 1 0 12 6 6 0 0 1 0-12Z"/></svg>
    </button>
    <button class="go adv-toggle" type="button" id="adv-toggle" aria-label="{{ __('site.more_options') }}" title="{{ __('site.more_options') }}">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18v2H3V6Zm4 5h10v2H7v-2Zm3 5h4v2h-4v-2Z"/></svg>
    </button>
  </span>
  @endisset
</nav>

      @isset($windows)
      @include('partials.controls')

      {{-- Under the controls, above nothing. It is a sponsor, so it sits below
           everything the reader came to use. --}}
      @include('partials.music-player')
      @endisset

      {{-- Under the controls on every page, not only the feed: somebody who
           publishes locally may arrive on a story, not the front page. --}}
      @include('partials._share-news')
    </div>

  <main id="main" class="shell-main">
    @yield('main')
  </main>

  @if($asideContent !== '')
    <aside class="shell-right" aria-label="{{ __('site.side_panel') }}">
      {!! $asideContent !!}
    </aside>
  @endif

</div>

<footer class="foot">
  <a href="{{ \App\Support\Loc::route('legal', ['page' => 'terms']) }}">{{ __('site.terms') }}</a>
  <a href="{{ \App\Support\Loc::route('legal', ['page' => 'privacy']) }}">{{ __('site.privacy') }}</a>
  <a href="{{ \App\Support\Loc::route('legal', ['page' => 'disclaimer']) }}">{{ __('site.disclaimer') }}</a>
  <span>&copy; {{ date('Y') }} {{ config('app.name') }}</span>
</footer>

<p class="powered-by">
  <a href="https://www.listingmine.com" target="_blank" rel="noopener">{{ __('site.powered_by') }}</a>
</p>

@yield('after')

</body>
</html>
