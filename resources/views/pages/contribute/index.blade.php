@extends('layouts.public')

@section('main')
  <div class="page-head">
    <h1>{{ __('site.my_posts') }}</h1>
    <p class="page-intro">{{ __('site.my_posts_intro') }}</p>
  </div>

  @if(session('status'))
    <div class="notice">{{ session('status') }}</div>
  @endif

  <div class="contribute-actions">
    <a class="desktop-action-btn primary" href="{{ route('contribute.create') }}">{{ __('site.write_a_post') }}</a>
    <form method="post" action="{{ route('contributor.logout') }}">
      @csrf
      <button class="desktop-action-btn" type="submit">{{ __('site.sign_out') }}</button>
    </form>
  </div>

  @forelse($posts as $post)
    @php
      $state = $post->review_status ?: 'held';
    @endphp

    <article class="story-card">
      <div class="story-meta">
        {{-- The verdict first, because it is the thing the writer opened this
             page to find out. --}}
        <span class="post-state post-state--{{ $state }}">{{ __('site.state_' . $state) }}</span>

        @if($post->primary_category && \App\Support\Taxonomy::isCanonical($post->primary_category))
          <span class="story-category">{{ \App\Support\Taxonomy::category($post->primary_category) }}</span>
        @endif

        <span class="story-category">{{ __('site.section_' . $post->section) }}</span>
      </div>

      <h2 class="story-title">
        @if($state === 'published')
          <a href="{{ route('post.show', ['id' => $post->id]) }}">{{ $post->title }}</a>
        @else
          {{ $post->title }}
        @endif
      </h2>

      @if($post->review_reason)
        <p class="story-summary">{{ $post->review_reason }}</p>
      @endif

      <div class="story-footer">
        <div class="story-footer-left">
          <time datetime="{{ optional($post->created_at)->toIso8601String() }}">
            {{ optional($post->created_at)->diffForHumans() }}
          </time>
          @if($post->location_label ?: $post->main_place_text)
            <span>{{ $post->location_label ?: $post->main_place_text }}</span>
          @endif
        </div>

        <div class="story-footer-left">
          <a class="post-action" href="{{ route('contribute.edit', ['id' => $post->id]) }}">{{ __('site.edit') }}</a>

          <form method="post" action="{{ route('contribute.destroy', ['id' => $post->id]) }}"
                onsubmit="return confirm('{{ __('site.confirm_delete') }}');">
            @csrf
            @method('DELETE')
            <button class="post-action post-action--danger" type="submit">{{ __('site.delete') }}</button>
          </form>
        </div>
      </div>
    </article>
  @empty
    <div class="empty-state">
      <p>{{ __('site.no_posts_yet') }}</p>
      <p class="empty-hint">
        <a href="{{ route('contribute.create') }}">{{ __('site.write_your_first') }}</a>
      </p>
    </div>
  @endforelse
@endsection
