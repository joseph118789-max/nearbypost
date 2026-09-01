@extends('layouts.admin')

@section('title', 'Corrections')

@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

  <h1>Corrections</h1>
  <p class="lede">
    Every fix a person has made, kept. Not only deletions &mdash; a story in the wrong town, under
    the wrong heading, refused when it should have been kept. Each one is a fact about how this job
    should be done, and each used to be applied once and thrown away.
    <br>
    This is the material the <a href="{{ route('admin.brain.bench') }}">bench</a> is built from and
    the evidence the next <a href="{{ route('admin.rules.index') }}">rule</a> should be written on.
  </p>

  @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif

  @if($tally)
    <div class="card">
      <h2>What goes wrong most</h2>
      <p class="sub">Last 90 days. Write the next rule about whatever is at the top.</p>
      <table class="tidy">
        <thead><tr><th>What was wrong</th><th style="width:80px;text-align:right;">Times</th></tr></thead>
        <tbody>
          @foreach($tally as $field => $n)
            <tr><td>{{ $fields[$field] ?? $field }}</td><td style="text-align:right;font-variant-numeric:tabular-nums;">{{ $n }}</td></tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endif

  <div class="card">
    <h2>The log</h2>
    @if($rows->isEmpty())
      <p class="mini">
        Nothing recorded yet. Corrections are made on the
        <a href="{{ route('admin.brain.bench') }}">bench</a> page &mdash; when an answer is wrong,
        say what it should have been and it lands here.
      </p>
    @else
      <table class="tidy">
        <thead>
          <tr><th style="width:110px;">When</th><th>Story</th><th style="width:150px;">What was wrong</th>
              <th style="width:150px;">Said / should be</th><th style="width:80px;">On bench</th></tr>
        </thead>
        <tbody>
          @foreach($rows as $row)
            <tr>
              <td class="mini" style="white-space:nowrap;">{{ \Carbon\Carbon::parse($row->created_at)->diffForHumans() }}</td>
              <td>
                {{ \Illuminate\Support\Str::limit($row->title ?: '(story gone)', 62) }}
                @if($row->reason)<div class="mini">{{ $row->reason }}</div>@endif
              </td>
              <td class="mini">{{ $fields[$row->field] ?? $row->field }}</td>
              <td class="mini">
                <span style="color:#a1481f;">{{ \Illuminate\Support\Str::limit($row->ai_answer ?: '—', 40) }}</span><br>
                <span style="color:#276b3a;">{{ \Illuminate\Support\Str::limit($row->correct_answer ?: '—', 40) }}</span>
              </td>
              <td>
                @if($row->used_in_bench)
                  <span class="pill">measured</span>
                @else
                  <span class="pill nowhere">not measured</span>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>
@endsection
