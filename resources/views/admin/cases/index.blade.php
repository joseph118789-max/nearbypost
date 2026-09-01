@extends('layouts.admin')
@section('title', 'Case studies')
@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

  <h1>Case studies</h1>
  <p class="lede">
    A story that is happening in many places at once &mdash; a nationwide promotion, a chain
    closing branches, a supply cut across several districts. Write it once, say where it applies,
    and every reader near any of those places sees it, once.
  </p>

  @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif

  <p style="margin-bottom:18px;">
    <a class="btn btn-primary" href="{{ route('admin.cases.create') }}">Write a case study</a>
  </p>

  @if(count($cases) === 0)
    <p class="lede">None yet.</p>
  @else
  <table class="srctable">
    <thead><tr><th>Story</th><th>Places</th><th>On the map</th><th>State</th><th>Written</th></tr></thead>
    <tbody>
      @foreach($cases as $case)
        @php $c = $counts[$case->id] ?? null; @endphp
        <tr>
          <td><a class="srcname" href="{{ route('admin.cases.show', ['id' => $case->id]) }}">{{ $case->title }}</a></td>
          <td class="num">{{ $c->total ?? 0 }}</td>
          <td class="num">{{ $c->placed ?? 0 }}</td>
          <td>
            <span class="badge {{ $case->status === 'active' ? 'badge-ok' : 'badge-warn' }}">
              {{ $case->status === 'active' ? 'live' : 'draft' }}
            </span>
          </td>
          <td class="dim">{{ $case->created_at?->diffForHumans() }}</td>
        </tr>
      @endforeach
    </tbody>
  </table>
  @endif
</div>
@endsection
