@extends('layouts.admin')
@section('title', 'Failing sources')
@push('styles')
@include('admin.sources._styles')
@endpush

@section('content')
<div class="srcpage">
  <a class="back" href="{{ route('admin.sources.countries') }}">&larr; Sources</a>
  <h1>Failing sources</h1>
  <p class="lede">
    Where effort is worth spending. A source can fail in two different ways and they need
    different work: the <strong>feed</strong> can stop answering, or the feed can answer while the
    <strong>article text</strong> never arrives. The second is the quiet one &mdash; stories keep
    appearing, but they are classified and located from a headline and a one-line teaser, which is
    how a national story ends up pinned to a street.
  </p>

  @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif

  <h2>The feed is not answering ({{ count($feedFailures) }})</h2>

  @if(count($feedFailures) === 0)
    <p class="lede">Every switched-on source answered its last read.</p>
  @else
    <table class="srctable">
      <thead><tr><th>Source</th><th>Failures in a row</th><th>Last status</th><th>Last read</th><th></th></tr></thead>
      <tbody>
        @foreach($feedFailures as $s)
          <tr>
            <td>
              <a class="srcname" href="{{ route('admin.sources.show', ['id' => $s->id]) }}">{{ $s->name }}</a>
              <div class="srcurl">{{ $s->rss_url ?: $s->index_url ?: $s->base_url }}</div>
            </td>
            <td class="num">{{ $s->consecutive_failures }}</td>
            <td>{{ $s->last_status ?: '—' }}</td>
            <td class="dim">{{ $s->last_fetched_at ? \Carbon\Carbon::parse($s->last_fetched_at)->diffForHumans() : 'never' }}</td>
            <td>
              <form method="post" action="{{ route('admin.sources.test', ['id' => $s->id]) }}">
                @csrf
                <button class="btn" type="submit">Test</button>
              </form>
            </td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  <h2>The text is not arriving ({{ count($textFailures) }})</h2>
  <p class="lede">
    These publish stories we can list but cannot read properly. Anything under about 400 characters
    is a teaser, not an article &mdash; and everything downstream, the category and above all the
    location, is being decided from it.
  </p>

  @if(count($textFailures) === 0)
    <p class="lede">Every source with recent stories is giving us readable article text.</p>
  @else
    <table class="srctable">
      <thead><tr><th>Publisher</th><th>Stories</th><th>With real text</th><th>Teaser only</th><th>Nothing</th><th>Average length</th></tr></thead>
      <tbody>
        @foreach($textFailures as $t)
          <tr class="{{ $t->ok == 0 ? 'off' : '' }}">
            <td>
              {{ $t->source }}
              @if($t->ok == 0)<span class="badge badge-bad">nothing readable</span>@endif
            </td>
            <td class="num">{{ $t->total }}</td>
            <td class="num">{{ $t->ok }}</td>
            <td class="num">{{ $t->fallback }}</td>
            <td class="num">{{ $t->failed }}</td>
            <td class="num">{{ number_format($t->avg_chars) }} chars</td>
          </tr>
        @endforeach
      </tbody>
    </table>
  @endif

  <h2>How this was solved before</h2>
  <p class="lede">
    Four publishers &mdash; New Straits Times, Harian Metro, Berita Harian and Malay Mail &mdash;
    had zero readable articles between them for months, for two reasons that looked identical from
    outside: two render their articles in the browser so the downloaded page has no prose in it,
    and one answers our crawler with 403. Both turned out to be moot, because all four publish the
    whole article in <code>&lt;content:encoded&gt;</code> in the RSS we were already downloading.
    <br><br>
    So the first thing to check on any source here is <strong>what the feed already contains</strong>,
    before anything more elaborate. The answer is written on each publisher's own page under
    &ldquo;How to extract the news&rdquo;.
  </p>
</div>
@endsection
