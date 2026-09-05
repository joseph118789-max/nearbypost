@extends('layouts.public')
@php $wide = true; @endphp

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
      @if(config('services.community.enabled') && in_array($post->review_status, ['rejected', 'removed'], true) || ($post->status === 'held' && $post->review_status === 'pending_review'))
        @php $openAppeal = \Illuminate\Support\Facades\DB::table('community_appeals')->where('news_item_id', $post->id)->orderByDesc('id')->first(); @endphp
        @if($openAppeal)
          <p class="mini">{{ __('site.appeal_' . $openAppeal->status) }} @if($openAppeal->resolution)— {{ $openAppeal->resolution }}@endif</p>
        @else
          <details class="inline-details"><summary>{{ __('site.appeal') }}</summary>
            <form method="post" action="{{ route('community.appeal', ['id' => $post->id]) }}" style="display:grid;gap:8px;max-width:520px">
              @csrf
              <textarea class="modal-input" name="text" rows="3" required minlength="10" maxlength="2000" placeholder="{{ __('site.appeal_hint') }}"></textarea>
              <button type="submit" class="desktop-action-btn">{{ __('site.send_appeal') }}</button>
              <span class="mini">{{ __('site.appeal_deadline') }}</span>
            </form>
          </details>
        @endif
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
