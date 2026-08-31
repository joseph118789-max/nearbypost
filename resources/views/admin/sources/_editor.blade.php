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
