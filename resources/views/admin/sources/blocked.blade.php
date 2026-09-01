@extends('layouts.admin')
@section('title', 'Do not visit')
@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

  <a class="back" href="{{ route('admin.sources.countries') }}">&larr; Sources</a>
  <h1>Do not visit</h1>
  <p class="lede">
    Addresses the crawler must leave alone. Deleting a source is not enough by itself: new sources
    are learned from what the aggregator cites and from feeds declared on publishers' pages, so
    anything removed by hand comes back on the next discovery run. This is what makes a removal
    stick.
    <br>
    Blocking a <strong>host</strong> covers everything on it, including subdomains. Junk usually
    arrives by the domain rather than by the page.
  </p>

  @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="warn">{{ $errors->first() }}</div>@endif

  <form class="srccard" method="post" action="{{ route('admin.sources.block') }}">
    @csrf
    <label class="lbl" for="u">Address</label>
    <input class="inp" id="u" type="url" name="url" required maxlength="900"
           placeholder="https://example.com/feed">
    <div class="srcrow">
      <div>
        <label class="lbl" for="s">How much of it</label>
        <select class="inp" id="s" name="scope">
          <option value="url">Just this address</option>
          <option value="host">The whole site, including subdomains</option>
        </select>
      </div>
      <div>
        <label class="lbl" for="r">Why (optional)</label>
        <input class="inp" id="r" type="text" name="reason" maxlength="300"
               placeholder="Aggregator, not a publisher">
      </div>
      <div class="onoff"><button class="btn btn-primary" type="submit">Block it</button></div>
    </div>
  </form>

  <h2>Blocked ({{ count($blocked) }})</h2>

  @forelse($blocked as $b)
    <div class="srccard" style="padding:14px 18px;">
      <div class="row-compact" style="display:flex;justify-content:space-between;gap:14px;flex-wrap:wrap;">
        <div style="min-width:0;">
          <div style="font-size:0.9rem;font-weight:600;word-break:break-all;">
            {{ $b->scope === 'host' ? $b->host . ' (whole site)' : $b->url }}
          </div>
          <div class="srcmeta" style="margin:4px 0 0;">
            {{ $b->reason ?: 'No reason given.' }}
            &middot; blocked {{ \Carbon\Carbon::parse($b->created_at)->diffForHumans() }}
          </div>
        </div>
        <form method="post" action="{{ route('admin.sources.unblock', ['id' => $b->id]) }}">
          @csrf @method('DELETE')
          <button class="btn" type="submit">Allow again</button>
        </form>
      </div>
    </div>
  @empty
    <p class="lede">Nothing is blocked.</p>
  @endforelse
</div>
@endsection
