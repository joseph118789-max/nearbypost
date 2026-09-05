@extends('layouts.admin')
@section('title', 'Marketplace state')
@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
<style>
  .msbanner { border: 1px solid #e2edf6; border-left: 4px solid #7c95ab; background: #f7fafc;
              border-radius: 8px; padding: 14px 18px; margin: 14px 0 24px; }
  .msbanner b { display: block; font-size: 15px; margin-bottom: 3px; }
  .msbanner p { margin: 0; font-size: 13.5px; color: #52657a; }

  .msphase { border: 1px solid #e2edf6; border-radius: 10px; background: #fff;
             padding: 18px 20px; margin-bottom: 14px; }
  .msphase.blocked { border-color: #f0dcc8; }

  .mshead { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; margin-bottom: 6px; }
  .msnum { font-size: 12px; font-weight: 700; color: #8296a8; letter-spacing: .06em;
           text-transform: uppercase; font-variant-numeric: tabular-nums; }
  .mshead h2 { margin: 0; font-size: 17px; flex: 1 1 auto; }

  .msstate { font-size: 11.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
             padding: 3px 9px; border-radius: 20px; white-space: nowrap; }
  .msstate.on      { background: #f0f8f2; color: #2f6b3a; border: 1px solid #bcd9c4; }
  .msstate.off     { background: #eef2f5; color: #5a6d7c; border: 1px solid #dbe6ef; }
  .msstate.blocked { background: #fdf3ec; color: #a8541f; border: 1px solid #f0cfc0; }

  .msreader { font-size: 13.5px; color: #52657a; margin: 0 0 12px; max-width: 66ch; }

  .msnums { display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 10px; }
  .msnum2 { border: 1px solid #eef3f7; border-radius: 6px; padding: 6px 11px; background: #fbfdfe; }
  .msnum2 b { font-size: 17px; line-height: 1.1; display: block; font-variant-numeric: tabular-nums; }
  .msnum2 span { font-size: 11.5px; color: #7b8fa1; }

  .msblock { border-left: 3px solid #d99a6c; background: #fdf8f4; padding: 10px 14px;
             border-radius: 0 6px 6px 0; font-size: 13.5px; color: #7a4a22; max-width: 72ch; }
  .msblock b { color: #a8541f; }

  .msdone { font-size: 13px; color: #2f6b3a; }
</style>
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

  <h1>Marketplace state</h1>
  <p class="lede">Six phases are built and tested. <strong>{{ $live }}</strong> of the capability switches are
  on, so almost none of it is visible to anyone yet — which is deliberate. This page says what exists,
  what is switched off, and which decisions are yours.</p>

  <div class="msbanner">
    <b>Nothing below is live on nearbypost.com</b>
    <p>Every capability is behind its own switch, per country, and each one defaults to off. Turning one on
    is a deliberate act — a country never inherits a sensitive capability by accident. Showing
    <strong>{{ $country }}</strong>.</p>
  </div>

  @foreach ($phases as $p)
    <div class="msphase {{ $p['blocker'] ? 'blocked' : '' }}">
      <div class="mshead">
        <span class="msnum">Phase {{ $p['n'] }}</span>
        <h2>{!! $p['name'] !!}</h2>
        @if ($p['on'])
          <span class="msstate on">On in {{ $country }}</span>
        @elseif ($p['blocker'])
          <span class="msstate blocked">Built &middot; waiting on you</span>
        @else
          <span class="msstate off">Built &middot; switched off</span>
        @endif
      </div>

      <p class="msreader">{{ $p['reader'] }}</p>

      @if (array_filter($p['counts'], fn ($v) => $v !== null))
        <div class="msnums">
          @foreach ($p['counts'] as $label => $value)
            @if ($value !== null)
              <div class="msnum2"><b>{{ is_int($value) ? number_format($value) : $value }}</b><span>{{ $label }}</span></div>
            @endif
          @endforeach
        </div>
      @endif

      @if ($p['blocker'])
        <div class="msblock"><b>Waiting on a decision.</b> {{ $p['blocker'] }}</div>
      @else
        <p class="msdone">Nothing outstanding — this can be switched on for {{ $country }} whenever you want it.</p>
      @endif
    </div>
  @endforeach

  <div class="msbanner" style="margin-top:24px">
    <b>How to check it still works</b>
    <p>On the server, <code>php artisan marketplace:check</code> runs 254 checks against the real database
    inside a transaction that always rolls back — safe to run at any time, on production. It is the
    fastest way to see what the Marketplace guarantees, and it should be run after any change here.</p>
  </div>
</div>
@endsection
