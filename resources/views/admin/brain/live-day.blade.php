@extends('layouts.admin')

@section('title', $date->format('j M Y'))

@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
@include('admin.brain._live_styles')
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

  <p class="crumb"><a href="{{ route('admin.brain.live', array_filter(['country' => ($country ?? 'ALL') === 'ALL' ? null : $country])) }}">&larr; Live</a></p>

  <h1>{{ $date->format('l, j F Y') }}</h1>
  <p class="lede">{{ ucfirst($label) }}.</p>

  <div class="bucketbar">
    @foreach([
      'ingested'  => 'Everything',
      'live'      => 'Live',
      'one_place' => '1 location',
      'many'      => 'Multiple',
      'national'  => 'National',
      'no_place'  => 'No pin',
      'duplicate' => 'Duplicate',
      'discarded' => 'Discarded',
      'waiting'   => 'Waiting',
      'held'      => 'Held',
    ] as $key => $text)
      <a href="{{ route('admin.brain.live.day', ['country' => ($country ?? 'ALL') === 'ALL' ? null : $country, 'date' => $date->toDateString(), 'bucket' => $key]) }}"
         class="{{ $bucket === $key ? 'on' : '' }}">{{ $text }}</a>
    @endforeach
  </div>

  {{-- ── The day's total: where the stories came from ──────────────────── --}}
  @if($sources !== null)
    <div class="card">
      <p class="sub" style="margin-top:0;">
        Every source that filed on this day, and what became of what it filed. A source whose
        stories are mostly duplicates is costing money to re-read what another source already
        gave us; one whose stories are mostly discarded is not writing for this audience.
      </p>

      <div class="table-scroll">
        <table class="daytable">
          <thead>
            <tr>
              <th class="text">Source</th>
              <th>Filed</th>
              <th class="lead">Live</th>
              <th class="sep">1&nbsp;location</th>
              <th>Multiple</th>
              <th>National</th>
              <th>No&nbsp;pin</th>
              <th class="sep">Duplicate</th>
              <th>Discarded</th>
              <th>Waiting</th>
              <th>Held</th>
              <th class="sep">Cost</th>
              <th>Kept</th>
            </tr>
          </thead>
          <tbody>
            @forelse($sources as $s)
              @php $kept = $s->ingested > 0 ? round($s->live * 100 / $s->ingested) : 0; @endphp
              <tr>
                <td class="text">{{ $s->source }}</td>
                <td>{{ $s->ingested }}</td>
                <td class="lead">{{ $s->live }}</td>
                <td class="sep {{ $s->one_place ? '' : 'zero' }}">{{ $s->one_place }}</td>
                <td class="{{ $s->many_places ? '' : 'zero' }}">{{ $s->many_places }}</td>
                <td class="{{ $s->national ? '' : 'zero' }}">{{ $s->national }}</td>
                <td class="held {{ $s->no_place ? 'nonzero' : 'zero' }}">{{ $s->no_place }}</td>
                <td class="sep {{ $s->duplicate ? '' : 'zero' }}">{{ $s->duplicate }}</td>
                <td class="{{ $s->discarded ? '' : 'zero' }}">{{ $s->discarded }}</td>
                <td class="{{ $s->waiting ? '' : 'zero' }}">{{ $s->waiting }}</td>
                <td class="held {{ $s->held ? 'nonzero' : 'zero' }}">{{ $s->held }}</td>
                <td class="sep money">
                  @if((float) $s->cost > 0)${{ number_format((float) $s->cost, 3) }}@else<span class="zero">&mdash;</span>@endif
                </td>
                {{-- The one number that ranks sources against each other. --}}
                <td class="{{ $kept < 25 ? 'held nonzero' : '' }}">{{ $kept }}%</td>
              </tr>
            @empty
              <tr><td colspan="13" style="text-align:center;color:#b6c3ce;padding:26px;">
                Nothing arrived on this day.
              </td></tr>
            @endforelse
          </tbody>
        </table>
      </div>

      <p class="legend">
        <b>Kept</b> is Live as a share of Filed &mdash; the share of what a source gave us that
        readers can actually see. Marked when it falls below a quarter, which usually means
        duplicates of another source or a beat this site does not cover.
      </p>
    </div>
  @endif

  {{-- ── A single count: the stories behind it ─────────────────────────── --}}
  @if($stories !== null)
    <div class="card">
      <p class="sub" style="margin-top:0;">
        {{ number_format($stories->total()) }}
        {{ \Illuminate\Support\Str::plural('story', $stories->total()) }}@if($stories->hasPages()),
          showing {{ $stories->firstItem() }}&ndash;{{ $stories->lastItem() }}@endif.
      </p>

      <div class="table-scroll">
        <table class="daytable">
          <thead>
            <tr>
              <th class="text">Story</th>
              <th class="text">Source</th>
              <th class="text">Category</th>
              <th class="text">Where</th>
              <th>News time / got</th>
            </tr>
          </thead>
          <tbody>
            @forelse($stories as $st)
              <tr>
                <td class="text" style="max-width:34rem;">
                  <a class="storytitle" href="{{ $st->url }}" target="_blank" rel="noopener">{{ $st->title }}</a>
                  <span class="storymeta">#{{ $st->id }}</span>
                </td>
                <td class="text">{{ $st->source }}</td>
                <td class="text">
                  {{ $st->ai_category ?: '—' }}
                  @if($st->sub_category)
                    <span class="storymeta">{{ $st->sub_category }}</span>
                  @endif
                </td>
                <td class="text">
                  @if($st->placed > 0)
                    {{-- Every place the story is served at, not just the first.
                         The badge says three, so three must be readable. --}}
                    <span class="tag pin">{{ $st->placed }} pin{{ $st->placed > 1 ? 's' : '' }}</span>
                    <span class="pinlist">
                      @foreach($st->pin_list as $pin)
                        @if($pin->lat !== null && $pin->lng !== null)
                          <a class="pinrow" target="_blank" rel="noopener"
                             href="https://www.google.com/maps/search/?api=1&query={{ $pin->lat }},{{ $pin->lng }}"
                             title="{{ number_format($pin->lat, 5) }}, {{ number_format($pin->lng, 5) }} &mdash; opens Google Maps">
                            {{ $pin->location_label ?: '(unnamed)' }}
                            <span class="pingo" aria-hidden="true">&#9906;</span>
                          </a>
                        @endif
                      @endforeach
                    </span>
                  @elseif($st->national > 0)
                    <span class="tag">national</span>
                  @elseif($st->main_place_text)
                    <span class="tag none">not placed</span>
                    <span class="storymeta">{{ $st->main_place_text }}</span>
                  @else
                    <span class="zero">&mdash;</span>
                  @endif
                </td>
                @php
                  $pub = \Carbon\Carbon::parse($st->published_at)->timezone('Asia/Kuala_Lumpur');
                  $got = !empty($st->created_at) ? \Carbon\Carbon::parse($st->created_at)->timezone('Asia/Kuala_Lumpur') : null;
                  $prec = $st->published_precision ?? 'time';
                  $sameDay = $got && $got->isSameDay($pub);
                @endphp
                <td style="white-space:nowrap;line-height:1.5;">
                  {{-- The publisher's clock, then ours. A story stamped with our own clock says so, in red. --}}
                  @if ($prec === 'scraped')
                    <span style="color:#b23b3b;" title="The publisher gave no time; this is when we fetched it">no news time</span>
                  @elseif ($prec === 'date')
                    <span title="The publisher gave the day only">{{ $pub->format('j M') }}</span>
                  @else
                    <span title="Publisher's time">{{ $pub->format('H:i') }}</span>
                  @endif
                  <br><span style="color:#8aa4b8;font-size:0.8em;" title="When we fetched it">got {{ $got ? ($sameDay ? $got->format('H:i') : $got->format('j M H:i')) : '—' }}</span>
                </td>
              </tr>
            @empty
              <tr><td colspan="5" style="text-align:center;color:#b6c3ce;padding:26px;">
                No stories in this group.
              </td></tr>
            @endforelse
          </tbody>
        </table>
      </div>

      @if($stories->hasPages())
        <div style="margin-top:14px;">{{ $stories->links() }}</div>
      @endif
    </div>
  @endif
</div>
@endsection
