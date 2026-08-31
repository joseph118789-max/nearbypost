{{-- How often this source is read, in words rather than minutes. --}}
@if($s->fetch_interval_minutes)
  @php
    $m = (int) $s->fetch_interval_minutes;
    $label = $m % 1440 === 0
        ? ($m === 1440 ? 'Once a day' : ($m / 1440) . ' days')
        : ($m % 60 === 0 ? ($m === 60 ? 'Hourly' : 'Every ' . ($m / 60) . ' hours') : 'Every ' . $m . ' min');
  @endphp
  {{ $label }}@if($s->fetch_at_hour !== null) at {{ sprintf('%02d:00', $s->fetch_at_hour) }}@endif
@else
  <span class="dim">{{ $s->priority_tier === 'secondary' ? 'Hourly (tier)' : 'Every 15 min (tier)' }}</span>
@endif
