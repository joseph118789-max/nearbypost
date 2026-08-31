@extends('layouts.admin')

@section('title', 'Where the news comes from')

@push('styles')
@include('admin.sources._styles')
@endpush

@section('content')
<div class="srcpage">
  <h1>Where the news comes from</h1>
  <p class="lede">
    One row per publisher. Open one to see its section feeds &mdash; sports, business,
    property &mdash; and what we know about reading each of them: what to expect, how to get the
    text out, the technical traps, and how often to go back.
  </p>

  @if(session('status'))
    <div class="flash">{{ session('status') }}</div>
  @endif

  @if($orphans > 0)
    <div class="warn">{{ $orphans }} section feed(s) have no parent publisher and appear nowhere below.</div>
  @endif

  <table class="srctable">
    <thead>
      <tr>
        <th>Publisher</th>
        <th>Sections</th>
        <th>Stories</th>
        <th>How often</th>
        <th>Last read</th>
        <th>Documented</th>
      </tr>
    </thead>
    <tbody>
      @foreach($roots as $root)
        @php
          $kids = $children[$root->id] ?? null;
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
          <td class="num">{{ number_format($total) }}</td>
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
</div>
@endsection
