{{-- The sponsor's player, under the controls in the left column.

     Desktop only. On a phone the column is the whole screen, and a video player
     between a reader and the news they came for is not a sponsorship, it is an
     obstacle - so the markup is not rendered there at all rather than hidden,
     which also keeps the YouTube script off every phone page load.

     Nothing loads from YouTube until somebody presses play. A news page should
     not cost a reader a third-party player they never asked for. --}}
<section class="music" id="music" aria-labelledby="music-title" hidden>
  <p class="music-sponsor" id="music-title">
    {{ __('site.music_sponsor') }}
  </p>

  <div class="music-picks">
    <label class="visually-hidden" for="music-lang">{{ __('site.music_language') }}</label>
    <select class="pick" id="music-lang">
      <option value="">{{ __('site.music_any_language') }}</option>
      <option value="mandarin">{{ __('site.lang_mandarin') }}</option>
      <option value="english">{{ __('site.lang_english') }}</option>
      <option value="malay">{{ __('site.lang_malay') }}</option>
      <option value="cantonese">{{ __('site.lang_cantonese') }}</option>
    </select>

    <label class="visually-hidden" for="music-role">{{ __('site.music_for') }}</label>
    <select class="pick" id="music-role">
      <option value="">{{ __('site.music_anyone') }}</option>
      <option value="agent">{{ __('site.role_agent') }}</option>
      <option value="team-leader">{{ __('site.role_team_leader') }}</option>
      <option value="boss">{{ __('site.role_boss') }}</option>
      <option value="admin">{{ __('site.role_admin') }}</option>
      <option value="student">{{ __('site.role_student') }}</option>
    </select>
  </div>

  <div class="music-stage" id="music-stage">
    <button class="music-start" type="button" id="music-start">
      <span class="music-play-icon" aria-hidden="true">&#9654;</span>
      <span id="music-start-label">{{ __('site.music_play') }}</span>
    </button>
  </div>

  <p class="music-now" id="music-now"></p>

  <div class="music-bar">
    <button class="music-btn" type="button" id="music-prev" aria-label="{{ __('site.music_previous') }}" title="{{ __('site.music_previous') }}">
      <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M4.6 2.4h1.7v11.2H4.6z"/><path d="M13.4 3.1v9.8L5.9 8z"/></svg>
    </button>
    <button class="music-btn" type="button" id="music-next" aria-label="{{ __('site.music_next') }}" title="{{ __('site.music_next') }}">
      <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false"><path d="M9.7 2.4h1.7v11.2H9.7z"/><path d="M2.6 3.1v9.8L10.1 8z"/></svg>
    </button>

    <span class="music-vol">
      <button class="music-btn music-btn-icon" type="button" id="music-mute"
              aria-label="{{ __('site.music_mute') }}" title="{{ __('site.music_mute') }}" aria-pressed="false">
        <svg viewBox="0 0 16 16" aria-hidden="true" focusable="false" id="music-vol-icon">
          <path d="M3 6h2.4L8.6 3v10L5.4 10H3z"/>
          <g id="music-vol-waves"><path d="M10.5 5.6a3.4 3.4 0 0 1 0 4.8" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/><path d="M12.4 3.9a6 6 0 0 1 0 8.2" fill="none" stroke="currentColor" stroke-width="1.3" stroke-linecap="round"/></g>
          <g id="music-vol-cross" style="display:none"><path d="M10.6 6.1l3.4 3.8M14 6.1l-3.4 3.8" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></g>
        </svg>
      </button>
      <input class="music-range" type="range" id="music-volume" min="0" max="100" step="1" value="60"
             aria-label="{{ __('site.music_volume') }}" title="{{ __('site.music_volume') }}">
    </span>

    <a class="music-link" href="https://www.listingmine.com/music/songs" target="_blank" rel="noopener">{{ __('site.music_channel') }} &#8599;</a>
  </div>
</section>

