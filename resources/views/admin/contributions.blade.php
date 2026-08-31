@extends('layouts.admin')

@section('title', 'News')

@push('styles')
<style>
  body { background: #eef2f5; font-family: Inter, system-ui, sans-serif; color: #0a2a3b; }
  .queue { max-width: 1100px; margin: 0 auto; padding: 20px 16px 60px; }
  .queue h1 { font-size: 1.5rem; font-weight: 700; color: #1c5a7f; margin-bottom: 4px; }
  .queue .lede { color: #5f7f9a; font-size: 0.9rem; margin-bottom: 18px; line-height: 1.6; }
  .flash { background: #e0f5e9; color: #1f7840; padding: 12px 18px; border-radius: 14px;
           margin-bottom: 18px; font-size: 0.9rem; }
  .errors { background: #fff3f0; color: #bc4e2c; padding: 12px 18px; border-radius: 14px;
            margin-bottom: 18px; font-size: 0.9rem; }

  .tabs { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
  .tabs a { padding: 9px 18px; border-radius: 40px; text-decoration: none; font-size: 0.85rem;
            font-weight: 600; background: #fff; color: #5f7f9a; border: 1px solid #e2edf6; }
  .tabs a.on { background: #1c5a7f; color: #fff; border-color: #1c5a7f; }
  .tabs .n { opacity: 0.65; font-weight: 500; margin-left: 4px; }

  .searchbar { display: flex; gap: 8px; margin-bottom: 16px; flex-wrap: wrap; }
  .searchbar input { border: 1px solid #d4e2ef; border-radius: 40px; padding: 9px 18px;
                     font-size: 0.85rem; min-width: 280px; font-family: inherit; }

  .sub { background: #fff; border: 1px solid #e2edf6; border-radius: 20px; padding: 18px 20px;
         margin-bottom: 12px; }
  .sub-top { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-bottom: 10px; }
  .badge { background: #e9f0f6; padding: 4px 12px; border-radius: 30px; font-size: 0.7rem;
           font-weight: 600; color: #1f5679; }
  .badge-new { background: #fef3c7; color: #78350f; }
  .badge-trusted, .badge-live { background: #e0f5e9; color: #1f7840; }
  .badge-no { background: #fff3f0; color: #bc4e2c; }
  .badge-reader { background: #fef3c7; color: #78350f; }
  .sub h3 { font-size: 1.05rem; margin-bottom: 8px; line-height: 1.35; }
  .sub .meta { font-size: 0.78rem; color: #5f7f9a; margin-bottom: 12px; }
  .sub .body { font-size: 0.88rem; line-height: 1.7; color: #34505f; white-space: pre-wrap;
               background: #f8fafc; padding: 14px 16px; border-radius: 14px; margin-bottom: 12px; }
  .sub .verdict { font-size: 0.8rem; color: #5f7f9a; font-style: italic; margin-bottom: 12px; }
  .sub img.shot { max-width: 260px; border-radius: 14px; display: block; margin-bottom: 12px; }

  .actions { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
  .actions form { display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
  .btn { border: none; font-weight: 600; padding: 8px 16px; border-radius: 40px; cursor: pointer;
         background: #f0f4f9; color: #1f5679; font-size: 0.8rem; font-family: inherit;
         text-decoration: none; display: inline-block; }
  .btn-primary { background: #1c5a7f; color: #fff; }
  .btn-danger { background: #fff3f0; color: #bc4e2c; border: 1px solid #f0cfc0; }
  .reason { border: 1px solid #d4e2ef; border-radius: 40px; padding: 8px 14px; font-size: 0.8rem;
            min-width: 200px; font-family: inherit; }

  .empty { background: #fff; border: 1px dashed #d4e2ef; border-radius: 20px; padding: 28px;
           text-align: center; color: #5f7f9a; font-size: 0.9rem; }
  .row-compact { display: flex; justify-content: space-between; gap: 14px; align-items: flex-start;
                 flex-wrap: wrap; }
  .row-compact .t { font-size: 0.92rem; font-weight: 600; line-height: 1.4; }
  .row-compact .s { font-size: 0.76rem; color: #5f7f9a; margin-top: 4px; }
  .pager { display: flex; gap: 8px; justify-content: center; margin-top: 18px; }
  .pager a, .pager span { padding: 7px 14px; border-radius: 40px; font-size: 0.8rem;
                          background: #fff; border: 1px solid #e2edf6; color: #1f5679;
                          text-decoration: none; }
  .pager span { opacity: 0.45; }
</style>
@endpush

@section('content')
<div class="queue">
  <h1>News</h1>
  <p class="lede">
    Everything on the site, by who wrote it &mdash; the same three words readers see in the
    Source filter. <strong>Official</strong> is gathered by Nearbypost from news publishers;
    <strong>Unofficial</strong> is sent in by readers.
  </p>

  @if(session('status'))
    <div class="flash">{{ session('status') }}</div>
  @endif

  @if($errors->any())
    <div class="errors">{{ $errors->first() }}</div>
  @endif

  <div class="tabs">
    <a class="{{ $tab === 'waiting' ? 'on' : '' }}"
       href="{{ route('admin.contributions.index') }}">Waiting for review <span class="n">{{ $counts['waiting'] }}</span></a>
    <a class="{{ $tab === 'unofficial' ? 'on' : '' }}"
       href="{{ route('admin.contributions.index', ['tab' => 'unofficial']) }}">Unofficial <span class="n">{{ $counts['unofficial'] }}</span></a>
    <a class="{{ $tab === 'official' ? 'on' : '' }}"
       href="{{ route('admin.contributions.index', ['tab' => 'official']) }}">Official <span class="n">{{ number_format($counts['official']) }}</span></a>
  </div>

  {{-- ── Waiting: a person reads a new contributor's first story ──────── --}}
  @if($tab === 'waiting')
    <p class="lede">
      Everything here has already passed the automatic news check. This is the second question,
      which no machine can answer: is it true, and do we want it on the site with our name on it.
      <br>
      <strong>Publish</strong> releases one story. <strong>Publish &amp; trust</strong> also says
      this person can be relied on &mdash; their later posts go live as soon as the automatic check
      passes them. {{ $trustedCount }} contributor(s) trusted so far.
    </p>

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
            <button class="btn" type="submit">Publish &amp; trust this writer</button>
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
  @else

  {{-- ── Unofficial and Official: everything, searchable ──────────────── --}}
    <form class="searchbar" method="get" action="{{ route('admin.contributions.index') }}">
      <input type="hidden" name="tab" value="{{ $tab }}">
      <input type="text" name="q" value="{{ $search }}" maxlength="120"
             placeholder="Search headline, source or place">
      <button class="btn btn-primary" type="submit">Search</button>
      @if($search !== '')
        <a class="btn" href="{{ route('admin.contributions.index', ['tab' => $tab]) }}">Clear</a>
      @endif
    </form>

    @forelse($rows as $post)
      @php
        $writer = $contributors[$post->contributor_id] ?? null;

        // On the site, as opposed to merely not withdrawn. A story can be
        // 'active' and still have never reached the feed.
        $live = isset($served[$post->id]);

        // 'held' is the one status an editor sets. Everything else here -
        // pending_extraction, international - belongs to the ingestion
        // pipeline's own state machine, and putting one of those "back" would
        // overwrite the state it is waiting on.
        $held = $post->status === 'held';
        $busy = !$live && !$held;
      @endphp

      <div class="sub">
        <div class="row-compact">
          <div style="min-width:0;flex:1;">
            <div class="t">{{ $post->title }}</div>
            <div class="s">
              @if($post->origin === 'user')
                <span class="badge badge-reader">reader</span>
                {{ $writer?->name ?? 'Unknown' }}
                @if($writer?->trusted_at) <span class="badge badge-trusted">trusted</span> @endif
              @else
                {{ $post->source ?: 'Unknown source' }}
              @endif
              &middot; {{ $post->published_at?->diffForHumans() }}
              @if($post->location_label) &middot; {{ $post->location_label }} @endif
              @if($post->primary_category) &middot; {{ $post->primary_category }} @endif
              @if(!$live && $post->review_reason) &middot; {{ $post->review_reason }} @endif
            </div>
          </div>

          <div class="actions">
            @if($live)
              <span class="badge badge-live">live</span>
            @elseif($held)
              <span class="badge badge-no">{{ $post->review_status === 'rejected' ? 'taken down' : 'held' }}</span>
            @else
              {{-- Not on the site, and not ours to change: either the pipeline
                   has not finished with it or it never passed the gates. --}}
              <span class="badge">{{ str_replace('_', ' ', $post->status) }}</span>
            @endif

            <a class="btn" href="{{ $post->url }}" target="_blank" rel="noopener">View</a>

            @if($live)
              <form method="post" action="{{ route('admin.contributions.unpublish', ['id' => $post->id]) }}">
                @csrf
                @if($post->origin === 'user')
                  <input class="reason" type="text" name="reason" maxlength="280"
                         placeholder="Reason (the contributor reads this)">
                @endif
                <button class="btn btn-danger" type="submit">Take down</button>
              </form>
            @elseif($held)
              {{-- Taking down without a way back is a trap. --}}
              <form method="post" action="{{ route('admin.contributions.republish', ['id' => $post->id]) }}">
                @csrf
                <button class="btn" type="submit">Put back</button>
              </form>

              <form method="post" action="{{ route('admin.contributions.destroy', ['id' => $post->id]) }}"
                    onsubmit="return confirm('Delete this story and its picture for good?');">
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
      <div class="empty">
        @if($search !== '')
          Nothing matches &ldquo;{{ $search }}&rdquo;.
        @else
          Nothing here yet.
        @endif
      </div>
    @endforelse

    <div class="pager">{{ $rows->links() }}</div>
  @endif
</div>
@endsection
