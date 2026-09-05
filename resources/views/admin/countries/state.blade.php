@extends('layouts.admin')
@section('title', $stateName . ', ' . $name)
@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
<style>
  .crumb { font-size: 0.84rem; color: #5f7f9a; margin: 0 0 6px; }
  .crumb a { color: #1f5679; }
  .num { text-align: right; font-variant-numeric: tabular-nums; }
  .dim { color: #a3b6c6; }
  .kept { font-weight: 600; }
  .kept.low { color: #a8501e; }
  .story { display: block; color: #123c55; text-decoration: none; font-weight: 600; }
  .story:hover { color: #1c5a7f; text-decoration: underline; }
  .meta { font-size: 0.76rem; color: #8aa4b8; }
  .tag { display: inline-block; padding: 1px 7px; border-radius: 20px; font-size: 0.68rem; background: #f2f7fb; border: 1px solid #e2edf6; color: #5f7f9a; margin-left: 6px; vertical-align: middle; }
  .tag.live { background: #eef7f0; border-color: #cfe6d5; color: #3d7350; }
  .tag.disc { background: #fdf2ec; border-color: #f0d8c8; color: #a8501e; }
</style>
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

  <p class="crumb">
    <a href="{{ route('admin.countries.index', ['view' => $view, 'view' => $view]) }}">Countries</a> &rsaquo;
    <a href="{{ route('admin.countries.country', ['view' => $view, 'iso3' => $iso3]) }}">{{ $name }}</a>
    @if($city) &rsaquo; <a href="{{ route('admin.countries.state', ['view' => $view, 'iso3' => $iso3, 'state' => $state]) }}">{{ $stateName }}</a> @endif
  </p>
  {{-- The two master tabs. Same layout beneath either; only the scope differs. --}}
  <div class="pillrow" style="margin-bottom:10px;">
    <a href="{{ request()->fullUrlWithQuery(['view' => 'raw']) }}" class="{{ $view === 'raw' ? 'on' : '' }}">Raw &mdash; everything ingested</a>
    <a href="{{ request()->fullUrlWithQuery(['view' => 'live']) }}" class="{{ $view === 'live' ? 'on' : '' }}">Live &mdash; what readers see</a>
  </div>
  <h1>{{ $cityName ?? $stateName }}@if($day) <span style="font-weight:400;color:#5f7f9a;">&middot; {{ \Carbon\Carbon::parse($day)->format('l j F Y') }}</span>@endif</h1>
  <p class="lede">
    {{ number_format($total) }} stories about {{ $stateName === 'No specific place' ? $name . ' with no specific place' : $stateName }}
    @if($day) on {{ \Carbon\Carbon::parse($day)->format('j M') }} @else in the last {{ $days }} days @endif, by the source that sent them &mdash; this is what the number on the grid is made of.
    <b>Kept</b> is what a reader can see as a share of what the source filed.
  </p>

  <div class="pillrow" style="margin-bottom:16px;">
    @if($day)
      <a href="{{ route('admin.countries.state', ['view' => $view, 'iso3' => $iso3, 'state' => $state, 'day' => \Carbon\Carbon::parse($day)->subDay()->toDateString()]) }}">&larr; {{ \Carbon\Carbon::parse($day)->subDay()->format('j M') }}</a>
      <a class="on" href="#">{{ \Carbon\Carbon::parse($day)->format('j M') }}</a>
      @if(\Carbon\Carbon::parse($day)->lt(now('Asia/Kuala_Lumpur')->startOfDay()))
        <a href="{{ route('admin.countries.state', ['view' => $view, 'iso3' => $iso3, 'state' => $state, 'day' => \Carbon\Carbon::parse($day)->addDay()->toDateString()]) }}">{{ \Carbon\Carbon::parse($day)->addDay()->format('j M') }} &rarr;</a>
      @endif
      <a href="{{ route('admin.countries.state', ['view' => $view, 'iso3' => $iso3, 'state' => $state]) }}" style="margin-left:auto;">Whole window</a>
    @else
      @foreach([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $n => $label)
        <a href="{{ route('admin.countries.state', ['view' => $view, 'iso3' => $iso3, 'state' => $state, 'days' => $n]) }}" class="{{ $days === $n ? 'on' : '' }}">{{ $label }}</a>
      @endforeach
    @endif
  </div>

  @if(count($cities))
    <h2>By district</h2>
    <div style="overflow-x:auto;margin-bottom:22px;">
      <table class="srctable" style="font-size:0.82rem;">
        <thead><tr><th style="position:sticky;left:0;background:#fff;min-width:220px;">District <span style="float:right;">Total</span></th>
          @foreach($dates as $d)<th class="num" style="white-space:nowrap;">{{ \Carbon\Carbon::parse($d)->format('j M') }}</th>@endforeach</tr></thead>
        <tbody>
          @foreach($cities as $row)
            <tr style="{{ $row['code'] === '' ? 'color:#8aa4b8;font-style:italic;' : '' }}">
              <td style="position:sticky;left:0;background:#fff;">
                @if($row['code'] !== '')<a class="srcname" href="{{ route('admin.countries.city', ['view' => $view, 'iso3' => $iso3, 'state' => $state, 'city' => $row['code']]) }}">{{ $row['name'] }}</a>@else {{ $row['name'] }} @endif
                <span style="float:right;font-weight:700;">{{ $row['total'] ?: '' }}</span>
              </td>
              @foreach($dates as $d)
                @php $n = $cityGrid[$row['code']][$d] ?? 0; @endphp
                <td class="num {{ $n ? '' : 'dim' }}">@if($n && $row['code'] !== '')<a href="{{ route('admin.countries.city', ['view' => $view, 'iso3' => $iso3, 'state' => $state, 'city' => $row['code'], 'day' => $d]) }}" style="color:inherit;text-decoration:none;">{{ $n }}</a>@elseif($n){{ $n }}@else·@endif</td>
              @endforeach
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
    <h2>By source</h2>
  @endif

  <table class="srctable">
    <thead><tr><th>Source</th><th>Filed</th><th>Live</th><th>Pinned</th><th>Duplicate</th><th>Discarded</th><th>Kept</th><th>Latest</th></tr></thead>
    <tbody>
      @forelse($rows as $r)
        @php $kept = $r->stories ? round($r->live * 100 / $r->stories) : 0; @endphp
        <tr>
          <td><span class="srcname">{{ $r->source }}</span></td>
          <td class="num">{{ $r->stories }}</td>
          <td class="num {{ $r->live ? '' : 'dim' }}">{{ $r->live }}</td>
          <td class="num {{ $r->pinned ? '' : 'dim' }}">{{ $r->pinned }}</td>
          <td class="num {{ $r->duplicate ? '' : 'dim' }}">{{ $r->duplicate }}</td>
          <td class="num {{ $r->discarded ? '' : 'dim' }}">{{ $r->discarded }}</td>
          <td class="num kept {{ $kept < 25 ? 'low' : '' }}">{{ $kept }}%</td>
          <td class="num dim">{{ \Carbon\Carbon::parse($r->latest)->timezone('Asia/Kuala_Lumpur')->format('j M') }}</td>
        </tr>
      @empty
        <tr><td colspan="8" style="text-align:center;color:#b6c3ce;padding:26px;">No stories here in this window.</td></tr>
      @endforelse
    </tbody>
  </table>

  @if(count($recent))
    <h2>{{ $day ? 'The stories' : 'Most recent' }}</h2>
    <table class="srctable">
      <thead><tr><th>Story</th><th>Source</th><th>Place as written</th><th>When</th></tr></thead>
      <tbody>
        @foreach($recent as $s)
          <tr>
            <td style="max-width:34rem;">
              <a class="story" href="{{ $s->url }}" target="_blank" rel="noopener">{{ $s->title }}</a>
              <span class="meta">#{{ $s->id }}</span>
              @if($s->live)<span class="tag live">live</span>@elseif($s->ai_status === 'discarded')<span class="tag disc">discarded</span>@else<span class="tag">{{ $s->ai_status }}</span>@endif
            </td>
            <td>{{ $s->source }}</td>
            <td class="meta">{{ $s->canonical_place_name ?: '—' }}</td>
            <td class="num dim">{{ \Carbon\Carbon::parse($s->published_at)->timezone('Asia/Kuala_Lumpur')->format('j M H:i') }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>
@endsection
