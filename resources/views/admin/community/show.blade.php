@extends('layouts.admin')
@section('title', 'Community report')
@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
<style>
  .two { display:grid; grid-template-columns:1fr 1fr; gap:14px; } @media (max-width:900px) { .two { grid-template-columns:1fr; } }
  .act { display:flex; gap:8px; flex-wrap:wrap; align-items:flex-end; } .act input[type=text] { flex:1 1 260px; padding:6px 10px; border:1px solid #cfe0ec; border-radius:8px; }
  pre.body { white-space:pre-wrap; font:inherit; background:#f8fafc; padding:10px; border-radius:8px; }
</style>
@endpush
@section('content')
<div class="srcpage">
  @include('admin.brain._nav')
  <p><a href="{{ route('admin.community.index') }}">&larr; Community reports</a></p>
  <h1>{{ $item->title }}</h1>
  <p class="lede">
    <b>Trust:</b> {{ $meta->trust_status }} · <b>Moderation:</b> {{ $meta->moderation_status }} · <b>Story:</b> {{ $item->status }} / {{ $item->review_status }}
    · <b>Breaking:</b> {{ $meta->breaking ? 'yes' : 'no' }} · <b>SEO:</b> {{ $meta->seo_eligibility }}
    · <a href="{{ route('post.show', ['id' => $item->id]) }}" target="_blank">public page</a>
  </p>
  @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="warn">{{ $errors->first() }}</div>@endif

  <div class="card">
    <h2>Act, with a reason</h2>
    @foreach(['publish' => 'Publish', 'hold' => 'Hold', 'confirm' => 'Mark confirmed', 'dispute' => 'Mark disputed', 'remove' => 'Remove', 'restore' => 'Restore', 'rereview' => 'Ask the editor again', 'reject_reports' => 'Reject open reports'] as $a => $label)
      <form method="post" action="{{ route('admin.community.act', ['id' => $item->id, 'action' => $a]) }}" class="act" style="margin-bottom:6px;">
        @csrf
        <input type="text" name="reason" placeholder="Why - the author reads this" required minlength="3" maxlength="400">
        <button class="btn-sm {{ in_array($a, ['remove', 'hold', 'dispute'], true) ? 'warn' : 'go' }}" type="submit">{{ $label }}</button>
      </form>
    @endforeach
  </div>

  <div class="two">
    <div class="card">
      <h2>As submitted (immutable)</h2>
      <p><b>{{ $meta->original_title }}</b></p>
      <pre class="body">{{ $meta->original_body }}</pre>
    </div>
    <div class="card">
      <h2>As published</h2>
      <p><b>{{ $item->title }}</b></p>
      <pre class="body">{{ $item->body }}</pre>
    </div>
  </div>

  <div class="two">
    <div class="card">
      <h2>Location (private detail, staff only)</h2>
      <table class="tidy"><tbody>
        <tr><td>Public pin</td><td>{{ $item->latitude }}, {{ $item->longitude }} — {{ $item->location_label ?: $item->main_place_text }}</td></tr>
        <tr><td>Phone GPS</td><td>{{ $meta->gps_lat_private !== null ? $meta->gps_lat_private . ', ' . $meta->gps_lng_private . ' (±' . $meta->gps_accuracy_m . ' m)' : '—' }}</td></tr>
        <tr><td>Pin moved</td><td>{{ $meta->pin_was_adjusted ? 'yes, ' . $meta->pin_adjustment_m . ' m from the GPS point' : 'no' }}</td></tr>
        <tr><td>How located</td><td>{{ $meta->location_source ?: '—' }}</td></tr>
        <tr><td>Location confidence</td><td>{{ $meta->location_confidence ?? '—' }}</td></tr>
      </tbody></table>
      @if($author)
        <h2>Author</h2>
        <p>{{ '@' . $author->username }} · {{ $author->name }} · credibility {{ $author->credibility }} · points {{ $author->points }} · joined {{ \Carbon\Carbon::parse($author->created_at)->format('j M Y') }}</p>
      @endif
    </div>
    <div class="card">
      <h2>Readers</h2>
      <table class="tidy"><thead><tr><th>Reaction</th><th>Count</th><th>Weight</th></tr></thead><tbody>
        @foreach($reactions as $r)<tr><td>{{ $r->type }}</td><td>{{ $r->n }}</td><td>{{ number_format($r->w, 1) }}</td></tr>@endforeach
      </tbody></table>
      <h2>Reports</h2>
      @forelse($reports as $r)
        <p><b>{{ $r->reason }}</b> ({{ $r->status }}, weight {{ $r->weight }}) {{ $r->explanation }} @if($r->evidence_url)<a href="{{ $r->evidence_url }}" rel="nofollow noopener" target="_blank">evidence</a>@endif <span class="mini">{{ \Carbon\Carbon::parse($r->created_at)->diffForHumans() }}</span></p>
      @empty<p class="mini">None.</p>@endforelse
    </div>
  </div>

  <div class="card">
    <h2>The editor's decisions</h2>
    <table class="tidy"><thead><tr><th>When</th><th>Trigger</th><th>Decision</th><th>Scores</th><th>Added claims</th><th>Flags</th><th>Said to the author</th></tr></thead><tbody>
      @foreach($checks as $c)
        <tr><td class="mini">{{ \Carbon\Carbon::parse($c->created_at)->diffForHumans() }}<br>{{ $c->latency_ms }} ms</td><td>{{ $c->trigger_type }}</td><td><b>{{ $c->decision }}</b></td>
          <td class="mini">{{ $c->scores_json }}</td><td class="mini">{{ $c->added_claims_json }}</td><td class="mini">{{ $c->flags_json }}</td><td>{{ $c->public_reason }}</td></tr>
      @endforeach
    </tbody></table>
  </div>

  <div class="two">
    <div class="card">
      <h2>Versions</h2>
      @foreach($versions as $v)<p><b>{{ $v->actor_type }}</b> <span class="mini">{{ \Carbon\Carbon::parse($v->created_at)->diffForHumans() }} — {{ $v->reason }}</span><br>{{ $v->title }}</p>@endforeach
    </div>
    <div class="card">
      <h2>Status history</h2>
      @foreach($history as $h)<p><span class="mini">{{ \Carbon\Carbon::parse($h->created_at)->diffForHumans() }}</span> {{ $h->field }}: {{ $h->from ?: '—' }} → <b>{{ $h->to }}</b> by {{ $h->actor_type }} — {{ $h->reason }}</p>@endforeach
      <h2>Author's ledger</h2>
      @foreach($ledger as $l)<p><span class="mini">{{ \Carbon\Carbon::parse($l->created_at)->diffForHumans() }}</span> {{ $l->event }}: points {{ $l->points_delta >= 0 ? '+' : '' }}{{ $l->points_delta }}, credibility {{ $l->credibility_delta >= 0 ? '+' : '' }}{{ $l->credibility_delta }} — {{ $l->note }}</p>@endforeach
    </div>
  </div>
</div>
@endsection
