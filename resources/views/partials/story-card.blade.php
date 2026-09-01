{{-- One line of the feed: the headline, and underneath it the three things
     that decide whether it is worth opening - when, who, where.

     The summary is in the markup but not shown. A crawler and a reader without
     JavaScript both get it; everyone else gets it in a dialog when they tap the
     headline, which keeps forty stories on a phone screen instead of six. --}}
@php
  $published = !empty($story['published_at']) ? strtotime((string) $story['published_at']) : null;
  $when      = $published ? \Carbon\Carbon::createFromTimestamp($published)->diffForHumans(null, true) . ' ' . __('site.ago') : null;
  $distance  = $story['distance_km'] ?? null;
  $place     = $story['location_label'] ?? null;
  $byReader  = ($story['origin'] ?? 'scraper') === 'user';
  $meta      = trim(implode('  ·  ', array_filter([$when, $story['source'] ?? null, $place])));
@endphp

<li class="story" data-meta="{{ $meta }}">
  <a class="headline" href="{{ $story['url'] }}" {!! $byReader ? '' : ' target="_blank" rel="noopener nofollow"' !!}>{{ $story['title'] }}</a>

  <p class="meta">
    @if($when)<span>{{ $when }}</span>@endif
    @if(!empty($story['source']))<span class="src">{{ $story['source'] }}</span>@endif
    @if($place)
      <a class="place" href="{{ \App\Support\Loc::route('place', ['slug' => \App\Support\Slug::make($place)]) }}">{{ $place }}</a>
    @endif
    {{-- Only when far enough to be worth knowing. On a feed that is nearby by
         definition, printing "Nearby" beside a place name that already says
         where the story is wraps the line for no information at all. --}}
    @if($distance !== null && $distance >= 1)
      <span class="dist">{{ round($distance, 1) }} km</span>
    @endif
    @if($byReader)<span class="reader">{{ __('site.by_a_reader') }}</span>@endif
  </p>

  <p class="summary">{{ $story['summary'] ?? '' }}</p>
</li>
