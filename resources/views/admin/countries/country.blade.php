@extends('layouts.admin')
@section('title', $name)
@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
<style>
  .crumb { font-size: 0.84rem; color: #5f7f9a; margin: 0 0 6px; }
  .crumb a { color: #1f5679; }
  .scroll { overflow-x: auto; }
  .grid { border-collapse: collapse; width: 100%; font-size: 0.82rem; background: #fff; border: 1px solid #e2edf6; border-radius: 12px; }
  .grid th, .grid td { padding: 6px 7px; border-bottom: 1px solid #eff3f9; text-align: right; font-variant-numeric: tabular-nums; white-space: nowrap; }
  .grid th { font-size: 0.66rem; text-transform: uppercase; letter-spacing: 0.06em; color: #8aa4b8; font-weight: 600; }
  /* The name and its total share the one sticky cell. As two columns, the
     sticky name slid over the total when the grid scrolled and "2,606" read
     as "606". */
  .grid th:first-child, .grid td:first-child { text-align: left; position: sticky; left: 0; background: #fff; min-width: 240px; border-right: 1px solid #e2edf6; }
  .grid td:first-child a { color: #1f5679; text-decoration: none; font-weight: 600; }
  .grid td:first-child a:hover { text-decoration: underline; }
  .grid td:first-child .tot { float: right; font-weight: 700; color: #123c55; margin-left: 12px; }
  .grid th:first-child .tot { float: right; }
  .grid td a.cell { display: block; color: inherit; text-decoration: none; }
  .grid td a.cell:hover { color: #1c5a7f; text-decoration: underline; }
  .grid td.z { color: #d5dfe8; }
  .grid td.hot { background: #e8f1f8; color: #1c5a7f; font-weight: 600; }
  .grid tr.none td { color: #8aa4b8; font-style: italic; }
  .grid tr.none td a { color: #5f7f9a; font-weight: 400; }
  .grid tr.unknown td:first-child::after { content: " (no polygon)"; color: #a8501e; font-weight: 400; font-size: 0.72rem; }
</style>
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

  <p class="crumb"><a href="{{ route('admin.countries.index', ['view' => $view, 'view' => $view]) }}">&larr; Countries</a></p>
  {{-- The two master tabs. Same layout beneath either; only the scope differs. --}}
  <div class="pillrow" style="margin-bottom:10px;">
    <a href="{{ request()->fullUrlWithQuery(['view' => 'raw']) }}" class="{{ $view === 'raw' ? 'on' : '' }}">Raw &mdash; everything ingested</a>
    <a href="{{ request()->fullUrlWithQuery(['view' => 'live']) }}" class="{{ $view === 'live' ? 'on' : '' }}">Live &mdash; what readers see</a>
  </div>
  <h1>{{ $name }}</h1>
  <p class="lede">
    {{ number_format($total) }} {{ $view === 'live' ? 'live' : 'ingested' }} stories in the last {{ $days }} days, by state and by day, today
    first.{{ $view === 'live' ? ' Only stories a reader can see right now - switch to Raw for everything that arrived.' : '' }} Every state the boundary table knows is a row, so a state with no news shows as zeros
    &mdash; the absence is the information. Click a number for what makes it up, by source; click
    a state for the whole window.
  </p>

  <div class="pillrow" style="margin-bottom:16px;">
    @foreach([14 => 'Last 14 days', 30 => 'Last 30 days', 60 => 'Last 60 days'] as $n => $label)
      <a href="{{ route('admin.countries.country', ['view' => $view, 'iso3' => $iso3, 'days' => $n]) }}" class="{{ $days === $n ? 'on' : '' }}">{{ $label }}</a>
    @endforeach
  </div>

  <div class="scroll">
    <table class="grid">
      <thead>
        <tr>
          <th>State / province <span class="tot">Total</span></th>
          @foreach($dates as $d)
            <th title="{{ $d }}">{{ \Carbon\Carbon::parse($d)->format('j M') }}</th>
          @endforeach
        </tr>
      </thead>
      <tbody>
        @foreach($rows as $row)
          @php $code = $row['code'] === '' ? '-' : $row['code']; $max = max(1, max(array_values($grid[$row['code']] ?? [0]))); @endphp
          <tr class="{{ $row['code'] === '' ? 'none' : '' }} {{ $row['known'] ? '' : 'unknown' }}" style="{{ $row['code'] === \App\Http\Controllers\Admin\CountriesReportController::MULTI ? 'border-top:2px solid #dde8f2;' : '' }}">
            <td>
              <a href="{{ route('admin.countries.state', ['view' => $view, 'iso3' => $iso3, 'state' => $code]) }}">{{ $row['name'] }}</a>
              <span class="tot">{{ $row['total'] ? number_format($row['total']) : '' }}</span>
            </td>
            @foreach($dates as $d)
              @php $n = $grid[$row['code']][$d] ?? 0; @endphp
              <td class="{{ $n === 0 ? 'z' : ($n >= $max && $n >= 5 ? 'hot' : '') }}">
                @if($n === 0)·@else<a class="cell" href="{{ route('admin.countries.state', ['view' => $view, 'iso3' => $iso3, 'state' => $code, 'day' => $d]) }}" title="{{ $row['name'] }}, {{ \Carbon\Carbon::parse($d)->format('j M') }}: by source">{{ $n }}</a>@endif
              </td>
            @endforeach
          </tr>
        @endforeach
      </tbody>
    </table>
  </div>

  <p class="lede" style="margin-top:14px;">
    Dates are Malaysian. A state marked <em>no polygon</em> has news coded to it but no boundary in
    the table &mdash; a code the source uses that the loaded set does not.
  </p>
</div>
@endsection
