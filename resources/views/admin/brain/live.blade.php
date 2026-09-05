@extends('layouts.admin')

@section('title', 'Live')

@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
@include('admin.brain._live_styles')
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

  <h1>Live</h1>

  @if(!empty($badRuns) && count($badRuns) > 0)
    <div class="fetchalarm">
      <strong>The news fetch did not finish cleanly.</strong>
      A run that dies looks exactly like a quiet news day on the table below &mdash; this is the difference.
      <table>
        <thead><tr><th>when (KL)</th><th>tier</th><th>collected</th><th>stored</th><th>what happened</th></tr></thead>
        <tbody>
        @foreach($badRuns as $r)
          <tr>
            <td>{{ \Carbon\Carbon::parse($r->started_at)->addHours(8)->format('j M, g:ia') }}</td>
            <td>{{ $r->tier }}</td>
            <td>{{ number_format((int) $r->collected) }}</td>
            <td>{{ number_format((int) $r->created) }}</td>
            <td>
              @if($r->status === 'crashed')
                <strong>it crashed</strong>
                @if($r->error) &mdash; {{ \Illuminate\Support\Str::limit($r->error, 120) }} @endif
              @else
                <strong>collected stories and stored none</strong>
              @endif
            </td>
          </tr>
        @endforeach
        </tbody>
      </table>
      <p class="mini">Re-run by hand with <code>php artisan ingest:fetch --tier=primary</code>. The next scheduled run is at 02:00, 08:00, 14:00 or 20:00 KL.</p>
    </div>
  @endif

  <p class="lede">
    A day at a time: what came in, what is on the site, and where the rest went. Every other page
    here judges one story &mdash; this one judges a day, because a fetch that returned nothing or a
    classifier that stopped leaves no story to look at.
    <br>
    The four outcome columns are exclusive and add up to what arrived, so a day always balances.
    Dates are Malaysian, not the server's. <b>Every number opens</b> &mdash; click a count for the
    stories behind it, or a day's total for the source-by-source breakdown.
  </p>

  <div class="pillrow" style="margin-bottom:8px;">
    @foreach([7 => 'Last 7 days', 30 => 'Last 30 days', 90 => 'Last 90 days'] as $n => $label)
      <a href="{{ route('admin.brain.live', array_filter(['days' => $n, 'country' => $country === 'ALL' ? null : $country])) }}" class="{{ $days === $n ? 'on' : '' }}">{{ $label }}</a>
    @endforeach
  </div>
  {{-- The country a story claims, from the boundary layer. "All countries" is
       every story that arrived; Malaysia is the home feed; the rest are what
       foreign sources brought in, shown so their share is visible. --}}
  <div class="pillrow" style="margin-bottom:16px;align-items:center;">
    <a href="{{ route('admin.brain.live', ['days' => $days]) }}" class="{{ $country === 'ALL' ? 'on' : '' }}">All countries</a>
    @foreach(array_slice($countries, 0, 12, true) as $code => $c)
      <a href="{{ route('admin.brain.live', ['days' => $days, 'country' => $code]) }}" class="{{ $country === $code ? 'on' : '' }}">{{ $c['name'] }} <span style="opacity:.6;font-size:.85em;">{{ $c['n'] }}</span></a>
    @endforeach
    {{-- Every country with a story in the window, for the ones that are not
         among the twelve busiest. Choosing one goes there at once. --}}
    <label class="visually-hidden" for="live-country">Country</label>
    <select id="live-country" style="height:34px;border:1px solid #d4e2ef;border-radius:40px;padding:0 30px 0 14px;font:inherit;font-size:0.85rem;color:#1c5a7f;background:#fff;"
            onchange="if (this.value) { location.href = '{{ route('admin.brain.live', ['days' => $days]) }}' + (this.value === 'ALL' ? '' : '&country=' + this.value); }">
      <option value="">Any country&hellip; ({{ count($countries) }})</option>
      <option value="ALL" {{ $country === 'ALL' ? 'selected' : '' }}>All countries</option>
      @foreach(collect($countries)->sortBy('name') as $code => $c)
        <option value="{{ $code }}" {{ $country === $code ? 'selected' : '' }}>{{ $c['name'] }} ({{ $c['n'] }})</option>
      @endforeach
    </select>
  </div>

  @php
    // One place that decides how a cell is drawn, so a zero never pretends to
    // be a link to an empty page.
    $cell = function ($value, $date, $bucket, $classes = '') use ($country) {
        $value = (int) $value;
        $href  = route('admin.brain.live.day', array_filter(['date' => $date, 'bucket' => $bucket, 'country' => ($country ?? 'ALL') === 'ALL' ? null : $country]));

        return $value === 0
            ? '<td class="' . $classes . ' zero">0</td>'
            : '<td class="' . $classes . '"><a href="' . $href . '">' . $value . '</a></td>';
    };
  @endphp

  <div class="card">
    <div class="table-scroll">
      <table class="daytable">
        <thead>
          <tr>
            <th class="day">Date</th>
            <th>Ingested</th>
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
          </tr>
        </thead>
        <tbody>
          @forelse($rows as $row)
            @php $d = \Carbon\Carbon::parse($row->day); @endphp
            <tr>
              <td class="day">
                <a href="{{ route('admin.brain.live.day', array_filter(['date' => $row->day, 'country' => $country === 'ALL' ? null : $country])) }}">
                  {{ $d->format('j M Y') }}
                </a>
                <span class="dow">{{ $d->format('l') }}</span>
              </td>
              {!! $cell($row->ingested,    $row->day, 'ingested') !!}
              {!! $cell($row->live,        $row->day, 'live', 'lead') !!}
              {!! $cell($row->one_place,   $row->day, 'one_place', 'sep') !!}
              {!! $cell($row->many_places, $row->day, 'many') !!}
              {!! $cell($row->national,    $row->day, 'national') !!}
              {!! $cell($row->no_place,    $row->day, 'no_place', 'held') !!}
              {!! $cell($row->duplicate,   $row->day, 'duplicate', 'sep') !!}
              {!! $cell($row->discarded,   $row->day, 'discarded') !!}
              {!! $cell($row->waiting,     $row->day, 'waiting') !!}
              {!! $cell($row->held,        $row->day, 'held', 'held') !!}
              <td class="sep money">
                @if((float) $row->cost > 0)
                  ${{ number_format((float) $row->cost, 3) }}@if(!empty($row->estimated))<span title="Before the call log began on 4 Sep 2026. Story classification only, and priced without the cache discount, so it reads high.">*</span>@endif
                  <span class="calls">{{ $row->calls }} calls</span>
                @else
                  <span class="zero">&mdash;</span>
                @endif
              </td>
            </tr>
          @empty
            <tr><td colspan="12" style="text-align:center;color:#b6c3ce;padding:26px;">
              Nothing published in this window.
            </td></tr>
          @endforelse
        </tbody>
        @if(count($rows))
          <tfoot>
            <tr>
              <td class="day">{{ count($rows) }} days</td>
              <td>{{ $total['ingested'] }}</td>
              <td class="lead">{{ $total['live'] }}</td>
              <td class="sep">{{ $total['one_place'] }}</td>
              <td>{{ $total['many_places'] }}</td>
              <td>{{ $total['national'] }}</td>
              <td class="held {{ $total['no_place'] ? 'nonzero' : '' }}">{{ $total['no_place'] }}</td>
              <td class="sep">{{ $total['duplicate'] }}</td>
              <td>{{ $total['discarded'] }}</td>
              <td>{{ $total['waiting'] }}</td>
              <td class="held {{ $total['held'] ? 'nonzero' : '' }}">{{ $total['held'] }}</td>
              <td class="sep money">${{ number_format((float) $total['cost'], 3) }}</td>
            </tr>
          </tfoot>
        @endif
      </table>
    </div>

    <p class="legend">
      <b>Ingested</b> everything that arrived that day, before anything was decided about it.
      <b>Live</b> on the site now &mdash; the four columns after it split those same stories by how
      well they are placed: <b>1 location</b> one pin, <b>Multiple</b> more than one (a story about
      two towns), <b>National</b> deliberately nowhere &mdash; a nationwide offer or a policy change,
      which is correct and needs no fixing.
      <b>No pin</b> is the one to watch: the story named a place and never got coordinates for it,
      so it is on the site but can never be found by distance.
      <br>
      <b>Duplicate</b> the same story already held from another source. <b>Discarded</b> refused by
      the playbook. <b>Waiting</b> still queued for classification &mdash; normal for an hour, a
      problem for a day. <b>Held</b> is what is left over: arrived, not published, and no reason on
      file. It should be zero.
      <br>
      <b>Cost</b> is what was actually spent that Malaysian day, across <em>every</em> AI task
      &mdash; classification, translation, duplicate checks, place names, moderation &mdash; from
      the call log. It is the same number the AI models page shows for the same day, on purpose.
      <br>
      It used to be something narrower: the cost of classifying that day's <em>stories</em>. That
      counted only calls tied to a story, so on 5 Sep it showed 56 calls against 7,107 actually
      made, and it attributed the work to the day a story was <em>published</em> rather than the day
      the money went &mdash; an overnight fetch mostly carries yesterday's news, so the two never
      reconciled with the bill. Changed 5 Sep 2026.
      <br>
      A figure marked <b>*</b> is from before the call log existed (4 Sep 2026). Those days count
      story classification only and are priced without the cache discount, so they read high.
    </p>
  </div>

  <div class="card">
    <h2 style="margin-top:0;">Is the estimate right?</h2>
    <p class="sub">
      The Cost column is arithmetic, not a receipt. This is the receipt: what DeepSeek's balance
      actually fell by, against what the tokens said it should. Both sides are counted by the day
      the <em>call ran</em>, which is when money leaves &mdash; not by the day the story was
      published, which is what the table above is grouped on. The two differ whenever a backlog is
      worked through late.
    </p>

    <div class="table-scroll">
      <table class="daytable">
        <thead>
          <tr>
            <th class="day">Day the calls ran</th>
            <th>Calls</th>
            <th>Estimated</th>
            <th>Actually taken</th>
            <th>Difference</th>
            <th class="text">&nbsp;</th>
          </tr>
        </thead>
        <tbody>
          @forelse($recon as $r)
            <tr>
              <td class="day">{{ \Carbon\Carbon::parse($r['day'])->format('j M Y') }}</td>
              <td>{{ number_format($r['calls']) }}</td>
              <td class="money">${{ number_format($r['estimated'], 4) }}</td>
              <td class="money">
                @if($r['actual'] !== null)
                  ${{ number_format($r['actual'], 4) }}
                @else
                  <span class="zero">&mdash;</span>
                @endif
              </td>
              <td class="money">
                @if($r['actual'] !== null && !$r['partial'])
                  @php $gap = $r['estimated'] - $r['actual']; @endphp
                  <span class="{{ abs($gap) > max(0.05, $r['actual'] * 0.15) ? 'held nonzero' : '' }}">
                    {{ $gap >= 0 ? '+' : '' }}{{ number_format($gap, 4) }}
                  </span>
                @else
                  <span class="zero">&mdash;</span>
                @endif
              </td>
              <td class="text storymeta">
                @if($r['actual'] === null)
                  no earlier reading to measure from
                @elseif($r['partial'])
                  part-day only &mdash; last read
                  {{ \Carbon\Carbon::parse($r['last_at'])->timezone('Asia/Kuala_Lumpur')->format('H:i') }},
                  so calls after that are not in it yet
                @else
                  full day
                @endif
              </td>
            </tr>
          @empty
            <tr><td colspan="6" style="text-align:center;color:#b6c3ce;padding:26px;">
              No balance readings yet.
            </td></tr>
          @endforelse
        </tbody>
      </table>
    </div>

    <p class="legend">
      A day can only be measured when a reading bounds it at each end, and a reading taken at
      midday has only counted half of it &mdash; those days say so rather than showing a
      difference that is really just the clock. The balance is also the <em>whole account</em>, so
      anything else billed to DeepSeek appears in "actually taken" and not in the estimate.
      The reading is now taken at midnight Malaysian time; before that it ran at 08:15 and
      straddled two days, which is why the earliest rows here cannot be reconciled.
    </p>
  </div>
</div>
@endsection
