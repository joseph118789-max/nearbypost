@extends('layouts.public')

@section('main')
  <div class="page-head">
    <h1>{{ __('site.login') }}</h1>
    <p class="page-intro">{{ __('site.login_intro') }}</p>
  </div>

  {{-- Two doors, named by what is behind them rather than by role. A reader
       who wants to send in a story should not have to work out whether that
       makes them a "user" or an "editor". --}}
  <div class="door-grid">
    <a class="door" href="{{ route('contributor.login') }}">
      <h2>{{ __('site.door_contributor') }}</h2>
      <p>{{ __('site.door_contributor_hint') }}</p>
      <span class="door-go">{{ __('site.door_contributor_action') }} &rarr;</span>
    </a>

    <a class="door" href="{{ route('admin.login') }}">
      <h2>{{ __('site.door_admin') }}</h2>
      <p>{{ __('site.door_admin_hint') }}</p>
      <span class="door-go">{{ __('site.door_admin_action') }} &rarr;</span>
    </a>
  </div>
@endsection
