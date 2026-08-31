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
      Try a nearby town or city, or <a href="{{ route('interest') }}">browse all news</a>.
    </div>
  @endif

  <div class="feed-container-inner">
    @forelse($stories as $story)
      @include('partials.story-card', ['story' => $story])
    @empty
      <div class="empty-state">
        <p>No stories match these filters yet.</p>
        <p class="empty-hint">
          Try
          <a href="{{ request()->fullUrlWithQuery(['days' => 30]) }}">a longer period</a>
          @if(!empty($showRadius))
            or <a href="{{ request()->fullUrlWithQuery(['radius' => 50]) }}">a wider radius</a>
          @endif
          @if(!empty($category))
            or <a href="{{ request()->fullUrlWithQuery(['category' => null]) }}">all topics</a>
          @endif.
        </p>
      </div>
    @endforelse
  </div>

  @if(!empty($places))
    {{-- Internal links to sibling places. These give crawlers a path to every
         location page and give readers the obvious next step. --}}
    <section class="link-cloud" aria-labelledby="nearby-places">
      <h2 id="nearby-places">News in other places</h2>
      <div class="filter-chips">
        @foreach(array_slice($places, 0, 24) as $p)
          <a class="filter-chip" href="{{ route('place', ['slug' => \App\Support\Slug::make($p)]) }}">{{ $p }}</a>
        @endforeach
      </div>
    </section>
  @endif
@endsection

@section('aside')
  <div class="info-card">
    <h3>Current settings</h3>
    <div class="info-row"><span>Location</span><span>{{ $place }}</span></div>
    @if(!empty($showRadius))
      <div class="info-row"><span>Radius</span><span>{{ $radius }} km</span></div>
    @endif
    <div class="info-row"><span>Period</span><span>{{ $windows[$days] ?? $days . 'd' }}</span></div>
    @if(!empty($category))
      <div class="info-row"><span>Topic</span><span>{{ ucwords($category) }}</span></div>
    @endif
  </div>

  <form class="info-card" method="get" action="{{ route('home') }}">
    <h3>Change location</h3>
    <label class="visually-hidden" for="place-input">Town or city</label>
    <input class="modal-input" id="place-input" type="text" name="place"
           value="{{ $place }}" placeholder="e.g. Shah Alam" maxlength="120">
    <input type="hidden" name="days" value="{{ $days }}">
    <input type="hidden" name="radius" value="{{ $radius }}">
    <button class="desktop-action-btn primary" type="submit">Show news here</button>
  </form>

  @if(!empty($showRadius))
    <div class="info-card">
      <h3>Story radius</h3>
      <div class="filter-chips">
        @foreach($radii as $r)
          <a class="filter-chip {{ $r === $radius ? 'active' : '' }}"
             href="{{ request()->fullUrlWithQuery(['radius' => $r]) }}">{{ $r }} km</a>
        @endforeach
      </div>
    </div>
  @endif

  <div class="info-card">
    <h3>Time range</h3>
    <div class="filter-chips">
      @foreach($windows as $value => $label)
        <a class="filter-chip {{ $value === $days ? 'active' : '' }}"
           href="{{ request()->fullUrlWithQuery(['days' => $value]) }}">{{ $label }}</a>
      @endforeach
    </div>
  </div>

  @if(!empty($categories))
    <div class="info-card">
      <h3>Topics</h3>
      <div class="filter-chips">
        <a class="filter-chip {{ empty($category) ? 'active' : '' }}"
           href="{{ request()->fullUrlWithQuery(['category' => null]) }}">All</a>
        @foreach($categories as $cat)
          <a class="filter-chip {{ !empty($category) && mb_strtolower($category) === mb_strtolower($cat) ? 'active' : '' }}"
             href="{{ request()->fullUrlWithQuery(['category' => $cat]) }}">{{ ucwords($cat) }}</a>
        @endforeach
      </div>
    </div>
  @endif
@endsection
