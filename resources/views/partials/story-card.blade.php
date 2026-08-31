{{-- One story. Semantic <article> with a real <time>, so the structure is
     readable without relying on the JSON-LD alone. --}}
@php
  $published = !empty($story['published_at']) ? strtotime((string) $story['published_at']) : null;
  $distance  = $story['distance_km'] ?? null;
  $source    = $story['source'] ?? null;
@endphp

<article class="story-card">
  <div class="story-meta">
    @if(!empty($story['primary_category']))
      <a class="story-category"
         href="{{ \App\Support\Loc::route('category', ['slug' => \App\Support\Slug::make($story['primary_category'])]) }}">{{ ucwords($story['primary_category']) }}</a>
    @endif

    @if(!empty($story['sub_category']) && $story['sub_category'] !== 'Others')
      <span class="story-category">{{ $story['sub_category'] }}</span>
    @endif

    @if($distance !== null)
      <span class="story-nearby">{{ $distance < 1 ? 'Nearby' : round($distance, 1) . ' km' }}</span>
    @endif
  </div>

  <h2 class="story-title">
    <a href="{{ $story['url'] }}" target="_blank" rel="noopener nofollow">{{ $story['title'] }}</a>
  </h2>

  @if(!empty($story['summary']))
    <p class="story-summary">{{ $story['summary'] }}</p>
  @endif

  <div class="story-footer">
    <div class="story-footer-left">
      @if($published)
        <time datetime="{{ date('c', $published) }}">{{ \Carbon\Carbon::createFromTimestamp($published)->diffForHumans() }}</time>
      @endif

      @if($source)
        {{-- Attribution sits with the story and opens the publisher's own page.
             We summarise other people's journalism, so the credit and the route
             back to it belong on every card, not just on the headline. --}}
        <a class="story-source-link" href="{{ $story['url'] }}" target="_blank" rel="noopener nofollow">
          {{ $source }}<span class="external-mark" aria-hidden="true">&#8599;</span>
          <span class="visually-hidden">{{ __('site.opens_original', ['source' => $source]) }}</span>
        </a>
      @endif
    </div>

    @if(!empty($story['location_label']))
      <a class="story-place"
         href="{{ \App\Support\Loc::route('place', ['slug' => \App\Support\Slug::make($story['location_label'])]) }}">{{ $story['location_label'] }}</a>
    @endif
  </div>
</article>
