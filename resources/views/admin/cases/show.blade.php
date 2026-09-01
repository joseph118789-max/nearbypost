@extends('layouts.admin')
@section('title', $case->title)
@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
<style>
  .places { width:100%;border-collapse:collapse;background:#fff;border:1px solid #e2edf6;
            border-radius:18px;overflow:hidden;font-size:0.85rem;margin-bottom:14px; }
  .places th { text-align:left;padding:11px 14px;background:#f8fafc;color:#5f7f9a;
               font-size:0.7rem;text-transform:uppercase;letter-spacing:0.04em; }
  .places td { padding:11px 14px;border-top:1px solid #eff3f9; }
  .places tr.unsure { background:#fffbeb; }
  .warn-box { background:#fffbeb; border-left:3px solid #d97706; padding:10px 12px; }
  .places tr.lost { background:#fff5f3; }
  .inline { display:inline; }
</style>
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

  <a class="back" href="{{ route('admin.cases.index') }}">&larr; Case studies</a>

  <h1>{{ $case->title }}</h1>

  @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="warn">{{ $errors->first() }}</div>@endif

  <div class="srccard">
    <div class="srcmeta" style="margin-bottom:10px;">
      @if($case->status === 'active')
        <span class="badge badge-ok">live at {{ $served }} location(s)</span>
      @else
        <span class="badge badge-warn">draft &mdash; not published</span>
      @endif
      &nbsp;Written {{ $case->created_at?->diffForHumans() }}.
    </div>
    <div style="font-size:0.9rem;line-height:1.7;color:#34505f;white-space:pre-wrap;">{{ $case->body }}</div>
  </div>

  <h2>Where it applies ({{ count($locations) }})</h2>

  {{-- The whole point of this screen. A model asked for a chain's branches
       produces a plausible list containing branches that closed and branches
       that never existed, so this list is a draft to be corrected, never a
       result to be accepted. --}}
  @if($case->outlet_scale === 'national')
    <p class="lede warn-box">
      <strong>The model read this as national news.</strong> It affects the whole country rather
      than a set of premises, so no places were added and none should be: pinning it to a map
      would push it at every reader as though it were happening on their own street. Publish it
      with no location and it will be found under its topic instead.
      @if($case->outlet_note)<br><em>{{ $case->outlet_note }}</em>@endif
    </p>
  @else
  <p class="lede">
    @if($case->outlet_note)<strong>The model's own caveat:</strong> <em>{{ $case->outlet_note }}</em><br>@endif
    <strong>Check every line.</strong> These were suggested by the model and it will have got some
    of them wrong &mdash; branches that have closed, branches in the wrong town, branches that
    never existed. Anything marked <em>unsure</em> is the model's own doubt. Delete what does not
    belong before publishing; this site's name goes on whatever is left.
  </p>
  @endif

  <table class="places">
    <thead><tr><th>Place</th><th>Added by</th><th>On the map</th><th></th></tr></thead>
    <tbody>
      @forelse($locations as $place)
        <tr class="{{ $place->geocode_status === 'not_found' ? 'lost' : ($place->ai_confident === false ? 'unsure' : '') }}">
          <td>
            {{ $place->label }}
            @if($place->ai_confident === false)
              <span class="badge badge-warn">model unsure</span>
            @endif
          </td>
          <td class="dim">{{ $place->added_by === 'manual' ? 'you' : 'model' }}</td>
          <td>
            @if($place->lat !== null)
              <span class="badge badge-ok">{{ round($place->lat, 3) }}, {{ round($place->lng, 3) }}</span>
            @elseif($place->geocode_status === 'not_found')
              <span class="badge badge-bad">not found</span>
            @else
              <span class="badge">not placed yet</span>
            @endif
          </td>
          <td style="text-align:right;">
            <form class="inline" method="post"
                  action="{{ route('admin.cases.places.remove', ['id' => $case->id, 'placeId' => $place->id]) }}">
              @csrf @method('DELETE')
              <button class="btn" type="submit" style="font-size:0.74rem;padding:4px 12px;">Remove</button>
            </form>
          </td>
        </tr>
      @empty
        <tr><td colspan="4" class="dim">No places yet. Add them below.</td></tr>
      @endforelse
    </tbody>
  </table>

  <div class="srccard">
    <div class="srcrow">
      <form method="post" action="{{ route('admin.cases.places.add', ['id' => $case->id]) }}"
            style="display:flex;gap:8px;flex:1 1 320px;">
        @csrf
        <input class="inp" type="text" name="label" maxlength="190" required
               placeholder="Add a place: e.g. Bukit Bintang, Kuala Lumpur">
        <button class="btn" type="submit">Add</button>
      </form>

      <form method="post" action="{{ route('admin.cases.geocode', ['id' => $case->id]) }}">
        @csrf
        <button class="btn" type="submit">Put them on the map</button>
      </form>
    </div>
    <p class="hint" style="margin-top:10px;">
      Placing them takes about a second each, so a long list is not instant. Only places on the map
      can be served.
    </p>
  </div>

  <h2>Publish</h2>

  <form class="srccard" method="post" action="{{ route('admin.cases.publish', ['id' => $case->id]) }}">
    @csrf
    <label class="lbl" for="cat">Topic</label>
    <p class="hint">Which topic readers will find it under.</p>
    <input class="inp" id="cat" type="text" name="primary_category" maxlength="60" required
           value="{{ old('primary_category', $case->primary_category ?: 'business & corporate') }}">

    <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
      <button class="btn btn-primary" type="submit">
        {{ $case->status === 'active' ? 'Republish with these places' : 'Publish' }}
      </button>
    </div>
    <p class="hint" style="margin-top:8px;">
      Publishing replaces whatever was served before, so a place you deleted stops being served.
    </p>
  </form>

  @if($case->status === 'active')
    <form method="post" action="{{ route('admin.cases.unpublish', ['id' => $case->id]) }}"
          onsubmit="return confirm('Take this down from every location?');">
      @csrf
      <button class="btn btn-danger" type="submit">Take down everywhere</button>
    </form>
  @endif
</div>
@endsection
