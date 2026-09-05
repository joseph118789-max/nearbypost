@extends('layouts.public')
@php $wide = true; @endphp

@section('main')
  <div class="page-head">
    <h1>{{ __('site.contributor_login') }}</h1>
    <p class="page-intro">{{ __('site.contributor_login_intro') }}</p>
  </div>

  <div class="form-card">
    @include('partials.form-errors')

    <form method="post" action="{{ route('contributor.login') }}">
      @csrf

      <label class="field-label" for="email">{{ __('site.email') }}</label>
      <input class="modal-input" id="email" type="email" name="email"
             value="{{ old('email') }}" required autocomplete="email" autofocus>

      <label class="field-label" for="password">{{ __('site.password') }}</label>
      <input class="modal-input" id="password" type="password" name="password"
             required autocomplete="current-password">

      <label class="field-check">
        <input type="checkbox" name="remember" value="1" {{ old('remember') ? 'checked' : '' }}>
        {{ __('site.remember_me') }}
      </label>

      <button class="desktop-action-btn primary" type="submit">{{ __('site.sign_in') }}</button>
    </form>

    <p class="form-foot">
      {{ __('site.no_account') }}
      <a href="{{ route('contributor.register') }}">{{ __('site.create_account') }}</a>
    </p>
  </div>
@endsection
