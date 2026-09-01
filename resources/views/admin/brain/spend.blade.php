@extends('layouts.admin')

@section('title', 'What the AI costs')

@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
<style>
  .bal { display:flex; align-items:baseline; gap:10px; flex-wrap:wrap; }
  .bal .amount { font-size:2.4rem; font-weight:700; color:#123c55; font-variant-numeric:tabular-nums; }
  .bal .amount.low { color:#a1481f; }
  .bal .cur { font-size:1rem; color:#7f9bb0; }

  /* Bars rather than a chart library: one number a day, read at a glance, and
     nothing to load. */
  .spendbars { display:flex; align-items:flex-end; gap:5px; height:130px; margin:16px 0 6px; }
  .spendbars .col { flex:1; display:flex; flex-direction:column; justify-content:flex-end; height:100%; }
  .spendbars .bar { background:#3d84ad; border-radius:3px 3px 0 0; min-height:2px; }
  .spendbars .col.today .bar { background:#1c5a7f; }
  .spendbars .col.none .bar { background:#e2edf6; }
  .daylabels { display:flex; gap:5px; }
  .daylabels span { flex:1; text-align:center; font-size:0.62rem; color:#9db4c5; }
</style>
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

  <h1>What the AI costs</h1>
  <p class="lede">
    What is left to spend, and what it has been going on. Classification is the only thing on this
    site that costs money per story, so this is the whole bill.
  </p>

  @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif

  <div class="grid2">
    <div class="card">
      <h2>Credit remaining</h2>
      @if($balance['error'])
        <p class="sub" style="color:#a1481f;">{{ $balance['error'] }}</p>
      @else
        <div class="bal">
          <span class="amount {{ $balance['balance'] !== null && $balance['balance'] < 10 ? 'low' : '' }}">
            {{ number_format((float) $balance['balance'], 2) }}
          </span>
          <span class="cur">{{ $balance['currency'] }}</span>
        </div>

        @if($daysLeft !== null)
          <p class="mini" style="margin-top:8px;">
            About <strong>{{ $daysLeft }} days</strong> at the last seven days' rate.
            @if($daysLeft < 14)
              <span class="pill nowhere">worth topping up</span>
            @endif
          </p>
        @else
          <p class="mini" style="margin-top:8px;">
            Not enough recent spending to estimate how long it lasts.
          </p>
        @endif

        <p class="mini" style="margin-top:8px;">Checked {{ $balance['checked_at'] ?? 'just now' }}.</p>
      @endif

      <form method="POST" action="{{ route('admin.brain.spend.refresh') }}" style="margin-top:12px;">
        @csrf
        <button class="btn-sm" type="submit">Check again now</button>
      </form>
    </div>

    <div class="card">
      <h2>How the price is kept down</h2>
      <p class="sub">
        The instructions are identical on every story, so the provider bills them at about a tenth of
        the price after the first call &mdash; but only while they sit at the very start of the
        prompt, unchanged.
      </p>
      @if($cachedPct !== null)
        <div class="stat"><span class="n">{{ $cachedPct }}%</span><span class="of">of input served from cache</span></div>
        <p class="mini" style="margin-top:8px;">
          Last {{ $cachedDays }} days. If this falls, something variable has moved into the prompt
          ahead of the instructions, and the bill will climb quietly.
        </p>
      @else
        <p class="mini">No calls yet to measure.</p>
      @endif
    </div>
  </div>

  <div class="card">
    <h2>Spent per day</h2>
    <p class="sub">
      Two figures, on purpose. <strong>Estimated</strong> is worked out from tokens and a price list,
      so it can be attributed per story &mdash; but a price list goes out of date.
      <strong>From balance</strong> is what the credit actually dropped by, which needs no
      assumptions. Where they disagree, believe the balance.
    </p>

    @php($max = collect($daily)->max('estimated') ?: 0.0001)
    <div class="spendbars">
      @foreach($daily as $d)
        <div class="col {{ $d['day'] === now()->toDateString() ? 'today' : '' }} {{ $d['estimated'] <= 0 ? 'none' : '' }}"
             title="{{ $d['day'] }}: {{ number_format($d['estimated'], 4) }} USD, {{ $d['calls'] }} calls">
          <div class="bar" style="height:{{ max(2, round(($d['estimated'] / $max) * 100)) }}%"></div>
        </div>
      @endforeach
    </div>
    <div class="daylabels">
      @foreach($daily as $d)<span>{{ \Carbon\Carbon::parse($d['day'])->format('j/n') }}</span>@endforeach
    </div>

    <table class="tidy" style="margin-top:18px;">
      <thead>
        <tr><th>Day</th><th style="text-align:right;">Stories judged</th>
            <th style="text-align:right;">Estimated</th><th style="text-align:right;">From balance</th>
            <th style="text-align:right;">Cached</th></tr>
      </thead>
      <tbody>
        @foreach(array_reverse($daily) as $d)
          <tr>
            <td>{{ \Carbon\Carbon::parse($d['day'])->format('D j M') }}</td>
            <td style="text-align:right;font-variant-numeric:tabular-nums;">{{ number_format($d['calls']) }}</td>
            <td style="text-align:right;font-variant-numeric:tabular-nums;">
              {{ $d['estimated'] > 0 ? '$' . number_format($d['estimated'], 4) : '—' }}
            </td>
            <td style="text-align:right;font-variant-numeric:tabular-nums;">
              {{ $d['from_balance'] !== null ? '$' . number_format($d['from_balance'], 4) : '—' }}
            </td>
            <td style="text-align:right;">{{ $d['cached_pct'] !== null ? $d['cached_pct'] . '%' : '—' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>

    <p class="mini" style="margin-top:12px;">
      &ldquo;From balance&rdquo; needs a reading from the day before to subtract from, so it stays
      empty until <code>ai:balance</code> has run on two consecutive days. Prices used for the
      estimate were read on {{ $ratesReadOn }} and are not checked automatically &mdash; if the two
      columns drift apart, that is the first thing to look at.
    </p>
  </div>
</div>
@endsection
