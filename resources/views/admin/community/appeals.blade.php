@extends('layouts.admin')
@section('title', 'Community desk')
@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
<style>.act { display:flex; gap:6px; flex-wrap:wrap; align-items:center; margin-top:6px; } .act input[type=text] { flex:1 1 220px; padding:6px 10px; border:1px solid #cfe0ec; border-radius:8px; }</style>
@endpush
@section('content')
<div class="srcpage">
  @include('admin.brain._nav')
  <p><a href="{{ route('admin.community.index') }}">&larr; Community reports</a></p>
  <h1>Appeals, corrections, comments, moderators</h1>
  @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif

  <div class="card"><h2>Appeals</h2>
    @forelse($appeals as $a)
      <div style="border-bottom:1px solid #e6edf3;padding:8px 0">
        <b>{{ $a->title }}</b> <span class="mini">by {{ '@' . $a->username }} · against: {{ $a->against }} · {{ $a->status }} · {{ \Carbon\Carbon::parse($a->created_at)->diffForHumans() }} · <a href="{{ route('admin.community.show', ['id' => $a->news_item_id]) }}">report</a></span>
        <p>{{ $a->text }}</p>
        @if($a->status === 'open')
          @foreach(['uphold' => 'Uphold (restore)', 'deny' => 'Deny'] as $d => $label)
            <form method="post" action="{{ route('admin.community.appeal.resolve', ['appeal' => $a->id, 'decision' => $d]) }}" class="act">@csrf<input type="text" name="resolution" placeholder="Why - the author reads this" required minlength="3" maxlength="400"><button class="btn-sm {{ $d === 'uphold' ? 'go' : 'warn' }}" type="submit">{{ $label }}</button></form>
          @endforeach
        @else<p class="mini">{{ $a->resolution }}</p>@endif
      </div>
    @empty<p class="mini">No appeals.</p>@endforelse
  </div>

  <div class="card"><h2>Open corrections</h2>
    @forelse($corrections as $c)
      <div style="border-bottom:1px solid #e6edf3;padding:8px 0">
        <b>{{ $c->title }}</b> <span class="mini">{{ $c->field }} · by {{ '@' . $c->username }} (weight {{ $c->proposer_weight }}) · <a href="{{ route('admin.community.show', ['id' => $c->news_item_id]) }}">report</a></span>
        <p>{{ $c->proposed_value }}</p>@if($c->explanation)<p class="mini">{{ $c->explanation }}</p>@endif @if($c->evidence_url)<a class="mini" href="{{ $c->evidence_url }}" target="_blank" rel="noopener">evidence</a>@endif
        @foreach(['accept' => 'Accept', 'reject' => 'Reject'] as $d => $label)
          <form method="post" action="{{ route('admin.community.correction.resolve', ['correction' => $c->id, 'decision' => $d]) }}" class="act">@csrf<input type="text" name="note" placeholder="Note (optional)" maxlength="400"><button class="btn-sm {{ $d === 'accept' ? 'go' : 'warn' }}" type="submit">{{ $label }}</button></form>
        @endforeach
      </div>
    @empty<p class="mini">None open.</p>@endforelse
  </div>

  <div class="card"><h2>Reported comments</h2>
    @forelse($comments as $r)
      <div style="border-bottom:1px solid #e6edf3;padding:8px 0">
        <span class="mini">{{ '@' . $r->username }} · {{ $r->reason }} · comment is {{ $r->comment_status }} · <a href="{{ route('post.show', ['id' => $r->news_item_id]) }}#c{{ $r->comment_id }}" target="_blank">see</a></span>
        <p>{{ $r->body }}</p>
        @foreach(['hide' => 'Hide', 'remove' => 'Remove', 'restore' => 'Restore'] as $d => $label)
          <form method="post" action="{{ route('admin.community.comment.moderate', ['comment' => $r->comment_id, 'decision' => $d]) }}" class="act">@csrf<input type="text" name="reason" placeholder="Reason" required minlength="3" maxlength="300"><button class="btn-sm {{ $d === 'restore' ? 'go' : 'warn' }}" type="submit">{{ $label }}</button></form>
        @endforeach
      </div>
    @empty<p class="mini">None open.</p>@endforelse
  </div>

  <div class="card"><h2>Trusted community moderators</h2>
    @foreach($moderators as $m)<p>{{ '@' . $m->username }} <span class="mini">credibility {{ $m->credibility }} · since {{ \Carbon\Carbon::parse($m->role_granted_at)->format('j M Y') }}</span>
      <form method="post" action="{{ route('admin.community.moderator', ['user' => $m->id, 'decision' => 'remove']) }}" style="display:inline">@csrf<button class="btn-sm warn" type="submit">Remove role</button></form></p>@endforeach
    <h3>Eligible (credibility 80+, trusted)</h3>
    @forelse($candidates as $c)<p>{{ '@' . $c->username }} <span class="mini">credibility {{ $c->credibility }}</span>
      <form method="post" action="{{ route('admin.community.moderator', ['user' => $c->id, 'decision' => 'assign']) }}" style="display:inline">@csrf<button class="btn-sm go" type="submit">Assign</button></form></p>@empty<p class="mini">Nobody eligible yet.</p>@endforelse
    <h3>Their actions</h3>
    @foreach($modActions as $a)<p class="mini">{{ \Carbon\Carbon::parse($a->created_at)->diffForHumans() }} · {{ '@' . $a->username }} · #{{ $a->news_item_id }} · {{ $a->action }} ({{ $a->reason_code }}) — {{ $a->reason }}
      @if(!$a->reversed)<form method="post" action="{{ route('admin.community.moderator.reverse', ['action' => $a->id]) }}" style="display:inline">@csrf<button class="btn-sm warn" type="submit">Mark reversed</button></form>@else<b>reversed</b>@endif</p>@endforeach
  </div>
</div>
@endsection
