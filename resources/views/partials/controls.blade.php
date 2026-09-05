{{-- Everything a reader can change, in one form.

     On a phone the place and the buttons take the first line and the two topic
     pickers take the second, because five controls on one line truncates every
     one of them to "Kuala Lum", "Crime" and "Sub-t(" - which tells a reader
     neither what is selected nor what they are choosing between.

     Radius, period and source stay behind a toggle: they are set once and then
     forgotten, while the place and the topic are what people reach for. One
     GET form, one submit button, no JavaScript required. --}}
@php
  // Source is no longer in there, so a source choice must not spring the panel
  // open on every page load.
  $hasAdvanced = ($radius ?? null) !== 10 || ($days ?? null) !== 7;
@endphp

<form class="controls {{ ($tab ?? '') === 'interest' ? 'interest' : '' }}" method="get" id="feed-controls"
      action="{{ \App\Support\Loc::route(($tab ?? '') === 'interest' ? 'interest' : 'home') }}">
  <div class="controls-row">
    @if(($tab ?? '') !== 'interest')
      <label class="visually-hidden" for="place">{{ __('site.field_place') }}</label>
      <input class="place-input" id="place" type="text" name="place" value="{{ $place ?? '' }}"
             placeholder="{{ __('site.field_place') }}" maxlength="120" enterkeyhint="search">
    @else
      {{-- Which country's news. The reader is in Malaysia (by address), so
           Malaysia is the default; the list holds only countries with live
           stories. Choosing one applies at once. --}}
      <label class="visually-hidden" for="country">{{ __('site.country') }}</label>
      <select class="pick countrypick" id="country" name="country">
        @foreach($countries ?? ['MYS' => 'Malaysia'] as $code => $label)
          <option value="{{ $code }}" {{ ($country ?? 'MYS') === $code ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
      </select>
    @endif

    {{-- Typing a town is work a phone already knows the answer to. The pin
         asks the browser instead - but only when it is pressed, and only after
         saying what is about to happen, because a permission prompt nobody
         asked for is refused on reflex. --}}
    @if(($tab ?? '') !== 'interest')
    <button class="go locate" type="button" id="use-location"
            aria-label="{{ __('site.use_my_location') }}" title="{{ __('site.use_my_location') }}">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 2a7 7 0 0 0-7 7c0 5.25 7 13 7 13s7-7.75 7-13a7 7 0 0 0-7-7Zm0 9.5A2.5 2.5 0 1 1 12 6.5a2.5 2.5 0 0 1 0 5Z"/></svg>
    </button>
    @endif

    <button class="go" type="submit" aria-label="{{ __('site.show_news_here') }}">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 2a8 8 0 1 0 4.9 14.32l5.39 5.39 1.42-1.42-5.39-5.39A8 8 0 0 0 10 2Zm0 2a6 6 0 1 1 0 12 6 6 0 0 1 0-12Z"/></svg>
    </button>

    <details class="menu adv">
      <summary aria-label="{{ __('site.more_options') }}" title="{{ __('site.more_options') }}">
        <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M3 6h18v2H3V6Zm4 5h10v2H7v-2Zm3 5h4v2h-4v-2Z"/></svg>
      </summary>

      <div class="menu-body adv-body">
        @if(!empty($showRadius))
          <p class="adv-label">{{ __('site.story_radius') }}</p>
          <div class="segmented">
            @foreach($radii as $r)
              <label class="{{ (int) $r === (int) ($radius ?? 0) ? 'on' : '' }}">
                <input type="radio" name="radius" value="{{ $r }}" {{ (int) $r === (int) ($radius ?? 0) ? 'checked' : '' }}>
                <span>{{ $r }} km</span>
              </label>
            @endforeach
          </div>
        @endif

        <p class="adv-label">{{ __('site.time_range') }}</p>
        <div class="segmented">
          @foreach($windows as $value => $label)
            <label class="{{ (int) $value === (int) ($days ?? 0) ? 'on' : '' }}">
              <input type="radio" name="days" value="{{ $value }}" {{ (int) $value === (int) ($days ?? 0) ? 'checked' : '' }}>
              <span>{{ $label }}</span>
            </label>
          @endforeach
        </div>

        {{-- The topic pickers live in the panel on a phone; on a wide screen
             the right column is the topic picker and these are hidden. --}}
        <p class="adv-label picks-label">{{ __('site.topics') }}</p>
        <div class="picks">
          <label class="visually-hidden" for="category">{{ __('site.topics') }}</label>
          <select class="pick" id="category" name="category">
            <option value="">{{ __('site.all_topics_option') }}</option>
            @foreach($categories ?? [] as $cat)
              <option value="{{ $cat }}" {{ !empty($category) && mb_strtolower($category) === mb_strtolower($cat) ? 'selected' : '' }}>
                {{ \App\Support\Taxonomy::category($cat) }}
              </option>
            @endforeach
          </select>

          @if(!empty($subCategories))
            <label class="visually-hidden" for="sub">{{ __('site.subtopics') }}</label>
            <select class="pick" id="sub" name="sub">
              <option value="">{{ __('site.all_subtopics_option') }}</option>
              @foreach($subCategories as $s)
                <option value="{{ $s['name'] }}" {{ !empty($sub) && mb_strtolower($sub) === mb_strtolower($s['name']) ? 'selected' : '' }}>
                  {{ \App\Support\Taxonomy::subCategory($s['name']) }} ({{ $s['count'] }})
                </option>
              @endforeach
            </select>
          @endif
        </div>

        {{-- Centred on a phone, the panel is nowhere near the button that opened
             it, so it needs its own way out. --}}
        <button class="adv-close" type="button" data-adv-close aria-label="{{ __('site.close') }}">{{ __('site.close') }}</button>
      </div>
    </details>

    {{-- Out in the open, not behind the toggle. Radius and time range are set
         once and forgotten; who wrote the story is a question a reader asks
         while reading, and it is how they learn that some of this was sent in
         by their neighbours. --}}
    <div class="sourcepick" role="group" aria-label="{{ __('site.news_type') }}">
      <span class="row-label">{{ __('site.news_type') }}</span>
      @foreach(['' => __('site.all'), 'official' => __('site.official'), 'unofficial' => __('site.unofficial')] as $value => $label)
        <label class="{{ (string) ($source ?? '') === (string) $value ? 'on' : '' }}">
          <input type="radio" name="source" value="{{ $value }}" {{ (string) ($source ?? '') === (string) $value ? 'checked' : '' }}>
          <span>{{ $label }}</span>
        </label>
      @endforeach
    </div>

    {{-- Nearest or newest is a way of looking at the list you already have,
         so it sits in the open, not behind the filter. Only where distance
         means something: on By Interest every story is the same distance away. --}}
    @if(!empty($showRadius))
      <div class="sourcepick sortpick" role="group" aria-label="{{ __('site.sort_by') }}">
        @foreach(['distance' => __('site.sort_distance'), 'time' => __('site.sort_time')] as $value => $label)
          <label class="{{ (string) ($sort ?? 'time') === (string) $value ? 'on' : '' }}">
            <input type="radio" name="sort" value="{{ $value }}" {{ (string) ($sort ?? 'time') === (string) $value ? 'checked' : '' }}>
            <span>{{ $label }}</span>
          </label>
        @endforeach
      </div>
    @endif
  </div>
</form>

{{-- Said before the browser asks, not after. A reader who is told why is
     deciding; one who gets a bare permission prompt is guessing. --}}
<dialog id="geo-ask" class="peek">
  <article>
    <h2>{{ __('site.geo_title') }}</h2>
    <p id="geo-msg">{{ __('site.geo_body') }}</p>
    <div class="peek-actions">
      <button type="button" id="geo-go">{{ __('site.geo_allow') }}</button>
      <button type="button" data-geo-close>{{ __('site.geo_not_now') }}</button>
    </div>
  </article>
</dialog>

<script>
  (function () {
    var button = document.getElementById('use-location');
    var dialog = document.getElementById('geo-ask');

    if (!button || !navigator.geolocation) {
      if (button) { button.hidden = true; }
      return;
    }

    var REMEMBER = 'np-geo';

    function go(position) {
      var params = new URLSearchParams(window.location.search);
      var query = new URLSearchParams({
        lat: position.coords.latitude.toFixed(5),
        lng: position.coords.longitude.toFixed(5)
      });

      // Whatever the reader had already chosen travels with them: finding out
      // where they are should not quietly reset their radius to the default.
      ['radius', 'days', 'category', 'sub', 'source'].forEach(function (key) {
        if (params.get(key)) { query.set(key, params.get(key)); }
      });

      try { localStorage.setItem(REMEMBER, 'on'); } catch (e) {}
      window.location.href = '{{ \App\Support\Loc::route('locate') }}?' + query.toString();
    }

    function refused() {
      var message = document.getElementById('geo-msg');
      if (message) { message.textContent = @json(__('site.geo_refused')); }
    }

    function ask() {
      navigator.geolocation.getCurrentPosition(go, refused, {
        enableHighAccuracy: false,
        timeout: 10000,
        maximumAge: 600000
      });
    }

    button.addEventListener('click', function () {
      if (dialog && typeof dialog.showModal === 'function') {
        dialog.showModal();
        return;
      }

      ask();   // no <dialog> support: the browser's own prompt is the whole ask
    });

    if (dialog) {
      var accept = document.getElementById('geo-go');
      if (accept) { accept.addEventListener('click', function () { dialog.close(); ask(); }); }

      dialog.querySelectorAll('[data-geo-close]').forEach(function (el) {
        el.addEventListener('click', function () { dialog.close(); });
      });
    }

    // Once allowed, it is the default. Only when the reader has used it before
    // AND the browser still holds the permission AND this page is not already
    // about a place - so no prompt appears unbidden, and a shared link keeps
    // showing the place it names.
    var alreadyPlaced = new URLSearchParams(window.location.search).has('place');
    // Only where the location is the point. On By Interest the feed is not
    // about where the reader is, and jumping them to the Near Me feed on load
    // read as the page refreshing itself away from what they had chosen.
    var onNearMe = @json(($tab ?? 'nearme') === 'nearme');
    var remembered = false;

    try { remembered = localStorage.getItem(REMEMBER) === 'on'; } catch (e) {}

    if (remembered && onNearMe && !alreadyPlaced && navigator.permissions) {
      navigator.permissions.query({ name: 'geolocation' }).then(function (status) {
        if (status.state === 'granted') { ask(); }
      }).catch(function () {});
    }
  })();
</script>

<script>
  (function () {
    var form = document.getElementById('feed-controls');
    if (!form) { return; }

    // A closed <details> hides its body in the user-agent stylesheet, which no
    // rule of ours can reach. On a wide screen the panel is part of the column
    // rather than a popover, so it is opened here and its summary hidden in
    // CSS. On a phone it stays a toggle, which is what 375px needs.
    var adv = form.querySelector('.adv');

    if (adv && window.matchMedia('(min-width: 1024px)').matches) {
      adv.open = true;
    }

    // The highlighted pill is rendered by the server for the value the page
    // loaded with. Choosing another radio must move it at once, or two pills
    // look chosen until the form is submitted.
    form.querySelectorAll('.segmented, .sourcepick').forEach(function (group) {
      group.querySelectorAll('input[type=radio]').forEach(function (radio) {
        radio.addEventListener('change', function () {
          group.querySelectorAll('label').forEach(function (label) {
            label.classList.toggle('on', label.contains(radio));
          });
        });
      });
    });

    // On a phone the panel floats in the middle of the screen: a tap outside
    // it, the Escape key, or its own Close button puts it away. On a wide
    // screen it is part of the column and stays.
    if (adv) {
      var phone = function () { return !window.matchMedia('(min-width: 1024px)').matches; };

      adv.querySelectorAll('[data-adv-close]').forEach(function (el) {
        el.addEventListener('click', function () { adv.open = false; });
      });

      document.addEventListener('click', function (event) {
        if (phone() && adv.open && !adv.contains(event.target)) { adv.open = false; }
      });

      document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && adv.open && phone()) { adv.open = false; }
      });
    }

    var category = form.querySelector('#category');
    var sub = form.querySelector('#sub');

    // Choosing a topic reloads at once, so its sub-topics appear without the
    // reader having to guess that a second tap is needed. The submit button
    // still works and is what happens with no JavaScript.
    if (category) {
      category.addEventListener('change', function () {
        // A sub-topic belongs to the topic it was chosen under; carrying
        // Badminton into Health would filter to nothing.
        if (sub) { sub.value = ''; }
        form.submit();
      });
    }

    if (sub) {
      sub.addEventListener('change', function () { form.submit(); });
    }

    var country = form.querySelector('#country');

    if (country) {
      country.addEventListener('change', function () { form.submit(); });
    }

    // Same as the topic pickers: choosing applies it. Leaving a chosen filter
    // sitting unapplied next to a list it does not describe is worse than
    // having no filter at all.
    // Sort applies on the spot, like the source picker and unlike the radius:
    // "nearest or newest" is a way of looking at the list you already have, not
    // a setting you configure and then apply. Radius and period stay behind the
    // button because changing either is a different question being asked.
    form.querySelectorAll('.sourcepick input[type=radio], input[name=sort], input[name=radius], input[name=days]').forEach(function (radio) {
      radio.addEventListener('change', function () { form.submit(); });
    });

    // The filter button in the tabs row (phones) opens the same panel.
    var toggle = document.getElementById('adv-toggle');

    if (toggle && adv) {
      toggle.addEventListener('click', function (event) {
        event.stopPropagation();
        adv.open = !adv.open;
      });
    }
  })();
</script>
