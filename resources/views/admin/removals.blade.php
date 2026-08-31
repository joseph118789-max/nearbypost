@extends('layouts.admin')
@section('title', 'Removed')
@push('styles')
@include('admin.sources._styles')
<style>
  .rem { background:#fff;border:1px solid #e2edf6;border-radius:16px;padding:14px 18px;margin-bottom:9px; }
  .rem .t { font-size:0.9rem;font-weight:600;line-height:1.4; }
  .rem .why { font-size:0.84rem;color:#bc4e2c;margin-top:5px;line-height:1.5; }
  .rem .m { font-size:0.75rem;color:#8aa4b8;margin-top:4px; }
  .prop { background:#f0f9ff;border:1px solid #bae6fd;border-radius:18px;padding:18px;margin-bottom:12px; }
  .prop .r { font-size:0.95rem;font-weight:600;color:#0a2a3b;line-height:1.5;margin-bottom:6px; }
  .prop .b { font-size:0.8rem;color:#5f7f9a;margin-bottom:12px;line-height:1.5; }
</style>
@endpush

@section('content')
<div class="srcpage">
  <h1>Removed</h1>
  <p class="lede">
    Every story taken off the site, and the reason given. One removal is an incident; enough of
    them are a pattern, and a pattern is a rule that should already have existed. When there are
    enough, the reasons can be read together and turned into wording for
    <a href="{{ route('admin.rules.index') }}">Rules</a> &mdash; so the reviewer stops making the
    same mistake and you stop making the same correction.
  </p>

  @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="warn">{{ $errors->first() }}</div>@endif

  <div class="srccard" style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
    <div style="flex:1 1 260px;">
      <strong>{{ $unreviewed }}</strong> removal(s) not yet turned into rules.
      @if($unreviewed < $enough)
        <span class="dim">Needs at least {{ $enough }} &mdash; fewer than that is anecdote.</span>
      @endif
    </div>

    <form method="post" action="{{ route('admin.removals.suggest') }}">
      @csrf
      <button class="btn btn-primary" type="submit" {{ $unreviewed < $enough ? 'disabled' : '' }}>
        Read these and propose rules
      </button>
    </form>

    @if($unreviewed > 0)
      <form method="post" action="{{ route('admin.removals.dismiss') }}">
        @csrf
        <button class="btn" type="submit">Mark as read</button>
      </form>
    @endif
  </div>

  @if(count($proposals))
    <h2>Proposed rules</h2>
    <p class="lede">
      Written by reading the reasons above. <strong>Nothing here is in force.</strong> Add the ones
      you agree with; the wording is editable afterwards on the Rules page.
    </p>

    @foreach($proposals as $p)
      <div class="prop">
        <div class="r">{{ $p['rule'] }}</div>
        @if($p['because'])<div class="b">From: {{ $p['because'] }}</div>@endif

        <form method="post" action="{{ route('admin.removals.accept') }}"
              style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
          @csrf
          <input type="hidden" name="rule" value="{{ $p['rule'] }}">
          <select class="inp" name="applies_to" style="max-width:230px;">
            <option value="both" {{ $p['applies_to'] === 'both' ? 'selected' : '' }}>Everything</option>
            <option value="contributor" {{ $p['applies_to'] === 'contributor' ? 'selected' : '' }}>Reader submissions only</option>
            <option value="scraper" {{ $p['applies_to'] === 'scraper' ? 'selected' : '' }}>Gathered articles only</option>
          </select>
          <button class="btn btn-primary" type="submit">Add this rule</button>
        </form>
      </div>
    @endforeach
  @endif

  <h2>What was removed ({{ count($removals) }})</h2>

  @forelse($removals as $r)
    <div class="rem">
      <div class="t">{{ $r->title }}</div>
      <div class="why">{{ $r->reason }}</div>
      <div class="m">
        {{ $r->origin === 'user' ? 'reader post' : 'gathered article' }}
        @if($r->source) &middot; {{ $r->source }} @endif
        @if($r->primary_category) &middot; {{ $r->primary_category }} @endif
        &middot; removed {{ \Carbon\Carbon::parse($r->created_at)->diffForHumans() }}
        @if($r->reviewed_at) &middot; <span class="badge">already learned from</span> @endif
      </div>
    </div>
  @empty
    <p class="lede">Nothing has been removed yet.</p>
  @endforelse
</div>
@endsection
