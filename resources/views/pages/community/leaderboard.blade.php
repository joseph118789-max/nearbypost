@extends('layouts.public')
@php $wide = true; @endphp
@section('main')
  <section class="profile-page">
    <h1>{{ __('site.leaderboard') }} @if($town)<small>— {{ $town }}</small>@endif</h1>
    <p class="mini">{{ __('site.leaderboard_note') }}</p>
    <p class="chips">
      @foreach(['weekly' => __('site.weekly'), 'monthly' => __('site.monthly'), 'all' => __('site.all_time')] as $k => $label)
        <a class="chip-btn {{ $window === $k ? 'on' : '' }}" href="{{ route('community.leaderboard', ['area' => $town, 'window' => $k]) }}">{{ $label }}</a>
      @endforeach
    </p>
    <form method="get" class="row"><select class="modal-input" name="area" onchange="location='{{ url('/community/leaderboards') }}/'+encodeURIComponent(this.value)+'?window={{ $window }}'"><option value="">{{ __('site.all_areas') }}</option>@foreach($towns as $t)<option value="{{ $t }}" {{ $town === $t ? 'selected' : '' }}>{{ $t }}</option>@endforeach</select></form>
    <table class="tidy">
      <thead><tr><th>#</th><th>{{ __('site.contributor') }}</th><th>{{ __('site.score') }}</th><th>{{ __('site.reports') }}</th><th>{{ __('site.community_confirmed') }}</th></tr></thead>
      <tbody>
        @forelse($rows as $i => $r)
          <tr><td>{{ $i + 1 }}</td><td><a href="{{ url('/@' . $r['username']) }}">{{ '@' . $r['username'] }}</a></td><td>{{ $r['score'] }}</td><td>{{ $r['reports'] }}</td><td>{{ $r['confirmed'] }}</td></tr>
        @empty
          <tr><td colspan="5" class="mini">{{ __('site.no_ranking_yet') }}</td></tr>
        @endforelse
      </tbody>
    </table>
  </section>
  <style>.profile-page { max-width:760px; margin:0 auto; } .tidy { width:100%; border-collapse:collapse; } .tidy th, .tidy td { text-align:left; padding:8px 6px; border-bottom:1px solid #e6e9ee; } .chip-btn { display:inline-block; padding:6px 12px; border-radius:999px; border:1px solid #cfe0ec; background:#fff; color:#1c5a7f; font-weight:600; text-decoration:none; margin-right:6px; } .chip-btn.on { background:#1c5a7f; color:#fff; } .mini { color:#5b6473; font-size:.86em; }</style>
@endsection
