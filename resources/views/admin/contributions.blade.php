@extends('layouts.admin')

@section('title', 'Reader submissions')

@push('styles')
<style>
  body { background: #eef2f5; font-family: Inter, system-ui, sans-serif; color: #0a2a3b; }
  .queue { max-width: 1100px; margin: 0 auto; padding: 20px 16px 60px; }
  .queue h1 { font-size: 1.5rem; font-weight: 700; color: #1c5a7f; margin-bottom: 4px; }
  .queue .lede { color: #5f7f9a; font-size: 0.9rem; margin-bottom: 20px; line-height: 1.6; }
  .flash { background: #e0f5e9; color: #1f7840; padding: 12px 18px; border-radius: 14px;
           margin-bottom: 18px; font-size: 0.9rem; }
  .errors { background: #fff3f0; color: #bc4e2c; padding: 12px 18px; border-radius: 14px;
            margin-bottom: 18px; font-size: 0.9rem; }
  .queue h2 { font-size: 1.05rem; color: #1c5a7f; margin: 28px 0 12px; }
  .sub { background: #fff; border: 1px solid #e2edf6; border-radius: 20px; padding: 18px 20px;
         margin-bottom: 14px; }
  .sub-top { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-bottom: 10px; }
  .badge { background: #e9f0f6; padding: 4px 12px; border-radius: 30px; font-size: 0.7rem;
           font-weight: 600; color: #1f5679; }
  .badge-new { background: #fef3c7; color: #78350f; }
  .badge-trusted { background: #e0f5e9; color: #1f7840; }
  .badge-live { background: #e0f5e9; color: #1f7840; }
  .badge-no { background: #fff3f0; color: #bc4e2c; }
  .sub h3 { font-size: 1.05rem; margin-bottom: 8px; line-height: 1.35; }
  .sub .meta { font-size: 0.78rem; color: #5f7f9a; margin-bottom: 12px; }
  .sub .body { font-size: 0.88rem; line-height: 1.7; color: #34505f; white-space: pre-wrap;
               background: #f8fafc; padding: 14px 16px; border-radius: 14px; margin-bottom: 12px; }
  .sub .verdict { font-size: 0.8rem; color: #5f7f9a; font-style: italic; margin-bottom: 12px; }
  .sub img.shot { max-width: 260px; border-radius: 14px; display: block; margin-bottom: 12px; }
  .actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
  .actions form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  .btn { border: none; font-weight: 600; padding: 8px 18px; border-radius: 40px; cursor: pointer;
         background: #f0f4f9; color: #1f5679; font-size: 0.82rem; font-family: inherit; }
  .btn-primary { background: #1c5a7f; color: #fff; }
  .btn-danger { background: #fff3f0; color: #bc4e2c; border: 1px solid #f0cfc0; }
  .reason { border: 1px solid #d4e2ef; border-radius: 40px; padding: 8px 16px; font-size: 0.82rem;
            min-width: 240px; font-family: inherit; }
  .check { font-size: 0.8rem; color: #34505f; display: flex; align-items: center; gap: 6px; }
  .empty { background: #fff; border: 1px dashed #d4e2ef; border-radius: 20px; padding: 28px;
           text-align: center; color: #5f7f9a; font-size: 0.9rem; }
  .row-compact { display: flex; justify-content: space-between; gap: 14px; align-items: flex-start; }
  .row-compact .t { font-size: 0.92rem; font-weight: 600; line-height: 1.4; }
  .row-compact .s { font-size: 0.76rem; color: #5f7f9a; margin-top: 4px; }
</style>
@endpush

@section('content')
<div class="queue">
  <h1>Reader submissions</h1>
  <p class="lede">
    Everything here has already passed the automatic news check. This is the second question,
    which no machine can answer: is it true, and do we want it on the site with our name on it.
    <br>
    <strong>Publish</strong> releases one story. <strong>Publish &amp; trust</strong> also says
    this person can be relied on &mdash; their later posts go live as soon as the automatic check
    passes them, so the queue stays about new contributors rather than regulars.
    {{ $trustedCount }} contributor(s) trusted so far.
  </p>

  @if(session('status'))
    <div class="flash">{{ session('status') }}</div>
  @endif

  @if($errors->any())
    <div class="errors">{{ $errors->first() }}</div>
  @endif

  <h2>Waiting for review ({{ count($waiting) }})</h2>

  @forelse($waiting as $post)
    @php $writer = $contributors[$post->contributor_id] ?? null; @endphp

    <div class="sub">
      <div class="sub-top">
        <span class="badge badge-new">New contributor</span>
        <span class="badge">{{ ucfirst($post->section) }}</span>
        @if($post->primary_category)
          <span class="badge">{{ $post->primary_category }}@if($post->sub_category) &middot; {{ $post->sub_category }}@endif</span>
        @endif
        @if($post->location_label ?: $post->main_place_text)
          <span class="badge">{{ $post->location_label ?: $post->main_place_text }}</span>
        @endif
      </div>

      <h3>{{ $post->title }}</h3>

      <p class="meta">
        {{ $writer?->name ?? 'Unknown' }}
        @if($writer) &middot; {{ $writer->email }} @endif
        &middot; {{ $post->created_at?->diffForHumans() }}
      </p>

      @if($post->image_path)
        <img class="shot" src="{{ asset($post->image_path) }}" alt="">
      @endif

      <div class="body">{{ $post->body }}</div>

      @if($post->review_reason)
        <p class="verdict">Automatic check: {{ $post->review_reason }}</p>
      @endif

      <div class="actions">
        <form method="post" action="{{ route('admin.contributions.approve', ['id' => $post->id]) }}">
          @csrf
          <button class="btn btn-primary" type="submit">Publish</button>
        </form>

        <form method="post" action="{{ route('admin.contributions.approve', ['id' => $post->id]) }}">
          @csrf
          <input type="hidden" name="trust" value="1">
          <button class="btn" type="submit">Publish &amp; trust {{ $writer?->name ? explode(' ', $writer->name)[0] : 'this writer' }}</button>
        </form>

        <form method="post" action="{{ route('admin.contributions.reject', ['id' => $post->id]) }}">
          @csrf
          <input class="reason" type="text" name="reason" maxlength="280" required
                 placeholder="Why not? The contributor reads this.">
          <button class="btn btn-danger" type="submit">Turn down</button>
        </form>
      </div>
    </div>
  @empty
    <div class="empty">Nothing waiting. New contributors' first posts appear here.</div>
  @endforelse

  <h2>Recent reader posts</h2>

  @forelse($recent as $post)
    @php $writer = $contributors[$post->contributor_id] ?? null; @endphp

    <div class="sub">
      <div class="row-compact">
        <div>
          <div class="t">{{ $post->title }}</div>
          <div class="s">
            {{ $writer?->name ?? 'Unknown' }}
            @if($writer?->trusted_at) <span class="badge badge-trusted">trusted</span> @endif
            &middot; {{ $post->created_at?->diffForHumans() }}
            @if($post->review_status === 'rejected' && $post->review_reason)
              &middot; {{ $post->review_reason }}
            @endif
          </div>
        </div>

        <div class="actions">
          @if($post->review_status === 'published')
            <span class="badge badge-live">live</span>
            <a class="btn" href="{{ url('/post/' . $post->id) }}" target="_blank" rel="noopener">View</a>
            <form method="post" action="{{ route('admin.contributions.unpublish', ['id' => $post->id]) }}">
              @csrf
              <input class="reason" type="text" name="reason" maxlength="280"
                     placeholder="Reason (the contributor reads this)">
              <button class="btn btn-danger" type="submit">Take down</button>
            </form>
          @else
            <span class="badge badge-no">{{ str_replace('_', ' ', $post->review_status) }}</span>
            <form method="post" action="{{ route('admin.contributions.destroy', ['id' => $post->id]) }}"
                  onsubmit="return confirm('Delete this submission and its picture for good?');">
              @csrf
              @method('DELETE')
              <button class="btn btn-danger" type="submit">Delete</button>
            </form>
          @endif

          @if($writer?->trusted_at)
            <form method="post" action="{{ route('admin.contributions.untrust', ['id' => $writer->id]) }}">
              @csrf
              <button class="btn" type="submit">Stop trusting</button>
            </form>
          @endif
        </div>
      </div>
    </div>
  @empty
    <div class="empty">No reader posts yet.</div>
  @endforelse
</div>
@endsection
