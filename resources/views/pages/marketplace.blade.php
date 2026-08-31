@extends('layouts.public')

@section('main')
  <div class="page-head">
    <h1>Marketplace</h1>
    <p class="page-intro">The Marketplace is not open yet. Local listings will appear here once it launches.</p>
  </div>

  <div class="empty-state">
    <p>Nothing to show yet.</p>
    <p class="empty-hint">In the meantime, read <a href="{{ route('home') }}">news near you</a>.</p>
  </div>
@endsection

@section('aside')
  <div class="info-card">
    <h3>Meanwhile</h3>
    <div class="info-row"><span>Your location</span><span>{{ $place }}</span></div>
  </div>
@endsection
