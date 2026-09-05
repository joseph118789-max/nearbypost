@extends('layouts.public')
@php $wide = true; @endphp
@section('main')
  <section class="profile-page">
    <h1>{{ __('site.moderation') }}</h1>
    <p class="mini">{{ __('site.moderation_note') }}</p>
    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif
    @forelse($rows as $r)
      <div class="row-item">
        <a href="{{ route('post.show', ['id' => $r->id]) }}"><b>{{ $r->title }}</b></a>
        <span class="mini">{{ $r->location_label }} · {{ __('site.trust_' . $r->trust_status) }} · {{ $r->report_count }} {{ __('site.reports_lower') }} · saw {{ number_format($r->saw_weight, 1) }} / wrong {{ number_format($r->wrong_weight, 1) }}</span>
        <form method="post" action="{{ route('community.moderate.act', ['id' => $r->id]) }}" class="grid-row">
          @csrf
          <select class="modal-input" name="action">@foreach($actionList as $a)<option value="{{ $a }}">{{ $a }}</option>@endforeach</select>
          <select class="modal-input" name="reason_code">@foreach($reasonCodes as $c)<option value="{{ $c }}">{{ $c }}</option>@endforeach</select>
          <input class="modal-input" name="value" placeholder="{{ __('site.value_optional') }}" maxlength="2000">
          <input class="modal-input" name="reason" placeholder="{{ __('site.reason_required') }}" required minlength="5" maxlength="400">
          <button class="desktop-action-btn" type="submit">{{ __('site.apply') }}</button>
        </form>
      </div>
    @empty
      <p class="mini">{{ __('site.nothing_to_moderate') }}</p>
    @endforelse
    <h2>{{ __('site.your_actions') }}</h2>
    @foreach($actions as $a)<p class="mini">{{ \Carbon\Carbon::parse($a->created_at)->diffForHumans() }} · #{{ $a->news_item_id }} · {{ $a->action }} ({{ $a->reason_code }}) — {{ $a->reason }} @if($a->reversed)· <b>{{ __('site.reversed') }}</b>@endif</p>@endforeach
  </section>
  <style>.profile-page { max-width:860px; margin:0 auto; } .row-item { display:grid; gap:6px; padding:10px 0; border-bottom:1px solid #e6e9ee; } .grid-row { display:grid; grid-template-columns: 1fr 1fr 1fr 2fr auto; gap:6px; } @media (max-width:800px) { .grid-row { grid-template-columns: 1fr; } } .mini { color:#5b6473; font-size:.86em; }</style>
@endsection
