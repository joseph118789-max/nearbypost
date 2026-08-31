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

  {{-- "News in other places" used to sit here: a chip cloud that mixed suburbs,
       cities and states in one row, because a story's location is whatever the
       article named at whatever scale it named it. Place pages are still live
       and still in the sitemap; they are no longer offered as a set to choose
       from, because they were never a comparable set. --}}
@endsection

@section('aside')
  {{-- The topic browser: the main categories, and one level down, the
       sub-categories of whichever one is open. The level shown is derived
       entirely from the URL, so back is a link and there is no state to keep. --}}
  @if(!empty($categories))
    <div class="info-card">
      @if(empty($category))
        <h3>{{ __('site.topics') }}</h3>
        <div class="filter-chips">
          @foreach($categories as $cat)
            <a class="filter-chip" href="{{ $topicUrls[$cat] ?? '#' }}">{{ \App\Support\Taxonomy::category($cat) }}</a>
          @endforeach
        </div>
      @else
        <a class="topic-back" href="{{ $topicsRootUrl }}">
          <span aria-hidden="true">&#8592;</span> {{ __('site.topics') }}
        </a>

        <h3>{{ \App\Support\Taxonomy::category($category) }}</h3>

        <div class="filter-chips">
          <a class="filter-chip {{ empty($sub) ? 'active' : '' }}"
             href="{{ request()->fullUrlWithQuery(['sub' => null]) }}">{{ __('site.all') }}</a>
          @foreach($subCategories as $s)
            <a class="filter-chip {{ !empty($sub) && mb_strtolower($sub) === mb_strtolower($s['name']) ? 'active' : '' }}"
               href="{{ request()->fullUrlWithQuery(['sub' => $s['name']]) }}">{{ \App\Support\Taxonomy::subCategory($s['name']) }}<span class="chip-count">{{ $s['count'] }}</span></a>
          @endforeach
        </div>

        @if(empty($subCategories))
          <p class="chip-note">{{ __('site.no_subtopics') }}</p>
        @endif
      @endif
    </div>
  @endif
@endsection
