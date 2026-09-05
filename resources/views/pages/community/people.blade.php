@extends('layouts.public')
@php $wide = true; @endphp

@section('main')
  <section class="profile-page">
    <h1>{{ __('site.people') }}</h1>
    <form method="get" action="{{ route('community.people') }}" class="people-search">
      <input class="modal-input" type="search" name="q" value="{{ $q }}" placeholder="@username" maxlength="60">
      <button class="desktop-action-btn primary" type="submit">{{ __('site.search') }}</button>
    </form>
    @if($q !== '' && $people->isEmpty())<p class="field-hint">{{ __('site.nobody_found') }}</p>@endif
    <ul class="stories">
      @foreach($people as $u)
        <li class="story"><a class="headline" href="{{ url('/@' . $u->username) }}">{{ '@' . $u->username }}</a>
          <p class="meta"><span>{{ $u->display_name ?: $u->name }}</span><span>{{ \App\Services\Community\CommunityTrust::levelName((int) $u->credibility) }}</span></p></li>
      @endforeach
    </ul>
  </section>
  <style>.profile-page { max-width:760px; margin:0 auto; } .people-search { display:flex; gap:8px; margin:10px 0 18px; }</style>
@endsection
