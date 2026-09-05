@extends('layouts.admin')
@section('title', 'AI models')
@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
<style>
  .srcpage.aip { max-width:1400px; }
  .aip table { table-layout:auto; }
  .aip .flash { background:#e0f5e9; color:#1f7840; padding:8px 14px; border-radius:10px; margin-bottom:10px; font-size:0.86rem; }
  .aip .flash.warn { background:#fde8e6; color:#9b2c1f; }
  .aip h2 { font-size:1rem; margin:18px 0 6px; color:#1c5a7f; }
  .aip table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e2edf6; border-radius:12px; overflow:hidden; font-size:0.82rem; }
  .aip th, .aip td { padding:4px 6px; text-align:left; border-bottom:1px solid #eef3f7; vertical-align:middle; }
  .aip th { background:#f6f9fc; color:#5f7f9a; font-weight:600; font-size:0.68rem; text-transform:uppercase; letter-spacing:0.04em; white-space:nowrap; }
  .aip input[type=text], .aip input[type=number], .aip select, .aip textarea { padding:3px 6px; border:1px solid #cfe0ec; border-radius:6px; font:inherit; font-size:0.8rem; background:#fff; }
  .aip select { width:auto; max-width:118px; font-size:0.76rem; }
  .aip input.model { width:118px; } .aip input.url { width:165px; font-size:0.72rem; } .aip input.price { width:54px; } .aip input.n { width:50px; }
  .aip button { font:inherit; font-size:0.76rem; padding:3px 10px; border-radius:999px; border:1px solid #cfe0ec; background:#fff; color:#1c5a7f; cursor:pointer; }
  .aip button.primary { background:#1c5a7f; border-color:#1c5a7f; color:#fff; }
  .aip .tag { display:inline-block; padding:1px 7px; border-radius:999px; font-size:0.7rem; font-weight:600; background:#eef3f7; color:#1f5679; white-space:nowrap; }
  .aip .tag.ok { background:#e0f5e9; color:#1f7840; } .aip .tag.bad { background:#fde8e6; color:#9b2c1f; }
  .aip .num { text-align:right; font-variant-numeric:tabular-nums; white-space:nowrap; }
  .aip .mini { font-size:0.72rem; color:#5f7f9a; }
  .aip .task-name { font-weight:700; white-space:nowrap; }
  .aip .task-desc { color:#5f7f9a; font-size:0.72rem; max-width:300px; white-space:normal; }
  .aip tr.notes td { padding:0 8px 6px; background:#fbfdfe; }
  .aip tr.notes details summary { cursor:pointer; color:#1c5a7f; font-size:0.74rem; }
  .aip .addenda { display:grid; grid-template-columns:repeat(auto-fit, minmax(280px, 1fr)); gap:8px; margin:6px 0 2px; }
  .aip .addenda textarea { width:100%; box-sizing:border-box; min-height:56px; }
  .aip .addenda .who { font-size:0.72rem; font-weight:700; color:#1c5a7f; }
  .aip form.inline { display:inline; }
  .aip .money { white-space:nowrap; }
  .aip .money b.ok { color:#1f7840; } .aip .money b.low { color:#9b2c1f; }
  .aip .money details summary { cursor:pointer; color:#1c5a7f; font-size:0.72rem; }
  .aip .lede { font-size:0.86rem; }
  .aip .prelim { color:#b7791f; } .aip .actual { color:#1f7840; font-weight:600; margin-left:6px; }
  .aip .wallets { display:grid; grid-template-columns:repeat(auto-fill, minmax(250px, 1fr)); gap:10px; }
  .aip .wallet { background:#fff; border:1px solid #e2edf6; border-radius:12px; padding:10px 12px; }
  .aip .wallet.low { border-color:#e6a5a0; background:#fff7f6; } .aip .wallet.off { opacity:0.6; }
  .aip .wallet-name { font-weight:700; color:#1c5a7f; font-size:0.86rem; }
  .aip .wallet-amount { font-size:1.35rem; font-weight:700; margin:4px 0 2px; } .aip .wallet-amount.ok { color:#1f7840; } .aip .wallet-amount.low { color:#9b2c1f; } .aip .wallet-amount.mini { font-size:0.8rem; font-weight:400; }
  .aip .wallet-form { display:flex; flex-wrap:wrap; gap:6px 10px; align-items:center; margin-top:8px; font-size:0.74rem; color:#5f7f9a; }
  .aip .wallet-form label { display:inline-flex; gap:4px; align-items:center; }
</style>
@endpush
@section('content')
<div class="srcpage aip">
  @include('admin.brain._nav')
  <h1>AI models</h1>
  <p class="lede">Which model does which job, who steps in, and what each model is told differently.
    <b>Failover</b>: the primary answers; if it is down, rate-limited or replies badly, the helper answers.
    <b>Share</b>: the same, and when the queue is longer than the threshold every other call goes to the helper.
    <b>Primary only</b>: nobody steps in. In every mode with a helper, a <b>weak answer</b> escalates too: if the primary replies but the reply fails the task's checks (no JSON, no category, no summary, no verdict), the helper gets the story before anything is accepted - so a cheap model first and a stronger one behind it is a safe setting. A model that fails three times in a row rests for ten minutes; a weak answer does not count against it. Keys live in <code>.env</code>, never here.</p>
  @if(session('status'))
    <div class="flash">{{ session('status') }}</div>
  @endif
  @if($errors->any())
    <div class="flash warn">{{ $errors->first() }}</div>
  @endif
  @if(!empty($low))
    <div class="flash warn"><b>Top up soon:</b>
      @foreach($low as $lk => $lb)
        {{ $wallets[$lk]['name'] ?? $lk }} has {{ $lb['currency'] }} {{ number_format((float) $lb['balance'], 2) }} left{{ $lb['days_left'] !== null ? ' (about ' . $lb['days_left'] . ' days)' : '' }}.
      @endforeach
    </div>
  @endif

  <h2>Money left</h2>
  <p class="mini" style="margin:-2px 0 8px">One card per account: models that share a key share the money.</p>
  <div class="wallets">
    @foreach($wallets as $acct => $w)
      @php($b = $w['balance'])
      @php($p = $w['owner'])
      <div class="wallet {{ $b['balance'] !== null && $b['low'] ? 'low' : '' }} {{ $w['anyOn'] ? '' : 'off' }}">
        <div class="wallet-name">{{ $w['name'] }} <span class="mini">{{ $w['models'] }}{{ $w['anyOn'] ? '' : ' · off' }}</span></div>
        @if($b['balance'] !== null)
          <div class="wallet-amount {{ $b['low'] ? 'low' : 'ok' }}">{{ $b['currency'] }} {{ number_format((float) $b['balance'], 2) }}</div>
          <div class="mini">
            @if($b['days_left'] !== null) about {{ $b['days_left'] }} days at last week's rate · @endif
            @if($b['source'] === 'live') from {{ $w['name'] }} at {{ $b['checked_at'] ? \Carbon\Carbon::parse($b['checked_at'])->format('H:i') : '' }} · <a href="{{ route('admin.brain.ai', ['refresh' => 1]) }}">refresh</a>
            @else estimate: {{ number_format((float) $b['credit'], 2) }} topped up {{ $b['credit_set_at'] ? \Carbon\Carbon::parse($b['credit_set_at'])->format('j M') : '' }}, {{ number_format((float) $b['spent_since'], 2) }} spent since @endif
          </div>
        @elseif($b['error'])
          <div class="wallet-amount"><span class="tag bad">{{ $b['error'] }}</span></div>
        @elseif(!$w['hasKey'])
          <div class="wallet-amount mini">no key in .env ({{ $w['keyVar'] }})</div>
        @else
          <div class="wallet-amount mini">{{ $w['name'] }} has no balance call</div>
          <div class="mini">enter what you topped up and the panel counts down from it</div>
        @endif
        <form method="post" action="{{ route('admin.brain.ai.credit', ['key' => $p['key']]) }}" class="wallet-form">@csrf
          @if(empty($p['balance_kind']))
            <label>topped up <input type="number" class="price" step="0.01" min="0" name="credit_usd" placeholder="USD"></label>
          @endif
          <label>warn below <input type="number" class="n" step="0.5" min="0" name="low_balance_usd" value="{{ $p['low_balance_usd'] ?? 3 }}"></label>
          <button type="submit">Save</button>
          @if(!empty($p['billing_url']))<a class="mini" href="{{ $p['billing_url'] }}" target="_blank" rel="noopener">top up at {{ $w['name'] }} ↗</a>@endif
        </form>
      </div>
    @endforeach
  </div>

  <h2>Spent per day</h2>
  <p class="mini" style="margin:-2px 0 8px">Per account, Malaysia time. <span class="prelim">Preliminary</span> is tokens × the prices above, known the same day; <span class="actual">actual</span> is what the balance really dropped by, known the next day. Only DeepSeek reports a balance, so only DeepSeek gets an actual.</p>
  <table>
    <thead><tr><th>Day</th>@foreach($wallets as $acct => $w)<th class="num">{{ $w['name'] }}</th>@endforeach<th class="num">Calls</th></tr></thead>
    <tbody>
    @foreach($days as $day)
      @php($row = $daily[$day] ?? [])
      @php($calls = array_sum(array_map(fn ($x) => $x['calls'] ?? 0, $row)))
      <tr>
        <td>{{ \Carbon\Carbon::parse($day)->format('D j M') }}</td>
        @foreach($wallets as $acct => $w)
          @php($est = isset($row[$acct]) && $row[$acct]['cost'] > 0 ? $row[$acct]['cost'] : null)
          @php($actual = $acct === 'deepseek' && isset($fromBalance[$day]) && $fromBalance[$day] !== null ? $fromBalance[$day] : null)
          <td class="num">
            @if($est !== null)<span class="prelim" title="preliminary: tokens × price">${{ number_format($est, 4) }}</span>@endif
            @if($actual !== null)<span class="actual" title="actual: what the balance dropped by">${{ number_format($actual, 4) }}</span>@endif
            @if($est === null && $actual === null)—@endif
          </td>
        @endforeach
        <td class="num">{{ $calls ?: '—' }}</td>
      </tr>
    @endforeach
    </tbody>
  </table>

  <h2>Providers</h2>
  <table>
    <thead><tr><th>Provider</th><th>Model</th><th>Vision model</th><th>Endpoint</th><th>Key</th><th>$/1M in · out</th><th>Health</th><th class="num">7 days</th><th>On</th><th></th></tr></thead>
    <tbody>
    @foreach($providers as $k => $p)
      @php($w = $week[$k] ?? null)
      @php($b = $balances[$k] ?? null)
      @php($hasKey = $keys[$k] ?? false)
      @php($keyVar = ['kimi' => 'MOONSHOT_API_KEY', 'claude' => 'ANTHROPIC_API_KEY'][$p['key_name']] ?? strtoupper($p['key_name']) . '_API_KEY')
      <tr>
        <form method="post" action="{{ route('admin.brain.ai.provider', ['key' => $k]) }}">@csrf
        <td><b>{{ $p['name'] }}</b> <span class="mini">{{ $k }}</span></td>
        <td><input type="text" class="model" name="model" value="{{ $p['model'] }}"></td>
        <td><input type="text" class="model" name="vision_model" value="{{ $p['vision_model'] }}" placeholder="none"></td>
        <td><input type="text" class="url" name="base_url" value="{{ $p['base_url'] }}"></td>
        <td>
          @if($hasKey)
            <span class="tag ok">present</span>
          @else
            <span class="tag bad">missing</span> <span class="mini">{{ $keyVar }}</span>
          @endif
        </td>
        <td class="num"><input type="number" class="price" step="0.0001" name="price_in" value="{{ $p['price_in'] }}"> <input type="number" class="price" step="0.0001" name="price_out" value="{{ $p['price_out'] }}"></td>
        <td>
          @if(!empty($p['cooldown_until']) && strtotime($p['cooldown_until']) > time())
            <span class="tag bad">resting till {{ \Carbon\Carbon::parse($p['cooldown_until'])->format('H:i') }}</span>
          @elseif((int) ($p['consecutive_failures'] ?? 0) > 0)
            <span class="tag bad">{{ $p['consecutive_failures'] }} fail</span>
          @elseif(!empty($p['last_ok_at']))
            <span class="tag ok">ok {{ \Carbon\Carbon::parse($p['last_ok_at'])->diffForHumans(null, true) }} ago</span>
          @else
            <span class="tag">never called</span>
          @endif
          @if(!empty($p['last_error']))
            <br><span class="mini" title="{{ $p['last_error'] }}">{{ \Illuminate\Support\Str::limit($p['last_error'], 40) }}</span>
          @endif
        </td>
        <td class="num">
          @if($w)
            {{ $w->ok }}/{{ $w->calls }} <span class="mini">{{ $w->avg_ms }} ms · ${{ number_format((float) $w->cost, 3) }}</span>
          @else
            <span class="mini">—</span>
          @endif
        </td>
        <td><input type="checkbox" name="enabled" value="1" {{ !empty($p['enabled']) ? 'checked' : '' }}></td>
        <td style="white-space:nowrap"><button type="submit" class="primary">Save</button></form> <form method="post" action="{{ route('admin.brain.ai.test', ['key' => $k]) }}" class="inline">@csrf<button type="submit">Test</button></form></td>
      </tr>
    @endforeach
    </tbody>
  </table>

  <h2>Tasks</h2>
  <table>
    <thead><tr><th>Task</th><th>Primary</th><th>Helper</th><th>2nd helper</th><th>Mode</th><th>Share &gt;</th><th>Last 7 days</th><th>On</th><th></th></tr></thead>
    <tbody>
    @foreach($tasks as $k => $t)
      @php($rows = $byTask[$k] ?? collect())
      <tr>
        <form method="post" action="{{ route('admin.brain.ai.task', ['key' => $k]) }}">@csrf
        <td><span class="task-name">{{ $t['name'] }}</span><div class="task-desc">{{ $t['description'] }}</div></td>
        <td><select name="primary_provider">@foreach($providers as $pk => $p)<option value="{{ $pk }}" {{ $t['primary_provider'] === $pk ? 'selected' : '' }}>{{ $p['name'] }}</option>@endforeach</select></td>
        <td><select name="helper_provider"><option value="">none</option>@foreach($providers as $pk => $p)<option value="{{ $pk }}" {{ ($t['helper_provider'] ?? '') === $pk ? 'selected' : '' }}>{{ $p['name'] }}</option>@endforeach</select></td>
        <td><select name="second_helper"><option value="">none</option>@foreach($providers as $pk => $p)<option value="{{ $pk }}" {{ ($t['second_helper'] ?? '') === $pk ? 'selected' : '' }}>{{ $p['name'] }}</option>@endforeach</select></td>
        <td><select name="mode">@foreach(['failover' => 'Failover', 'share' => 'Share load', 'primary' => 'Primary only'] as $mv => $ml)<option value="{{ $mv }}" {{ ($t['mode'] ?? 'failover') === $mv ? 'selected' : '' }}>{{ $ml }}</option>@endforeach</select></td>
        <td><input type="number" class="n" name="share_threshold" value="{{ $t['share_threshold'] ?? 50 }}" min="0"></td>
        <td>
          @forelse($rows as $r)
            <span class="tag {{ $r->ok < $r->calls ? 'bad' : 'ok' }}">{{ $providers[$r->provider_key]['name'] ?? $r->provider_key }} {{ $r->ok }}/{{ $r->calls }}{{ $r->helped ? ' · ' . $r->helped . ' helped' : '' }}</span>
          @empty
            <span class="mini">no calls yet</span>
          @endforelse
        </td>
        <td><input type="checkbox" name="enabled" value="1" {{ !empty($t['enabled']) ? 'checked' : '' }}></td>
        <td><button type="submit" class="primary">Save</button></td>
        </form>
      </tr>
      <tr class="notes">
        <td colspan="9">
          <details>
            <summary>Notes for each model on this task &mdash; {{ $scopeName ? 'for ' . $scopeName . ' only' : 'for every country' }}</summary>
            @if($scopeName)
              <p class="mini" style="margin:4px 0 0">The country switch beside Overview is on <b>{{ $scopeName }}</b>, so what you write here is added to the base notes for {{ $scopeName }}'s publishers only. Switch to <b>All countries</b> to edit the notes every country gets.</p>
            @endif
            <div class="addenda">
              @foreach($providers as $pk => $p)
                @if(in_array($pk, [$t['primary_provider'], $t['helper_provider'] ?? null, $t['second_helper'] ?? null], true))
                  <form method="post" action="{{ route('admin.brain.ai.prompt', ['task' => $k, 'provider' => $pk]) }}">@csrf
                    <div class="who">{{ $p['name'] }}</div>
                    <textarea name="addendum" placeholder="{{ $scopeName ? 'Nothing extra for ' . $scopeName . ' yet.' : 'e.g. Reply with the JSON object only, no prose before it.' }}">{{ $addenda[$k][$pk] ?? '' }}</textarea>
                    @if($scopeName && trim((string) ($base[$k][$pk] ?? '')) !== '')
                      <details><summary class="mini">the base notes this is added to ({{ strlen($base[$k][$pk]) }} characters)</summary><p class="mini" style="white-space:pre-wrap">{{ \Illuminate\Support\Str::limit($base[$k][$pk], 600) }}</p></details>
                    @endif
                    <button type="submit">Save notes</button>
                  </form>
                @endif
              @endforeach
            </div>
          </details>
        </td>
      </tr>
    @endforeach
    </tbody>
  </table>

  <h2>Training</h2>
  <p class="mini" style="margin:-2px 0 8px">Every round of testing against the confirmed answers, Malaysia time. <b>Training</b> is the set the notes are written against; <b>held out</b> is a different set that is never tuned against, so it says whether an improvement is real or just memorised. A round is only progress if the held-out numbers move too.</p>

  @php($kl = fn ($t) => $t ? \Carbon\Carbon::parse($t)->timezone('Asia/Kuala_Lumpur')->format('j M, g:ia') : '')

  @foreach(['train' => 'Training set', 'holdout' => 'Held out'] as $setKey => $setName)
    @if(!empty($training['enrich'][$setKey]))
      <h3 class="mini" style="margin:12px 0 4px;font-weight:700;color:#1c5a7f">The story pipeline &mdash; {{ $setName }}</h3>
      <table>
        <thead><tr><th>Round</th><th>When</th><th>Model</th><th class="num">Keep</th><th class="num">Category</th><th class="num">Place</th><th class="num">National</th><th class="num">Copied</th><th class="num">Errors</th><th>What changed before it</th></tr></thead>
        <tbody>
        @foreach($training['enrich'][$setKey] as $key => $byProvider)
          @foreach($byProvider as $pk => $r)
            <tr>
              @if($loop->first)
                <td rowspan="{{ count($byProvider) }}"><b>{{ explode('|', $key)[0] }}</b></td>
                <td rowspan="{{ count($byProvider) }}" class="mini">{{ $kl($r->created_at) }}</td>
              @endif
              <td>{{ $providers[$pk]['name'] ?? $pk }}</td>
              @foreach(['keep_pct', 'category_pct', 'place_pct', 'national_pct'] as $f)
                <td class="num">@if($r->$f === null)&mdash;@else<b style="color:{{ $r->$f >= 90 ? '#1f7840' : ($r->$f >= 75 ? '#b7791f' : '#9b2c1f') }}">{{ $r->$f }}%</b>@endif</td>
              @endforeach
              <td class="num">{{ $r->copies ?: '—' }}</td>
              <td class="num">{{ $r->errors ?: '—' }}</td>
              @if($loop->first)<td rowspan="{{ count($byProvider) }}" class="mini">{{ $r->changed ?: '—' }}</td>@endif
            </tr>
          @endforeach
        @endforeach
        </tbody>
      </table>
    @endif
  @endforeach

  @if(!empty($training['tasks']['train']))
    <h3 class="mini" style="margin:14px 0 4px;font-weight:700;color:#1c5a7f">The other tasks &mdash; how many cases each model got right</h3>
    <table>
      <thead><tr><th>Round</th><th>When</th><th>Task</th><th>Model</th><th class="num">Correct</th><th class="num">Cases</th><th class="num">Errors</th><th>What changed before it</th></tr></thead>
      <tbody>
      @foreach($training['tasks']['train'] as $key => $byProvider)
        @foreach($byProvider as $pk => $r)
          <tr>
            @if($loop->first)
              <td rowspan="{{ count($byProvider) }}"><b>{{ explode('|', $key)[0] }}</b></td>
              <td rowspan="{{ count($byProvider) }}" class="mini">{{ $kl($r->created_at) }}</td>
              <td rowspan="{{ count($byProvider) }}">{{ $tasks[$r->task_key]['name'] ?? $r->task_key }}</td>
            @endif
            <td>{{ $providers[$pk]['name'] ?? $pk }}</td>
            <td class="num">@if($r->keep_pct === null)&mdash;@else<b style="color:{{ $r->keep_pct >= 90 ? '#1f7840' : ($r->keep_pct >= 75 ? '#b7791f' : '#9b2c1f') }}">{{ $r->keep_pct }}%</b>@endif</td>
            <td class="num">{{ $r->items }}</td>
            <td class="num">{{ $r->errors ?: '—' }}</td>
            @if($loop->first)<td rowspan="{{ count($byProvider) }}" class="mini">{{ $r->changed ?: '—' }}</td>@endif
          </tr>
        @endforeach
      @endforeach
      </tbody>
    </table>
  @endif

  @if(empty($training))
    <p class="mini">No rounds recorded yet.</p>
  @endif

  @if(!empty($latestMisses))
    <h3 class="mini" style="margin:14px 0 4px;font-weight:700;color:#1c5a7f">What is still wrong, newest round</h3>
    <table>
      <thead><tr><th>Model</th><th>Case</th><th>Problem</th></tr></thead>
      <tbody>
      @foreach($latestMisses as $pk => $rows)
        @foreach($rows as $m)
          <tr><td>{{ $providers[$pk]['name'] ?? $pk }}</td><td>{{ \Illuminate\Support\Str::limit($m->title, 58) }}</td><td class="mini">{{ $m->problem }}</td></tr>
        @endforeach
      @endforeach
      </tbody>
    </table>
  @endif

  <h2>Recent failures</h2>
  @if($failures->isEmpty())
    <p class="mini">None recorded.</p>
  @else
    <table>
      <thead><tr><th>When</th><th>Task</th><th>Provider</th><th>Why it got the call</th><th>Error</th></tr></thead>
      <tbody>
      @foreach($failures as $f)
        <tr><td class="mini">{{ \Carbon\Carbon::parse($f->created_at)->diffForHumans() }}</td><td>{{ $f->task_key }}</td><td>{{ $f->provider_key }}</td><td>{{ $f->reason }}</td><td class="mini">{{ $f->error }}</td></tr>
      @endforeach
      </tbody>
    </table>
  @endif
</div>
@endsection
