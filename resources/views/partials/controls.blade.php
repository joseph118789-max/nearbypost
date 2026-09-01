{{-- Everything a reader can change, in one row on a phone.

     One form, submitted by one button, working with no JavaScript at all. The
     radius and the period are behind a toggle because they are set once and
     then forgotten, while the place and the topic are what people actually
     reach for - and on a phone the difference between "always visible" and
     "one tap away" is most of the screen. --}}
@php
  $hasAdvanced = request()->hasAny(['radius', 'days', 'source'])
      || ($radius ?? null) !== 20 || ($days ?? null) !== 7 || !empty($source);
@endphp

<form class="controls" method="get" action="{{ \App\Support\Loc::route(($tab ?? '') === 'interest' ? 'interest' : 'home') }}">
  <div class="controls-row">
    @if(($tab ?? '') !== 'interest')
      <label class="visually-hidden" for="place">{{ __('site.field_place') }}</label>
      <input class="place-input" id="place" type="text" name="place" value="{{ $place ?? '' }}"
             placeholder="{{ __('site.field_place') }}" maxlength="120" enterkeyhint="search">
    @endif

    <label class="visually-hidden" for="category">{{ __('site.topics') }}</label>
    <select class="pick" id="category" name="category">
      <option value="">{{ __('site.topics') }} &middot; {{ __('site.all') }}</option>
      @foreach($categories ?? [] as $cat)
        <option value="{{ $cat }}" {{ !empty($category) && mb_strtolower($category) === mb_strtolower($cat) ? 'selected' : '' }}>
          {{ \App\Support\Taxonomy::category($cat) }}
        </option>
      @endforeach
    </select>

    {{-- Only shown once a topic is chosen, because it is empty until then and
         an empty control is a question a reader cannot answer. --}}
    @if(!empty($subCategories))
      <label class="visually-hidden" for="sub">{{ __('site.subtopics') }}</label>
      <select class="pick" id="sub" name="sub">
        <option value="">{{ __('site.subtopics') }} &middot; {{ __('site.all') }}</option>
        @foreach($subCategories as $s)
          <option value="{{ $s['name'] }}" {{ !empty($sub) && mb_strtolower($sub) === mb_strtolower($s['name']) ? 'selected' : '' }}>
            {{ \App\Support\Taxonomy::subCategory($s['name']) }} ({{ $s['count'] }})
          </option>
        @endforeach
      </select>
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

        <p class="adv-label">{{ __('site.source') }}</p>
        <div class="segmented">
          @foreach(['' => __('site.all'), 'official' => __('site.official'), 'unofficial' => __('site.unofficial')] as $value => $label)
            <label class="{{ (string) ($source ?? '') === (string) $value ? 'on' : '' }}">
              <input type="radio" name="source" value="{{ $value }}" {{ (string) ($source ?? '') === (string) $value ? 'checked' : '' }}>
              <span>{{ $label }}</span>
            </label>
          @endforeach
        </div>

        <button class="go wide" type="submit">{{ __('site.show_news_here') }}</button>
      </div>
    </details>
  </div>
</form>
