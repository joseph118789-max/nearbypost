@extends('layouts.public')

@section('main')
  <article class="post-page">
    <div class="story-meta">
      @if($post->primary_category && \App\Support\Taxonomy::isCanonical($post->primary_category))
        <a class="story-category"
           href="{{ \App\Support\Loc::route('category', ['slug' => \App\Support\Slug::make($post->primary_category)]) }}">{{ \App\Support\Taxonomy::category($post->primary_category) }}</a>
      @endif

      @if($post->sub_category && !in_array($post->sub_category, ['Others', 'General'], true))
        <span class="story-category">{{ \App\Support\Taxonomy::subCategory($post->sub_category) }}</span>
      @endif

      {{-- Said plainly on the story itself, not only in the feed's filter: a
           reader deserves to know this was sent in by another reader rather
           than gathered from a newsroom. --}}
      <span class="story-category origin-mark">{{ __('site.by_a_reader') }}</span>
    </div>

    <h1 class="post-title">{{ $headline }}</h1>

    <div class="post-byline">
      <span>{{ $post->source }}</span>
      @if($post->published_at)
        <time datetime="{{ $post->published_at->toIso8601String() }}">{{ $post->published_at->diffForHumans() }}</time>
      @endif
      @if($place)
        <a href="{{ \App\Support\Loc::route('place', ['slug' => \App\Support\Slug::make($place)]) }}">{{ $place }}</a>
      @endif
    </div>

    @if($post->image_path)
      <figure class="post-figure">
        <img src="{{ asset($post->image_path) }}" alt="{{ $headline }}" loading="lazy">
      </figure>
    @endif

    @if($standfirst)
      <p class="post-standfirst">{{ $standfirst }}</p>
    @endif

    {{-- The contributor's own words, printed as written. Escaped by Blade and
         broken into paragraphs on blank lines - never rendered as markup. --}}
    <div class="post-body">
      @foreach(preg_split('/\R{2,}/u', (string) $post->body) as $paragraph)
        @if(trim($paragraph) !== '')
          <p>{{ trim($paragraph) }}</p>
        @endif
      @endforeach
    </div>

    <p class="post-disclaimer">{{ __('site.reader_post_disclaimer') }}</p>
  </article>
@endsection
