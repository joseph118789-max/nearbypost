@extends('layouts.public')

@section('main')
  {{-- Where a forwarded link lands. Everything a reader needs to decide what to
       do next is above the fold: what happened, when, where, who reported it,
       and the two ways onward - the publisher, or the rest of the feed. --}}
  <article class="story-page">
    <div class="story-meta">
      @if($story->primary_category && \App\Support\Taxonomy::isCanonical($story->primary_category))
        <a class="story-category"
           href="{{ \App\Support\Loc::route('category', ['slug' => \App\Support\Slug::make($story->primary_category)]) }}">{{ \App\Support\Taxonomy::category($story->primary_category) }}</a>
      @endif

      @if($story->sub_category && !in_array($story->sub_category, ['Others', 'General'], true))
        <span class="story-category">{{ \App\Support\Taxonomy::subCategory($story->sub_category) }}</span>
      @endif

      @if($story->origin === 'user')
        <span class="story-category origin-mark">{{ __('site.by_a_reader') }}</span>
      @endif
    </div>

    <h1 class="post-title">{{ $story->title }}</h1>

    <div class="post-byline">
      @if($story->source)<span>{{ $story->source }}</span>@endif
      @if($story->published_at)
        <time datetime="{{ \Carbon\Carbon::parse($story->published_at)->toIso8601String() }}">{{ \Carbon\Carbon::parse($story->published_at)->diffForHumans() }}</time>
      @endif
      {{-- ⛔ A place whose name has no Latin letters slugs to an empty
           string, and route('place', ['slug' => '']) THROWS - which took the
           whole story page down with a 500, not just the link. Seven live
           stories carry such a label today: நேப்பாளம், 布城, الدوحة and the
           rest. story-card.blade.php already guarded this; these two did not,
           and neither did the sitemap. --}}
      @php $placeSlug = $place ? \App\Support\Slug::make($place) : ''; @endphp
      @if($place && $placeSlug !== '')
        <a href="{{ \App\Support\Loc::route('place', ['slug' => \App\Support\Slug::make($place)]) }}">{{ $place }}</a>
      @endif
    </div>

    @if($story->image_path)
      <figure class="post-figure">
        <img src="{{ asset($story->image_path) }}" alt="{{ $story->title }}" loading="lazy">
      </figure>
    @endif

    @if($story->summary)
      <p class="post-standfirst">{{ $story->summary }}</p>
    @endif

    {{-- The summary is ours; the article is not. The publisher's link is the
         primary action on the page and says whose it is, so nobody has to
         wonder whether this site wrote it. --}}
    <div class="story-onward">
      @if($story->origin !== 'user')
        <a class="btn-primary" href="{{ $story->url }}" target="_blank" rel="noopener nofollow">
          {{ __('site.read_at_source') }}@if($story->source) &middot; {{ $story->source }}@endif &#8599;
        </a>
      @endif

      @if($place && $placeSlug !== '')
        <a class="btn-quiet" href="{{ \App\Support\Loc::route('place', ['slug' => $placeSlug]) }}">{{ __('site.more_near', ['place' => $place]) }}</a>
      @endif

      <a class="btn-quiet" href="{{ \App\Support\Loc::route('home') }}">{{ __('site.browse_all') }}</a>
    </div>

    @if($related)
      <section class="story-related">
        <h2>{{ $place ? __('site.also_near', ['place' => $place]) : __('site.more_like_this') }}</h2>
        <ul>
          @foreach($related as $item)
            <li>
              <a href="{{ \App\Support\Loc::route('story', ['id' => $item->news_item_id]) }}">{{ $item->title }}</a>
              <span class="meta">{{ $item->source }}@if($item->location_label) &middot; {{ $item->location_label }}@endif</span>
            </li>
          @endforeach
        </ul>
      </section>
    @endif
  </article>
@endsection
