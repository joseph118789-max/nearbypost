@extends('layouts.admin')
@section('title', 'Marketplace')
@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
<style>
  .mpcounts { display: flex; flex-wrap: wrap; gap: 10px; margin: 14px 0 20px; }
  .mpcount { border: 1px solid #e2edf6; border-radius: 8px; padding: 10px 14px; min-width: 96px; background: #fff; }
  .mpcount b { display: block; font-size: 22px; line-height: 1.1; font-variant-numeric: tabular-nums; }
  .mpcount span { font-size: 12px; color: #62788a; }
  .mpcount.warn { border-color: #f0cfc0; background: #fff8f5; }
  .mpcount.warn b { color: #bc4e2c; }

  .mpflags { display: flex; flex-wrap: wrap; gap: 6px; margin: 0 0 18px; }
  .mpflag { font-size: 12px; padding: 3px 9px; border-radius: 20px; border: 1px solid #dbe6ef; background: #f7fafc; color: #5a6d7c; }
  .mpflag.on { border-color: #bcd9c4; background: #f0f8f2; color: #2f6b3a; font-weight: 600; }

  .sev { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .05em;
         padding: 2px 7px; border-radius: 3px; }
  .sev.critical { background: #fbeaea; color: #9b2c2c; }
  .sev.high     { background: #fdeee4; color: #b3541e; }
  .sev.medium   { background: #fdf6e3; color: #8a6d1f; }
  .sev.low      { background: #eef2f5; color: #5a6d7c; }

  .mprow { border-bottom: 1px solid #eef3f7; padding: 14px 0; }
  .mprow:last-child { border-bottom: 0; }
  .mprow h3 { margin: 0 0 4px; font-size: 15px; }
  .mpmeta { font-size: 12.5px; color: #62788a; margin-bottom: 8px; }
  .mpacts { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; }
  .mpacts input[type=text] { padding: 6px 10px; border: 1px solid #cfdae4; border-radius: 6px; font: inherit; min-width: 240px; }
  .empty { color: #62788a; font-size: 14px; padding: 10px 0; }
</style>
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

  <h1>Marketplace</h1>
  <p class="lede">Local businesses, neighbour offers and services near a reader. Nothing here is on the site until
     both gates agree: you publish it, and moderation accepts it.
     &nbsp;<a href="{{ route('admin.marketplace.status', ['country' => $country]) }}">See what the whole
     Marketplace is waiting on &rarr;</a></p>

  @if(session('status'))<div class="ok">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="warn">{{ $errors->first() }}</div>@endif

  {{-- What the module is switched on for. A desk that does not say this leaves
       a moderator wondering why an approved listing is not on the site. --}}
  <div class="mpflags">
    @foreach($flags as $key => $flag)
      <span class="mpflag {{ $flag['enabled'] ? 'on' : '' }}"
            title="{{ $flag['note'] }} — {{ $flag['source'] }}">{{ str_replace('_', ' ', $key) }}: {{ $flag['enabled'] ? 'on' : 'off' }}</span>
    @endforeach
  </div>

  <div class="mpcounts">
    <div class="mpcount"><b>{{ number_format($counts['live']) }}</b><span>live</span></div>
    <div class="mpcount {{ $counts['waiting'] > 0 ? 'warn' : '' }}"><b>{{ number_format($counts['waiting']) }}</b><span>waiting for you</span></div>
    <div class="mpcount"><b>{{ number_format($counts['draft']) }}</b><span>draft</span></div>
    <div class="mpcount"><b>{{ number_format($counts['paused']) }}</b><span>paused</span></div>
    <div class="mpcount {{ $counts['suspended'] > 0 ? 'warn' : '' }}"><b>{{ number_format($counts['suspended']) }}</b><span>suspended</span></div>
    <div class="mpcount"><b>{{ number_format($counts['expired']) }}</b><span>expired</span></div>
    <div class="mpcount"><b>{{ number_format($counts['providers']) }}</b><span>providers</span></div>
  </div>

  @if($openRules === 0)
    <div class="warn">
      <strong>No category is open in {{ $country }} yet.</strong>
      {{ $categories }} categories exist, and none has a rule row for this country, so nobody can post anything.
      That is the intended state until somebody opens one deliberately.
    </div>
  @endif

  {{-- ── waiting for review ─────────────────────────────────────────── --}}
  <div class="card">
    <h2>Waiting for review ({{ count($waiting) }})</h2>
    <p class="sub">Oldest first, so nobody waits longest by accident.</p>

    @forelse($waiting as $row)
      <div class="mprow">
        <h3>{{ $row['title'] }}</h3>
        <div class="mpmeta">
          {{ $row['provider'] ?? 'no provider' }} &middot; {{ $row['category'] ?? 'no category' }} &middot;
          {{ str_replace('_', ' ', (string) $row['provider_kind']) }} &middot; {{ $row['country_code'] }} &middot;
          {{ str_replace('_', ' ', (string) $row['public_location_mode']) }} &middot;
          @if($row['price_mode'] === 'quotation') on quotation
          @elseif($row['price_min'] !== null) {{ $row['currency'] }} {{ rtrim(rtrim(number_format((float) $row['price_min'], 2), '0'), '.') }}@if($row['price_max'] !== null)–{{ rtrim(rtrim(number_format((float) $row['price_max'], 2), '0'), '.') }}@endif
          @else no price @endif
          &middot; submitted {{ \Carbon\Carbon::parse($row['updated_at'])->addHours(8)->diffForHumans() }}
        </div>

        @if($row['description'])
          <p class="mpmeta">{{ \Illuminate\Support\Str::limit($row['description'], 220) }}</p>
        @endif

        <div class="mpacts">
          <form method="POST" action="{{ route('admin.marketplace.approve', $row['id']) }}">
            @csrf
            <button class="btn btn-primary" type="submit">Publish</button>
          </form>
          <form method="POST" action="{{ route('admin.marketplace.reject', $row['id']) }}" class="mpacts">
            @csrf
            <input type="text" name="reason" placeholder="Why it is being turned down — the provider is told" required>
            <button class="btn btn-danger" type="submit">Turn down</button>
          </form>
        </div>
      </div>
    @empty
      <p class="empty">Nothing waiting.</p>
    @endforelse
  </div>

  {{-- ── reports ────────────────────────────────────────────────────── --}}
  <div class="card">
    <h2>Reports ({{ count($reports) }})</h2>
    <p class="sub">Worst first, then oldest. A high-severity report has already acted on the listing &mdash;
       this is where you confirm or undo that.</p>

    @forelse($reports as $r)
      <div class="mprow">
        <h3>
          <span class="sev {{ $r['severity'] }}">{{ $r['severity'] }}</span>
          {{ str_replace('_', ' ', $r['reason_code']) }}
        </h3>
        <div class="mpmeta">
          {{ $r['target_type'] }} #{{ $r['target_id'] }} &middot;
          {{ \Carbon\Carbon::parse($r['created_at'])->addHours(8)->diffForHumans() }}
          @if($r['also'] > 0) &middot; {{ $r['also'] }} more said the same @endif
        </div>

        @if($r['description'])
          <p class="mpmeta">&ldquo;{{ \Illuminate\Support\Str::limit($r['description'], 300) }}&rdquo;</p>
        @endif

        <form method="POST" action="{{ route('admin.marketplace.report.resolve', $r['id']) }}" class="mpacts">
          @csrf
          <select name="decision_code" required>
            <option value="">Outcome…</option>
            <option value="upheld_removed">Upheld — stays removed</option>
            <option value="upheld_corrected">Upheld — provider must correct it</option>
            <option value="no_action">No action — the listing is fine</option>
            <option value="not_enough_evidence">Not enough to act on</option>
          </select>
          <input type="text" name="notes" placeholder="Note for the record (optional)">
          <button class="btn btn-primary" type="submit">Close report</button>
        </form>
      </div>
    @empty
      <p class="empty">No open reports.</p>
    @endforelse
  </div>

  {{-- ── live ───────────────────────────────────────────────────────── --}}
  <div class="card">
    <h2>Live now ({{ count($live) }})</h2>
    <p class="sub">Read with the same rule the public feed uses, so this list and the site cannot disagree.</p>

    @forelse($live as $row)
      <div class="mprow">
        <h3>{{ $row['title'] }}</h3>
        <div class="mpmeta">
          {{ $row['provider'] ?? 'no provider' }} &middot; {{ $row['country_code'] }} &middot;
          expires {{ \Carbon\Carbon::parse($row['expires_at'])->addHours(8)->diffForHumans() }}
        </div>
        <form method="POST" action="{{ route('admin.marketplace.suspend', $row['id']) }}" class="mpacts">
          @csrf
          <input type="text" name="reason" placeholder="Why it is being suspended" required>
          <button class="btn btn-danger" type="submit">Suspend</button>
        </form>
      </div>
    @empty
      <p class="empty">Nothing live yet.</p>
    @endforelse
  </div>
</div>
@endsection
