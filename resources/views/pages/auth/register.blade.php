@extends('layouts.public')
@php $wide = true; @endphp

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

      <label class="field-label" for="username">{{ __('site.username') }}</label>
      <div class="username-row"><span class="at">@</span>
        <input class="modal-input" id="username" type="text" name="username" value="{{ old('username') }}"
               minlength="3" maxlength="30" pattern="[A-Za-z0-9][A-Za-z0-9_.]{2,29}" autocomplete="username" spellcheck="false"
               placeholder="{{ __('site.username_placeholder') }}"></div>
      <p class="field-hint" id="usernameHint">{{ __('site.username_hint') }}</p>

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

    <style>
      .username-row { display:flex; align-items:center; gap:6px; } .username-row .at { color:#5b6473; font-weight:600; }
      .username-row input { flex:1; } #usernameHint.ok { color:#1d7a4a; } #usernameHint.bad { color:#b3261e; }
      #usernameHint button { background:none; border:1px solid #cfe0ec; border-radius:999px; padding:2px 9px; margin:0 4px 4px 0; color:#1c5a7f; cursor:pointer; font:inherit; font-size:.9em; }
    </style>
    <script>
    (function () {
      var input = document.getElementById('username'), hint = document.getElementById('usernameHint'), nameEl = document.getElementById('name');
      var idle = '{{ __('site.username_hint') }}', timer = null;
      if (!input) return;
      function show(d) {
        if (!d) { hint.textContent = idle; hint.className = 'field-hint'; return; }
        if (d.ok) { hint.textContent = '@' + d.normalised + ' {{ __('site.username_available') }}'; hint.className = 'field-hint ok'; return; }
        hint.className = 'field-hint bad'; hint.textContent = (d.reason === 'taken' ? '@' + d.normalised + ' {{ __('site.username_is_taken') }} ' : '{{ __('site.username_invalid') }} ');
        (d.alternatives || []).forEach(function (a) { var b = document.createElement('button'); b.type = 'button'; b.textContent = '@' + a; b.onclick = function () { input.value = a; check(); }; hint.appendChild(b); });
      }
      function check() {
        var v = input.value.trim(); clearTimeout(timer);
        if (v.length < 3) { show(null); return; }
        timer = setTimeout(function () {
          fetch('{{ route('contributor.username.check') }}?u=' + encodeURIComponent(v) + '&name=' + encodeURIComponent(nameEl ? nameEl.value : ''), { headers: { 'Accept': 'application/json' } })
            .then(function (r) { return r.json(); }).then(show).catch(function () { show(null); });
        }, 300);
      }
      input.addEventListener('input', check);
      if (input.value) check();
    })();
    </script>

    <p class="form-foot">
      {{ __('site.have_account') }}
      <a href="{{ route('contributor.login') }}">{{ __('site.sign_in') }}</a>
    </p>
  </div>
@endsection
