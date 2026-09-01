{{-- One bar across every resource-centre page. The parts only make sense
     together: a rule is worth writing when the corrections say the same mistake
     keeps happening, and a playbook edit is worth keeping when the bench says
     it scored better afterwards. --}}
<div class="brainnav">
  @php($tabs = [
    'admin.brain.index'       => 'Overview',
    'admin.brain.prompt'      => 'The prompt',
    'admin.brain.playbook'    => 'Playbook',
    'admin.brain.briefing'    => 'Malaysia briefing',
    'admin.brain.bench'       => 'Bench',
    'admin.brain.corrections' => 'Corrections',
  ])
  @foreach($tabs as $route => $label)
    <a href="{{ route($route) }}" class="{{ request()->routeIs($route) ? 'on' : '' }}">{{ $label }}</a>
  @endforeach
</div>
