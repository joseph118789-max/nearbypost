@extends('layouts.admin')

@section('title', 'Playbook')

@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
<style>
  .pb textarea { width:100%; min-height:230px; font-family:ui-monospace,Menlo,Consolas,monospace;
                 font-size:0.79rem; line-height:1.6; padding:12px; border:1px solid #cfe0ec;
                 border-radius:10px; color:#2c3f4c; }
  .pb .why { background:#f8fafc; border-left:3px solid #a7cfe4; padding:9px 12px; border-radius:0 8px 8px 0;
             font-size:0.82rem; color:#4a6b80; margin-bottom:10px; }
  .pb .actions { display:flex; gap:8px; align-items:center; margin-top:8px; flex-wrap:wrap; }
  .pb .actions input[type=text] { flex:1 1 220px; padding:6px 10px; border:1px solid #cfe0ec; border-radius:8px; font-size:0.8rem; }
</style>
@endpush

@section('content')
<div class="srcpage">
  <h1>Playbook</h1>
  <p class="lede">
    How this newsroom decides. Not a list of bans &mdash; those are the
    <a href="{{ route('admin.rules.index') }}">Rules</a> &mdash; but the method: what counts as news,
    what makes a story matter to a Malaysian reader, and how to work out where it happened.
    <br>
    This text is sent to the AI with every article. Changing it changes how the site judges news from
    the very next story, so change one thing at a time and run the
    <a href="{{ route('admin.brain.bench') }}">bench</a> afterwards.
  </p>

  @include('admin.brain._nav')

  @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="warn">{{ $errors->first() }}</div>@endif

  <div class="pb">
    @foreach($sections as $key => $section)
      <div class="card {{ $section['is_active'] ? '' : 'off' }}" style="{{ $section['is_active'] ? '' : 'opacity:.6;' }}">
        <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
          <h2 style="margin-bottom:2px;">{{ $section['title'] }}</h2>
          <form method="POST" action="{{ route('admin.brain.playbook.toggle', $key) }}">
            @csrf
            <button class="btn-sm {{ $section['is_active'] ? 'warn' : 'go' }}" type="submit">
              {{ $section['is_active'] ? 'Stop sending this' : 'Send this again' }}
            </button>
          </form>
        </div>

        @if($section['why'])
          <div class="why"><strong>Why this exists:</strong> {{ $section['why'] }}</div>
        @endif

        <form method="POST" action="{{ route('admin.brain.playbook.save', $key) }}">
          @csrf
          @method('PUT')
          <textarea name="body" spellcheck="false">{{ $section['body'] }}</textarea>
          <div class="actions">
            <input type="text" name="note" placeholder="What are you changing, and why? (kept with the old version)">
            <button class="btn-sm go" type="submit">Save</button>
          </div>
        </form>
      </div>
    @endforeach
  </div>

  <div class="card">
    <h2>What was changed before</h2>
    <p class="sub">
      Every edit keeps the previous wording. When the feed starts behaving differently and nobody
      remembers what was changed, this is the answer.
    </p>
    @if(count($revisions))
      <table class="tidy">
        <thead><tr><th>When</th><th>Note</th><th>Replaced text</th></tr></thead>
        <tbody>
          @foreach($revisions as $rev)
            <tr>
              <td class="mini" style="white-space:nowrap;">{{ \Carbon\Carbon::parse($rev->created_at)->diffForHumans() }}</td>
              <td>{{ $rev->note ?: '—' }}</td>
              <td class="mini">{{ \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $rev->body), 150) }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @else
      <p class="mini">Nothing changed yet. The text above is the original wording, moved out of the code unaltered.</p>
    @endif
  </div>
</div>
@endsection
