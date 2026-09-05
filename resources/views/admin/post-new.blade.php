@extends('layouts.admin')
@section('title', $kind === 'official' ? 'Add official news' : 'Add unofficial news')
@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
<link rel="stylesheet" href="{{ asset('css/app.css') }}">
<style>
  .adminpost .form-card { background:#fff; border:1px solid #e2edf6; border-radius:14px; padding:18px 20px; max-width:860px; }
  .adminpost .field-label { display:block; font-size:0.8rem; color:#5f7f9a; margin:12px 0 4px; font-weight:600; }
  .adminpost .field-hint { font-size:0.76rem; color:#8aa4b8; margin:4px 0 0; }
  .adminpost .modal-input { width:100%; box-sizing:border-box; padding:9px 12px; border:1px solid #cfe0ec; border-radius:10px; font:inherit; }
  .adminpost .desktop-action-btn { margin-top:16px; padding:9px 18px; border-radius:999px; border:1px solid #1c5a7f; background:#1c5a7f; color:#fff; font:inherit; font-weight:600; cursor:pointer; }
  .adminpost .notice { background:#fde8e6; color:#9b2c1f; padding:10px 14px; border-radius:10px; margin-bottom:12px; font-size:0.88rem; }
</style>
@endpush
@section('content')
<div class="srcpage adminpost">
  @include('admin.brain._nav')
  <h1>{{ $kind === 'official' ? 'Add official news' : 'Add unofficial news' }}</h1>
  <p class="lede">
    @if($kind === 'official')
      Published at once as official news, under the publisher you name. No vetting: the AI only files it under a category and writes the summary and translations.
    @else
      Published at once as a reader post by <b>@admin</b>, with reactions and comments like any community report. No vetting, no photo check: the AI only files it under a category.
    @endif
    Put the pin where it happened; the pin is the story's place.
  </p>
  @include('partials.post-form')
  @include('partials.post-form-map')
</div>
@endsection
