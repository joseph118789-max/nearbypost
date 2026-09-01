@extends('layouts.admin')

@section('title', 'Resource centre')

@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
@endpush

@section('content')
<div class="srcpage">
  <h1>Resource centre</h1>
  <p class="lede">
    Everything the AI is told, and everything that says whether it is getting it right. This is what
    a different AI would have to be handed to do the same job &mdash; so anything that lives only in
    the code, or only in somebody's head, is a thing the next model will get wrong.
  </p>

  @include('admin.brain._nav')

  <div class="card">
    <h2>What the model is told</h2>
    <p class="sub">Measured from the prompt as it is assembled right now.</p>

    @php($order = ['code','playbook','rules','cases','briefing','taxonomy'])
    @php($names = [
      'code' => 'The reply shape and the role',
      'playbook' => 'Playbook — the reasoning',
      'rules' => 'House rules',
      'cases' => 'Case studies',
      'briefing' => 'Malaysia briefing',
      'taxonomy' => 'Category taxonomy',
    ])

    <div class="barline">
      @foreach($order as $k)
        @if(($sizes[$k] ?? 0) > 0)
          <span class="o-{{ $k }}" style="width:{{ round(($sizes[$k] / max($total,1)) * 100, 2) }}%"></span>
        @endif
      @endforeach
    </div>

    <div class="legend">
      @foreach($order as $k)
        @if(($sizes[$k] ?? 0) > 0)
          <div>
            <i class="o-{{ $k }}"></i>
            <span>{{ $names[$k] }}</span>
            <span class="n">{{ number_format($sizes[$k]) }}</span>
            <span class="p">{{ round(($sizes[$k] / max($total,1)) * 100) }}%</span>
          </div>
        @endif
      @endforeach
    </div>

    @php($editable = ($sizes['playbook'] ?? 0) + ($sizes['rules'] ?? 0) + ($sizes['cases'] ?? 0) + ($sizes['briefing'] ?? 0))
    <p class="mini" style="margin-top:12px;">
      <strong>{{ round(($editable / max($total,1)) * 100) }}%</strong> of it can be changed from this
      panel without a programmer. Before the resource centre existed that figure was 18%, and the most
      valuable paragraph on the site &mdash; that a dateline is not a location &mdash; was in a PHP file.
    </p>
  </div>

  <div class="grid2">
    <div class="card">
      <h2>The bench</h2>
      <p class="sub">Answers a person has confirmed, used to score any model.</p>
      <div class="stat"><span class="n">{{ number_format($benchItems) }}</span><span class="of">confirmed answers</span></div>

      @if($lastScores)
        <div class="scoregrid" style="margin-top:14px;">
          <div class="score">
            <div class="lbl">Kept or discarded</div>
            <div class="v">{{ $lastScores['kept_or_discarded'] !== null ? $lastScores['kept_or_discarded'].'%' : '—' }}</div>
          </div>
          <div class="score">
            <div class="lbl">Place</div>
            <div class="v">{{ $lastScores['place'] !== null ? $lastScores['place'].'%' : '—' }}</div>
          </div>
          <div class="score">
            <div class="lbl">Of which “nowhere”</div>
            <div class="v {{ ($lastScores['nowhere'] ?? 100) < 80 ? 'bad' : '' }}">{{ $lastScores['nowhere'] !== null ? $lastScores['nowhere'].'%' : '—' }}</div>
          </div>
        </div>
        <p class="mini" style="margin-top:10px;">
          Last run: {{ $lastRun->adapter }} ({{ $lastRun->model }}), {{ \Carbon\Carbon::parse($lastRun->created_at)->diffForHumans() }}.
        </p>
      @elseif($benchItems === 0)
        <p class="mini" style="margin-top:10px;">
          Nothing to test against yet. Confirming an answer takes one click, and most answers are
          right &mdash; the bench fills fastest by agreeing quickly and stopping only at what is wrong.
        </p>
      @else
        <p class="mini" style="margin-top:10px;">Never run. <code>php artisan bench:run</code></p>
      @endif

      <p style="margin-top:12px;"><a class="btn-sm go" href="{{ route('admin.brain.bench') }}">Open the bench</a></p>
    </div>

    <div class="card">
      <h2>Corrections</h2>
      <p class="sub">Every human fix, kept as material for the bench and the rules.</p>
      <div class="stat"><span class="n">{{ number_format($corrections) }}</span><span class="of">recorded</span></div>

      @if($tally)
        <table class="tidy" style="margin-top:12px;">
          @foreach($tally as $field => $n)
            <tr><td>{{ \App\Services\Knowledge\CorrectionLog::FIELDS[$field] ?? $field }}</td><td style="text-align:right;">{{ $n }}</td></tr>
          @endforeach
        </table>
        <p class="mini" style="margin-top:10px;">Write the next rule about whatever is at the top of that list.</p>
      @else
        <p class="mini" style="margin-top:10px;">
          Nothing yet. The removals table that came before this ran for months with no rows in it,
          because it only opened when something was deleted &mdash; and most corrections are not deletions.
        </p>
      @endif

      <p style="margin-top:12px;"><a class="btn-sm" href="{{ route('admin.brain.corrections') }}">Open the log</a></p>
    </div>

    <div class="card">
      <h2>Knowledge stores</h2>
      <p class="sub">What a model trained elsewhere would otherwise have to guess.</p>
      <table class="tidy">
        <tr><td>Playbook sections</td><td style="text-align:right;">{{ $sections }}</td></tr>
        <tr><td>Malaysia briefing terms</td><td style="text-align:right;">{{ $terms }}</td></tr>
      </table>
      <p style="margin-top:12px;">
        <a class="btn-sm" href="{{ route('admin.brain.playbook') }}">Playbook</a>
        <a class="btn-sm" href="{{ route('admin.brain.briefing') }}">Briefing</a>
      </p>
    </div>

    <div class="card">
      <h2>Models</h2>
      <p class="sub">Swapping brand is an adapter and a bench run, not a rewrite.</p>
      <table class="tidy">
        <thead><tr><th>Adapter</th><th>Model</th><th>Key</th></tr></thead>
        <tbody>
          @foreach($adapters as $a)
            <tr>
              <td>{{ $a['key'] }}</td>
              <td class="mini">{{ $a['model'] }}</td>
              <td><span class="pill {{ $a['configured'] ? '' : 'nowhere' }}">{{ $a['configured'] ? 'configured' : 'no key' }}</span></td>
            </tr>
          @endforeach
        </tbody>
      </table>
      <p class="mini" style="margin-top:10px;">
        Add one in <code>app/Services/Ai</code>, register it, then <code>bench:run --adapter=&lt;name&gt;</code>
        and compare the numbers against the run above.
      </p>
    </div>
  </div>
</div>
@endsection
