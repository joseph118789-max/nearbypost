@extends('layouts.public')

@section('main')
  {{-- What this page is, for a reader arriving from a search result and for the
       search engine that sent them. --}}
  <h1 class="feed-title">{{ $heading }}</h1>
  {{-- No sentence of context under the heading (owner, 3 Sep): the heading
       already names the place, and the sentence only pushed the first story
       down. The intro text still goes out in the meta description. --}}

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
      <div id="peek-community" hidden></div>
      <div class="peek-actions">
        {{-- One destination. A second button to this site's own copy of a
             summary the reader is already looking at reads as the same thing
             twice; the page still exists and every headline in the feed links
             to it, which is what a crawler follows. --}}
        <a id="peek-link" href="#" target="_blank" rel="noopener nofollow">{{ __('site.read_at_source') }} &#8599;</a>
        <button type="button" data-peek-close>{{ __('site.close') }}</button>
      </div>
    </article>
  </dialog>

  <script>
    (function () {
      var dialog = document.getElementById('peek');
      if (!dialog || typeof dialog.showModal !== 'function') { return; }

      var pageTitle = document.title;

      // A reader's report brings its reactions, report form and comments into
      // the popup. Every form inside posts through fetch and the panel is
      // fetched again, so the count and the new comment show at once.
      var panel = document.getElementById('peek-community');
      var panelUrl = '{{ url('/community') }}';
      function loadPanel(id) {
        panel.dataset.post = id;
        if (!id) { panel.hidden = true; panel.innerHTML = ''; return; }
        panel.hidden = false; panel.innerHTML = '<p class="mini">…</p>';
        fetch(panelUrl + '/' + id + '/panel', { credentials: 'same-origin', headers: { 'Accept': 'text/html' } })
          .then(function (r) { return r.ok ? r.text() : ''; })
          .then(function (html) { if (panel.dataset.post === id) { panel.innerHTML = html; } })
          .catch(function () { panel.hidden = true; });
      }
      panel.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement)) { return; }
        event.preventDefault();
        var buttons = form.querySelectorAll('button'); buttons.forEach(function (b) { b.disabled = true; });
        fetch(form.action, { method: 'POST', credentials: 'same-origin', body: new FormData(form), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
          .then(function () { loadPanel(panel.dataset.post); })
          .catch(function () { buttons.forEach(function (b) { b.disabled = false; }); });
      });
      // the Translate link under a comment (the same behaviour as on the report's page)
      panel.addEventListener('click', function (e) {
        var b = e.target.closest('.tr-comment'); if (!b) { return; }
        var box = document.getElementById('ct' + b.dataset.id);
        if (b.dataset.state === 'shown') { box.hidden = true; b.textContent = b.dataset.show; b.dataset.state = 'loaded'; return; }
        if (b.dataset.state === 'loaded') { box.hidden = false; b.textContent = b.dataset.hide; b.dataset.state = 'shown'; return; }
        b.disabled = true; b.textContent = '...';
        fetch(b.dataset.url, { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
          b.disabled = false;
          if (!d.ok) { b.textContent = b.dataset.show; return; }
          if (d.same) { b.textContent = b.dataset.same; b.disabled = true; return; }
          box.textContent = ''; box.appendChild(document.createTextNode(d.text));
          var n = document.createElement('span'); n.className = 'mini'; n.textContent = ' (' + b.dataset.note + ')'; box.appendChild(n);
          box.hidden = false; b.textContent = b.dataset.hide; b.dataset.state = 'shown';
        }).catch(function () { b.disabled = false; b.textContent = b.dataset.show; });
      });

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
          loadPanel(card.dataset.post || '');
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


{{-- The topic browser, in the right column on a desktop.

     Two levels: the main categories, and the sub-categories of whichever one
     is open. Which level shows is derived entirely from the URL, so "back" is
     a link, there is no state to keep, and a crawler sees every topic page.

     On a phone this column is not rendered as a sidebar at all - it falls
     below the feed, where the dropdowns in the controls remain the way to
     choose. Two controls for one thing is confusing on a small screen and
     obvious on a large one, where the list is simply visible. --}}
