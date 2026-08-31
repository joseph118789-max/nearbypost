@extends('layouts.public')

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

  <div class="form-card">
    @include('partials.form-errors')

    <form method="post" action="{{ $action }}" enctype="multipart/form-data">
      @csrf
      @if($editing)
        @method('PUT')
      @endif

      <fieldset class="field-set">
        <legend class="field-label">{{ __('site.where_it_goes') }}</legend>

        <div class="filter-chips">
          @foreach($sections as $section)
            <label class="chip-radio">
              <input type="radio" name="section" value="{{ $section }}"
                     {{ old('section', $post->section ?? 'nearme') === $section ? 'checked' : '' }}>
              <span>{{ __('site.section_' . $section) }}</span>
            </label>
          @endforeach
        </div>

        <p class="field-hint">{{ __('site.where_it_goes_hint') }}</p>
      </fieldset>

      <label class="field-label" for="title">{{ __('site.field_title') }}</label>
      <input class="modal-input" id="title" type="text" name="title"
             value="{{ old('title', $post->title) }}" required minlength="8" maxlength="200">
      <p class="field-hint">{{ __('site.title_hint') }}</p>

      <label class="field-label" for="body">{{ __('site.field_story') }}</label>
      <textarea class="modal-input modal-textarea" id="body" name="body" rows="10"
                required minlength="40" maxlength="5000">{{ old('body', $post->body) }}</textarea>
      <p class="field-hint">{{ __('site.body_hint') }}</p>

      <label class="field-label" for="place">{{ __('site.field_place') }}</label>
      <input class="modal-input" id="place" type="text" name="place"
             value="{{ old('place', $post->main_place_text) }}" maxlength="160"
             placeholder="e.g. Kepong, Kuala Lumpur">
      <p class="field-hint">{{ __('site.place_hint') }}</p>

      <label class="field-label" for="image">{{ __('site.field_image') }}</label>

      @if($post->image_path)
        <div class="image-current">
          <img src="{{ asset($post->image_path) }}" alt="">
          <label class="field-check">
            <input type="checkbox" name="remove_image" value="1">
            {{ __('site.remove_image') }}
          </label>
        </div>
      @endif

      <input class="modal-input" id="image" type="file" name="image"
             accept="image/jpeg,image/png,image/webp,image/gif">
      <p class="field-hint">{{ __('site.image_hint') }}</p>

      <button class="desktop-action-btn primary" type="submit">
        {{ $editing ? __('site.resubmit') : __('site.submit_for_review') }}
      </button>

      <p class="field-hint">{{ __('site.review_wait_hint') }}</p>
    </form>
  </div>
@endsection
