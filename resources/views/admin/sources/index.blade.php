@extends('layouts.admin')

@section('title', 'Where the news comes from')

@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

  @if($canLeave)
    <a class="back" href="{{ route('admin.sources.countries') }}">&larr; All countries</a>
  @endif

  <h1>{{ $name }}</h1>
  <p class="lede">
    One row per publisher. Open one to see its section feeds &mdash; sports, business,
    property &mdash; and what we know about reading each of them: what to expect, how to get the
    text out, the technical traps, and how often to go back.
  </p>

  @if(session('status'))
    <div class="flash">{{ session('status') }}</div>
  @endif

  <table class="srctable">
    <thead>
      <tr>
        <th>Publisher</th>
        <th>Sections</th>
        <th>Today</th>
        <th>Per day</th>
        <th>All time</th>
        <th>How often</th>
        <th>Last read</th>
        <th>Documented</th>
      </tr>
    </thead>
    <tbody>
      @foreach($roots as $root)
        @php
          $kids = $children[$root->id] ?? null;
          $day = $daily[$root->id] ?? null;
          $total = (int) ($root->items_contributed ?? 0) + (int) ($kids->items ?? 0);
          $documented = trim((string) $root->expect_note) !== '';
        @endphp
        <tr class="{{ $root->is_active ? '' : 'off' }}">
          <td>
            <a class="srcname" href="{{ route('admin.sources.show', ['id' => $root->id]) }}">{{ $root->name }}</a>
            <div class="srcurl">{{ $root->rss_url ?: $root->index_url ?: $root->base_url }}</div>
            <div class="tags">
              @if(!$root->is_active) <span class="badge badge-off">off</span> @endif
              <span class="badge">{{ $root->source_kind ?: 'rss' }}</span>
              @if($root->language && $root->language !== 'Unknown')
                <span class="badge">{{ $root->language }}</span>
              @endif
              @if(($root->consecutive_failures ?? 0) > 0)
                <span class="badge badge-bad">{{ $root->consecutive_failures }} failure(s)</span>
              @endif
            </div>
          </td>
          <td class="num">{{ $kids->sections ?? 0 }}@if($kids && $kids->sections != $kids->active_sections) <span class="dim">({{ $kids->active_sections }} on)</span>@endif</td>
          {{-- Today and the weekly average answer "how much is this giving me
               now". All time is a counter that cannot go down, which is why it
               still read 277 for a publisher minutes after the archive was
               purged. --}}
          <td class="num">{{ number_format((int) ($day->today ?? 0)) }}</td>
          <td class="num dim">{{ number_format((int) ($day->per_day ?? 0)) }}</td>
          <td class="num dim">{{ number_format($total) }}</td>
          <td>@include('admin.sources._cadence', ['s' => $root])</td>
          <td class="dim">{{ $root->last_fetched_at ? \Carbon\Carbon::parse($root->last_fetched_at)->diffForHumans() : 'never' }}</td>
          <td>
            @if($documented)
              <span class="badge badge-ok">yes</span>
            @else
              <span class="badge badge-warn">not yet</span>
            @endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <h2>Add a publisher</h2>
  <p class="lede">
    Give the feed address if the publisher has one, or a section page if it does not. It is added
    switched off, so you can test it and write the notes before it starts being read.
  </p>

  <form class="srccard" method="post" action="{{ route('admin.sources.publishers.add') }}">
    @csrf
    <input type="hidden" name="country" value="{{ $country }}">

    <div class="srcrow">
      <div>
        <label class="lbl" for="pname">Publisher</label>
        <input class="inp" id="pname" type="text" name="name" required maxlength="120"
               placeholder="The Guardian">
      </div>
      <div style="flex:2 1 320px;">
        <label class="lbl" for="purl">Address</label>
        <input class="inp" id="purl" type="url" name="url" required maxlength="900"
               placeholder="https://www.theguardian.com/uk/rss">
      </div>
      <div>
        <label class="lbl" for="pkind">Kind</label>
        <select class="inp" id="pkind" name="kind">
          <option value="rss">A feed (RSS or Atom)</option>
          <option value="index">A section page to crawl</option>
        </select>
      </div>
      <div class="onoff"><button class="btn btn-primary" type="submit">Add</button></div>
    </div>
  </form>
</div>
@endsection
