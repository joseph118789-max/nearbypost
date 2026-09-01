{{-- The section follows the pipeline, because the pipeline is the thing being
     explained: news is acquired, then judged, then published and corrected.

     Grouping it this way answers a question the old layout could not. A reader's
     post is acquired differently from a scraped story - a form rather than a
     feed - but from the moment it arrives it is judged by the same playbook,
     the same rules and the same case studies. It plugs into stage two. Nothing
     in stage two needs to know which door a story came through. --}}
<div class="brainnav">
  <a href="{{ route('admin.brain.index') }}" class="{{ request()->routeIs('admin.brain.index') ? 'on' : '' }}">Overview</a>

  <span class="navgroup">1 · acquisition</span>
  <a href="{{ route('admin.sources.countries') }}" class="{{ request()->routeIs('admin.sources.*') ? 'on' : '' }}">Sources</a>

  <span class="navgroup">2 · processing</span>
  <a href="{{ route('admin.brain.prompt') }}" class="{{ request()->routeIs('admin.brain.prompt') ? 'on' : '' }}">The prompt</a>
  <a href="{{ route('admin.brain.playbook') }}" class="{{ request()->routeIs('admin.brain.playbook') ? 'on' : '' }}">Playbook</a>
  <a href="{{ route('admin.rules.index') }}" class="{{ request()->routeIs('admin.rules.*') ? 'on' : '' }}">Rules</a>
  <a href="{{ route('admin.cases.index') }}" class="{{ request()->routeIs('admin.cases.*') ? 'on' : '' }}">Case studies</a>
  <a href="{{ route('admin.brain.briefing') }}" class="{{ request()->routeIs('admin.brain.briefing') ? 'on' : '' }}">Malaysia briefing</a>

  <span class="navgroup">3 · published</span>
  <a href="{{ route('admin.brain.bench') }}" class="{{ request()->routeIs('admin.brain.bench') ? 'on' : '' }}">Bench</a>
  <a href="{{ route('admin.brain.corrections') }}" class="{{ request()->routeIs('admin.brain.corrections') ? 'on' : '' }}">Corrections</a>
  <a href="{{ route('admin.removals.index') }}" class="{{ request()->routeIs('admin.removals.*') ? 'on' : '' }}">Removed</a>
</div>
