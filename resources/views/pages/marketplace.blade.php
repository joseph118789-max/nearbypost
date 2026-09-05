@extends('layouts.public')

@section('main')
  <div class="page-head">
    <h1>Marketplace</h1>
    <p class="page-intro">People and businesses near you. Nothing here is open yet — the cards below
      show what each part will look like.</p>
  </div>

  <div class="mp-sections">
    @foreach ($sections as $s)
      <a class="mp-section" href="{{ \App\Support\Loc::route('marketplace.section', ['section' => $s['code']]) }}">
        <div class="mp-section-head">
          <h2>{{ $s['name'] }}</h2>
          @if ($s['open'])
            <span class="mp-pill on">{{ $s['live'] }} nearby</span>
          @else
            <span class="mp-pill">Opening soon</span>
          @endif
        </div>

        <p class="mp-section-blurb">{{ $s['blurb'] }}</p>

        @if ($s['children'])
          <p class="mp-kids">{{ implode(' · ', array_slice($s['children'], 0, 4)) }}@if (count($s['children']) > 4) · +{{ count($s['children']) - 4 }} more @endif</p>
        @endif

        <span class="mp-go">Look inside &rarr;</span>
      </a>
    @endforeach
  </div>
@endsection

@section('aside')
  <div class="info-card">
    <h3>Meanwhile</h3>
    <div class="info-row"><span>Your location</span><span>{{ $place }}</span></div>
    <p class="mp-aside-note">Read <a href="{{ \App\Support\Loc::route('home') }}">news near you</a> while
      the Marketplace is being built.</p>
  </div>
@endsection
