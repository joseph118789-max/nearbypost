@extends('layouts.admin')

@section('title', $root->name)

@push('styles')
@include('admin.sources._styles')
@endpush

@section('content')
<div class="srcpage">
  <a class="back" href="{{ route('admin.sources.index') }}">&larr; All publishers</a>

  <h1>{{ $root->name }}</h1>
  <p class="lede">
    The publisher itself, then each of its section feeds. Everything below is editable and is
    written for three readers: an editor deciding whether a source earns its place, whoever sets
    the crawl rules, and whoever &mdash; or whatever &mdash; has to repair the crawler later.
  </p>

  @if(session('status'))
    <div class="flash">{{ session('status') }}</div>
  @endif

  @if($errors->any())
    <div class="warn">{{ $errors->first() }}</div>
  @endif

  @include('admin.sources._editor', ['s' => $root, 'intervals' => $intervals, 'isRoot' => true])

  <h2>Section feeds ({{ count($sections) }})</h2>

  @if(count($sections) === 0)
    <p class="dim" style="margin-bottom:18px;">
      No section feeds yet. These are what fill the narrow sub-categories &mdash; Badminton,
      Motorsports, Property &mdash; and they are found automatically by
      <code>ingest:discover --sections</code>, or can be added by hand.
    </p>
  @endif

  @foreach($sections as $section)
    @include('admin.sources._editor', ['s' => $section, 'intervals' => $intervals, 'isRoot' => false])
  @endforeach

  <h2>What actually arrived</h2>
  <p class="lede">
    The notes above say what to expect. This is what turned up. When the two disagree, the notes
    are out of date.
  </p>

  @if(count($recent) === 0)
    <p class="dim">Nothing from this publisher yet.</p>
  @else
    <table class="srctable">
      <thead><tr><th>Story</th><th>Category</th><th>Enrichment</th><th>Published</th></tr></thead>
      <tbody>
        @foreach($recent as $story)
          <tr>
            <td>{{ $story->title }}</td>
            <td class="dim">{{ $story->primary_category ?: '—' }}</td>
            <td>
              <span class="badge {{ $story->ai_status === 'success' ? 'badge-ok' : 'badge-warn' }}">
                {{ str_replace('_', ' ', $story->ai_status ?: 'pending') }}
              </span>
            </td>
            <td class="dim">{{ $story->published_at ? \Carbon\Carbon::parse($story->published_at)->diffForHumans() : '—' }}</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif
</div>
@endsection
