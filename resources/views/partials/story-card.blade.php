{{-- One story. Semantic <article> with a real <time>, so the structure is
     readable without relying on the JSON-LD alone. --}}
@php
  $published = !empty($story['published_at']) ? strtotime((string) $story['published_at']) : null;
  $distance  = $story['distance_km'] ?? null;
@endphp

<article class="story-card">
  <div class="story-meta">
    <span class="story-source">{{ $story['source'] ?? 'Unknown' }}</span>

    @if(!empty($story['primary_category']))
      <a class="story-category"
         href="{{ route('category', ['slug' => \App\Support\Slug::make($story['primary_category'])]) }}">{{ ucwords($story['primary_category']) }}</a>
    @endif

    @if(!empty($story['sub_category']) && $story['sub_category'] !== 'Others')
      <span class="story-category">{{ $story['sub_category'] }}</span>
    @endif

    @if($distance !== null)
      <span class="story-nearby">{{ $distance < 1 ? 'Nearby' : round($distance, 1) . ' km' }}</span>
    @endif
  </div>

  <h2 class="story-title">
    <a href="{{ $story['url'] }}" rel="noopener nofollow" target="_blank">{{ $story['title'] }}</a>
  </h2>

  @if(!empty($story['summary']))
    <p class="story-summary">{{ $story['summary'] }}</p>
  @endif

  <div class="story-footer">
    @if($published)
      <time datetime="{{ date('c', $published) }}">{{ \Carbon\Carbon::createFromTimestamp($published)->diffForHumans() }}</time>
    @else
      <span></span>
    @endif

    @if(!empty($story['location_label']))
      <a class="story-place"
         href="{{ route('place', ['slug' => \App\Support\Slug::make($story['location_label'])]) }}">{{ $story['location_label'] }}</a>
    @endif
  </div>
</article>
