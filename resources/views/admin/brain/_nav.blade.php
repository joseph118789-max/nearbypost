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
  <a class="home {{ request()->routeIs('admin.brain.index') ? 'on' : '' }}"
     href="{{ route('admin.brain.index') }}">Overview</a>

  <span class="navgroup"><b>1</b> Acquisition</span>
  <span class="navlinks">
    <a href="{{ route('admin.sources.countries') }}" class="{{ request()->routeIs('admin.sources.*') ? 'on' : '' }}">Sources</a>
  </span>

  <span class="navgroup"><b>2</b> Processing</span>
  <span class="navlinks">
    <a href="{{ route('admin.brain.prompt') }}" class="{{ request()->routeIs('admin.brain.prompt') ? 'on' : '' }}">The prompt</a>
    <a href="{{ route('admin.brain.playbook') }}" class="{{ request()->routeIs('admin.brain.playbook') ? 'on' : '' }}">Playbook</a>
    <a href="{{ route('admin.rules.index') }}" class="{{ request()->routeIs('admin.rules.*') ? 'on' : '' }}">Rules</a>
    <a href="{{ route('admin.cases.index') }}" class="{{ request()->routeIs('admin.cases.*') ? 'on' : '' }}">Case studies</a>
    <a href="{{ route('admin.brain.briefing') }}" class="{{ request()->routeIs('admin.brain.briefing') ? 'on' : '' }}">Malaysia briefing</a>
  </span>

  <span class="navgroup"><b>3</b> Published</span>
  <span class="navlinks">
    <a href="{{ route('admin.brain.bench') }}" class="{{ request()->routeIs('admin.brain.bench') ? 'on' : '' }}">Bench</a>
    <a href="{{ route('admin.brain.corrections') }}" class="{{ request()->routeIs('admin.brain.corrections') ? 'on' : '' }}">Corrections</a>
    <a href="{{ route('admin.removals.index') }}" class="{{ request()->routeIs('admin.removals.*') ? 'on' : '' }}">Removed</a>
  </span>
</nav>
