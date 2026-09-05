@extends('layouts.admin')
@section('title', 'Members')
@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
<style>
  .members table { width:100%; border-collapse:collapse; background:#fff; border:1px solid #e2edf6; border-radius:14px; overflow:hidden; font-size:0.86rem; }
  .members th, .members td { padding:9px 12px; text-align:left; border-bottom:1px solid #eef3f7; vertical-align:top; }
  .members th { background:#f6f9fc; color:#5f7f9a; font-weight:600; font-size:0.74rem; text-transform:uppercase; letter-spacing:0.05em; }
  .members .num { text-align:right; font-variant-numeric:tabular-nums; }
  .members .tag { display:inline-block; padding:2px 8px; border-radius:999px; font-size:0.72rem; font-weight:600; background:#eef3f7; color:#1f5679; }
  .members .tag.ok { background:#e0f5e9; color:#1f7840; } .members .tag.mod { background:#fdf1dc; color:#8a5a12; }
  .members form.inline { display:inline; } .members button { font:inherit; font-size:0.78rem; padding:3px 9px; border-radius:999px; border:1px solid #cfe0ec; background:#fff; color:#1c5a7f; cursor:pointer; }
  .members .search { display:flex; gap:8px; margin:0 0 14px; } .members .search input { padding:8px 12px; border:1px solid #cfe0ec; border-radius:999px; min-width:280px; font:inherit; }
  .flash { background:#e0f5e9; color:#1f7840; padding:10px 16px; border-radius:12px; margin-bottom:14px; font-size:0.88rem; }
  .pager { margin-top:14px; }
</style>
@endpush
@section('content')
<div class="srcpage members">
  @include('admin.brain._nav')
  <h1>Members</h1>
  <p class="lede">{{ number_format($total) }} reader accounts, {{ $trusted }} trusted. Credibility starts at 50; confirmed reports raise it, disputed or removed ones lower it; 80 and above is a trusted local contributor.</p>
  @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
  <form method="get" class="search"><input type="search" name="q" value="{{ $q }}" placeholder="@username, name or email"><button type="submit">Search</button>@if($q)<a href="{{ route('admin.members.index') }}" style="align-self:center;font-size:0.85rem;">clear</a>@endif</form>
  <table>
    <thead><tr><th>Member</th><th>Email</th><th>Joined</th><th class="num">Credibility</th><th>Standing</th><th class="num">Reports</th><th class="num">Comments</th><th>Actions</th></tr></thead>
    <tbody>
    @forelse($members as $m)
      <tr>
        <td><a href="{{ url('/@' . $m->username) }}" target="_blank" rel="noopener"><b>{{ '@' . $m->username }}</b></a><br><span style="color:#5f7f9a">{{ $m->name }}</span></td>
        <td>{{ $m->email }}</td>
        <td>{{ \Carbon\Carbon::parse($m->created_at)->format('j M Y') }}</td>
        <td class="num">{{ (int) $m->credibility }}</td>
        <td>
          <span class="tag">{{ \App\Http\Controllers\Admin\MembersController::level((int) $m->credibility) }}</span>
          @if($m->trusted_at)<span class="tag ok">trusted</span>@endif
          @if($m->community_role === 'moderator')<span class="tag mod">moderator</span>@endif
          @if(($m->status ?? 'active') !== 'active')<span class="tag">{{ $m->status }}</span>@endif
        </td>
        <td class="num">{{ $posts[$m->id]->live ?? 0 }} live / {{ $posts[$m->id]->n ?? 0 }}</td>
        <td class="num">{{ $comments[$m->id] ?? 0 }}</td>
        <td>
          @if($m->trusted_at)
            <form method="post" action="{{ route('admin.contributions.untrust', ['id' => $m->id]) }}" class="inline">@csrf<button type="submit">Untrust</button></form>
          @else
            <form method="post" action="{{ route('admin.members.trust', ['id' => $m->id]) }}" class="inline">@csrf<button type="submit">Trust</button></form>
          @endif
          @if($m->community_role === 'moderator')
            <form method="post" action="{{ route('admin.community.moderator', ['user' => $m->id, 'decision' => 'remove']) }}" class="inline">@csrf<button type="submit">Remove moderator</button></form>
          @else
            <form method="post" action="{{ route('admin.community.moderator', ['user' => $m->id, 'decision' => 'assign']) }}" class="inline">@csrf<button type="submit">Make moderator</button></form>
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="8" style="color:#5f7f9a">No members match.</td></tr>
    @endforelse
    </tbody>
  </table>
  <div class="pager">{{ $members->links() }}</div>
</div>
@endsection
