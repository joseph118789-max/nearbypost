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
      mistake is recorded and measured from now on.
    </p>

    @if($recent->isEmpty())
      <p class="mini">Everything recent is already on the bench.</p>
    @else
      <table class="tidy">
        <thead><tr><th>Story</th><th style="width:170px;">The AI said</th><th style="width:210px;"></th></tr></thead>
        <tbody>
          @foreach($recent as $r)
            <tr>
              <td>
                {{ \Illuminate\Support\Str::limit($r->title, 78) }}
                <div class="fixform" id="fix-{{ $r->id }}">
                  <form method="POST" action="{{ route('admin.brain.correct') }}">
                    @csrf
                    <input type="hidden" name="news_item_id" value="{{ $r->id }}">
                    <select name="field" required>
                      @foreach($fields as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
                    </select>
                    <input type="text" name="correct_answer" placeholder="The right answer — a place, a category, or leave blank">
                    <input type="text" name="reason" placeholder="Why? This is what teaches the next rule.">
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

  <div class="card">
    <h2>Confirmed answers ({{ number_format($count) }})</h2>
    @if($items->isEmpty())
      <p class="mini">Nothing yet.</p>
    @else
      <table class="tidy">
        <thead><tr><th>Story</th><th style="width:110px;">Should be</th><th style="width:160px;">Where</th><th style="width:90px;"></th></tr></thead>
        <tbody>
          @foreach($items as $item)
            <tr>
              <td>{{ \Illuminate\Support\Str::limit($item->title, 72) }}
                @if($item->note)<div class="mini">{{ $item->note }}</div>@endif
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
              <td style="text-align:right;">
                <form method="POST" action="{{ route('admin.brain.bench.remove', $item->id) }}">
                  @csrf @method('DELETE')
                  <button class="btn-sm warn" type="submit">Remove</button>
                </form>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    @endif
  </div>
</div>
@endsection
