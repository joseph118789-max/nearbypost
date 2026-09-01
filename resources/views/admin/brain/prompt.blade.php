@extends('layouts.admin')

@section('title', 'The prompt')

@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

  <h1>The prompt</h1>
  <p class="lede">
    Word for word, what the AI is sent. Built by the same code the pipeline uses, so this is the text
    the model actually receives &mdash; not a reconstruction that would drift away from the truth
    within a month.
    <br>
    Each part says who can change it. When an answer looks wrong, that is usually the question worth
    asking first.
  </p>

  {{-- Two doors into the same room. Showing them side by side is the clearest
       way to say that a reader's post is judged by the same house rules as a
       newsroom's story - the thing that makes stage two one stage. --}}
  <div class="card">
    <h2>Two ways in, one set of judgements</h2>
    <p class="sub" style="margin-bottom:12px;">
      A gathered story and a reader's post arrive differently and are checked differently at the
      door. Past that door they meet the same house rules.
    </p>
    <div class="brainnav" style="margin:0;">
      <a href="{{ route('admin.brain.prompt', ['for' => 'scraper'] + ($story ? ['news_item_id' => $story->id] : [])) }}"
         class="{{ $for === 'scraper' ? 'on' : '' }}">Official &mdash; a gathered story</a>
      <a href="{{ route('admin.brain.prompt', ['for' => 'contributor']) }}"
         class="{{ $for === 'contributor' ? 'on' : '' }}">Unofficial &mdash; a reader's post</a>
    </div>
  </div>

  @if($for === 'contributor')
    <div class="card">
      <h2>What a reader's post is asked</h2>
      <p class="sub">
        This runs the moment somebody presses submit, before anything reaches the queue. It decides
        whether a post is news at all; if it passes, the post is classified by exactly the same
        prompt as a gathered story &mdash; same playbook, same briefing, same categories.
      </p>
      <p class="mini">
        The house rules it carries are the ones marked <em>contributor</em> or <em>both</em>; the
        gathered-story prompt carries those marked <em>scraper</em> or <em>both</em>. That is the only
        difference in what the two are told.
      </p>
    </div>

    <details class="part" open>
      <summary>
        <span><strong>The reader-post review</strong>
          <span class="mini">— {{ number_format(strlen($contributorPrompt)) }} chars</span></span>
        <span class="tag locked">Hardcoded, plus the house rules</span>
      </summary>
      <pre>{{ $contributorPrompt }}</pre>
    </details>
  @else
    <div class="card">
      <h2>Which story?</h2>
      <p class="sub">
        The reasoning is the same for every story; only the last part changes. Pick a real one to see
        exactly what was sent.
      </p>
      <form method="GET" action="{{ route('admin.brain.prompt') }}" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
        <select name="news_item_id" style="flex:1 1 320px;padding:7px 10px;border:1px solid #cfe0ec;border-radius:8px;font-size:0.85rem;">
          <option value="">An example story</option>
          @foreach($recent as $r)
            <option value="{{ $r->id }}" @selected($story && $story->id == $r->id)>{{ \Illuminate\Support\Str::limit($r->title, 70) }}</option>
          @endforeach
        </select>
        <button type="submit" class="btn-sm go">Show</button>
      </form>
    </div>

    @php($chars = collect($parts)->sum(fn ($p) => strlen($p['text'])))
    <p class="mini" style="margin-bottom:10px;">
      {{ count($parts) }} parts, {{ number_format($chars) }} characters in total.
    </p>

    @foreach($parts as $part)
      @php($cls = in_array($part['origin'], ['playbook','rules','cases','briefing']) ? 'editable'
                   : ($part['origin'] === 'code' ? 'locked' : 'derived'))
      <details class="part" @if($part['origin'] === 'playbook') open @endif>
        <summary>
          <span><strong>{{ $part['label'] }}</strong>
            <span class="mini">— {{ number_format(strlen($part['text'])) }} chars</span></span>
          <span class="tag {{ $cls }}">{{ $origins[$part['origin']] }}</span>
        </summary>
        @if(trim($part['text']) === '')
          <pre class="mini">(nothing — this part is empty, so the model is told nothing here)</pre>
        @else
          <pre>{{ $part['text'] }}</pre>
        @endif
      </details>
    @endforeach

    <div class="card" style="margin-top:16px;">
      <h2>Why the reply shape is locked</h2>
      <p class="sub" style="margin-bottom:0;">
        The field names in that part &mdash; <code>d</code>, <code>place</code>, <code>rel</code>,
        <code>sub</code> &mdash; are what the code reads the answer back out of. Tidying
        <code>d</code> into <code>discard</code> would break every classification on the site, and
        nothing would say so: stories would simply stop appearing. It stays where a change needs a
        developer and a deployment. Everything about <em>judgement</em> is editable here.
      </p>
    </div>
  @endif
</div>
@endsection
