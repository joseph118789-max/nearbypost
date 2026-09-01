{{-- One bar across every resource-centre page.

     Grouped, because ten links in a row is a wall. The grouping is the argument
     the section makes: everything here either tells the AI what to do, tells it
     where to find the news, or tells us whether it worked. A page that fits
     none of those three does not belong in here. --}}
<div class="brainnav">
  <a href="{{ route('admin.brain.index') }}" class="{{ request()->routeIs('admin.brain.index') ? 'on' : '' }}">Overview</a>

  <span class="navgroup">what it is told</span>
  <a href="{{ route('admin.brain.prompt') }}" class="{{ request()->routeIs('admin.brain.prompt') ? 'on' : '' }}">The prompt</a>
  <a href="{{ route('admin.brain.playbook') }}" class="{{ request()->routeIs('admin.brain.playbook') ? 'on' : '' }}">Playbook</a>
  <a href="{{ route('admin.rules.index') }}" class="{{ request()->routeIs('admin.rules.*') ? 'on' : '' }}">Rules</a>
  <a href="{{ route('admin.cases.index') }}" class="{{ request()->routeIs('admin.cases.*') ? 'on' : '' }}">Case studies</a>
  <a href="{{ route('admin.brain.briefing') }}" class="{{ request()->routeIs('admin.brain.briefing') ? 'on' : '' }}">Malaysia briefing</a>

  <span class="navgroup">where news comes from</span>
  <a href="{{ route('admin.sources.countries') }}" class="{{ request()->routeIs('admin.sources.*') ? 'on' : '' }}">Sources</a>

  <span class="navgroup">whether it worked</span>
  <a href="{{ route('admin.brain.bench') }}" class="{{ request()->routeIs('admin.brain.bench') ? 'on' : '' }}">Bench</a>
  <a href="{{ route('admin.brain.corrections') }}" class="{{ request()->routeIs('admin.brain.corrections') ? 'on' : '' }}">Corrections</a>
  <a href="{{ route('admin.removals.index') }}" class="{{ request()->routeIs('admin.removals.*') ? 'on' : '' }}">Removed</a>
</div>