<script>
  (function () {
    var panel = document.getElementById('music');
    if (!panel) { return; }

    // Desktop only, decided here rather than in CSS: hidden markup would still
    // pull the YouTube player onto a phone the moment anybody tapped it.
    var wide = window.matchMedia('(min-width: 1024px)');
    if (!wide.matches) { return; }

    panel.hidden = false;

    var stage = document.getElementById('music-stage');
    var start = document.getElementById('music-start');
    var now = document.getElementById('music-now');
    var langPick = document.getElementById('music-lang');
    var rolePick = document.getElementById('music-role');

    var queue = [];
    var at = 0;
    var player = null;
    var apiReady = false;
    var pendingPlay = false;

    function label(track) {
      return track ? track.title + (track.album ? ' · ' + track.album : '') : '';
    }

    function fetchQueue() {
      var params = new URLSearchParams();
      if (langPick.value) { params.set('lang', langPick.value); }
      if (rolePick.value) { params.set('role', rolePick.value); }

      return fetch('{{ route('music.tracks') }}?' + params.toString(), { headers: { 'Accept': 'application/json' } })
        .then(function (r) { return r.ok ? r.json() : { tracks: [] }; })
        .then(function (data) {
          queue = data.tracks || [];
          at = 0;

          if (queue.length === 0) {
            now.textContent = @json(__('site.music_none'));
          }

          return queue.length;
        })
        .catch(function () { queue = []; return 0; });
    }

    // The API script is fetched the first time somebody presses play, and never
    // otherwise. onYouTubeIframeAPIReady is a global YouTube insists on.
    function loadApi() {
      if (window.YT && window.YT.Player) { apiReady = true; return Promise.resolve(); }

      return new Promise(function (resolve) {
        window.onYouTubeIframeAPIReady = function () { apiReady = true; resolve(); };

        var tag = document.createElement('script');
        tag.src = 'https://www.youtube.com/iframe_api';
        document.head.appendChild(tag);
      });
    }

    function mount() {
      var host = document.createElement('div');
      host.id = 'music-frame';
      stage.innerHTML = '';
      stage.appendChild(host);

      player = new YT.Player('music-frame', {
        host: 'https://www.youtube-nocookie.com',
        videoId: queue[at].v,
        playerVars: { autoplay: 1, rel: 0, modestbranding: 1, playsinline: 1 },
        events: {
          onReady: function (e) { applyVolume(); e.target.playVideo(); },
          // One song ending starts the next, which is what makes this a station
          // rather than a video somebody has to keep clicking.
          onStateChange: function (e) { if (e.data === YT.PlayerState.ENDED) { step(1); } }
        }
      });

      now.textContent = label(queue[at]);
    }

    function step(by) {
      if (queue.length === 0) { return; }

      at = (at + by + queue.length) % queue.length;
      now.textContent = label(queue[at]);

      if (player && player.loadVideoById) {
        player.loadVideoById(queue[at].v);
      }
    }

    function play() {
      if (pendingPlay) { return; }
      pendingPlay = true;
      start.disabled = true;
      document.getElementById('music-start-label').textContent = @json(__('site.music_loading'));

      Promise.all([queue.length ? Promise.resolve(queue.length) : fetchQueue(), loadApi()])
        .then(function () {
          pendingPlay = false;

          if (queue.length === 0) {
            start.disabled = false;
            document.getElementById('music-start-label').textContent = @json(__('site.music_play'));
            return;
          }

          mount();
        });
    }

    start.addEventListener('click', play);
    document.getElementById('music-prev').addEventListener('click', function () { step(-1); });
    document.getElementById('music-next').addEventListener('click', function () { step(1); });

    // ── volume ──────────────────────────────────────────────────────────
    //
    // The slider and the mute button work before anything is playing, and
    // whatever they are set to is applied the moment the player mounts - a
    // volume control that only works after the music starts is a control people
    // reach for at exactly the wrong moment.
    //
    // The setting is remembered per browser. Storage can be unavailable or
    // throw outright (private windows, blocked site data), so every read and
    // write is guarded and the default stands when it fails.
    var volume = 60;
    var muted  = false;

    try {
      var savedVol = window.localStorage.getItem('nbp-music-volume');
      var savedMute = window.localStorage.getItem('nbp-music-muted');

      if (savedVol !== null && !isNaN(parseInt(savedVol, 10))) {
        volume = Math.max(0, Math.min(100, parseInt(savedVol, 10)));
      }

      muted = savedMute === '1';
    } catch (e) { /* defaults stand */ }

    function remember() {
      try {
        window.localStorage.setItem('nbp-music-volume', String(volume));
        window.localStorage.setItem('nbp-music-muted', muted ? '1' : '0');
      } catch (e) { /* not worth failing a click over */ }
    }

    function applyVolume() {
      if (!player || !player.setVolume) { return; }

      player.setVolume(volume);

      // Muting and setting the volume to zero are different states to YouTube,
      // and only one of them survives the next track.
      if (muted || volume === 0) {
        if (player.mute) { player.mute(); }
      } else if (player.unMute) {
        player.unMute();
      }
    }

    function paintVolume() {
      var off = muted || volume === 0;

      volIcon.setAttribute('aria-pressed', off ? 'true' : 'false');
      // .hidden belongs to HTMLElement. Setting it on an SVG <g> creates a
      // meaningless JS property and changes nothing on screen - which is how
      // the speaker came to show its sound waves and its mute cross at once.
      document.getElementById('music-vol-waves').style.display = off ? 'none' : '';
      document.getElementById('music-vol-cross').style.display = off ? '' : 'none';

      var label = off ? @json(__('site.music_unmute')) : @json(__('site.music_mute'));
      volIcon.setAttribute('aria-label', label);
      volIcon.setAttribute('title', label);

      if (String(volume) !== slider.value) { slider.value = String(volume); }
    }

    var volIcon = document.getElementById('music-mute');
    var slider  = document.getElementById('music-volume');

    slider.addEventListener('input', function () {
      volume = parseInt(slider.value, 10) || 0;

      // Dragging the slider up from silence is an unmistakable request to hear
      // something, so it lifts the mute rather than moving a muted volume.
      if (volume > 0) { muted = false; }

      applyVolume();
      paintVolume();
      remember();
    });

    volIcon.addEventListener('click', function () {
      muted = !(muted || volume === 0);

      // Un-muting from a slider sitting at zero would still be silent.
      if (!muted && volume === 0) { volume = 40; }

      applyVolume();
      paintVolume();
      remember();
    });

    paintVolume();

    // Changing a filter builds a new list. If something is already playing it
    // switches to the new list at once - a filter that needs a second button
    // press is a filter people think is broken.
    [langPick, rolePick].forEach(function (pick) {
      pick.addEventListener('change', function () {
        fetchQueue().then(function (n) {
          if (n === 0) { return; }

          if (player && player.loadVideoById) {
            player.loadVideoById(queue[0].v);
            now.textContent = label(queue[0]);
          }
        });
      });
    });
  })();
</script>
