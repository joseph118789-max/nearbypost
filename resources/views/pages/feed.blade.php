@extends('layouts.public')

@section('main')
  {{-- What this page is, for a reader arriving from a search result and for the
       search engine that sent them. --}}
  <h1 class="feed-title">{{ $heading }}</h1>
  <p class="lede">{{ $intro }}</p>

  @if(!empty($unresolved))
    <div class="notice">
      {{ __('site.not_found_place', ['place' => $place]) }}
    </div>
  @endif

  <ol class="feed">
    @forelse($stories as $i => $story)
      @include('partials.story-card', ['story' => $story, 'i' => $i])
    @empty
      <li class="empty">
        <p>{{ __('site.empty_title') }}</p>
        <p>
          {{ __('site.try') }}
          <a href="{{ request()->fullUrlWithQuery(['days' => 30]) }}">{{ __('site.longer_period') }}</a>
          @if(!empty($showRadius))
            {{ __('site.or') }} <a href="{{ request()->fullUrlWithQuery(['radius' => 50]) }}">{{ __('site.wider_radius') }}</a>
          @endif
          @if(!empty($category))
            {{ __('site.or') }} <a href="{{ request()->fullUrlWithQuery(['category' => null, 'sub' => null]) }}">{{ __('site.all_topics') }}</a>
          @endif.
        </p>
      </li>
    @endforelse
  </ol>
@endsection

@section('after')
  {{-- One dialog reused by every headline. The summary is already in the page
       for a crawler and for a reader with no JavaScript; this only moves it
       into view. Without JavaScript the headline is an ordinary link to this
       story's own page, which carries the summary and the publisher's link. --}}
  <dialog id="peek" class="peek">
    <article>
      <p class="peek-meta" id="peek-meta"></p>
      <h2 id="peek-title"></h2>
      <p id="peek-summary"></p>
      <div class="peek-actions">
        <a id="peek-link" href="#" target="_blank" rel="noopener nofollow">{{ __('site.read_at_source') }} &#8599;</a>
        {{-- The page on this site, for a reader who wants to keep or send it.
             An ordinary link, so long-press to copy works the way a phone user
             already expects it to. --}}
        <a id="peek-page" href="#">{{ __('site.open_page') }}</a>
        <button type="button" data-peek-close>{{ __('site.close') }}</button>
      </div>
    </article>
  </dialog>

  <script>
    (function () {
      var dialog = document.getElementById('peek');
      if (!dialog || typeof dialog.showModal !== 'function') { return; }

      var pageTitle = document.title;

      document.querySelectorAll('.feed .headline').forEach(function (link) {
        link.addEventListener('click', function (event) {
          var card = link.closest('li');
          var summary = card.querySelector('.summary');
          // Nothing to show is not worth a dialog: let the link do its job.
          if (!summary || !summary.textContent.trim()) { return; }

          event.preventDefault();
          document.getElementById('peek-title').textContent = link.textContent.trim();
          document.getElementById('peek-summary').textContent = summary.textContent.trim();
          document.getElementById('peek-meta').textContent = card.dataset.meta || '';
          // The publisher, not the page the headline now points at: inside
          // the site the reader wanted the article, and the page here is for
          // whoever they forward it to.
          document.getElementById('peek-link').href = link.dataset.src || link.href;
          document.getElementById('peek-page').href = link.href;
          dialog.showModal();

          // The address bar follows the popup. The reader is looking at this
          // story, so the browser should say so: the URL can be copied or sent
          // from the share sheet without leaving the feed, Back closes the
          // popup the way a phone user expects, and anything counting page
          // views counts the story rather than the feed it was opened from.
          //
          // No page is fetched - the summary is already here. This only names
          // what is on the screen.
          if (window.history && history.pushState) {
            history.pushState({ peek: true }, '', link.href);
            document.title = link.textContent.trim() + ' | {{ config('app.name') }}';
          }
        });
      });

      // Closing puts the address bar and the title back where they were.
      //
      // Driven from the two things a reader actually does - press Close, or
      // press Back - rather than from the dialog's own close event, which does
      // not fire everywhere and left the feed wearing a story's title.
      function restore() {
        document.title = pageTitle;
      }

      document.querySelectorAll('[data-peek-close]').forEach(function (button) {
        button.addEventListener('click', function () {
          dialog.close();
          restore();

          // Back to the feed's own address, without adding a second entry the
          // reader would have to press Back twice to get out of.
          if (history.state && history.state.peek) {
            history.back();
          }
        });
      });

      window.addEventListener('popstate', function () {
        if (dialog.open) {
          dialog.close();
        }

        restore();
      });

      // Tapping the backdrop closes it, which is what a phone user expects -
      // and leaves the address bar and title exactly as pressing Close would.
      dialog.addEventListener('click', function (event) {
        if (event.target !== dialog) { return; }

        dialog.close();
        restore();

        if (history.state && history.state.peek) {
          history.back();
        }
      });
    })();
  </script>
@endsection
