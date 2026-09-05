@extends('layouts.admin')

@section('title', 'Taxonomy')

@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
<style>
  .taxo { display: grid; grid-template-columns: repeat(auto-fill, minmax(340px, 1fr)); gap: 14px; margin-top: 14px; }
  .taxo-card { background: #fff; border: 1px solid #e6edf4; border-radius: 16px; padding: 14px 16px; }
  .taxo-card h3 { font-size: 1rem; color: #1c5a7f; margin: 0 0 2px; display: flex; justify-content: space-between; align-items: baseline; gap: 8px; }
  .taxo-card h3 small { font-weight: 500; color: #8aa4b8; font-size: 0.78rem; white-space: nowrap; }
  .taxo-meta { font-size: 0.74rem; color: #8aa4b8; margin-bottom: 8px; }
  .taxo-subs { list-style: none; margin: 0; padding: 0; }
  .taxo-subs li { display: flex; justify-content: space-between; gap: 8px; padding: 4px 0; border-top: 1px solid #f1f5f9; font-size: 0.86rem; }
  .taxo-subs li .n { color: #1c5a7f; font-variant-numeric: tabular-nums; min-width: 2.5em; text-align: right; }
  .taxo-subs li .n.zero { color: #c3d0dc; }
  .taxo-subs li .who { font-size: 0.7rem; color: #8aa4b8; margin-left: 6px; }
  .taxo-subs li .tr { font-size: 0.74rem; color: #8aa4b8; }
  .taxo-none { color: #8aa4b8; font-size: 0.86rem; padding: 6px 0; }
  .taxo-warn { background: #fff7ed; border: 1px solid #fed7aa; border-radius: 12px; padding: 12px 16px; margin-top: 18px; font-size: 0.86rem; }
  .taxo-warn h3 { margin: 0 0 6px; font-size: 0.95rem; color: #9a3412; }
</style>
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

  <h1>Taxonomy</h1>
  <p class="pmeta">
    The categories and sub-categories the classifier is allowed to use and the site files stories under &mdash; read straight from the tables the pipeline reads.
    {{ count($categories) }} categories, {{ $totalSubs }} sub-categories. The count beside each is live stories in the last {{ $days }} days.
    A sub-category marked <em>AI</em> was added by the classifier itself, which it may do in the five light categories only.
  </p>

  <div class="taxo">
    @foreach ($categories as $c)
      <div class="taxo-card">
        <h3>
          <span>{{ $c['name'] }}</span>
          <small>{{ $c['live'] }} live &middot; {{ count($c['subs']) }} sub</small>
        </h3>
        <div class="taxo-meta">
          @if ($c['ms'] || $c['zh']){{ $c['ms'] }}@if ($c['ms'] && $c['zh']) &middot; @endif{{ $c['zh'] }} &middot; @endif
          weight {{ $c['weight'] ?? '–' }}@if (!is_null($c['gps'])) &middot; gps {{ $c['gps'] }}@endif
        </div>
        @if ($c['subs'] === [])
          <p class="taxo-none">No sub-categories.</p>
        @else
          <ul class="taxo-subs">
            @foreach ($c['subs'] as $s)
              <li>
                <span>
                  {{ $s['name'] }}
                  @if ($s['created_by'] && $s['created_by'] !== 'system')<span class="who">AI</span>@endif
                  @if ($s['ms'] || $s['zh'])<br><span class="tr">{{ $s['ms'] }}@if ($s['ms'] && $s['zh']) &middot; @endif{{ $s['zh'] }}</span>@endif
                </span>
                <span class="n {{ $s['live'] ? '' : 'zero' }}">{{ $s['live'] }}</span>
              </li>
            @endforeach
          </ul>
        @endif
      </div>
    @endforeach
  </div>

  @if ($unregistered !== [])
    <div class="taxo-warn">
      <h3>On the site under a sub-category the table does not have</h3>
      <p class="pmeta" style="margin-bottom:8px;">The classifier wrote these before they were registered, or the spelling drifted. Register or rename them with <code>taxonomy:sub rename</code>; never delete a row by hand, stories are keyed to it.</p>
      <ul class="taxo-subs">
        @foreach ($unregistered as $u)
          <li><span>{{ $u['category'] }} &rsaquo; {{ $u['sub'] }}</span><span class="n">{{ $u['live'] }}</span></li>
        @endforeach
      </ul>
    </div>
  @endif
</div>
@endsection
