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
  $hasAdvanced = ($radius ?? null) !== 20 || ($days ?? null) !== 7;
@endphp

<form class="controls" method="get" id="feed-controls"
      action="{{ \App\Support\Loc::route(($tab ?? '') === 'interest' ? 'interest' : 'home') }}">
  <div class="controls-row">
    @if(($tab ?? '') !== 'interest')
      <label class="visually-hidden" for="place">{{ __('site.field_place') }}</label>
      <input class="place-input" id="place" type="text" name="place" value="{{ $place ?? '' }}"
             placeholder="{{ __('site.field_place') }}" maxlength="120" enterkeyhint="search">
    @else
      <span class="place-input as-label">{{ __('site.latest_news') }}</span>
    @endif

    <button class="go" type="submit" aria-label="{{ __('site.show_news_here') }}">
      <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M10 2a8 8 0 1 0 4.9 14.32l5.39 5.39 1.42-1.42-5.39-5.39A8 8 0 0 0 10 2Zm0 2a6 6 0 1 1 0 12 6 6 0 0 1 0-12Z"/></svg>
    </button>

    <details class="menu adv" {{ $hasAdvanced ? 'open' : '' }}>
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

        <button class="go wide" type="submit">{{ __('site.show_news_here') }}</button>
      </div>
    </details>

    {{-- Out in the open, not behind the toggle. Radius and time range are set
         once and forgotten; who wrote the story is a question a reader asks
         while reading, and it is how they learn that some of this was sent in
         by their neighbours. --}}
    <div class="sourcepick" role="group" aria-label="{{ __('site.source') }}">
      @foreach(['' => __('site.all'), 'official' => __('site.official'), 'unofficial' => __('site.unofficial')] as $value => $label)
        <label class="{{ (string) ($source ?? '') === (string) $value ? 'on' : '' }}">
          <input type="radio" name="source" value="{{ $value }}" {{ (string) ($source ?? '') === (string) $value ? 'checked' : '' }}>
          <span>{{ $label }}</span>
        </label>
      @endforeach
    </div>

    {{-- Their own line, so each is wide enough to read. --}}
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

      {{-- Offered only once a topic is chosen: until then it has nothing in it,
           and an empty control is a question a reader cannot answer. --}}
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
  </div>
</form>

<script>
  (function () {
    var form = document.getElementById('feed-controls');
    if (!form) { return; }

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

    // Same as the topic pickers: choosing applies it. Leaving a chosen filter
    // sitting unapplied next to a list it does not describe is worse than
    // having no filter at all.
    form.querySelectorAll('.sourcepick input[type=radio]').forEach(function (radio) {
      radio.addEventListener('change', function () { form.submit(); });
    });
  })();
</script>
