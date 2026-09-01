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

    {{-- The panel, not the login form. /admin/login is behind guest:admin, so
         sending an already-signed-in administrator there bounces them to the
         public homepage - which looks exactly like a login that does not work.
         /admin resolves for both: the dashboard when signed in, the login page
         by way of auth:admin when not. --}}
    <a class="door" href="{{ route('admin.index') }}">
      <h2>{{ __('site.door_admin') }}</h2>
      <p>{{ __('site.door_admin_hint') }}</p>
      <span class="door-go">{{ __('site.door_admin_action') }} &rarr;</span>
    </a>
  </div>
@endsection
