@extends('layouts.admin')
@section('title', 'Professional credentials')
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

  .cred { border-bottom: 1px solid #eef3f7; padding: 16px 0; }
  .cred:last-child { border-bottom: 0; }
  .cred h3 { margin: 0 0 3px; font-size: 15px; }
  .credmeta { font-size: 12.5px; color: #62788a; margin-bottom: 4px; }
  .credno { font-family: ui-monospace, monospace; background: #f4f7fa; padding: 1px 6px; border-radius: 3px; }
  .clash { font-size: 12.5px; color: #9b2c2c; background: #fbeaea; border: 1px solid #f0cfc0;
           border-radius: 5px; padding: 6px 10px; margin: 6px 0; }
  .credacts { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin-top: 10px; }
  .credacts input[type=text] { padding: 6px 10px; border: 1px solid #cfdae4; border-radius: 6px;
                               font: inherit; min-width: 260px; }
  .btn-reg { background: #1c5a7f; color: #fff; }
  .btn-doc { background: #f3f7fa; color: #2e5c78; border: 1px solid #cfdae4; }
  .whatitsays { font-size: 12.5px; color: #5a6d7c; background: #f7fafc; border-left: 3px solid #cfdae4;
                padding: 7px 11px; margin: 8px 0 0; }
  .empty { color: #62788a; font-size: 14px; padding: 10px 0; }
</style>
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

  <h1>Professional credentials</h1>
  <p class="lede">Every credential is checked on its own. A firm does not confirm its staff, and a staff member
     does not confirm their firm.</p>

  @if(session('status'))<div class="ok">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="warn">{{ $errors->first() }}</div>@endif

  <div class="mpcounts">
    <div class="mpcount {{ $counts['waiting'] > 0 ? 'warn' : '' }}"><b>{{ $counts['waiting'] }}</b><span>waiting for you</span></div>
    <div class="mpcount"><b>{{ $counts['confirmed'] }}</b><span>register-confirmed</span></div>
    <div class="mpcount"><b>{{ $counts['document'] }}</b><span>document only</span></div>
    <div class="mpcount {{ $counts['due'] > 0 ? 'warn' : '' }}"><b>{{ $counts['due'] }}</b><span>due for re-check</span></div>
    <div class="mpcount"><b>{{ $counts['refused'] }}</b><span>refused</span></div>
    <div class="mpcount"><b>{{ $counts['lapsed'] }}</b><span>lapsed or suspended</span></div>
    <div class="mpcount"><b>{{ $counts['profiles'] }}</b><span>professionals</span></div>
  </div>

  @if(count($authorities) === 0)
    <div class="warn">
      <strong>No issuing authorities are configured.</strong>
      Nothing can be checked against a register until somebody adds the bodies that issue credentials
      in each country &mdash; and until then every decision here can only ever be &ldquo;document only&rdquo;.
    </div>
  @endif

  {{-- ── waiting ────────────────────────────────────────────────────── --}}
  <div class="card">
    <h2>Waiting for review ({{ count($waiting) }})</h2>
    <p class="sub">Oldest first. Check the number on the issuing body&rsquo;s own register where there is one &mdash;
       that is the difference between the two buttons, and readers are told which you used.</p>

    @forelse($waiting as $c)
      <div class="cred">
        <h3>{{ $c['public_professional_name'] }}
          @if($c['legal_name'] && $c['legal_name'] !== $c['public_professional_name'])
            <span class="credmeta">(legally {{ $c['legal_name'] }})</span>
          @endif
        </h3>
        <div class="credmeta">
          @{{ $c['username'] }} &middot; {{ $c['authority_name'] }} &middot;
          <span class="credno">{{ $c['registration_number'] }}</span> &middot;
          {{ $c['jurisdiction_region_code'] ? $c['jurisdiction_region_code'] . ', ' : '' }}{{ $c['jurisdiction_country_code'] }}
          @if($c['credential_type']) &middot; {{ $c['credential_type'] }} @endif
          &middot; submitted {{ \Carbon\Carbon::parse($c['created_at'])->addHours(8)->diffForHumans() }}
        </div>

        @if(!empty($c['also_claimed_by']))
          <div class="clash">
            <strong>This number is also on file for:</strong> {{ implode(', ', $c['also_claimed_by']) }}.
            Usually a typing mistake &mdash; check before confirming either.
          </div>
        @endif

        @if($c['official_register_url'] || $c['authority_register_url'])
          <div class="credmeta">
            Register:
            <a href="{{ $c['official_register_url'] ?: $c['authority_register_url'] }}" target="_blank" rel="noopener noreferrer">open it</a>
          </div>
        @endif

        @if($c['document_media_id'])
          <div class="credmeta">A document was uploaded. It is stored privately and is not linked from this page.</div>
        @endif

        <p class="whatitsays">
          Confirming from the register shows: <strong>Professional credential confirmed</strong>.<br>
          Confirming from a document shows: <strong>Credential document submitted &mdash; not independently
          confirmed against an official register</strong>.
        </p>

        <div class="credacts">
          <form method="POST" action="{{ route('admin.professionals.register', $c['id']) }}">
            @csrf
            <button class="btn btn-reg" type="submit">Confirmed on the register</button>
          </form>
          <form method="POST" action="{{ route('admin.professionals.document', $c['id']) }}">
            @csrf
            <button class="btn btn-doc" type="submit">Document only</button>
          </form>
        </div>

        <div class="credacts">
          <form method="POST" action="{{ route('admin.professionals.unable', $c['id']) }}" class="credacts">
            @csrf
            <input type="text" name="note" placeholder="What you tried, so nobody repeats it" required>
            <button class="btn" type="submit">Could not check</button>
          </form>
          <form method="POST" action="{{ route('admin.professionals.reject', $c['id']) }}" class="credacts">
            @csrf
            <input type="text" name="note" placeholder="Why &mdash; the professional is told" required>
            <button class="btn btn-danger" type="submit">Turn down</button>
          </form>
        </div>
      </div>
    @empty
      <p class="empty">Nothing waiting.</p>
    @endforelse
  </div>

  {{-- ── due for re-check ───────────────────────────────────────────── --}}
  <div class="card">
    <h2>Due for re-check ({{ count($due) }})</h2>
    <p class="sub">A practising certificate lapses and nobody writes to tell us. Until one of these is looked at again,
       the site is repeating a year-old check as though it were current.</p>

    @forelse($due as $c)
      <div class="cred">
        <h3>{{ $c['public_professional_name'] }}</h3>
        <div class="credmeta">
          {{ $c['authority_name'] }} &middot; <span class="credno">{{ $c['registration_number'] }}</span> &middot;
          last checked {{ \Carbon\Carbon::parse($c['verified_at'])->addHours(8)->format('j M Y') }} &middot;
          due {{ \Carbon\Carbon::parse($c['next_review_at'])->addHours(8)->diffForHumans() }}
        </div>
        <div class="credacts">
          <form method="POST" action="{{ route('admin.professionals.register', $c['id']) }}">
            @csrf
            <button class="btn btn-reg" type="submit">Still on the register</button>
          </form>
          <form method="POST" action="{{ route('admin.professionals.suspend', $c['id']) }}" class="credacts">
            @csrf
            <input type="text" name="note" placeholder="Why it is being suspended" required>
            <button class="btn btn-danger" type="submit">Suspend</button>
          </form>
        </div>
      </div>
    @empty
      <p class="empty">Nothing due.</p>
    @endforelse
  </div>
</div>
@endsection
