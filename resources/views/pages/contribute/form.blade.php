@extends('layouts.public')
@php $wide = true; @endphp

@php
  $editing = (bool) $post->id;
  $action  = $editing
      ? route('contribute.update', ['id' => $post->id])
      : route('contribute.store');
@endphp

@section('main')
  <div class="page-head">
    <h1>{{ $editing ? __('site.edit_post') : __('site.write_a_post') }}</h1>
    <p class="page-intro">{{ __('site.write_intro') }}</p>
  </div>

  @if($editing && $post->review_status === 'rejected' && $post->review_reason)
    {{-- The reason the last version was turned down, kept in front of the
         writer while they fix it rather than left behind on the list page. --}}
    <div class="notice notice--warn">
      <strong>{{ __('site.state_rejected') }}:</strong> {{ $post->review_reason }}
    </div>
  @endif

  @include('partials.post-form')
@endsection

@section('after')
  @include('partials.post-form-map')
@endsection
