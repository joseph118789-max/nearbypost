@extends('layouts.public')

@section('main')
  <div class="page-head">
    <p class="mp-crumb"><a href="{{ \App\Support\Loc::route('marketplace') }}">&larr; Marketplace</a></p>
    <h1>{{ $section['name'] }}</h1>
    <p class="page-intro">{{ $section['blurb'] }}</p>
  </div>

  @if ($section['children'])
    <div class="mp-chips">
      @foreach ($section['children'] as $child)
        <span class="mp-chip">{{ $child }}</span>
      @endforeach
    </div>
  @endif

  @unless ($section['open'])
    <div class="mp-notice">
      <strong>Not open yet.</strong> The listings below are made up, so you can see how this section
      will look. Nobody here is real and none of them can be contacted.
    </div>
  @endunless

  @forelse ($section['samples'] as $item)
    <article class="mp-card">
      <div class="mp-card-top">
        <span class="mp-chip small">{{ $item['child'] }}</span>
        <span class="mp-example">{{ $label }}</span>
      </div>

      <h3 class="mp-card-title">{{ $item['title'] }}</h3>
      <p class="mp-card-provider">{!! $item['provider'] !!}</p>
      <p class="mp-card-note">{{ $item['note'] }}</p>

      <div class="mp-card-facts">
        <span class="mp-price">{{ $item['price'] }}</span>
        <span class="mp-dot">·</span>
        <span>{{ $item['distance'] }}</span>
        <span class="mp-dot">·</span>
        <span>{{ $item['area'] }}</span>
      </div>

      <div class="mp-card-foot">
        <span class="mp-badge">{{ $item['badge'] }}</span>
        <span class="mp-contact" aria-disabled="true">Contact — not available in an example</span>
      </div>
    </article>
  @empty
    <div class="empty-state">
      <p>Nothing to show yet.</p>
      <p class="empty-hint">In the meantime, read
        <a href="{{ \App\Support\Loc::route('home') }}">news near you</a>.</p>
    </div>
  @endforelse
@endsection

@section('aside')
  <div class="info-card">
    <h3>About this section</h3>
    <div class="info-row"><span>Your location</span><span>{{ $place }}</span></div>
    <div class="info-row"><span>Status</span><span>{{ $section['open'] ? 'Open' : 'Opening soon' }}</span></div>
    @if ($section['children'])
      <div class="info-row"><span>Categories</span><span>{{ count($section['children']) }}</span></div>
    @endif
  </div>
@endsection
