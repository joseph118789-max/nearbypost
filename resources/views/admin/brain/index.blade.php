@extends('layouts.admin')

@section('title', 'Resource centre')

@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

  <h1>Resource centre</h1>
  <p class="lede">
    Everything the AI is told, and everything that says whether it is getting it right &mdash; laid
    out in the order the work actually happens. This is what a different AI would have to be handed
    to do the same job, so anything living only in the code, or only in somebody's head, is a thing
    the next model will get wrong.
  </p>

  {{-- The pipeline, stated once at the top, because the rest of the page only
       makes sense against it. --}}
  <div class="pipeline">
    <div class="stage">
      <span class="num">1</span>
      <strong>Acquisition</strong>
      <span class="what">Where news comes from and how it is read</span>
    </div>
    <span class="arrow">&rarr;</span>
    <div class="stage">
      <span class="num">2</span>
      <strong>Processing</strong>
      <span class="what">What counts as news, where it happened, what it is about</span>
    </div>
    <span class="arrow">&rarr;</span>
    <div class="stage">
      <span class="num">3</span>
      <strong>Published</strong>
      <span class="what">What readers saw, and what we got wrong</span>
    </div>
  </div>

  {{-- ── 1 ────────────────────────────────────────────────────────────── --}}
  <h2 class="stagehead"><span class="num">1</span> News acquisition</h2>
  <p class="stagelede">
    Getting stories in, and getting the whole article rather than its headline. There is no model at
    this stage &mdash; what governs it is the source handbook: what each publisher produces, how its
    text is actually obtained, and the traps.
  </p>

  <div class="grid2">
    <div class="card">
      <h2>Sources</h2>
      <p class="sub">The handbook, and the strategy the extractor obeys.</p>
      <div class="stat"><span class="n">{{ $sourcesOn }}</span><span class="of">switched on</span></div>
      <p class="mini" style="margin-top:8px;">
        {{ $sourcesNoted }} have handbook notes written.
        @if($sourcesNoted < $sourcesOn)
          The {{ $sourcesOn - $sourcesNoted }} without them are what the next person has to work out
          from scratch.
        @endif
      </p>
      <p style="margin-top:12px;">
        <a class="btn-sm go" href="{{ route('admin.sources.countries') }}">Sources</a>
        <a class="btn-sm" href="{{ route('admin.sources.failing') }}">Failing</a>
        <a class="btn-sm" href="{{ route('admin.sources.blocked') }}">Do not visit</a>
      </p>
    </div>

    <div class="card">
      <h2>What is being read</h2>
      <p class="sub">Last seven days. A story held as a headline cannot be summarised or located.</p>
      <table class="tidy">
        <tr><td>Full article</td><td style="text-align:right;"><strong>{{ number_format($readFull) }}</strong></td></tr>
        <tr><td>Publisher's own teaser<div class="mini">paywalled or blocked &mdash; published as written, never expanded</div></td>
            <td style="text-align:right;">{{ number_format($readTeaser) }}</td></tr>
        <tr><td>Held back as unreadable<div class="mini">nothing is served from these</div></td>
            <td style="text-align:right;">{{ number_format($readHeld) }}</td></tr>
      </table>
    </div>
  </div>

  {{-- ── 2 ────────────────────────────────────────────────────────────── --}}
  <h2 class="stagehead"><span class="num">2</span> News processing</h2>
  <p class="stagelede">
    Everything the model is told, for every story, whoever wrote it.
    <strong>This is where a reader's post plugs in.</strong> A contribution arrives through a form
    rather than a feed, but from that moment it is judged by the same playbook, the same rules and
    the same case studies &mdash; nothing here needs to know which door a story came through.
  </p>

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
      <strong>{{ round(($editable / max($total,1)) * 100) }}%</strong> can be changed from this panel
      without a programmer. Before the resource centre existed that was 18%, and the most valuable
      paragraph on the site &mdash; that a dateline is not a location &mdash; was in a PHP file.
    </p>
    <p style="margin-top:10px;"><a class="btn-sm go" href="{{ route('admin.brain.prompt') }}">Read the prompt word for word</a></p>
  </div>

  <div class="grid2">
    <div class="card">
      <h2>What it is told to think</h2>
      <p class="sub">Sent with every article, official or from a reader.</p>
      <table class="tidy">
        <tr><td>Playbook sections<div class="mini">the method: what is news, where it happened</div></td>
            <td style="text-align:right;">{{ $sections }}</td></tr>
        <tr><td>House rules<div class="mini">the refusals</div></td>
            <td style="text-align:right;">{{ $rules }}</td></tr>
        <tr><td>Case studies published<div class="mini">decisions to follow by analogy</div></td>
            <td style="text-align:right;">{{ $casesLive }}</td></tr>
        <tr><td>Malaysia briefing terms<div class="mini">what a foreign model would guess at</div></td>
            <td style="text-align:right;">{{ $terms }}</td></tr>
      </table>
      @if($casesDraft)
        <p class="mini" style="margin-top:8px;">
          {{ $casesDraft }} case {{ $casesDraft === 1 ? 'study is' : 'studies are' }} still draft, and
          teach nothing until published.
        </p>
      @endif
      <p style="margin-top:12px;">
        <a class="btn-sm" href="{{ route('admin.brain.playbook') }}">Playbook</a>
        <a class="btn-sm" href="{{ route('admin.rules.index') }}">Rules</a>
        <a class="btn-sm" href="{{ route('admin.cases.index') }}">Case studies</a>
        <a class="btn-sm" href="{{ route('admin.brain.briefing') }}">Briefing</a>
      </p>
    </div>

    <div class="card">
      <h2>Which model does the judging</h2>
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
        Everything above travels with the story to whichever model answers. Add an adapter in
        <code>app/Services/Ai</code>, then <code>bench:run --adapter=&lt;name&gt;</code> and compare.
      </p>
    </div>
  </div>

  {{-- ── 3 ────────────────────────────────────────────────────────────── --}}
  <h2 class="stagehead"><span class="num">3</span> Published news</h2>
  <p class="stagelede">
    What readers actually saw, and what we got wrong. Every correction here becomes material for
    stage two &mdash; a rule, a case study, or an answer the bench holds the model to.
  </p>

  <div class="grid2">
    <div class="card">
      <h2>The bench</h2>
      <p class="sub">Answers a person has confirmed, used to score any model.</p>
      <div class="stat"><span class="n">{{ number_format($benchItems) }}</span><span class="of">confirmed answers</span></div>

      @if($proposals)
        <p class="mini" style="margin-top:8px;">
          <span class="pill nowhere">{{ $proposals }} proposed</span>
          waiting for you to accept or reject. They count for nothing until you do.
        </p>
      @endif

      @if($lastScores)
        <div class="scoregrid" style="margin-top:14px;">
          <div class="score"><div class="lbl">Kept or discarded</div>
            <div class="v">{{ $lastScores['kept_or_discarded'] !== null ? $lastScores['kept_or_discarded'].'%' : '—' }}</div></div>
          <div class="score"><div class="lbl">Place</div>
            <div class="v">{{ $lastScores['place'] !== null ? $lastScores['place'].'%' : '—' }}</div></div>
          <div class="score"><div class="lbl">Of which “nowhere”</div>
            <div class="v {{ ($lastScores['nowhere'] ?? 100) < 80 ? 'bad' : '' }}">{{ $lastScores['nowhere'] !== null ? $lastScores['nowhere'].'%' : '—' }}</div></div>
        </div>
        <p class="mini" style="margin-top:10px;">
          Last run: {{ $lastRun->adapter }} ({{ $lastRun->model }}), {{ \Carbon\Carbon::parse($lastRun->created_at)->diffForHumans() }}.
        </p>
      @else
        <p class="mini" style="margin-top:10px;">Never run. <code>php artisan bench:run</code></p>
      @endif

      <p style="margin-top:12px;"><a class="btn-sm go" href="{{ route('admin.brain.bench') }}">Open the bench</a></p>
    </div>

    <div class="card">
      <h2>Corrections and takedowns</h2>
      <p class="sub">Every human fix, kept as material for the rules and the bench.</p>
      <div class="stat"><span class="n">{{ number_format($corrections) }}</span><span class="of">corrections</span></div>

      @if($tally)
        <table class="tidy" style="margin-top:12px;">
          @foreach($tally as $field => $n)
            <tr><td>{{ \App\Services\Knowledge\CorrectionLog::FIELDS[$field] ?? $field }}</td>
                <td style="text-align:right;">{{ $n }}</td></tr>
          @endforeach
        </table>
        <p class="mini" style="margin-top:10px;">
          Write the next playbook change about whatever is at the top of that list.
        </p>
      @endif

      <p style="margin-top:12px;">
        <a class="btn-sm" href="{{ route('admin.brain.corrections') }}">Corrections</a>
        <a class="btn-sm" href="{{ route('admin.removals.index') }}">Removed ({{ $removals }})</a>
      </p>
    </div>
  </div>
</div>
@endsection
