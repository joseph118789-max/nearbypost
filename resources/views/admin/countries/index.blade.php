@extends('layouts.admin')
@section('title', 'Countries')
@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
<style>
  .num { text-align: right; font-variant-numeric: tabular-nums; }
  .dim { color: #a3b6c6; }
  .srctable td.num a { color: inherit; text-decoration: none; }
  .srctable td.num a:hover { color: #1c5a7f; text-decoration: underline; }
</style>
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

  {{-- The two master tabs. Same layout beneath either; only the scope differs. --}}
  <div class="pillrow" style="margin-bottom:10px;">
    <a href="{{ request()->fullUrlWithQuery(['view' => 'raw']) }}" class="{{ $view === 'raw' ? 'on' : '' }}">Raw &mdash; everything ingested</a>
    <a href="{{ request()->fullUrlWithQuery(['view' => 'live']) }}" class="{{ $view === 'live' ? 'on' : '' }}">Live &mdash; what readers see</a>
  </div>
  <h1>Countries</h1>
  <p class="lede">
    Where the news <em>is</em> &mdash; not where the publisher is. A Malaysian paper's report from
    Kathmandu counts here under Nepal; a wire story about Johor counts under Malaysia, whoever
    carried it. A pinned story belongs where its pin falls; an unpinned one where its place text
    and its masthead say. Click a country for its states by day, a state for who sent the news.
  </p>

  <div class="pillrow" style="margin-bottom:8px;">
    @foreach([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days', 365 => 'Last year'] as $n => $label)
      <a href="{{ route('admin.countries.index', ['view' => $view, 'days' => $n]) }}" class="{{ $days === $n ? 'on' : '' }}">{{ $label }}</a>
    @endforeach
  </div>
  {{-- The same country chips as the Live page. "All countries" is this table;
       a country opens its states by day, keeping the view and the window. --}}
  <div class="pillrow" style="margin-bottom:16px;">
    <a href="{{ route('admin.countries.index', ['view' => $view, 'days' => $days]) }}" class="on">All countries</a>
    @foreach(array_slice(array_values(array_filter($rows, fn ($r) => (int) $r->stories > 0)), 0, 12) as $r)
      <a href="{{ route('admin.countries.country', ['iso3' => $r->iso3, 'view' => $view, 'days' => $days]) }}">{{ $r->name }} <span style="opacity:.6;font-size:.85em;">{{ number_format($r->stories) }}</span></a>
    @endforeach
  </div>

  <table class="srctable">
    <thead><tr><th>Country</th><th>Stories</th><th>Pinned</th><th>Live</th><th>Discarded</th><th>States</th><th>Sources</th><th>Latest</th></tr></thead>
    <tbody>
      @forelse($rows as $r)
        <tr>
          <td>
            <a class="srcname" href="{{ route('admin.countries.country', ['view' => $view, 'iso3' => $r->iso3]) }}" style="{{ $r->stories ? '' : 'color:#8aa4b8;font-weight:400;' }}">{{ $r->name }}</a>
            <div class="srcurl">{{ $r->iso3 }}@if(!$r->has_states) &middot; <span style="color:#a8501e;">no states loaded</span>@endif</div>
          </td>
          <td class="num {{ $r->stories ? '' : 'dim' }}"><a href="{{ route('admin.countries.country', ['view' => $view, 'iso3' => $r->iso3]) }}">{{ number_format($r->stories) }}</a></td>
          <td class="num {{ $r->pinned ? '' : 'dim' }}">{{ number_format($r->pinned) }}</td>
          <td class="num {{ $r->live ? '' : 'dim' }}">{{ number_format($r->live) }}</td>
          <td class="num {{ $r->discarded ? '' : 'dim' }}">{{ number_format($r->discarded) }}</td>
          <td class="num {{ $r->states ? '' : 'dim' }}">{{ $r->states }}</td>
          <td class="num">{{ $r->sources }}</td>
          <td class="num dim">{{ $r->latest ? \Carbon\Carbon::parse($r->latest)->timezone('Asia/Kuala_Lumpur')->format('j M') : '·' }}</td>
        </tr>
      @empty
        <tr><td colspan="8" style="text-align:center;color:#b6c3ce;padding:26px;">Nothing in this window.</td></tr>
      @endforelse
    </tbody>
    @if(count($rows))
      <tfoot><tr><td>{{ count($rows) }} countries</td><td class="num"><b>{{ number_format($total) }}</b></td><td colspan="6" class="dim" style="font-size:0.85rem;padding-left:14px;">
        @if($nowhere) plus {{ number_format($nowhere) }} stories that name no country and come from an international outlet @endif
      </td></tr></tfoot>
    @endif
  </table>

  <p class="lede" style="margin-top:14px;">
    <b>Pinned</b> has coordinates inside that country's polygon. <b>Stories</b> also counts the
    unpinned &mdash; national stories, and foreign ones that were discarded before anyone tried to
    place them &mdash; by the country their text or masthead gives.
  </p>
</div>
@endsection
