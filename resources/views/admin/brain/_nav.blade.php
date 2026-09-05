{{-- The section follows the pipeline, because the pipeline is the thing being
     explained: news is acquired, then judged, then published and corrected.

     A row per stage, with the stage name in its own column. Flowing them all
     into one wrapping line put "3 · published" at the start of a second row,
     detached from the pills it labels, which read as a jumble rather than three
     stages.

     Grouping it this way answers a question the old layout could not. A reader's
     post is acquired differently from a scraped story - a form rather than a
     feed - but from the moment it arrives it is judged by the same playbook, the
     same rules and the same case studies. It plugs into stage two. Nothing in
     stage two needs to know which door a story came through. --}}
<nav class="brainnav" aria-label="Resource centre">
  {{-- Whose news: Official is gathered from publishers and runs through the
       pipeline below; Unofficial is sent in by readers and waits for a person.
       Owner, 4 Sep 2026: the reader desk lives here, at the very top. --}}
  @php($desk = request()->routeIs('admin.contributions.*') ? 'unofficial' : (request()->routeIs('admin.marketplace.*') ? 'marketplace' : (request()->routeIs('admin.members.*') ? 'members' : (request()->routeIs('admin.admins.*') ? 'admins' : 'official'))))
  <div class="desktabs" role="tablist" aria-label="Desks">
    <a class="{{ $desk === 'official' ? 'on' : '' }}" href="{{ route('admin.brain.index') }}"><b>Official</b><small>from publishers</small></a>
    <a class="{{ $desk === 'unofficial' ? 'on' : '' }}" href="{{ route('admin.contributions.index') }}"><b>Unofficial</b><small>sent in by readers</small></a>
    <a class="{{ $desk === 'marketplace' ? 'on' : '' }}" href="{{ route('admin.marketplace.index') }}"><b>Marketplace</b><small>local services</small></a>
    <a class="{{ $desk === 'members' ? 'on' : '' }}" href="{{ route('admin.members.index') }}"><b>Members</b><small>reader accounts</small></a>
    <a class="{{ $desk === 'admins' ? 'on' : '' }}" href="{{ route('admin.admins.index') }}"><b>Admin</b><small>who runs this</small></a>
    <span class="desk-actions">
      <a class="add" href="{{ route('admin.post.create', ['kind' => 'official']) }}">+ Add official news</a>
      <a class="add" href="{{ route('admin.post.create', ['kind' => 'unofficial']) }}">+ Add unofficial news</a>
    </span>
  </div>
  <style>
    .brainnav .desktabs { grid-column: 1 / -1; display: flex; flex-wrap: wrap; align-items: stretch; gap: 8px; padding-bottom: 12px; margin-bottom: 4px; border-bottom: 1px solid #e2edf6; }
    .brainnav .desktabs > a { display: flex; flex-direction: column; justify-content: center; gap: 2px; min-width: 128px; padding: 8px 16px; border-radius: 14px; border: 1px solid #e2edf6; background: #f2f7fb; color: #1f5679; text-decoration: none; white-space: nowrap; }
    .brainnav .desktabs > a b { font-size: 0.92rem; font-weight: 700; }
    .brainnav .desktabs > a small { font-size: 0.72rem; color: #5f7f9a; font-weight: 400; }
    .brainnav .desktabs > a:hover { border-color: #9fc4dc; }
    .brainnav .desktabs > a.on { background: #1c5a7f; border-color: #1c5a7f; color: #fff; }
    .brainnav .desktabs > a.on small { color: #cfe3f1; }
    .brainnav .desk-actions { margin-left: auto; display: flex; gap: 8px; align-items: center; flex-wrap: wrap; }
    .brainnav .desk-actions a.add { padding: 8px 14px; border-radius: 999px; background: #fff; border: 1px solid #1c5a7f; color: #1c5a7f; font-weight: 600; font-size: 0.82rem; text-decoration: none; white-space: nowrap; }
    .brainnav .desk-actions a.add:hover { background: #1c5a7f; color: #fff; }
  </style>
  <div class="homerow">
  <a class="home {{ request()->routeIs('admin.brain.index') ? 'on' : '' }}"
     href="{{ route('admin.brain.index') }}">Overview</a>

  {{-- Which country the centre is looking at. "All" is the site as a whole;
       a country is its own prompt, playbook, rules and corrections. Owner:
       "each country need different processing prompt ... Better each
       country have different settings at once." --}}
  @php($brainCountry = \App\Support\BrainCountry::current())
  <form method="get" action="" class="country-switch" title="Which country's settings and figures to show">
    @foreach(request()->except('country') as $k => $v)
      @if(is_scalar($v))<input type="hidden" name="{{ $k }}" value="{{ $v }}">@endif
    @endforeach
    <select name="country" onchange="this.form.submit()" aria-label="Country">
      <option value="ALL" {{ $brainCountry === 'ALL' ? 'selected' : '' }}>All countries</option>
      @foreach(\App\Support\BrainCountry::options() as $code => $name)
        <option value="{{ $code }}" {{ $brainCountry === $code ? 'selected' : '' }}>{{ $name }}</option>
      @endforeach
    </select>
  </form>
  </div>

  <span class="navgroup"><b>1</b> Acquisition</span>
  <span class="navlinks">
    <a href="{{ route('admin.sources.countries') }}" class="{{ request()->routeIs('admin.sources.*') ? 'on' : '' }}">Sources</a>
    <a href="{{ route('admin.brain.daily') }}" class="{{ request()->routeIs('admin.brain.daily') ? 'on' : '' }}">Daily report</a>
    <a href="{{ route('admin.brain.constraints') }}" class="{{ request()->routeIs('admin.brain.constraints') ? 'on' : '' }}">What they permit</a>
    <a href="{{ route('admin.countries.index', request()->only('view')) }}" class="{{ request()->routeIs('admin.countries.*') ? 'on' : '' }}">Countries</a>
  </span>

  <span class="navgroup"><b>2</b> Processing</span>
  <span class="navlinks">
    <a href="{{ route('admin.brain.ai') }}" class="{{ request()->routeIs('admin.brain.ai') ? 'on' : '' }}">AI models</a>
    <a href="{{ route('admin.brain.prompt') }}" class="{{ request()->routeIs('admin.brain.prompt') ? 'on' : '' }}">The prompt</a>
    <a href="{{ route('admin.brain.playbook') }}" class="{{ request()->routeIs('admin.brain.playbook') ? 'on' : '' }}">Playbook</a>
    <a href="{{ route('admin.brain.taxonomy') }}" class="{{ request()->routeIs('admin.brain.taxonomy') ? 'on' : '' }}">Taxonomy</a>
    <a href="{{ route('admin.rules.index') }}" class="{{ request()->routeIs('admin.rules.*') ? 'on' : '' }}">Rules</a>
    <a href="{{ route('admin.cases.index') }}" class="{{ request()->routeIs('admin.cases.*') ? 'on' : '' }}">Case studies</a>
    <a href="{{ route('admin.brain.briefing') }}" class="{{ request()->routeIs('admin.brain.briefing') ? 'on' : '' }}">Briefing</a>
    <a href="{{ route('admin.places.index') }}" class="{{ request()->routeIs('admin.places.*') ? 'on' : '' }}">Places to check</a>
  </span>

  <span class="navgroup"><b>3</b> Published</span>
  <span class="navlinks">
    <a href="{{ route('admin.brain.live') }}" class="{{ request()->routeIs('admin.brain.live') ? 'on' : '' }}">Live</a>
    <a href="{{ route('admin.brain.bench') }}" class="{{ request()->routeIs('admin.brain.bench') ? 'on' : '' }}">Check answers</a>
    <a href="{{ route('admin.brain.translations') }}" class="{{ request()->routeIs('admin.brain.translations') ? 'on' : '' }}">Translations</a>
    <a href="{{ route('admin.brain.corrections') }}" class="{{ request()->routeIs('admin.brain.corrections') ? 'on' : '' }}">Corrections</a>
    <a href="{{ route('admin.removals.index') }}" class="{{ request()->routeIs('admin.removals.*') ? 'on' : '' }}">Removed</a>
    <a href="{{ route('admin.community.index') }}" class="{{ request()->routeIs('admin.community.*') ? 'on' : '' }}">Community</a>
  </span>
</nav>
