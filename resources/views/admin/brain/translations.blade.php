{{-- The three versions of each story, side by side.

     A translation is the only part of the pipeline nobody could see. The
     classifier's answers are checked on Check answers, its mistakes on
     Corrections, its refusals on Removed - and the words a reader in Malay or
     Chinese actually sees were written, stored and served without ever being
     read by anyone here.

     Side by side rather than one at a time, because the fault in a translation
     is almost never visible in isolation. "Six areas" becoming "enam kawasan"
     reads perfectly until you see the English said five. --}}
@extends('layouts.admin')

@section('title', 'Translations')

@push('styles')
@include('admin.brain._styles')
<style>
  .thead { margin-bottom: 16px; }
  .thead p { color:#5a6b78; font-size:0.86rem; line-height:1.6; max-width:74ch; margin:8px 0 0; }

  .tfilter { display:flex; gap:6px; margin:14px 0 20px; flex-wrap:wrap; align-items:center; }
  .tfilter a { font-size:0.78rem; padding:6px 14px; border-radius:20px; background:#eef4f8;
               color:#41637d; text-decoration:none; font-weight:600; }
  .tfilter a.on { background:#203d74; color:#fff; }
  .tfilter .lbl { font-size:0.72rem; color:#8a9aa6; text-transform:uppercase;
                  letter-spacing:0.05em; margin-right:4px; }

  .story { border:1px solid #e2e8ee; border-radius:10px; background:#fff;
           margin-bottom:14px; overflow:hidden; }
  .story > header { padding:10px 14px; background:#f7f9fb; border-bottom:1px solid #eef2f7;
                    display:flex; justify-content:space-between; gap:12px; align-items:baseline; }
  .story > header .src { font-size:0.72rem; color:#7a8b98; white-space:nowrap; }
  .story > header .cat { font-size:0.72rem; color:#41637d; }

  .langs { display:grid; grid-template-columns:repeat(3, 1fr); }
  .lang { padding:12px 14px; border-right:1px solid #eef2f7; }
  .lang:last-child { border-right:0; }

  /* The version the journalist actually wrote. Everything else is derived from
     it, so a fault in it is a fault in all three. */
  .lang.original { background:#f6faf6; }
  .lang.original .tag { background:#dcefdf; color:#276b3a; }

  .lang .tag { display:inline-block; font-size:0.63rem; font-weight:700; letter-spacing:0.05em;
               text-transform:uppercase; padding:2px 8px; border-radius:20px;
               background:#eef4f8; color:#5f7f9a; margin-bottom:7px; }
  .lang h4 { font-size:0.87rem; line-height:1.45; color:#1c2b36; margin:0 0 6px; font-weight:650; }
  .lang p { font-size:0.79rem; line-height:1.6; color:#5a6b78; margin:0; }
  .lang .missing { color:#a1281f; font-style:italic; font-size:0.79rem; }

  @media (max-width: 900px) {
    .langs { grid-template-columns:1fr; }
    .lang { border-right:0; border-bottom:1px solid #eef2f7; }
  }

  .none { padding:40px; text-align:center; color:#8a9aa6; }
</style>
@endpush

@section('content')
@include('admin.brain._nav')

<div class="thead">
  <h1>Translations</h1>
  <p>
    Every story in the three languages a reader can choose. The version the journalist wrote is
    marked <strong>original</strong>; the other two were written by the model in the same call that
    classified the story.
  </p>
  <p>
    Read them across, not down. A translation almost never looks wrong on its own &mdash; &ldquo;six
    areas&rdquo; rendered as &ldquo;enam kawasan&rdquo; is perfect Malay, and wrong if the English
    said five.
  </p>
</div>

{{-- A day at a time. The whole table in publication order is not how
     anyone reads this, and it was also what made the page unbounded. --}}
<div class="tfilter daybar">
  <span class="lbl">Day</span>
  @foreach($days as $d)
    <a href="{{ route('admin.brain.translations', array_filter(['day' => $d->day, 'lang' => $lang === 'all' ? null : $lang])) }}"
       class="{{ $day === $d->day ? 'on' : '' }}">
      {{ \Carbon\Carbon::parse($d->day)->format('j M') }} <b>{{ $d->n }}</b>
    </a>
  @endforeach
</div>

<div class="tfilter">
  <span class="lbl">Written in</span>
  <a href="{{ route('admin.brain.translations', ['day' => $day]) }}" class="{{ $lang === 'all' ? 'on' : '' }}">All</a>
  @foreach($languages as $code => $count)
    <a href="{{ route('admin.brain.translations', ['lang' => $code, 'day' => $day]) }}"
       class="{{ $lang === $code ? 'on' : '' }}">{{ strtoupper($code) }} <b>{{ $count }}</b></a>
  @endforeach
</div>

<p class="pmeta" style="margin-bottom:12px;color:#7a8b98;font-size:0.8rem;">
  {{ $stories->count() }} {{ Str::plural('story', $stories->count()) }} translated on
  {{ \Carbon\Carbon::parse($day)->format('j F') }}.
</p>

@forelse($stories as $story)
  <div class="story">
    <header>
      <span class="cat">{{ $story->ai_category ?: 'uncategorised' }}</span>
      <span class="src">
        {{ $story->source }} &middot;
        {{ \Carbon\Carbon::parse($story->published_at, 'UTC')->timezone('Asia/Kuala_Lumpur')->format('j M, g:ia') }}
      </span>
    </header>

    <div class="langs">
      @foreach(['en' => 'English', 'ms' => 'Bahasa Melayu', 'zh' => '中文'] as $code => $name)
        @php $t = $story->versions[$code] ?? null; @endphp
        <div class="lang {{ $story->source_language === $code ? 'original' : '' }}">
          <span class="tag">
            {{ $name }}@if($story->source_language === $code) &middot; original @endif
          </span>

          @if($t)
            <h4>{{ $t->title }}</h4>
            <p>{{ $t->summary }}</p>
          @else
            <p class="missing">Not translated yet &mdash; a reader here sees the original.</p>
          @endif
        </div>
      @endforeach
    </div>
  </div>
@empty
  <div class="none">Nothing translated yet.</div>
@endforelse


@endsection
