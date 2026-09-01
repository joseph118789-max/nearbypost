@extends('layouts.admin')

@section('title', 'Bench')

@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

  <h1>Bench</h1>
  <p class="lede">
    Stories with an answer a person has confirmed, so a model's accuracy is a number rather than an
    impression. Every change to the playbook, the rules or the model itself changes how the site
    judges news &mdash; this is the only thing that can say whether the change helped.
    <br>
    <strong>Most answers are right.</strong> The bench fills fastest by agreeing quickly and stopping
    only at what is wrong.
  </p>

  @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="warn">{{ $errors->first() }}</div>@endif

  <div class="card">
    <h2>Recently judged &mdash; is this right?</h2>
    <p class="sub">
      What the AI decided for each story. Agree and it becomes a confirmed answer; correct it and the
      mistake is recorded and measured from now on. Either way it moves down to
      <a href="#reviewed">what you have said</a>, where you can change your mind.
    </p>

    @if($recent->isEmpty())
      <p class="mini">Everything recent has been reviewed already.</p>
    @else
      <table class="tidy">
        <thead><tr><th>Story</th><th style="width:170px;">The AI said</th><th style="width:210px;"></th></tr></thead>
        <tbody>
          @foreach($recent as $r)
            <tr>
              <td>
                {{-- Opens the publisher's article in a new tab, so the source is
                     one click away and this page is not lost. --}}
                <a href="{{ $r->url }}" target="_blank" rel="noopener nofollow" class="storylink">
                  {{ \Illuminate\Support\Str::limit($r->title, 78) }} &#8599;
                </a>

                {{-- The evidence. If this is a stub, the fault is extraction and
                     not the judgement, and the answer should not be marked wrong. --}}
                @if($r->read_text)
                  <p class="readtext">
                    <span class="mini">{{ $r->source }} &middot; {{ number_format((int) $r->read_chars) }} chars read</span><br>
                    {{ $r->read_text }}{{ (int) $r->read_chars > 320 ? '…' : '' }}
                  </p>
                @else
                  <p class="readtext none">Nothing was read for this story &mdash; only the headline.</p>
                @endif

                <div class="fixform" id="fix-{{ $r->id }}">
                  <form method="POST" action="{{ route('admin.brain.correct') }}">
                    @csrf
                    <input type="hidden" name="news_item_id" value="{{ $r->id }}">
                    {{-- Nothing preselected. When the first option was the
                         default, corrections about a wrong location were
                         recorded as requests to delete the story. --}}
                    <select name="field" required>
                      <option value="" selected disabled>— what is wrong with it? —</option>
                      @foreach($fields as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                    </select>
                    <input type="text" name="correct_answer"
                           placeholder="The right answer: the place, or the category. This is what gets recorded.">
                    <input type="text" name="reason"
                           placeholder="Why — in your words. This is what the next rule gets written from.">
                    <p class="mini" style="margin:6px 0 0;">
                      The dropdown decides what is recorded; the last box is the explanation.
                    </p>
                    <div style="margin-top:8px;"><button class="btn-sm go" type="submit">Record the correction</button></div>
                  </form>
                </div>
              </td>
              <td class="mini">
                @if($r->discarded)
                  <span class="pill nowhere">discarded</span>
                @else
                  {{ $r->ai_category ?: '—' }}@if($r->sub_category) / {{ $r->sub_category }}@endif
                  <br>
                  @if($r->main_place_text)
                    <span class="pill">{{ $r->main_place_text }}</span>
                  @else
                    <span class="pill nowhere">nowhere</span>
                  @endif
                @endif
              </td>
              <td style="text-align:right;white-space:nowrap;">
                <form method="POST" action="{{ route('admin.brain.confirm') }}" style="display:inline;">
                  @csrf
                  <input type="hidden" name="news_item_id" value="{{ $r->id }}">
                  <button class="btn-sm go" type="submit">That is right</button>
                </form>
                <button class="btn-sm warn" type="button"
                        onclick="document.getElementById('fix-{{ $r->id }}').classList.toggle('open')">Not right</button>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>

  @if($proposals->isNotEmpty())
    <div class="card" style="border-color:#cfe0ec;">
      <h2>Proposed answers ({{ $proposals->count() }})</h2>
      <p class="sub">
        A review pass, offered as suggestions with the reason attached. <strong>None of these count
        until you accept one</strong> &mdash; a bench is worth having because a person confirmed each
        answer, and one confirmed by a machine would be measuring the model against its own opinion.
        Tick the ones you agree with, or handle them one at a time.
      </p>

      {{-- One form for the whole table. HTML will not nest a form inside a
           form, so the per-row buttons carry their own id rather than being
           little forms of their own - which keeps single-click accept working
           alongside the checkboxes instead of trading one for the other. --}}
      <form method="POST" action="{{ route('admin.brain.bench.accept') }}" id="proposals">
        @csrf

        <div class="bulkbar">
          <span class="count"><strong id="tickcount">0</strong> ticked</span>
          <button class="btn-sm go" type="submit">Accept ticked</button>
          <button class="btn-sm warn" type="submit"
                  formaction="{{ route('admin.brain.bench.reject') }}">Throw away ticked</button>
          <button class="btn-sm" type="submit" name="all" value="1"
                  onclick="return confirm('Accept all {{ $proposals->count() }} proposals without reading them?');">Accept all</button>
        </div>

        <table class="tidy">
          <thead>
            <tr>
              <th class="tickcol"><input type="checkbox" id="tickall" aria-label="Tick every proposal"></th>
              <th>Story</th><th style="width:150px;">Proposed</th><th>Why</th><th style="width:120px;"></th>
            </tr>
          </thead>
          <tbody>
            @foreach($proposals as $p)
              <tr>
                <td class="tickcol">
                  <input type="checkbox" name="ids[]" value="{{ $p->id }}" class="tick"
                         aria-label="Tick {{ \Illuminate\Support\Str::limit($p->title, 40) }}">
                </td>
                <td>
                  {{-- The publisher's article, in a new tab. Agreeing with a
                       proposal without being able to read the story is the same
                       guesswork the evidence line was added to end. --}}
                  <a href="{{ $p->url }}" target="_blank" rel="noopener nofollow"
                     class="storylink">{{ \Illuminate\Support\Str::limit($p->title, 62) }} &#8599;</a>

                  @if($p->read_text)
                    <p class="readtext">
                      <span class="mini">{{ $p->source }} &middot; {{ number_format((int) $p->read_chars) }} chars read</span><br>
                      {{ $p->read_text }}{{ (int) $p->read_chars > 260 ? '…' : '' }}
                    </p>
                  @else
                    <p class="readtext none">Nothing was read for this story &mdash; only the headline.</p>
                  @endif

                  <a class="mini" href="{{ route('admin.brain.prompt', ['news_item_id' => $p->news_item_id]) }}">
                    See the whole prompt it was judged from
                  </a>
                </td>
                <td class="mini">
                  @if(!$p->expect_keep)
                    <span class="pill nowhere">should not be published</span>
                  @else
                    {{ $p->expect_category ?: '—' }}<br>
                    @if($p->expect_nowhere)
                      <span class="pill nowhere">nowhere</span>
                    @elseif($p->expect_place)
                      <span class="pill">{{ $p->expect_place }}</span>
                    @endif
                  @endif
                </td>
                <td class="mini">{{ $p->proposed_reason }}</td>
                <td style="text-align:right;white-space:nowrap;">
                  <button class="btn-sm go" type="submit" name="only" value="{{ $p->id }}">Accept</button>
                  <button class="btn-sm warn" type="submit" name="only" value="{{ $p->id }}"
                          formaction="{{ route('admin.brain.bench.reject') }}">No</button>
                </td>
              </tr>
            @endforeach
          </tbody>
        </table>
      </form>
    </div>

    <script>
      (function () {
        var form = document.getElementById('proposals');
        if (!form) { return; }

        var all = form.querySelector('#tickall');
        var ticks = form.querySelectorAll('.tick');
        var count = form.querySelector('#tickcount');

        // The bar says how many are ticked, so a batch action is never taken
        // blind - the difference between accepting two and accepting eleven is
        // otherwise invisible at the moment of pressing.
        function refresh() {
          var n = 0;
          ticks.forEach(function (t) { if (t.checked) { n++; } });
          count.textContent = n;
          all.checked = n === ticks.length && n > 0;
          all.indeterminate = n > 0 && n < ticks.length;
        }

        all.addEventListener('change', function () {
          ticks.forEach(function (t) { t.checked = all.checked; });
          refresh();
        });

        ticks.forEach(function (t) { t.addEventListener('change', refresh); });
        refresh();
      })();
    </script>
  @endif

  {{-- Directly under the thing that fills it. This used to sit below the runs,
       which meant the answer to "where do I see what I just said" was three
       screens down, past a table of numbers. --}}
  <div class="card" id="reviewed">
    <h2>What you have said ({{ number_format($count) }})</h2>
    <p class="sub">
      Every answer you have confirmed or corrected, and what the bench will hold the model to.
      Change any of them &mdash; a bench answer that is itself wrong is worse than none, because it
      marks the model down for being right.
    </p>

    @if($items->isEmpty())
      <p class="mini">Nothing yet. Start with the table above.</p>
    @else
      <table class="tidy">
        <thead>
          <tr><th>Story</th><th style="width:110px;">Should be</th><th style="width:150px;">Where</th>
              <th style="width:130px;">How you answered</th><th style="width:150px;"></th></tr>
        </thead>
        <tbody>
          @foreach($items as $item)
            <tr>
              <td>
                {{ \Illuminate\Support\Str::limit($item->title, 66) }}
                <div class="fixform" id="edit-{{ $item->id }}">
                  <form method="POST" action="{{ route('admin.brain.bench.update', $item->id) }}">
                    @csrf @method('PUT')
                    <label class="mini" style="display:block;margin-bottom:6px;">
                      <input type="checkbox" name="expect_keep" value="1" @checked($item->expect_keep)>
                      should be published
                    </label>
                    <input type="text" name="expect_category" value="{{ $item->expect_category }}" placeholder="Category, e.g. government &amp; policy">
                    <input type="text" name="expect_place" value="{{ $item->expect_place }}" placeholder="Where it happened — leave blank if nowhere">
                    <label class="mini" style="display:block;margin-top:6px;">
                      <input type="checkbox" name="expect_nowhere" value="1" @checked($item->expect_nowhere)>
                      the right answer is <strong>nowhere</strong>
                    </label>
                    <input type="text" name="note" value="{{ $item->note }}" placeholder="Note to yourself" style="margin-top:6px;">
                    <div style="margin-top:8px;"><button class="btn-sm go" type="submit">Save the change</button></div>
                  </form>
                </div>
              </td>
              <td class="mini">
                {{ $item->expect_keep ? 'published' : 'discarded' }}
                @if($item->expect_category)<br>{{ $item->expect_category }}@endif
              </td>
              <td>
                @if($item->expect_nowhere)
                  <span class="pill nowhere">nowhere</span>
                @elseif($item->expect_place)
                  <span class="pill">{{ $item->expect_place }}</span>
                @else
                  <span class="mini">not asserted</span>
                @endif
              </td>
              <td class="mini">
                @if($item->note === 'Confirmed as correct')
                  <span class="pill">you agreed</span>
                @else
                  <span class="pill nowhere">you corrected</span>
                  @if($item->note)<div class="mini" style="margin-top:4px;">{{ $item->note }}</div>@endif
                @endif
              </td>
              <td style="text-align:right;white-space:nowrap;">
                <button class="btn-sm" type="button"
                        onclick="document.getElementById('edit-{{ $item->id }}').classList.toggle('open')">Change</button>
                <form method="POST" action="{{ route('admin.brain.bench.remove', $item->id) }}" style="display:inline;">
                  @csrf @method('DELETE')
                  <button class="btn-sm warn" type="submit">Remove</button>
                </form>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>

      <p class="mini" style="margin-top:12px;">
        Corrections are also kept in full on the
        <a href="{{ route('admin.brain.corrections') }}">Corrections</a> page, with what the AI said
        beside what you said it should have been.
      </p>
    @endif
  </div>

  <div class="card">
    <h2>Runs</h2>
    <p class="sub">
      <code>php artisan bench:run</code> &mdash; or <code>bench:run --adapter=&lt;name&gt;</code> to
      test a different model against the same answers. A run writes nothing to the feed.
    </p>

    @if($runs->isEmpty())
      <p class="mini">Never run.</p>
    @else
      <table class="tidy">
        <thead>
          <tr><th>When</th><th>Model</th><th>Keep</th><th>Category</th><th>Place</th><th>“Nowhere”</th><th>Note</th></tr>
        </thead>
        <tbody>
          @foreach($runs as $run)
            <tr>
              <td class="mini" style="white-space:nowrap;">{{ \Carbon\Carbon::parse($run->created_at)->diffForHumans() }}</td>
              <td class="mini">{{ $run->adapter }}<br><span class="mini">{{ $run->model }}</span></td>
              <td>{{ $run->parsed['kept_or_discarded'] ?? '—' }}%</td>
              <td>{{ $run->parsed['category'] ?? '—' }}%</td>
              <td>{{ $run->parsed['place'] ?? '—' }}%</td>
              <td>{{ $run->parsed['nowhere'] ?? '—' }}%</td>
              <td class="mini">{{ $run->note ?: '—' }}</td>
            </tr>
          @endforeach
        </tbody>
      </table>
      <p class="mini" style="margin-top:10px;">
        “Nowhere” is scored on its own because it is the answer this site gets wrong most often, and
        an overall place score hides it &mdash; a model that pins everything to its dateline still
        scores well on the stories that genuinely have a location.
      </p>
    @endif
  </div>
</div>
@endsection
