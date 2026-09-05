@extends('layouts.admin')
@section('title', 'Sites that asked to be listed')
@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
<style>
  .srq { border: 1px solid #e2edf6; border-radius: 10px; background: #fff; padding: 18px 20px; margin-bottom: 14px; }
  .srq.waiting { border-left: 4px solid #d99a6c; }
  .srq.done { opacity: .72; }
  .srq-head { display: flex; align-items: baseline; gap: 12px; flex-wrap: wrap; margin-bottom: 4px; }
  .srq-head h2 { margin: 0; font-size: 16px; flex: 1 1 auto; }
  .srq-url { font-size: 13px; color: #62788a; word-break: break-all; }
  .srq-when { font-size: 12px; color: #8296a8; white-space: nowrap; }

  .srq-state { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
               padding: 3px 9px; border-radius: 20px; white-space: nowrap; }
  .srq-state.reviewed { background: #fdf3ec; color: #a8541f; border: 1px solid #f0cfc0; }
  .srq-state.new, .srq-state.probing { background: #eef2f5; color: #5a6d7c; border: 1px solid #dbe6ef; }
  .srq-state.approved { background: #f0f8f2; color: #2f6b3a; border: 1px solid #bcd9c4; }
  .srq-state.declined, .srq-state.duplicate { background: #f4f6f8; color: #7b8fa1; border: 1px solid #e2e8ee; }

  .srq-panes { display: grid; gap: 12px; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); margin: 12px 0; }
  .srq-pane { border: 1px solid #eef3f7; border-radius: 8px; padding: 11px 13px; background: #fbfdfe; }
  .srq-pane h3 { margin: 0 0 6px; font-size: 11px; font-weight: 700; letter-spacing: .06em;
                 text-transform: uppercase; color: #8296a8; }
  .srq-pane p { margin: 0 0 5px; font-size: 13px; color: #40566a; line-height: 1.55; }
  .srq-pane p:last-child { margin-bottom: 0; }
  .srq-pane b { color: #24384a; }
  .srq-pane .none { color: #a5b5c2; }

  .srq-facts { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
  .srq-fact { font-size: 11.5px; padding: 3px 9px; border-radius: 20px; background: #f2f6fa; color: #52697d; }
  .srq-fact.good { background: #f0f8f2; color: #2f6b3a; }
  .srq-fact.warn { background: #fdf3ec; color: #a8541f; }

  .srq-acts { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; padding-top: 12px; border-top: 1px solid #eef3f7; }
  .srq-acts input[type=text] { padding: 7px 10px; border: 1px solid #cfdae4; border-radius: 6px; font: inherit; min-width: 230px; }
  .srq-acts select { padding: 7px 9px; border: 1px solid #cfdae4; border-radius: 6px; font: inherit; }
  .srq-filters { display: flex; gap: 7px; flex-wrap: wrap; margin-bottom: 18px; }
  .srq-filters a { font-size: 13px; padding: 5px 12px; border-radius: 20px; border: 1px solid #dbe6ef;
                   background: #f7fafc; color: #5a6d7c; text-decoration: none; }
  .srq-filters a.on { background: #1c3d5a; border-color: #1c3d5a; color: #fff; font-weight: 600; }
</style>
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

  <h1>Sites that asked to be listed</h1>
  <p class="lede">Publishers who used the form on the site. Each one shows three things separately:
    <b>what they told us</b>, <b>what the probe found</b>, and <b>what the model made of it</b> — a
    claim, a measurement and an opinion are not the same kind of thing.
    Approving adds a source that is <b>switched off</b>; you turn it on from Sources.</p>

  @if(session('status'))<div class="ok">{{ session('status') }}</div>@endif
  @if(session('error'))<div class="warn">{{ session('error') }}</div>@endif

  <div class="srq-filters">
    @foreach(['open' => 'Waiting', 'approved' => 'Approved', 'declined' => 'Declined', 'all' => 'Everything'] as $key => $label)
      <a class="{{ $show === $key ? 'on' : '' }}" href="{{ route('admin.source-requests.index', ['status' => $key]) }}">
        {{ $label }}@if(isset($counts[$key])) ({{ $counts[$key] }})@endif
      </a>
    @endforeach
  </div>

  @forelse($rows as $r)
    <div class="srq {{ in_array($r->status, ['new','probing','reviewed']) ? 'waiting' : 'done' }}">
      <div class="srq-head">
        <h2>{{ $r->site_name ?: parse_url($r->website_url, PHP_URL_HOST) }}</h2>
        <span class="srq-state {{ $r->status }}">{{ $r->status }}</span>
        <span class="srq-when">{{ \Carbon\Carbon::parse($r->created_at)->addHours(8)->format('j M, H:i') }} KL</span>
      </div>
      <p class="srq-url"><a href="{{ $r->website_url }}" target="_blank" rel="noopener nofollow">{{ $r->website_url }}</a></p>

      <div class="srq-facts">
        @if($r->found_kind)
          <span class="srq-fact good">readable: {{ $r->found_kind }}</span>
        @else
          <span class="srq-fact warn">no feed or API found</span>
        @endif
        @if($r->items_per_week !== null)
          <span class="srq-fact">{{ rtrim(rtrim(number_format((float) $r->items_per_week, 2), '0'), '.') }} items/week</span>
        @endif
        @if($r->recommended_cadence)
          <span class="srq-fact {{ $r->recommended_cadence === 'daily' ? 'good' : '' }}">suggest: {{ $r->recommended_cadence }}</span>
        @endif
        @if(($r->probe_data['robots']['reachable'] ?? true) === false)
          <span class="srq-fact warn">robots unreadable — likely a bot challenge</span>
        @endif
        @if($r->probe_data['robots']['content_signal'] ?? null)
          <span class="srq-fact">signal: {{ $r->probe_data['robots']['content_signal'] }}</span>
        @endif
        @if($r->verified_at)
          <span class="srq-fact good">ownership proved by {{ $r->verify_method }}</span>
        @else
          <span class="srq-fact warn">ownership NOT proved</span>
        @endif
        <span class="srq-fact">may show:
          {{ implode(', ', array_keys(array_filter([
              'headline' => $r->allow_headline, 'excerpt' => $r->allow_excerpt,
              'summary'  => $r->allow_ai_summary, 'image'   => $r->allow_image,
          ]))) ?: 'nothing' }}</span>
      </div>

      @unless($r->verified_at)
        <div class="srq-pane" style="margin-bottom:12px">
          <h3>Ownership &mdash; a checkbox is not proof</h3>
          <p>Anyone can tick "I speak for this site" about somebody else's website. Ask them to put
            this token on the domain &mdash; a DNS TXT record, a meta tag on the home page, or a file
            at <code>{{ \App\Services\Ingest\SourceOwnership::FILE_PATH }}</code> &mdash; then press
            <b>Check ownership</b>.</p>
          <p><code>{{ \App\Services\Ingest\SourceOwnership::KEY }}={{ \App\Services\Ingest\SourceOwnership::tokenFor($r->id) }}</code></p>
          @if($r->verify_error)<p><b>Last check:</b> {{ $r->verify_error }}</p>@endif
        </div>
      @endunless

      <div class="srq-panes">
        <div class="srq-pane">
          <h3>What they told us</h3>
          <p>{{ $r->describes ?: '—' }}</p>
          @if($r->contact_name || $r->contact_email)
            <p><b>{{ $r->contact_name ?: 'no name' }}</b> · {{ $r->contact_email ?: 'no email' }}</p>
          @endif
          @if($r->social)
            <p>{{ implode(' · ', array_slice($r->social, 0, 3)) }}</p>
          @endif
        </div>

        <div class="srq-pane">
          <h3>What the probe found</h3>
          @if($r->found_url)
            <p><b>{{ $r->found_kind }}</b><br><span style="word-break:break-all">{{ $r->found_url }}</span></p>
          @else
            <p class="none">Nothing readable. It may still be usable — a page can hold its list as
              embedded JSON or plain text, which no probe catches reliably.</p>
          @endif
          @if(($r->probe_data['routes']['given page']['embedded_json_list'] ?? false))
            <p><b>The page carries an embedded list</b> — worth a look by hand.</p>
          @endif
          @if(($r->probe_data['routes']['given page']['text_date_labels'] ?? 0) > 2)
            <p><b>{{ $r->probe_data['routes']['given page']['text_date_labels'] }} "Date:" labels</b> in the text — a listing board.</p>
          @endif
        </div>

        <div class="srq-pane">
          <h3>What the model made of it</h3>
          @if($r->ai_decision)
            <p><b>{{ $r->ai_decision }}</b></p>
            <p>{{ $r->ai_verdict }}</p>
          @else
            <p class="none">{{ $r->ai_verdict ?: 'Not judged yet.' }}</p>
          @endif
        </div>
      </div>

      @if(in_array($r->status, ['new','probing','reviewed']))
        <div class="srq-acts">
          <form method="post" action="{{ route('admin.source-requests.approve', $r->id) }}" style="display:flex;gap:8px;align-items:center">
            @csrf
            <select name="cadence">
              <option value="weekly" {{ $r->recommended_cadence !== 'daily' ? 'selected' : '' }}>check weekly</option>
              <option value="daily" {{ $r->recommended_cadence === 'daily' ? 'selected' : '' }}>check daily</option>
            </select>
            <button class="btn" {{ $r->found_kind ? '' : 'disabled title=Nothing-readable-was-found' }}>Approve</button>
          </form>

          <form method="post" action="{{ route('admin.source-requests.decline', $r->id) }}" style="display:flex;gap:8px;align-items:center">
            @csrf
            <input type="text" name="reason" placeholder="Why — they may ask" maxlength="500">
            <button class="btn">Decline</button>
          </form>

          <form method="post" action="{{ route('admin.source-requests.recheck', $r->id) }}">
            @csrf<button class="btn">Look again</button>
          </form>

          <form method="post" action="{{ route('admin.source-requests.verify', $r->id) }}">
            @csrf<button class="btn">Check ownership</button>
          </form>
        </div>
      @elseif($r->status === 'approved' && $r->source_name)
        <p class="srq-url">Added as <b>{{ $r->source_name }}</b>, switched off.</p>
      @elseif($r->status === 'declined')
        <p class="srq-url">Declined: {{ $r->decline_reason }}</p>
      @endif
    </div>
  @empty
    <div class="empty">Nothing here.</div>
  @endforelse
</div>
@endsection
