{{-- Leaflet and the pin script for the post form; included where the page's scripts go. --}}
@if(config('services.community.enabled'))
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
<style>
  .pin-box { margin: 6px 0 10px; }
  .pin-map { height: 260px; border-radius: 12px; border: 1px solid #dfe3ea; background: #eef2f6; }
  .pin-actions { display: flex; gap: 10px; align-items: center; margin-top: 6px; flex-wrap: wrap; }
  .chip-btn { padding: 6px 12px; border-radius: 999px; border: 1px solid #cfe0ec; background: #fff; color: #1c5a7f; font-weight: 600; cursor: pointer; }
</style>
<script>
(function () {
  var mapEl = document.getElementById('pinMap'); if (!mapEl || !window.L) return;
  var latEl = document.getElementById('pin_lat'), lngEl = document.getElementById('pin_lng');
  var status = document.getElementById('pinStatus'), placeEl = document.getElementById('place');
  var start = (latEl.value && lngEl.value) ? [parseFloat(latEl.value), parseFloat(lngEl.value)] : [3.139, 101.687];
  var map = L.map('pinMap', { zoomControl: true }).setView(start, latEl.value ? 16 : 11);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19, attribution: '&copy; OpenStreetMap' }).addTo(map);
  var marker = L.marker(start, { draggable: true }).addTo(map);
  var placeTouched = !!placeEl.value, gpsPoint = null, reverseTimer = null;

  function setPin(lat, lng, source, adjusted) {
    latEl.value = lat.toFixed(7); lngEl.value = lng.toFixed(7);
    if (source) document.getElementById('location_source').value = source;
    if (adjusted) document.getElementById('pin_adjusted').value = '1';
    marker.setLatLng([lat, lng]);
    clearTimeout(reverseTimer);
    reverseTimer = setTimeout(function () { reverse(lat, lng); }, 350);
  }
  function reverse(lat, lng) {
    fetch('{{ route('community.reverse') }}?lat=' + lat + '&lng=' + lng, { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (d && d.label) {
          document.getElementById('pin_label').value = d.label;
          if (!placeTouched) placeEl.value = d.label;
          status.textContent = d.label + (gpsPoint && document.getElementById('gps_accuracy').value ? ' · {{ __('site.pin_accuracy') }} ' + document.getElementById('gps_accuracy').value + ' m' : '');
        } else { status.textContent = '{{ __('site.pin_placed') }}'; }
      }).catch(function () { status.textContent = '{{ __('site.pin_placed') }}'; });
  }
  placeEl.addEventListener('input', function () { placeTouched = placeEl.value.trim() !== ''; });
  marker.on('dragend', function () { var p = marker.getLatLng(); setPin(p.lat, p.lng, null, true); });
  map.on('click', function (e) { setPin(e.latlng.lat, e.latlng.lng, latEl.value ? null : 'manual', true); });

  function locate() {
    if (!navigator.geolocation) { document.getElementById('location_source').value = 'unavailable'; status.textContent = '{{ __('site.pin_no_gps') }}'; return; }
    status.textContent = '{{ __('site.pin_locating') }}';
    navigator.geolocation.getCurrentPosition(function (pos) {
      gpsPoint = pos.coords;
      document.getElementById('gps_lat').value = pos.coords.latitude.toFixed(7);
      document.getElementById('gps_lng').value = pos.coords.longitude.toFixed(7);
      document.getElementById('gps_accuracy').value = Math.round(pos.coords.accuracy || 0);
      document.getElementById('pin_adjusted').value = '0';
      map.setView([pos.coords.latitude, pos.coords.longitude], 16);
      setPin(pos.coords.latitude, pos.coords.longitude, 'gps', false);
    }, function (err) {
      document.getElementById('location_source').value = err.code === 1 ? 'denied' : (err.code === 3 ? 'timeout' : 'unavailable');
      status.textContent = err.code === 1 ? '{{ __('site.pin_denied') }}' : '{{ __('site.pin_failed') }}';
    }, { enableHighAccuracy: true, timeout: 12000, maximumAge: 60000 });
  }
  document.getElementById('pinLocate').addEventListener('click', locate);
  if (!latEl.value) locate(); else reverse(parseFloat(latEl.value), parseFloat(lngEl.value));
})();
</script>
@endif
