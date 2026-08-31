@extends('layouts.admin')
@section('title', 'Write a case study')
@push('styles')
@include('admin.sources._styles')
@endpush

@section('content')
<div class="srcpage">
  <a class="back" href="{{ route('admin.cases.index') }}">&larr; Case studies</a>
  <h1>Write a case study</h1>
  <p class="lede">
    Write the story once. When you save, the places it applies to are worked out for you &mdash;
    and you will be asked to check that list before anything is published, because it will contain
    mistakes.
  </p>

  @if($errors->any())<div class="warn">{{ $errors->first() }}</div>@endif

  <form class="srccard" method="post" action="{{ route('admin.cases.store') }}">
    @csrf

    <label class="lbl" for="title">Headline</label>
    <p class="hint">What is happening, in one line. Name the organisation &mdash; that is what the places are found from.</p>
    <input class="inp" id="title" type="text" name="title" required minlength="8" maxlength="200"
           value="{{ old('title') }}" placeholder="Harvey Norman cuts 50% off across all Malaysian stores"
           style="border-radius:14px;">

    <label class="lbl" for="body">The story</label>
    <p class="hint">The detail: what, when, and until when. Anything you write here is what readers see.</p>
    <textarea class="ta" id="body" name="body" rows="8" required minlength="20" maxlength="5000">{{ old('body') }}</textarea>

    <div style="margin-top:18px;">
      <button class="btn btn-primary" type="submit">Save and find the places</button>
    </div>
    <p class="hint" style="margin-top:8px;">Finding the places takes a few seconds. Nothing is published yet.</p>
  </form>
</div>
@endsection
