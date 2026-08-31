@extends('layouts.public')

@section('main')
  <div class="page-head">
    <h1>{{ $heading }}</h1>
    {{-- A plain factual lead-in. Answer engines quote opening sentences, so this
         states what the page covers rather than leaving it to be inferred. --}}
    <p class="page-intro">{{ $intro }}</p>
  </div>

  @if(!empty($unresolved))
    <div class="notice">
      We could not find <strong>{{ $place }}</strong> on the map.
      Try a nearby town or city, or <a href="{{ \App\Support\Loc::route('interest') }}">browse all news</a>.
    </div>
  @endif

  <div class="feed-container-inner">
    @forelse($stories as $story)
      @include('partials.story-card', ['story' => $story])
    @empty
      <div class="empty-state">
        <p>{{ __('site.empty_title') }}</p>
        <p class="empty-hint">
          {{ __('site.try') }}
          <a href="{{ request()->fullUrlWithQuery(['days' => 30]) }}">{{ __('site.longer_period') }}</a>
          @if(!empty($showRadius))
            {{ __('site.or') }} <a href="{{ request()->fullUrlWithQuery(['radius' => 50]) }}">{{ __('site.wider_radius') }}</a>
          @endif
          @if(!empty($sub))
            {{ __('site.or') }} <a href="{{ request()->fullUrlWithQuery(['sub' => null]) }}">{{ __('site.all_subtopics') }}</a>
          @endif
          @if(!empty($category))
            {{ __('site.or') }} <a href="{{ request()->fullUrlWithQuery(['category' => null, 'sub' => null]) }}">{{ __('site.all_topics') }}</a>
          @endif.
        </p>
      </div>
    @endforelse
  </div>

  @if(!empty($places))
    {{-- Internal links to sibling places. These give crawlers a path to every
         location page and give readers the obvious next step. --}}
    <section class="link-cloud" aria-labelledby="nearby-places">
      <h2 id="nearby-places">{{ __('site.other_places') }}</h2>
      <div class="filter-chips">
        @foreach(array_slice($places, 0, 24) as $p)
          <a class="filter-chip" href="{{ \App\Support\Loc::route('place', ['slug' => \App\Support\Slug::make($p)]) }}">{{ $p }}</a>
        @endforeach
      </div>
    </section>
  @endif
@endsection

@section('aside')
  <div class="info-card">
    <h3>{{ __('site.current_settings') }}</h3>
    <div class="info-row"><span>{{ __('site.location') }}</span><span>{{ $place }}</span></div>
    @if(!empty($showRadius))
      <div class="info-row"><span>{{ __('site.radius') }}</span><span>{{ $radius }} km</span></div>
    @endif
    <div class="info-row"><span>{{ __('site.period') }}</span><span>{{ $windows[$days] ?? $days . 'd' }}</span></div>
    @if(!empty($category))
      <div class="info-row"><span>{{ __('site.topic') }}</span><span>{{ \App\Support\Taxonomy::category($category) }}</span></div>
    @endif
    @if(!empty($sub))
      <div class="info-row"><span>{{ __('site.subtopics') }}</span><span>{{ \App\Support\Taxonomy::subCategory($sub) }}</span></div>
    @endif
  </div>

  <form class="info-card" method="get" action="{{ \App\Support\Loc::route('home') }}">
    <h3>{{ __('site.change_location') }}</h3>
    <label class="visually-hidden" for="place-input">{{ __('site.town_or_city') }}</label>
    <input class="modal-input" id="place-input" type="text" name="place"
           value="{{ $place }}" placeholder="e.g. Shah Alam" maxlength="120">
    <input type="hidden" name="days" value="{{ $days }}">
    <input type="hidden" name="radius" value="{{ $radius }}">
    <button class="desktop-action-btn primary" type="submit">{{ __('site.show_news_here') }}</button>
  </form>

  @if(!empty($showRadius))
    <div class="info-card">
      <h3>{{ __('site.story_radius') }}</h3>
      <div class="filter-chips">
        @foreach($radii as $r)
          <a class="filter-chip {{ $r === $radius ? 'active' : '' }}"
             href="{{ request()->fullUrlWithQuery(['radius' => $r]) }}">{{ $r }} km</a>
        @endforeach
      </div>
    </div>
  @endif

  <div class="info-card">
    <h3>{{ __('site.time_range') }}</h3>
    <div class="filter-chips">
      @foreach($windows as $value => $label)
        <a class="filter-chip {{ $value === $days ? 'active' : '' }}"
           href="{{ request()->fullUrlWithQuery(['days' => $value]) }}">{{ $label }}</a>
      @endforeach
    </div>
  </div>

  @if(!empty($categories))
    <div class="info-card">
      <h3>{{ __('site.topics') }}</h3>
      <div class="filter-chips">
        {{-- Changing topic clears the sub-topic: Badminton does not survive a
             move from Sports to Health. --}}
        <a class="filter-chip {{ empty($category) ? 'active' : '' }}"
           href="{{ request()->fullUrlWithQuery(['category' => null, 'sub' => null]) }}">{{ __('site.all') }}</a>
        @foreach($categories as $cat)
          <a class="filter-chip {{ !empty($category) && mb_strtolower($category) === mb_strtolower($cat) ? 'active' : '' }}"
             href="{{ request()->fullUrlWithQuery(['category' => $cat, 'sub' => null]) }}">{{ \App\Support\Taxonomy::category($cat) }}</a>
        @endforeach
      </div>

      @if(!empty($subCategories))
        {{-- Every card already carries a sub-category. Until now it described a
             filter that did not exist: choosing Sports gave no way to reach
             Badminton. Only sub-topics with stories behind them are offered,
             with the count, so nothing here leads to an empty page. --}}
        <div class="subcat-row">
          <h4 class="subcat-heading">{{ __('site.subtopics') }}</h4>
          <div class="filter-chips">
            <a class="filter-chip {{ empty($sub) ? 'active' : '' }}"
               href="{{ request()->fullUrlWithQuery(['sub' => null]) }}">{{ __('site.all') }}</a>
            @foreach($subCategories as $s)
              <a class="filter-chip {{ !empty($sub) && mb_strtolower($sub) === mb_strtolower($s['name']) ? 'active' : '' }}"
                 href="{{ request()->fullUrlWithQuery(['sub' => $s['name']]) }}">{{ \App\Support\Taxonomy::subCategory($s['name']) }}<span class="chip-count">{{ $s['count'] }}</span></a>
            @endforeach
          </div>
        </div>
      @endif
    </div>
  @endif
@endsection
