@extends('layouts.public')

@section('main')
  <article class="post-page">
    <div class="story-meta">
      @if($post->primary_category && \App\Support\Taxonomy::isCanonical($post->primary_category))
        <a class="story-category"
           href="{{ \App\Support\Loc::route('category', ['slug' => \App\Support\Slug::make($post->primary_category)]) }}">{{ \App\Support\Taxonomy::category($post->primary_category) }}</a>
      @endif

      @if($post->sub_category && !in_array($post->sub_category, ['Others', 'General'], true))
        <span class="story-category">{{ \App\Support\Taxonomy::subCategory($post->sub_category) }}</span>
      @endif

      {{-- Said plainly on the story itself, not only in the feed's filter: a
           reader deserves to know this was sent in by another reader rather
           than gathered from a newsroom. --}}
      @if(!empty($community))
        <span class="story-category origin-mark trust-{{ $community->trust_status }}">{{ __('site.community_report') }} · {{ __('site.trust_' . $community->trust_status) }}</span>
      @else
        <span class="story-category origin-mark">{{ __('site.by_a_reader') }}</span>
      @endif
    </div>

    <h1 class="post-title">{{ $headline }}</h1>

    <div class="post-byline">
      @if(!empty($author) && $author->username)
        <a href="{{ url('/@' . $author->username) }}" rel="author">{{ '@' . $author->username }}</a>
        <span class="post-cred" title="{{ __('site.credibility_help') }}">{{ \App\Services\Community\CommunityTrust::levelName((int) $author->credibility) }} · {{ __('site.credibility') }} {{ (int) $author->credibility }}/100 · {{ trans_choice('site.reports_by_poster', (int) ($author->posts ?? 0)) }}</span>
      @else
        <span>{{ $post->source }}</span>
      @endif
      @if($post->published_at)
        <time datetime="{{ $post->published_at->toIso8601String() }}">{{ $post->published_at->diffForHumans() }}</time>
      @endif
      @if($place)
        {{-- guarded for the same reason as the story page: an empty slug
             throws out of route() and takes the page with it --}}
        @php $placeSlug = \App\Support\Slug::make($place); @endphp
        @if($placeSlug !== '')
          <a href="{{ \App\Support\Loc::route('place', ['slug' => $placeSlug]) }}">{{ $place }}</a>
        @else
          {{ $place }}
        @endif
      @endif
    </div>

    @if($post->image_path)
      <figure class="post-figure">
        <img src="{{ asset($post->image_path) }}" alt="{{ $headline }}" loading="lazy">
      </figure>
    @endif

    @if($standfirst && empty($community))
      <p class="post-standfirst">{{ $standfirst }}</p>
    @endif

    {{-- The contributor's own words, printed as written. Escaped by Blade and
         broken into paragraphs on blank lines - never rendered as markup. --}}
    <div class="post-body">
      @foreach(preg_split('/\R{2,}/u', (string) $post->body) as $paragraph)
        @if(trim($paragraph) !== '')
          <p>{{ trim($paragraph) }}</p>
        @endif
      @endforeach
    </div>

    @if(!empty($community))
      @if(session('status'))<div class="notice">{{ session('status') }}</div>@endif

      <div class="community-actions">
        @foreach(['saw' => 'saw_this_too', 'helpful' => 'helpful', 'wrong' => 'something_wrong'] as $type => $key)
          <form method="post" action="{{ route('community.react', ['id' => $post->id]) }}" class="inline">
            @csrf
            <input type="hidden" name="type" value="{{ $type }}">
            <button type="submit" class="chip-btn {{ $type }}">{{ __('site.' . $key) }} <b>{{ $counts[$type] ?? 0 }}</b></button>
          </form>
        @endforeach
        <details class="report-box">
          <summary class="chip-btn">{{ __('site.report_this') }}</summary>
          <form method="post" action="{{ route('community.report', ['id' => $post->id]) }}">
            @csrf
            <label class="field-label" for="reason">{{ __('site.report_reason') }}</label>
            <select class="modal-input" name="reason" id="reason" required>
              @foreach(\App\Http\Controllers\CommunityController::REPORT_REASONS as $r)
                <option value="{{ $r }}">{{ __('site.reason_' . $r) }}</option>
              @endforeach
            </select>
            <textarea class="modal-input" name="explanation" rows="3" maxlength="1000" placeholder="{{ __('site.report_explain') }}"></textarea>
            <input class="modal-input" type="url" name="evidence" maxlength="500" placeholder="{{ __('site.report_evidence') }}">
            <button type="submit" class="desktop-action-btn primary">{{ __('site.send_report') }}</button>
          </form>
        </details>
      </div>

      @if(!empty($isTranslated))
        <p class="field-hint">{{ __('site.ai_translated_note') }} <a href="{{ url('/' . ($sourceLocale ?: 'en') . '/post/' . $post->id) }}">{{ __('site.see_original_language') }}</a></p>
      @endif

      @if($history->count())
        <details class="inline-details"><summary>{{ __('site.status_history') }}</summary>
          <ul class="history">@foreach($history as $h)<li>{{ \Carbon\Carbon::parse($h->created_at)->diffForHumans() }}: {{ __('site.trust_' . $h->to) }} @if($h->reason)<span class="mini">— {{ $h->reason }}</span>@endif</li>@endforeach</ul>
        </details>
      @endif

      <section class="corrections" id="corrections">
        <h2>{{ __('site.corrections') }}</h2>
        @foreach($corrections as $c)
          <div class="correction {{ $c->status }}">
            <b>{{ __('site.field_' . $c->field) }}</b> <span class="mini">{{ __('site.trust_' . ($c->status === 'accepted' ? 'corrected' : 'unverified')) }} · @if($c->username)<a href="{{ url('/@' . $c->username) }}">{{ '@' . $c->username }}</a>@endif · {{ __('site.state_' . $c->status) }}</span>
            <p>{{ $c->proposed_value }}</p>
            @if($c->explanation)<p class="mini">{{ $c->explanation }}</p>@endif
            @if($c->evidence_url)<a class="mini" href="{{ $c->evidence_url }}" rel="nofollow noopener" target="_blank">{{ __('site.evidence') }}</a>@endif
            @auth('web')
              @if($c->status === 'open' && (int) $post->contributor_id === (int) auth('web')->id())
                <form method="post" action="{{ route('community.correction.resolve', ['correction' => $c->id, 'decision' => 'accept']) }}" class="inline">@csrf<button class="chip-btn" type="submit">{{ __('site.accept') }}</button></form>
                <form method="post" action="{{ route('community.correction.resolve', ['correction' => $c->id, 'decision' => 'reject']) }}" class="inline">@csrf<button class="chip-btn" type="submit">{{ __('site.reject') }}</button></form>
              @endif
            @endauth
          </div>
        @endforeach
        @auth('web')
          <details class="report-box"><summary class="chip-btn">{{ __('site.propose_correction') }}</summary>
            <form method="post" action="{{ route('community.correction', ['id' => $post->id]) }}">
              @csrf
              <select class="modal-input" name="field" required>@foreach(\App\Services\Community\Corrections::FIELDS as $f)<option value="{{ $f }}">{{ __('site.field_' . $f) }}</option>@endforeach</select>
              <textarea class="modal-input" name="proposed_value" rows="3" required maxlength="5000" placeholder="{{ __('site.proposed_value') }}"></textarea>
              <textarea class="modal-input" name="explanation" rows="2" maxlength="1000" placeholder="{{ __('site.report_explain') }}"></textarea>
              <input class="modal-input" type="url" name="evidence" maxlength="500" placeholder="{{ __('site.report_evidence') }}">
              <button type="submit" class="desktop-action-btn primary">{{ __('site.send_correction') }}</button>
            </form>
          </details>
        @else
          <p class="mini"><a href="{{ route('login') }}">{{ __('site.sign_in_to_correct') }}</a></p>
        @endauth
      </section>

      <section class="comments" id="comments">
        <h2>{{ __('site.comments') }} <small>{{ $comments->where('status', 'published')->count() }}</small></h2>
        @php $byParent = $comments->groupBy(fn ($c) => $c->parent_id ?: 0); @endphp
        @foreach($byParent->get(0, collect()) as $c)
          @include('partials.comment', ['c' => $c, 'post' => $post, 'blocked' => $blocked, 'replies' => $byParent->get($c->id, collect())])
        @endforeach
        @auth('web')
          <form method="post" action="{{ route('community.comment', ['id' => $post->id]) }}" class="comment-form">
            @csrf
            <textarea class="modal-input" name="body" rows="3" required minlength="2" maxlength="2000" placeholder="{{ __('site.write_comment') }}"></textarea>
            <button type="submit" class="desktop-action-btn primary">{{ __('site.post_comment') }}</button>
          </form>
        @else
          <p class="mini"><a href="{{ route('login') }}">{{ __('site.sign_in_to_comment') }}</a></p>
        @endauth
      </section>
      <style>
        .comments, .corrections { margin-top: 22px; } .comments h2, .corrections h2 { font-size: 1.05em; }
        .comment { border-top: 1px solid #e6e9ee; padding: 10px 0; } .comment.reply { margin-left: 26px; border-top: 1px dashed #e6e9ee; }
        .comment .who { font-weight: 600; } .comment .mini, .mini { color: #5b6473; font-size: .86em; }
        .comment.hidden p { color: #8a919c; font-style: italic; } .comment .tools { display: flex; gap: 8px; flex-wrap: wrap; }
        .comment .tools form { display: inline; } .comment .tools button { background: none; border: 0; color: #1c5a7f; cursor: pointer; padding: 0; font-size: .86em; }
        .comment-form { display: grid; gap: 8px; max-width: 620px; margin-top: 10px; }
        .correction { border-left: 3px solid #cfe0ec; padding: 6px 10px; margin: 8px 0; } .correction.accepted { border-color: #1d7a4a; } .correction.rejected { opacity: .6; }
        .history { padding-left: 18px; }
      </style>

      <p class="post-disclaimer">
        {{ __('site.community_disclaimer') }}
        @if($community->published_title && $community->published_title !== $community->original_title)
          {{ __('site.edited_by_editor') }}
          <details class="inline-details"><summary>{{ __('site.see_original') }}</summary>
            <p><b>{{ $community->original_title }}</b></p>
            @foreach(preg_split('/\R{2,}/u', (string) $community->original_body) as $para)@if(trim($para) !== '')<p>{{ trim($para) }}</p>@endif @endforeach
          </details>
        @endif
      </p>
      <style>
        .community-actions { display:flex; flex-wrap:wrap; gap:8px; align-items:center; margin:14px 0; }
        .community-actions .inline { display:inline; }
        .chip-btn { padding:7px 13px; border-radius:999px; border:1px solid #cfe0ec; background:#fff; color:#1c5a7f; font-weight:600; cursor:pointer; }
        .chip-btn b { font-weight:700; margin-left:4px; }
        .report-box { flex-basis:100%; } .report-box summary { list-style:none; display:inline-block; }
        .report-box form { margin-top:10px; display:grid; gap:8px; max-width:520px; }
        .trust-confirmed { background:#e6f4ea; color:#1d7a4a; } .trust-disputed { background:#fdecea; color:#b3261e; } .trust-unverified { background:#f2f4f7; }
        .post-cred { color:#5b6473; font-size:.9em; margin-left:6px; }
        .inline-details summary { cursor:pointer; color:#1c5a7f; }
      </style>
    @else
      <p class="post-disclaimer">{{ __('site.reader_post_disclaimer') }}</p>
    @endif
  </article>
<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('.tr-comment'); if (!b) return;
  var box = document.getElementById('ct' + b.dataset.id);
  if (b.dataset.state === 'shown') { box.hidden = true; b.textContent = b.dataset.show; b.dataset.state = 'loaded'; return; }
  if (b.dataset.state === 'loaded') { box.hidden = false; b.textContent = b.dataset.hide; b.dataset.state = 'shown'; return; }
  b.disabled = true; b.textContent = '...';
  fetch(b.dataset.url, { headers: { 'Accept': 'application/json' } }).then(function (r) { return r.json(); }).then(function (d) {
    b.disabled = false;
    if (!d.ok) { b.textContent = b.dataset.show; return; }
    if (d.same) { b.textContent = b.dataset.same; b.disabled = true; return; }
    box.textContent = ''; box.appendChild(document.createTextNode(d.text));
    var n = document.createElement('span'); n.className = 'mini'; n.textContent = ' (' + b.dataset.note + ')'; box.appendChild(n);
    box.hidden = false; b.textContent = b.dataset.hide; b.dataset.state = 'shown';
  }).catch(function () { b.disabled = false; b.textContent = b.dataset.show; });
});
</script>
<style>.comment .translated { border-left: 3px solid #cfe0ec; padding-left: 10px; color: #33404f; } .tr-comment { background: none; border: 0; color: #1c5a7f; cursor: pointer; font: inherit; font-size: .9em; padding: 0; }</style>
@endsection
