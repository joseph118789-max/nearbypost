{{-- One source: its address, its three notes, and its schedule. --}}
@php
  $url = $s->rss_url ?: ($s->index_url ?: $s->base_url);
@endphp

<form class="srccard" method="post" action="{{ route('admin.sources.update', ['id' => $s->id]) }}">
  @csrf
  @method('PUT')

  <div class="srccard-head">
    <div>
      <h3>{{ $isRoot ? $s->name : ($s->section ? ucfirst($s->section) : $s->name) }}</h3>
      @if($url)
        {{-- Opens the publisher's own page, in a new window. --}}
        <a class="srcurl link" href="{{ $url }}" target="_blank" rel="noopener noreferrer">{{ $url }} &#8599;</a>
      @endif
    </div>

    <div class="tags">
      <span class="badge">{{ $s->source_kind ?: 'rss' }}</span>
      @if($s->language && $s->language !== 'Unknown') <span class="badge">{{ $s->language }}</span> @endif
      @if($s->priority_tier) <span class="badge">{{ $s->priority_tier }}</span> @endif
      <span class="badge">{{ number_format((int) ($s->items_contributed ?? 0)) }} stories</span>
      @if(($s->items_duplicate ?? 0) > 0)
        <span class="badge dimbadge">{{ number_format((int) $s->items_duplicate) }} duplicates</span>
      @endif
      @if(($s->consecutive_failures ?? 0) > 0)
        <span class="badge badge-bad">{{ $s->consecutive_failures }} failure(s)</span>
      @endif
    </div>
  </div>

  <div class="srcmeta">
    Last read {{ $s->last_fetched_at ? \Carbon\Carbon::parse($s->last_fetched_at)->diffForHumans() : 'never' }}@if($s->last_status), status {{ $s->last_status }}@endif.
    @if($s->link_pattern) Article links matched by <code>{{ $s->link_pattern }}</code>. @endif
    @if($s->notes_updated_at) Notes updated {{ \Carbon\Carbon::parse($s->notes_updated_at)->diffForHumans() }}. @endif
  </div>

  <label class="lbl" for="expect-{{ $s->id }}">What to expect</label>
  <p class="hint">The kind of story this produces, and roughly how much of it. An editor reads this to decide whether the source earns its place.</p>
  <textarea class="ta" id="expect-{{ $s->id }}" name="expect_note" rows="3" maxlength="4000">{{ old('expect_note', $s->expect_note) }}</textarea>

  <label class="lbl" for="extract-{{ $s->id }}">How to extract the news</label>
  <p class="hint">How the text is actually obtained: which feed, whether the article page must be fetched separately, what happens when it cannot be.</p>
  <textarea class="ta" id="extract-{{ $s->id }}" name="extract_note" rows="3" maxlength="4000">{{ old('extract_note', $s->extract_note) }}</textarea>

  <label class="lbl" for="strategy-{{ $s->id }}">How the pipeline should read it</label>
  <p class="hint">
    The note above is for people; this is the part the extractor obeys. Leave it on the general
    strategy unless this publisher needs something else &mdash; and if you change it, say why in the
    note, so the reason and the setting cannot drift apart.
  </p>
  <select class="ta" id="strategy-{{ $s->id }}" name="extraction_strategy" style="height:auto;padding:8px 10px;">
    @php($strategy = old('extraction_strategy', $s->extraction_strategy))
    <option value="" @selected(!$strategy)>General &mdash; use the feed's text, fetch the page if it is thin</option>
    <option value="feed_only" @selected($strategy === 'feed_only')>Feed only &mdash; the whole article is already in the feed</option>
    <option value="page_only" @selected($strategy === 'page_only')>Page only &mdash; the feed carries a teaser worth ignoring</option>
  </select>

  <label class="lbl" for="tech-{{ $s->id }}">What to look for technically</label>
  <p class="hint">The specific trap. A wrong timezone offset, a 403 to identified crawlers, a redirect instead of an article, a selector that moves. This is the field that saves the next person a day.</p>
  <textarea class="ta" id="tech-{{ $s->id }}" name="tech_note" rows="3" maxlength="4000">{{ old('tech_note', $s->tech_note) }}</textarea>

  <div class="srcrow">
    <div>
      <label class="lbl" for="interval-{{ $s->id }}">How often to read it</label>
      <select class="inp" id="interval-{{ $s->id }}" name="fetch_interval_minutes">
        <option value="">Let the tier decide ({{ $s->priority_tier ?: 'primary' }})</option>
        @foreach($intervals as $minutes => $label)
          <option value="{{ $minutes }}" {{ (int) $s->fetch_interval_minutes === $minutes ? 'selected' : '' }}>{{ $label }}</option>
        @endforeach
      </select>
    </div>

    <div>
      <label class="lbl" for="hour-{{ $s->id }}">At what hour (once-a-day only)</label>
      <select class="inp" id="hour-{{ $s->id }}" name="fetch_at_hour">
        <option value="">Any hour</option>
        @for($h = 0; $h < 24; $h++)
          <option value="{{ $h }}" {{ $s->fetch_at_hour !== null && (int) $s->fetch_at_hour === $h ? 'selected' : '' }}>
            {{ sprintf('%02d:00', $h) }} Malaysia time
          </option>
        @endfor
      </select>
    </div>

    <div class="onoff">
      <label class="chk">
        <input type="checkbox" name="is_active" value="1" {{ $s->is_active ? 'checked' : '' }}>
        Read this source
      </label>
      <button class="btn btn-primary" type="submit">Save</button>
    </div>
  </div>
</form>

{{-- Outside the form above, because a nested form is not valid HTML and the
     browser silently drops it. --}}
<div class="srccard" style="margin-top:-8px;padding-top:14px;">
  @if(!empty($probe) && ($probe['id'] ?? null) === $s->id)
    {{-- What actually happened when we read it, in the words an editor needs:
         a moved feed, a publisher refusing our crawler and an address that was
         never a feed all look identical until something tries. --}}
    <div class="{{ $probe['ok'] ? 'flash' : 'warn' }}" style="margin-bottom:12px;">
      <strong>{{ $probe['ok'] ? 'Read it.' : 'Could not use it.' }}</strong>
      {{ $probe['message'] }}
      @if($probe['title'])<br><span class="dim">Called itself: {{ $probe['title'] }}</span>@endif
      @foreach($probe['samples'] as $sample)
        <br><span class="dim">&mdash; {{ $sample }}</span>
      @endforeach
    </div>
  @elseif($s->probe_notes)
    <div class="srcmeta" style="margin-bottom:10px;">
      Last tested {{ $s->last_probed_at ? \Carbon\Carbon::parse($s->last_probed_at)->diffForHumans() : 'never' }}:
      {{ $s->probe_notes }}
    </div>
  @endif

  <div style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
    <form method="post" action="{{ route('admin.sources.test', ['id' => $s->id]) }}">
      @csrf
      <button class="btn" type="submit">Test this address</button>
    </form>

    <form method="post" action="{{ route('admin.sources.destroy', ['id' => $s->id]) }}"
          onsubmit="return confirm('Remove this source?');"
          style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
      @csrf @method('DELETE')
      <input class="reason inp" type="text" name="reason" maxlength="300"
             placeholder="Why remove it? (optional)" style="min-width:200px;">
      <select class="inp" name="scope" style="max-width:190px;">
        <option value="url">Never visit this address</option>
        <option value="host">Never visit this whole site</option>
      </select>
      <label class="chk"><input type="checkbox" name="block" value="1" checked> Never visit again</label>
      <button class="btn btn-danger" type="submit">Remove</button>
    </form>
  </div>

  <p class="hint" style="margin-top:8px;">
    Removing alone does not stick: new sources are found automatically from what the aggregator
    cites, so a junk address deleted today comes back. &ldquo;Never visit again&rdquo; is what stops it.
  </p>
</div>
