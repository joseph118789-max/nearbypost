@extends('layouts.admin')
@section('title', 'Community reports')
@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
@endpush
@section('content')
<div class="srcpage">
  @include('admin.brain._nav')
  <h1>Community reports</h1>
  <p class="lede">What readers posted, what the editor did with it, and what other readers said. Every action here needs a reason, and every reason is kept.
    <a href="{{ route('admin.community.appeals') }}">Appeals, corrections, comments and moderators &rarr;</a></p>
  @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
  <div class="filter-chips" style="margin:10px 0 16px;">
    @foreach(['attention' => 'Needs attention', 'held' => 'Held', 'disputed' => 'Disputed', 'reported' => 'Reported', 'removed' => 'Removed', 'all' => 'All'] as $k => $label)
      <a class="chip {{ $view === $k ? 'on' : '' }}" href="{{ route('admin.community.index', ['view' => $k]) }}">{{ $label }} <small>{{ $counts[$k] }}</small></a>
    @endforeach
  </div>
  <div class="card">
    <table class="tidy">
      <thead><tr><th>Report</th><th>Author</th><th>Trust</th><th>Moderation</th><th>Saw / wrong / reports</th><th>Breaking</th><th>When</th></tr></thead>
      <tbody>
        @forelse($rows as $r)
          <tr>
            <td><a href="{{ route('admin.community.show', ['id' => $r->news_item_id]) }}">{{ $r->title }}</a><div class="mini">{{ $r->location_label ?: $r->main_place_text }}</div></td>
            <td>{{ $r->username ? '@' . $r->username : '—' }}<div class="mini">cred {{ $r->credibility }}</div></td>
            <td>{{ $r->trust_status }}</td>
            <td>{{ $r->moderation_status }}<div class="mini">{{ $r->story_status }} / {{ $r->review_status }}</div></td>
            <td>{{ number_format($r->saw_weight, 1) }} / {{ number_format($r->wrong_weight, 1) }} / {{ $r->report_count }}</td>
            <td>{{ $r->breaking ? 'yes' : '' }}</td>
            <td class="mini">{{ $r->published_at ? \Carbon\Carbon::parse($r->published_at)->diffForHumans() : '—' }}</td>
          </tr>
        @empty
          <tr><td colspan="7" class="mini">Nothing here.</td></tr>
        @endforelse
      </tbody>
    </table>
  </div>
</div>
@endsection
