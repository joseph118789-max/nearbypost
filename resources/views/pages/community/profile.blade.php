@extends('layouts.public')
@php $wide = true; @endphp

@section('main')
  <section class="profile-page">
    <div class="profile-head">
      <h1>{{ '@' . $profile->username }}</h1>
      <p class="profile-name">{{ $profile->display_name ?: $profile->name }}
        <span class="post-cred">{{ $level }} · {{ __('site.credibility') }} {{ (int) $profile->credibility }} · {{ __('site.points') }} {{ (int) $profile->points }}</span></p>
      @if($profile->bio)<p class="profile-bio">{{ $profile->bio }}</p>@endif
      <p class="profile-facts">
        <span>{{ __('site.joined') }} {{ $profile->created_at?->format('M Y') }}</span>
        @if($profile->area)<span>{{ $profile->area }}</span>@endif
        <span>{{ $posts->count() }} {{ __('site.reports_published') }}</span>
        <span>{{ $confirmed }} {{ __('site.community_confirmed') }}</span>
        <span>{{ $followers }} {{ __('site.followers') }}</span>
        <span>{{ $following }} {{ __('site.following') }}</span>
      </p>
      @auth('web')
        @if((int) auth('web')->id() !== (int) $profile->id)
          <form method="post" action="{{ $isFollowing ? route('community.unfollow', ['username' => $profile->username]) : route('community.follow', ['username' => $profile->username]) }}">
            @csrf @if($isFollowing)@method('DELETE')@endif
            <button type="submit" class="desktop-action-btn {{ $isFollowing ? '' : 'primary' }}">{{ $isFollowing ? __('site.unfollow') : __('site.follow') }}</button>
          </form>
        @endif
      @else
        <a class="desktop-action-btn" href="{{ route('login') }}">{{ __('site.follow') }}</a>
      @endauth
    </div>

    @php $badges = \Illuminate\Support\Facades\DB::table('user_community_badges')->where('user_id', $profile->id)->whereNull('revoked_at')->get(); @endphp
    @if($badges->count())
      <p class="badges">@foreach($badges as $b)<span class="badge-pill">{{ \App\Services\Community\Badges::RULES[$b->badge] ?? $b->badge }}@if($b->detail): {{ $b->detail }}@endif</span>@endforeach</p>
    @endif
    @auth('web')
      @if((int) auth('web')->id() === (int) $profile->id)
        <p class="mini"><a href="{{ route('community.follows') }}">{{ __('site.following_settings') }}</a> · <a href="{{ route('community.notifications') }}">{{ __('site.notifications') }} ({{ \App\Services\Community\Notifications::unread($profile->id) }})</a>
          @if(\App\Services\Community\Moderators::isModerator(auth('web')->user())) · <a href="{{ route('community.moderate') }}">{{ __('site.moderation') }}</a>@endif</p>
      @else
        <form method="post" action="{{ route('community.block', ['username' => $profile->username]) }}" class="inline">@csrf<input type="hidden" name="kind" value="block"><button class="chip-btn" type="submit">{{ __('site.block') }}</button></form>
      @endif
    @endauth
    <style>.badge-pill { display:inline-block; background:#eef6fb; color:#1c5a7f; border-radius:999px; padding:3px 10px; margin:0 6px 6px 0; font-size:.85em; font-weight:600; }</style>

    <h2>{{ __('site.reports') }}</h2>
    @if($posts->isEmpty())
      <p class="field-hint">{{ __('site.no_reports_yet') }}</p>
    @else
      <ul class="stories">
        @foreach($posts as $p)
          @php $m = $metas[$p->id] ?? null; @endphp
          <li class="story">
            <a class="headline" href="{{ route('post.show', ['id' => $p->id]) }}">{{ $p->title }}</a>
            <p class="meta">
              <span>{{ $p->published_at?->diffForHumans() }}</span>
              @if($p->location_label ?: $p->main_place_text)<span class="place">{{ $p->location_label ?: $p->main_place_text }}</span>@endif
              @if($m)<span class="src trust-{{ $m->trust_status }}">{{ __('site.trust_' . $m->trust_status) }}</span>@endif
            </p>
          </li>
        @endforeach
      </ul>
    @endif
  </section>
  <style>
    .profile-page { max-width: 760px; margin: 0 auto; padding: 8px 0 40px; }
    .profile-head h1 { margin: 0 0 2px; } .profile-name { margin: 0 0 8px; font-weight: 600; }
    .profile-facts { display:flex; flex-wrap:wrap; gap:14px; color:#5b6473; font-size:.92em; }
    .post-cred { color:#5b6473; font-weight:400; font-size:.9em; margin-left:6px; }
    .trust-confirmed { color:#1d7a4a; } .trust-disputed { color:#b3261e; }
  </style>
@endsection