@section('aside')
  @if(!empty($categories))
    <div class="info-card topic-browser" id="topic-browser"
         data-topic-initial="{{ !empty($category) ? mb_strtolower($category) : 'root' }}">

      <div class="topic-level" data-topic-level="root">
        <h3>{{ __('site.topics') }}</h3>
        <ul class="topic-list">
          {{-- The way back to everything.

               Without it a reader who opened Automotive and picked a sub-topic
               was stuck: "← Topics" returns the PANEL to the top of the tree but
               leaves the feed filtered, so the list of topics they were looking
               at no longer described the stories beside it. This clears both,
               and it is an ordinary link rather than a back button because it
               undoes a filter rather than a navigation. --}}
          <li>
            <a class="topic-item {{ empty($category) ? 'on' : '' }}" href="{{ $topicsRootUrl }}">
              <span>{{ __('site.all_topics_option') }}</span>
            </a>
          </li>
          @foreach($categories as $cat)
            <li>
              <a class="topic-item" href="{{ $topicUrls[$cat] ?? '#' }}"
                 data-topic-open="{{ mb_strtolower($cat) }}">
                <span>{{ \App\Support\Taxonomy::category($cat) }}</span>
                <span class="topic-caret" aria-hidden="true">&rsaquo;</span>
              </a>
            </li>
          @endforeach
        </ul>
      </div>

      @foreach($categories as $cat)
        @php $key = mb_strtolower($cat); @endphp
        <div class="topic-level" data-topic-level="{{ $key }}" hidden>
          <div class="topic-head">
            <button class="topic-back" type="button" data-topic-back>
              <span aria-hidden="true">&larr;</span> {{ __('site.topics') }}
            </button>

            {{-- Only in the panel that is actually filtered. Rendered in every
                 category's panel it was seventeen identical links in the page,
                 sixteen of them hidden - noise for a crawler and for anyone
                 reading the markup. --}}
            @if(!empty($category) && mb_strtolower($category) === $key)
              <a class="topic-clear" href="{{ $topicsRootUrl }}">{{ __('site.all_topics_option') }}</a>
            @endif
          </div>

          <h3>{{ \App\Support\Taxonomy::category($cat) }}</h3>

          <ul class="topic-list">
            <li>
              <a class="topic-item {{ (!empty($category) && mb_strtolower($category) === $key && empty($sub)) ? 'on' : '' }}"
                 href="{{ $topicUrls[$cat] ?? '#' }}">{{ __('site.all') }}</a>
            </li>
            @foreach($topicTree[$key] ?? [] as $s)
              <li>
                {{-- A topic with nothing under it this week is still a topic, and
                     shown - but greyed, and without a "0" beside it. Printing the
                     zero labels the emptiness twice. --}}
                <a class="topic-item {{ (!empty($sub) && mb_strtolower($sub) === mb_strtolower($s['name'])) ? 'on' : '' }} {{ $s['count'] === 0 ? 'empty' : '' }}"
                   href="{{ $subUrls[$key][$s['name']] ?? '#' }}">
                  <span>{{ \App\Support\Taxonomy::subCategory($s['name']) }}</span>
                  @if($s['count'] > 0)<span class="chip-count">{{ $s['count'] }}</span>@endif
                </a>
              </li>
            @endforeach
          </ul>

          @if(empty($topicTree[$key]))
            <p class="chip-note">{{ __('site.no_subtopics') }}</p>
          @endif
        </div>
      @endforeach

    </div>

    <script>
      (function () {
        var browser = document.getElementById('topic-browser');
        if (!browser) { return; }

        var levels = browser.querySelectorAll('[data-topic-level]');

        function show(name) {
          for (var i = 0; i < levels.length; i++) {
            levels[i].hidden = levels[i].getAttribute('data-topic-level') !== name;
          }
        }

        // Opening a topic shows its sub-topics from the page instead of asking
        // the server for a list it already sent. The anchor keeps its href, so
        // without JavaScript - and for a crawler - every topic is still an
        // ordinary link to its own page.
        browser.addEventListener('click', function (event) {
          var opener = event.target.closest('[data-topic-open]');

          if (opener) {
            event.preventDefault();
            show(opener.getAttribute('data-topic-open'));
            return;
          }

          if (event.target.closest('[data-topic-back]')) {
            event.preventDefault();
            show('root');
          }
        });

        // Land on the level the URL describes, so arriving at a sub-topic page
        // does not show the reader the top of the tree again.
        show(browser.getAttribute('data-topic-initial') || 'root');
      })();
    </script>
  @endif
@endsection
