{{-- The places the machines could not identify.

     Every other page in this panel explains a decision the pipeline made. This
     one asks a question instead, so it is laid out as a question: the name, the
     story that needed it, what was already tried, and a box to answer in.

     Showing what was tried matters. Without it the first thing a reviewer does
     is repeat the searches that already failed. --}}
@extends('layouts.admin')

@section('title', 'Places needing a person')

@push('styles')
@include('admin.brain._styles')
<style>
  .plhead { margin-bottom:18px; }
  .plhead p { color:#5a6b78; font-size:0.86rem; line-height:1.6; max-width:70ch; margin:8px 0 0; }

  .tabs { display:flex; gap:6px; margin:16px 0 20px; flex-wrap:wrap; }
  .tabs a { font-size:0.78rem; padding:6px 14px; border-radius:20px; background:#eef4f8;
            color:#41637d; text-decoration:none; font-weight:600; }
  .tabs a.on { background:#203d74; color:#fff; }
  .tabs a b { font-weight:700; }

  .pcard { border:1px solid #e2e8ee; border-radius:10px; padding:16px 18px; margin-bottom:14px;
           background:#fff; }
  .pcard.resolved { background:#f6faf6; border-color:#cfe3d2; }
  .pcard.dismissed { background:#fafafa; border-color:#e6e6e6; }

  .pname { font-size:1.02rem; font-weight:700; color:#1c2b36; word-break:break-word; }
  .pmeta { font-size:0.76rem; color:#7a8b98; margin-top:4px; }
  .pmeta a { color:#41637d; }

  .waiting { display:inline-block; font-size:0.7rem; font-weight:700; padding:3px 9px;
             border-radius:20px; background:#fdf3e3; color:#8a5a12; margin-left:8px; }

  .tried { margin:12px 0 0; padding:10px 12px; background:#f7f9fb; border-radius:7px;
           font-size:0.75rem; color:#5a6b78; line-height:1.7; }
  .tried b { color:#41637d; font-weight:600; }
  .tried .step { display:inline-block; min-width:74px; color:#8a9aa6; }

  .answer { display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; margin-top:14px; }
  .answer label { display:block; font-size:0.7rem; color:#7a8b98; margin-bottom:3px;
                  text-transform:uppercase; letter-spacing:0.04em; }
  .answer input[type=text] { padding:7px 10px; border:1px solid #d4dde5; border-radius:6px;
                             font-size:0.84rem; font-family:inherit; }
  .answer .co { width:118px; }
  .answer .lb { width:210px; }
  .answer .nt { flex:1; min-width:190px; }

  .btn-place { background:#276b3a; color:#fff; border:0; padding:8px 18px; border-radius:6px;
               font-weight:600; font-size:0.82rem; cursor:pointer; }
  .btn-skip { background:none; border:1px solid #d4dde5; color:#7a8b98; padding:8px 14px;
              border-radius:6px; font-size:0.8rem; cursor:pointer; }

  .hint-find { font-size:0.74rem; color:#8a9aa6; margin-top:8px; }
  .hint-find a { color:#1c5a7f; }

  .settled { font-size:0.8rem; color:#276b3a; margin-top:8px; }
  .settled .note { color:#7a8b98; display:block; margin-top:3px; }

  .none { padding:40px; text-align:center; color:#8a9aa6; font-size:0.9rem; }
</style>
@endpush

@section('content')
@include('admin.brain._nav')

<div class="plhead">
  <h1>Places needing a person</h1>
  <p>
    A story named a place, and none of the three free sources could find it &mdash; not the map,
    not Wikidata, not the landmark it stands in. Usually that means a launch given only as a lot
    number, a kampung too small to be recorded, or a building known locally by a name nobody has
    registered. No paid service would do better; somebody who knows the area does it in seconds.
  </p>
  <p>
    Answering once settles it for good. The coordinate is remembered, every story already waiting
    on that name is placed, and the question is never asked again.
  </p>
</div>

@if (session('status'))
  <div class="flash">{{ session('status') }}</div>
@endif

@php
  $pending   = (int) ($counts['pending']->n ?? 0);
  $resolved  = (int) ($counts['resolved']->n ?? 0);
  $dismissed = (int) ($counts['dismissed']->n ?? 0);
  $blocked   = (int) ($counts['pending']->stories ?? 0);
@endphp

<div class="tabs">
  <a href="{{ route('admin.places.index') }}"
     class="{{ $status === 'pending' ? 'on' : '' }}">Waiting <b>{{ $pending }}</b></a>
  <a href="{{ route('admin.places.index', ['status' => 'resolved']) }}"
     class="{{ $status === 'resolved' ? 'on' : '' }}">Placed <b>{{ $resolved }}</b></a>
  <a href="{{ route('admin.places.index', ['status' => 'dismissed']) }}"
     class="{{ $status === 'dismissed' ? 'on' : '' }}">Closed <b>{{ $dismissed }}</b></a>
  <a href="{{ route('admin.places.index', ['status' => 'all']) }}"
     class="{{ $status === 'all' ? 'on' : '' }}">All</a>
</div>

@if ($status === 'pending' && $blocked > 0)
  <p class="pmeta" style="margin-bottom:14px;">
    {{ $blocked }} {{ Str::plural('story', $blocked) }} {{ $blocked === 1 ? 'is' : 'are' }}
    waiting on {{ $pending }} {{ Str::plural('name', $pending) }}.
  </p>
@endif

@if ($state === null)
  {{-- The states first. Click one for its names. --}}
  <table class="tidy" style="max-width:640px;margin-bottom:22px;">
    <thead><tr><th>State</th><th style="text-align:right;width:90px;">Names</th><th style="text-align:right;width:90px;">Stories</th></tr></thead>
    <tbody>
      @forelse ($byState as $s)
        <tr>
          <td><a href="{{ route('admin.places.index', ['status' => $status, 'state' => $s['code']]) }}" style="{{ $s['code'] === '-' ? 'color:#8aa4b8;font-style:italic;' : '' }}">{{ $s['name'] }}</a></td>
          <td style="text-align:right;font-variant-numeric:tabular-nums;font-weight:600;">{{ $s['names'] }}</td>
          <td style="text-align:right;font-variant-numeric:tabular-nums;">{{ $s['stories'] }}</td>
        </tr>
      @empty
        <tr><td colspan="3" style="color:#b6c3ce;padding:20px;text-align:center;">Nothing waiting.</td></tr>
      @endforelse
    </tbody>
  </table>
  <p class="pmeta">A name is put under the state its own text gives &mdash; the district or town it names, resolved to its state. <em>State unknown</em> holds the ones that give none.</p>
@else
  <p class="pmeta" style="margin-bottom:12px;">
    <a href="{{ route('admin.places.index', ['status' => $status]) }}">&larr; All states</a>
    &nbsp;&middot;&nbsp; <b>{{ $stateName }}</b> &mdash; {{ $reviews->count() }} {{ Str::plural('name', $reviews->count()) }}
  </p>
@forelse ($reviews as $r)
  <div class="pcard {{ $r->status }}">
    <div class="pname">
      {{ $r->place_text }}
      @if ($r->story_count > 1)
        <span class="waiting">{{ $r->story_count }} stories waiting</span>
      @endif
    </div>

    <div class="pmeta">
      @if ($r->story_url)
        <a href="{{ $r->story_url }}" target="_blank" rel="noopener">{{ Str::limit($r->story_title ?: 'the story', 90) }}</a>
        &middot;
      @endif
      {{ $r->source ?: 'unknown source' }}
      &middot; held {{ \Carbon\Carbon::parse($r->created_at)->diffForHumans() }}
    </div>

    @if (!empty($r->placed_at))
      {{-- The story is live at another place it names, so it reaches nearby
           readers while the exact spot waits for a person. --}}
      <div class="tried" style="background:#eef3fb;">
        <b>Live meanwhile at {{ $r->placed_at['name'] }}</b>
        <a href="https://www.google.com/maps/search/?api=1&query={{ $r->placed_at['lat'] }},{{ $r->placed_at['lng'] }}" target="_blank" rel="noopener" style="margin-left:6px;">map</a>
        <span style="color:#8aa4b8;">&mdash;
          @if (($r->placed_at['tier'] ?? '') === 'model_coordinates')
            the model's own coordinates, accepted because they fall inside the state, near the district, and reverse-look-up in the right town. Typically 1&ndash;3 km out.
          @else
            the story names it as {{ $r->placed_at['role'] }}{{ !empty($r->placed_at['why']) ? ': ' . $r->placed_at['why'] : '' }}.
          @endif
          Readers near it already see the story; an exact spot entered below replaces it.</span>
      </div>
    @endif
    @if (!empty($r->suggestions))
      {{-- Found inside the right state, but not this name - or not near the
           district. A person can tell in a second whether one of these is it. --}}
      <div class="tried" style="background:#eef7f0;">
        <b>Nearby in {{ $r->state_name }} &mdash; check before using:</b><br>
        @foreach ($r->suggestions as $sg)
          <span class="step">{{ $sg['src'] }}</span>
          <a href="https://www.google.com/maps/search/?api=1&query={{ $sg['lat'] }},{{ $sg['lng'] }}" target="_blank" rel="noopener">{{ $sg['label'] }}</a>
          <span style="color:#8aa4b8;">&mdash; {{ $sg['why'] }}</span>
          <button type="button" class="use-sg" data-lat="{{ $sg['lat'] }}" data-lng="{{ $sg['lng'] }}" data-for="{{ $r->id }}"
                  style="margin-left:6px;padding:1px 8px;font-size:0.72rem;border:1px solid #cfe6d5;border-radius:12px;background:#fff;cursor:pointer;">use these coordinates</button><br>
        @endforeach
      </div>
    @endif
    @if (!empty($r->attempts))
      <div class="tried">
        <b>Already tried, so you need not:</b><br>
        @foreach ($r->attempts as $a)
          <span class="step">{{ $a['step'] ?? '?' }}</span>
          &ldquo;{{ Str::limit($a['query'] ?? '', 60) }}&rdquo; &rarr; {{ $a['outcome'] ?? '' }}<br>
        @endforeach
      </div>
    @endif

    @if ($r->status === 'pending')
      <form method="POST" action="{{ route('admin.places.resolve', $r->id) }}">
        @csrf
        <div class="answer">
          <div>
            <label for="lat-{{ $r->id }}">Latitude</label>
            <input class="co" type="text" id="lat-{{ $r->id }}" name="lat" placeholder="4.19469" required>
          </div>
          <div>
            <label for="lng-{{ $r->id }}">Longitude</label>
            <input class="co" type="text" id="lng-{{ $r->id }}" name="lng" placeholder="100.66500" required>
          </div>
          <div>
            <label for="lb-{{ $r->id }}">Call it</label>
            <input class="lb" type="text" id="lb-{{ $r->id }}" name="label"
                   value="{{ Str::limit($r->place_text, 60, '') }}">
          </div>
          <div class="nt">
            <label for="nt-{{ $r->id }}">Note (optional)</label>
            <input type="text" id="nt-{{ $r->id }}" name="note" style="width:100%"
                   placeholder="how you identified it">
          </div>
          <button class="btn-place" type="submit">Place it</button>
        </div>
      </form>

      <p class="hint-find">
        To find the coordinates: open
        <a href="https://www.google.com/maps/search/{{ urlencode($r->place_text) }}"
           target="_blank" rel="noopener">Google Maps</a>,
        right-click the spot, and click the numbers at the top of the menu to copy them.
        Paste latitude in the first box, longitude in the second.
      </p>

      <form method="POST" action="{{ route('admin.places.dismiss', $r->id) }}" style="margin-top:8px;">
        @csrf
        <input type="hidden" name="note" value="not worth placing">
        <button class="btn-skip" type="submit">Not a real place &mdash; close it</button>
      </form>
    @elseif ($r->status === 'resolved')
      <div class="settled">
        Placed at {{ $r->lat }}, {{ $r->lng }} as &ldquo;{{ $r->resolved_label }}&rdquo;
        by {{ $r->resolved_by }}
        @if ($r->note)<span class="note">{{ $r->note }}</span>@endif
      </div>
    @else
      <div class="settled" style="color:#8a9aa6;">
        Closed by {{ $r->resolved_by }}
        @if ($r->note)<span class="note">{{ $r->note }}</span>@endif
      </div>
    @endif
  </div>
@empty
  <div class="none">
    @if ($status === 'pending')
      Nothing waiting. Every place a story has named was found.
    @else
      Nothing here yet.
    @endif
  </div>
@endforelse
@endif

<script>
  document.querySelectorAll('.use-sg').forEach(function (b) {
    b.addEventListener('click', function () {
      var id = b.dataset.for;
      document.getElementById('lat-' + id).value = b.dataset.lat;
      document.getElementById('lng-' + id).value = b.dataset.lng;
      document.getElementById('lat-' + id).focus();
    });
  });
</script>
@endsection
