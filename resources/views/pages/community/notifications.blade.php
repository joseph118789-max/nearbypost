@extends('layouts.public')
@php $wide = true; @endphp
@section('main')
  <section class="profile-page">
    <h1>{{ __('site.notifications') }}</h1>
    @forelse($rows as $n)
      <div class="row-item {{ $n->read_at ? '' : 'unread' }}">
        <a href="{{ $n->url ?: '#' }}"><b>{{ $n->title }}</b></a>
        @if($n->body)<span class="mini">{{ $n->body }}</span>@endif
        <span class="mini">{{ \Carbon\Carbon::parse($n->created_at)->diffForHumans() }}</span>
      </div>
    @empty
      <p class="mini">{{ __('site.no_notifications') }}</p>
    @endforelse
    <p class="mini"><a href="{{ route('community.follows') }}">{{ __('site.following_settings') }}</a></p>
  </section>
  <style>.profile-page { max-width:760px; margin:0 auto; } .row-item { display:grid; gap:2px; padding:10px 0; border-bottom:1px solid #e6e9ee; } .mini { color:#5b6473; font-size:.86em; }</style>
@endsection
