@extends('layouts.public')
@php $wide = true; @endphp
@section('main')
  <section class="profile-page">
    <h1>{{ __('site.following_settings') }}</h1>
    @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif

    <h2>{{ __('site.notifications') }}</h2>
    <form method="post" action="{{ route('community.follows.mode') }}" class="row">
      @csrf
      <select class="modal-input" name="mode">
        @foreach(['immediate' => __('site.notify_immediate'), 'daily' => __('site.notify_daily'), 'none' => __('site.notify_none')] as $k => $label)
          <option value="{{ $k }}" {{ $mode === $k ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
      </select>
      <button class="desktop-action-btn" type="submit">{{ __('site.save') }}</button>
    </form>

    <h2>{{ __('site.areas') }}</h2>
    @foreach($areas as $a)
      <div class="row-item"><b>{{ $a->label }}</b> <span class="mini">{{ $a->radius_km }} km @if($a->category)· {{ $a->category }}@endif @if($a->paused)· {{ __('site.paused') }}@endif</span>
        <form method="post" action="{{ route('community.follows.area.action', ['follow' => $a->id, 'action' => $a->paused ? 'resume' : 'pause']) }}" class="inline">@csrf<button class="chip-btn" type="submit">{{ $a->paused ? __('site.resume') : __('site.pause') }}</button></form>
        <form method="post" action="{{ route('community.follows.area.action', ['follow' => $a->id, 'action' => 'delete']) }}" class="inline">@csrf<button class="chip-btn" type="submit">{{ __('site.unfollow') }}</button></form>
      </div>
    @endforeach
    <details class="inline-details"><summary class="chip-btn">{{ __('site.follow_an_area') }}</summary>
      <form method="post" action="{{ route('community.follows.area') }}" class="grid">
        @csrf
        <div id="areaMap" class="pin-map"></div>
        <input class="modal-input" name="label" id="areaLabel" placeholder="{{ __('site.area_label') }}" required maxlength="120">
        <input type="hidden" name="lat" id="areaLat"><input type="hidden" name="lng" id="areaLng">
        <label>{{ __('site.radius') }} <input class="modal-input" type="number" name="radius_km" value="5" min="1" max="50" step="1"> km</label>
        <select class="modal-input" name="category"><option value="">{{ __('site.any_topic') }}</option>@foreach($allCategories as $cat)<option value="{{ $cat['name'] ?? $cat }}">{{ $cat['label'] ?? $cat['name'] ?? $cat }}</option>@endforeach</select>
        <button class="desktop-action-btn primary" type="submit">{{ __('site.follow') }}</button>
      </form>
    </details>

    <h2>{{ __('site.topics') }}</h2>
    @foreach($topics as $t)
      <div class="row-item"><b>{{ $t->category }}</b> @if($t->paused)<span class="mini">{{ __('site.paused') }}</span>@endif
        <form method="post" action="{{ route('community.follows.topic.action', ['follow' => $t->id, 'action' => $t->paused ? 'resume' : 'pause']) }}" class="inline">@csrf<button class="chip-btn" type="submit">{{ $t->paused ? __('site.resume') : __('site.pause') }}</button></form>
        <form method="post" action="{{ route('community.follows.topic.action', ['follow' => $t->id, 'action' => 'delete']) }}" class="inline">@csrf<button class="chip-btn" type="submit">{{ __('site.unfollow') }}</button></form>
      </div>
    @endforeach
    <form method="post" action="{{ route('community.follows.topic') }}" class="row">
      @csrf
      <select class="modal-input" name="category" required>@foreach($allCategories as $cat)<option value="{{ $cat['name'] ?? $cat }}">{{ $cat['label'] ?? $cat['name'] ?? $cat }}</option>@endforeach</select>
      <button class="desktop-action-btn" type="submit">{{ __('site.follow') }}</button>
    </form>

    <h2>{{ __('site.people') }}</h2>
    @forelse($people as $p)<p><a href="{{ url('/@' . $p->username) }}">{{ '@' . $p->username }}</a> <span class="mini">{{ $p->display_name }}</span></p>@empty<p class="mini">{{ __('site.nobody_yet') }}</p>@endforelse
  </section>
  <style>
    .profile-page { max-width: 760px; margin: 0 auto; } .row { display:flex; gap:8px; align-items:center; margin:8px 0 18px; } .grid { display:grid; gap:8px; max-width:560px; margin-top:8px; }
    .row-item { display:flex; gap:10px; align-items:center; flex-wrap:wrap; padding:8px 0; border-bottom:1px solid #e6e9ee; } .inline { display:inline; } .mini { color:#5b6473; font-size:.86em; }
    .pin-map { height: 260px; border-radius: 12px; border: 1px solid #dfe3ea; } .chip-btn { padding:6px 12px; border-radius:999px; border:1px solid #cfe0ec; background:#fff; color:#1c5a7f; font-weight:600; cursor:pointer; }
  </style>
@endsection
@section('after')
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<script>
(function(){ var el=document.getElementById('areaMap'); if(!el||!window.L) return; var map=L.map('areaMap').setView([3.139,101.687],11);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'&copy; OpenStreetMap'}).addTo(map); var marker=null;
  function set(lat,lng){ document.getElementById('areaLat').value=lat.toFixed(6); document.getElementById('areaLng').value=lng.toFixed(6); if(marker) marker.setLatLng([lat,lng]); else marker=L.marker([lat,lng]).addTo(map);
    fetch('{{ route('community.reverse') }}?lat='+lat+'&lng='+lng,{headers:{'Accept':'application/json'}}).then(r=>r.json()).then(d=>{ if(d&&d.label&&!document.getElementById('areaLabel').value) document.getElementById('areaLabel').value=d.label; }).catch(()=>{}); }
  map.on('click',function(e){ set(e.latlng.lat,e.latlng.lng); });
  if(navigator.geolocation) navigator.geolocation.getCurrentPosition(function(p){ map.setView([p.coords.latitude,p.coords.longitude],13); set(p.coords.latitude,p.coords.longitude); },function(){});
  document.querySelector('details').addEventListener('toggle',function(){ setTimeout(function(){ map.invalidateSize(); },50); });
})();
</script>
@endsection
