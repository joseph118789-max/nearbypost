@extends('layouts.admin')

@section('title', 'Daily report')

@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
<style>
  .quiet { background:#fbeee7; }
  .quiet td:first-child { border-left:3px solid #a1481f; }
  .heat td.n { font-variant-numeric:tabular-nums; text-align:right; padding-right:10px; }
  .heat td.n.zero { color:#c8d6e0; }
  .heat th.d { font-size:0.66rem; color:#9db4c5; text-align:right; padding-right:10px; font-weight:600; }
  .totalrow td { border-top:2px solid #e2edf6; font-weight:600; color:#123c55; }
</style>
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

  <h1>Daily report</h1>
  <p class="lede">
    What each source delivered, day by day. This is the number to watch: a source that stops is
    silent otherwise &mdash; the site keeps working, the feed keeps filling from everything else, and
    nobody notices a publisher has been missing for a week.
    <br>
    Counted at the moment of fetching, and only what was <strong>new</strong>: a feed re-serving the
    same fifty items every quarter hour is not delivering anything.
  </p>

  @if($quiet->isNotEmpty())
    <div class="card" style="border-color:#e6c4b4;">
      <h2>Gone quiet</h2>
      <p class="sub">
        Delivered nothing today, having averaged something over the week. Usually a feed that moved
        or started refusing us &mdash; worth opening before it becomes a fortnight.
      </p>
      <table class="tidy">
        <thead><tr><th>Source</th><th style="text-align:right;">Usual per day</th><th style="text-align:right;">Today</th><th>Last read</th></tr></thead>
        <tbody>
          @foreach($quiet as $q)
            <tr>
              <td><a class="storylink" href="{{ route('admin.sources.show', ['id' => $q->id]) }}">{{ $q->name }}</a></td>
              <td style="text-align:right;">{{ number_format($q->per_day) }}</td>
              <td style="text-align:right;"><span class="pill nowhere">0</span></td>
              <td class="mini">{{ $q->last_fetched_at ? \Carbon\Carbon::parse($q->last_fetched_at)->diffForHumans() : 'never' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif

  <div class="card">
    <h2>Stories captured, by source and day</h2>
    <p class="sub">Newest day on the left. A publisher's section feeds are counted in its own row.</p>

    @if(empty($days))
      <p class="mini">Nothing recorded yet. The tally starts from the next fetch.</p>
    @else
      <div style="overflow-x:auto;">
        <table class="tidy heat">
          <thead>
            <tr>
              <th>Source</th>
              @foreach($days as $d)
                <th class="d">{{ \Carbon\Carbon::parse($d)->format('j/n') }}</th>
              @endforeach
              <th class="d">Total</th>
            </tr>
          </thead>
          <tbody>
            @foreach($rows as $row)
              <tr class="{{ $row['quiet'] ? 'quiet' : '' }}">
                <td>
                  <a class="storylink" href="{{ route('admin.sources.show', ['id' => $row['id']]) }}">{{ $row['name'] }}</a>
                  @if(!$row['is_active'])<span class="pill nowhere">off</span>@endif
                </td>
                @foreach($days as $d)
                  @php($n = $row['by_day'][$d] ?? 0)
                  <td class="n {{ $n === 0 ? 'zero' : '' }}">{{ $n === 0 ? '·' : number_format($n) }}</td>
                @endforeach
                <td class="n"><strong>{{ number_format($row['total']) }}</strong></td>
              </tr>
            @endforeach
            <tr class="totalrow">
              <td>All sources</td>
              @foreach($days as $d)
                <td class="n">{{ number_format($totals[$d] ?? 0) }}</td>
              @endforeach
              <td class="n">{{ number_format(array_sum($totals)) }}</td>
            </tr>
          </tbody>
        </table>
      </div>
    @endif
  </div>

  <div class="card">
    <h2>Why fetching stays frequent</h2>
    <p class="sub" style="margin-bottom:0;">
      A feed is a window, not an archive. New Straits Times holds fifty items covering about
      <strong>three hours</strong> of its output, so fetching every fifteen minutes leaves roughly
      twelve fetches of safety margin &mdash; and fetching once a day would capture the last three
      hours and lose the other twenty-one.
      <br><br>
      It costs nothing to fetch often: a story already held is rejected by its URL before it reaches
      extraction or the model. The only thing a fetch spends is a request.
    </p>
  </div>
</div>
@endsection
