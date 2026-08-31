@extends('layouts.public')

@section('main')
  <div class="page-head">
    <h1>{{ __('site.contributor_register') }}</h1>
    <p class="page-intro">{{ __('site.contributor_register_intro') }}</p>
  </div>

  <div class="form-card">
    @include('partials.form-errors')

    <form method="post" action="{{ route('contributor.register') }}">
      @csrf

      <label class="field-label" for="name">{{ __('site.your_name') }}</label>
      <input class="modal-input" id="name" type="text" name="name"
             value="{{ old('name') }}" required maxlength="120" autocomplete="name" autofocus>
      <p class="field-hint">{{ __('site.name_is_public') }}</p>

      <label class="field-label" for="email">{{ __('site.email') }}</label>
      <input class="modal-input" id="email" type="email" name="email"
             value="{{ old('email') }}" required autocomplete="email">

      <label class="field-label" for="password">{{ __('site.password') }}</label>
      <input class="modal-input" id="password" type="password" name="password"
             required minlength="8" autocomplete="new-password">
      <p class="field-hint">{{ __('site.password_hint') }}</p>

      <label class="field-label" for="password_confirmation">{{ __('site.password_again') }}</label>
      <input class="modal-input" id="password_confirmation" type="password"
             name="password_confirmation" required autocomplete="new-password">

      <button class="desktop-action-btn primary" type="submit">{{ __('site.create_account') }}</button>
    </form>

    <p class="form-foot">
      {{ __('site.have_account') }}
      <a href="{{ route('contributor.login') }}">{{ __('site.sign_in') }}</a>
    </p>
  </div>
@endsection
