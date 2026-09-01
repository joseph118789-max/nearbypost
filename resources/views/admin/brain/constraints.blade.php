@extends('layouts.admin')

@section('title', 'What each source permits')

@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
<style>
  .pol { font-size:0.7rem; padding:3px 9px; border-radius:20px; white-space:nowrap; font-weight:600; }
  .pol.permitted     { background:#e6f2e6; color:#276b3a; }
  .pol.ai_restricted { background:#fdf3e3; color:#8a5a12; }
  .pol.prohibited    { background:#fbe9e7; color:#a1281f; }
  .pol.unknown       { background:#eef4f8; color:#5f7f9a; }

  .routes { display:flex; gap:4px; flex-wrap:wrap; }
  .routes span { font-size:0.66rem; padding:2px 7px; border-radius:4px; white-space:nowrap; }
  .routes .ok  { background:#e6f2e6; color:#276b3a; }
  .routes .bad { background:#fbe9e7; color:#a1281f; }

  .con { font-size:0.8rem; color:#a1481f; line-height:1.5; }
  .fix { font-size:0.8rem; color:#276b3a; line-height:1.5; margin-top:5px; }
  .fix strong { color:#1c5a7f; }
  tr.blocked td { background:#fdf6f5; }
</style>
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

  <h1>What each source permits</h1>
  <p class="lede">
    Three questions per publisher: <strong>may we</strong> read them, <strong>can we</strong> reach
    the article, and <strong>how much</strong> of what they publish do we end up holding. Every
    limitation is written down with a route beside it &mdash; a problem recorded without one is half
    an answer.
    <br>
    Run <code>php artisan sources:audit</code> to refresh. It reads only; it changes no source and
    switches nothing on.
  </p>

  @if($unaudited)
    <div class="warn">{{ $unaudited }} source(s) have never been audited. Run the command above.</div>
  @endif

  <div class="grid2">
    @foreach($summary as $policy => $count)
      <div class="card" style="margin-bottom:0;">
        <div class="stat"><span class="n">{{ $count }}</span><span class="of">{{ $labels[$policy] ?? $policy }}</span></div>
        <p class="mini" style="margin-top:6px;">{{ $blurbs[$policy] ?? '' }}</p>
      </div>
    @endforeach
  </div>

  <div class="card" style="margin-top:14px;">
    <h2>Every source, and what stands in the way</h2>
    <p class="sub">
      Sorted with the blocked and the broken first, because those are the ones needing a decision.
    </p>

    <div style="overflow-x:auto;">
      <table class="tidy">
        <thead>
          <tr>
            <th style="width:150px;">Source</th>
            <th style="width:96px;">May we</th>
            <th style="width:180px;">Routes tested</th>
            <th style="width:74px;">Sections</th>
            <th>The limitation, and what to do about it</th>
          </tr>
        </thead>
        <tbody>
          @foreach($sources as $s)
            @php($routes = $s->routes ? json_decode($s->routes, true) : [])
            <tr class="{{ in_array($s->robots_policy, ['prohibited']) || !$s->best_route ? 'blocked' : '' }}">
              <td>
                <a class="storylink" href="{{ route('admin.sources.show', ['id' => $s->id]) }}">{{ $s->name }}</a>
                @if(!$s->is_active)<br><span class="pill nowhere">off</span>@endif
              </td>
              <td><span class="pol {{ $s->robots_policy ?: 'unknown' }}">{{ $labels[$s->robots_policy] ?? 'not checked' }}</span></td>
              <td>
                <div class="routes">
                  @foreach(['rss' => 'RSS', 'wp_json' => 'API', 'sitemap' => 'sitemap', 'article_page' => 'page'] as $k => $label)
                    @if(isset($routes[$k]))
                      <span class="{{ (int) ($routes[$k]['status'] ?? 0) === 200 ? 'ok' : 'bad' }}"
                            title="{{ $routes[$k]['detail'] ?? '' }}">{{ $label }}</span>
                    @endif
                  @endforeach
                </div>
                @if($s->best_route)
                  <div class="mini" style="margin-top:4px;">using <strong>{{ $s->best_route }}</strong></div>
                @else
                  <div class="mini" style="margin-top:4px;color:#a1481f;">no route works</div>
                @endif
              </td>
              <td class="mini" style="text-align:right;">
                @if($s->sections_published)
                  {{ $s->sections_reachable ?? 0 }} / {{ $s->sections_published }}
                  @if($s->coverage_pct !== null)<br><strong>{{ $s->coverage_pct }}%</strong>@endif
                @else
                  &mdash;
                @endif
              </td>
              <td>
                <div class="con">{{ $s->constraint_note ?: 'Not audited.' }}</div>
                @if($s->workaround_note)
                  <div class="fix">&rarr; {{ $s->workaround_note }}</div>
                @endif
                @if($s->robots_note && $s->robots_policy !== 'permitted')
                  <details style="margin-top:6px;">
                    <summary class="mini" style="cursor:pointer;">what they actually say</summary>
                    <p class="mini" style="margin-top:5px;">{{ \Illuminate\Support\Str::limit($s->robots_note, 700) }}</p>
                  </details>
                @endif
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  </div>

  <div class="card">
    <h2>How to read the sections column</h2>
    <p class="sub" style="margin-bottom:0;">
      Sections reachable against sections published. A publisher listing 113 sections in its sitemap
      while its feed reaches 6 is telling you the feed is a window onto part of the newsroom, not all
      of it &mdash; and that the missing part needs a different route or is not available at all.
      <br><br>
      A blank means no sitemap was found to compare against, not that coverage is complete.
    </p>
  </div>
</div>
@endsection
