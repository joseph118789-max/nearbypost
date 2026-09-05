{{-- One line of the feed: the headline, and underneath it the three things
     that decide whether it is worth opening - when, who, where.

     The summary is in the markup but not shown. A crawler and a reader without
     JavaScript both get it; everyone else gets it in a dialog when they tap the
     headline, which keeps forty stories on a phone screen instead of six. --}}
@php
  $published = !empty($story['published_at']) ? strtotime((string) $story['published_at']) : null;
  // "9 hours ago" only when the publisher gave a time. A day is shown as the
  // day; a story the publisher never dated is shown as when we found it.
  $precision = $story['published_precision'] ?? 'time';
  $when = null;
  // An event is about when it HAPPENS, not when the listing was found.
  if (!empty($story['event_start'])) {
      $s = \Carbon\Carbon::parse($story['event_start']);
      $e = !empty($story['event_end']) ? \Carbon\Carbon::parse($story['event_end']) : null;
      $when = $e && !$e->isSameDay($s) ? $s->format('j M') . ' – ' . $e->format('j M') : $s->format('D j M');
  } elseif ($published) {
      $c = \Carbon\Carbon::createFromTimestamp($published)->timezone('Asia/Kuala_Lumpur');
      $when = match ($precision) {
          'date'    => $c->format('j M'),
          'scraped' => __('site.added') . ' ' . $c->format('j M H:i'),
          default   => $c->diffForHumans(null, true) . ' ' . __('site.ago'),
      };
  }
  $distance  = $story['distance_km'] ?? null;
  $place     = $story['location_label'] ?? null;
  $origin    = $story['origin'] ?? 'scraper';
  $byReader  = $origin === 'user';

  // ⛔ THREE ORIGINS, THREE BADGES, AND A READER MUST BE ABLE TO TELL THEM
  // APART. A neighbour who saw something, a publisher who asked us to carry
  // their work, and a newsroom we read are three different kinds of claim on a
  // reader's trust. Showing them identically would flatten that, and it is the
  // publisher's own name that is doing the vouching in the third case.
  $byPublisher = $origin === 'publisher';
  $meta      = trim(implode('  ·  ', array_filter([$when, $story['source'] ?? null, $place])));
@endphp

<li class="story" data-meta="{{ $meta }}"@if($byReader && !empty($story['news_item_id'])) data-post="{{ $story['news_item_id'] }}"@endif>
  {{-- Our own address, so the link a reader copies or forwards brings the next
       person here rather than straight to the publisher. A reader's post already
       has a page of its own and keeps it. data-src carries the publisher's link
       for the popup and for anyone without JavaScript to reach from the page. --}}
  <a class="headline"
     href="{{ $byReader || empty($story['news_item_id'])
              ? $story['url']
              : \App\Support\Loc::route('story', ['id' => $story['news_item_id']]) }}"
     data-src="{{ $story['url'] }}">{{ $story['title'] }}</a>

  <p class="meta">
    @if($when)<span>{{ $when }}</span>@endif
    @if($byReader)<span class="src community-mark">{{ __('site.community') }}</span>
    @elseif($byPublisher && !empty($story['source']))<span class="src publisher-mark">{{ $story['source'] }}</span>
    @elseif(!empty($story['source']))<span class="src">{{ $story['source'] }}</span>@endif
    @if($place)
      @php $placeSlug = \App\Support\Slug::make($place); @endphp
      {{-- A label that makes no slug (a name in Chinese script, say) is shown
           but not linked: a link with an empty slug is a 500 for the whole page. --}}
      @if ($placeSlug !== '' && $placeSlug !== null)
        <a class="place" href="{{ \App\Support\Loc::route('place', ['slug' => $placeSlug]) }}">{{ $place }}</a>
      @else
        <span class="place">{{ $place }}</span>
      @endif
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
