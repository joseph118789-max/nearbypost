{{-- One story. Semantic <article> with a real <time>, so the structure is
     readable without relying on the JSON-LD alone. --}}
@php
  $published = !empty($story['published_at']) ? strtotime((string) $story['published_at']) : null;
  $distance  = $story['distance_km'] ?? null;
  $source    = $story['source'] ?? null;

  // A gathered story belongs to the publisher who wrote it and opens in a new
  // tab; a contributed one was written here and has a page of its own, so it
  // opens in place like any other link on the site.
  $byReader  = ($story['origin'] ?? 'scraper') === 'user';
  $linkAttrs = $byReader ? '' : ' target="_blank" rel="noopener nofollow"';
@endphp

<article class="story-card">
  <div class="story-meta">
    {{-- Only the twenty-one real categories get a label. Legacy values from
         before the taxonomy was enforced would otherwise show as "OTHERS". --}}
    @if(!empty($story['primary_category']) && \App\Support\Taxonomy::isCanonical($story['primary_category']))
      <a class="story-category"
         href="{{ \App\Support\Loc::route('category', ['slug' => \App\Support\Slug::make($story['primary_category'])]) }}">{{ \App\Support\Taxonomy::category($story['primary_category']) }}</a>
    @endif

    {{-- The sub-category is a filter, not a decoration. Clicking it narrows
         the topic it belongs to, which is what its presence here has always
         implied. --}}
    @if(!empty($story['sub_category']) && !in_array($story['sub_category'], ['Others', 'General'], true))
      @if(!empty($story['primary_category']) && \App\Support\Taxonomy::isCanonical($story['primary_category']))
        <a class="story-category story-subcategory"
           href="{{ \App\Support\Loc::route('category', ['slug' => \App\Support\Slug::make($story['primary_category'])]) }}?sub={{ urlencode($story['sub_category']) }}">{{ \App\Support\Taxonomy::subCategory($story['sub_category']) }}</a>
      @else
        <span class="story-category">{{ \App\Support\Taxonomy::subCategory($story['sub_category']) }}</span>
      @endif
    @endif

    @if($distance !== null)
      <span class="story-nearby">{{ $distance < 1 ? 'Nearby' : round($distance, 1) . ' km' }}</span>
    @endif
  </div>

  <h2 class="story-title">
    <a href="{{ $story['url'] }}"{!! $linkAttrs !!}>{{ $story['title'] }}</a>
  </h2>

  @if(!empty($story['image_path']))
    <a class="story-figure" href="{{ $story['url'] }}"{!! $linkAttrs !!}>
      <img src="{{ asset($story['image_path']) }}" alt="" loading="lazy">
    </a>
  @endif

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
        <a class="story-source-link" href="{{ $story['url'] }}"{!! $linkAttrs !!}>
          {{ $source }}@unless($byReader)<span class="external-mark" aria-hidden="true">&#8599;</span>@endunless
          <span class="visually-hidden">
            {{ $byReader ? __('site.opens_post') : __('site.opens_original', ['source' => $source]) }}
          </span>
        </a>

        @if($byReader)
          {{-- Said on the card, not only in the filter: a reader should be able
               to tell at a glance that this came from another reader. --}}
          <span class="origin-mark">{{ __('site.by_a_reader') }}</span>
        @endif
      @endif
    </div>

    @if(!empty($story['location_label']))
      <a class="story-place"
         href="{{ \App\Support\Loc::route('place', ['slug' => \App\Support\Slug::make($story['location_label'])]) }}">{{ $story['location_label'] }}</a>
    @endif
  </div>
</article>
