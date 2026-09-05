@extends('layouts.public')
@php $wide = true; @endphp

@section('main')
  <div class="page-head">
    <h1>{{ __('site.post_market_title') }}</h1>
  </div>

  <div class="prose-card">
    <p>{{ __('site.mp_offer_body') }}</p>
    <p>{{ __('site.mp_offer_meanwhile') }} <a href="{{ route('contribute.create') }}">{{ __('site.post_news_title') }}</a>.</p>
  </div>
@endsection
