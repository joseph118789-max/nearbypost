@extends('layouts.public')

@section('main')
  <div class="page-head">
    <h1>{{ $pageTitle }}</h1>
  </div>

  <div class="prose-card">
    @if($page === 'terms')
      <p>{{ config('app.name') }} collects publicly published news headlines and summaries from Malaysian news sources and presents them by location and topic.</p>
      <p>Articles remain the property of the publishers who wrote them. Every headline links to the original publisher, and we do not republish full articles.</p>
      <p>The service is provided as is. Use it as a way to find news, not as a system of record.</p>
    @elseif($page === 'privacy')
      <p>We do not require an account and we do not ask for your name or email to read the news.</p>
      <p>If you choose a location, it is stored in a cookie on your own device so the site remembers it next time. You can clear it at any time by clearing your browser cookies.</p>
      <p>We do not sell personal data.</p>
    @else
      <p>Headlines and summaries are gathered automatically from third-party news sources, and summaries may be generated with automated assistance.</p>
      <p>Accuracy, completeness and timeliness are not guaranteed. Always check the original publisher before relying on any story.</p>
      <p>Locations are approximate. A story is placed near the town or area it mentions, which is not always where the event happened.</p>
    @endif
  </div>
@endsection

@section('aside')
  <div class="info-card">
    <h3>Pages</h3>
    <ul class="nav-links">
      <li><a href="{{ \App\Support\Loc::route('legal', ['page' => 'terms']) }}">Terms of Use</a></li>
      <li><a href="{{ \App\Support\Loc::route('legal', ['page' => 'privacy']) }}">Privacy Policy</a></li>
      <li><a href="{{ \App\Support\Loc::route('legal', ['page' => 'disclaimer']) }}">Disclaimer</a></li>
    </ul>
  </div>
@endsection
