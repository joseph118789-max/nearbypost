@extends('layouts.admin')

@section('title', $root->name)

@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

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

  <div class="srccard">
    <h3 style="font-size:0.95rem;color:#1c5a7f;margin-bottom:4px;">Add a section feed</h3>
    <p class="hint" style="margin-bottom:12px;">
      A publisher's sports or business feed, or a section page to crawl. Added switched off so you
      can test it first.
    </p>

    <form method="post" action="{{ route('admin.sources.sections.add', ['id' => $root->id]) }}">
      @csrf
      <div class="srcrow">
        <div>
          <label class="lbl" for="sec">Section</label>
          <input class="inp" id="sec" type="text" name="section" required maxlength="40"
                 placeholder="sports">
        </div>
        <div style="flex:2 1 320px;">
          <label class="lbl" for="securl">Address</label>
          <input class="inp" id="securl" type="url" name="url" required maxlength="900"
                 placeholder="https://example.com/sports/feed">
        </div>
        <div>
          <label class="lbl" for="seckind">Kind</label>
          <select class="inp" id="seckind" name="kind">
            <option value="rss">A feed (RSS or Atom)</option>
            <option value="index">A section page to crawl</option>
          </select>
        </div>
        <div class="onoff"><button class="btn btn-primary" type="submit">Add</button></div>
      </div>
    </form>
  </div>

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
