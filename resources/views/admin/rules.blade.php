@extends('layouts.admin')

@section('title', 'Rules')

@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
<style>
  .rule { background:#fff;border:1px solid #e2edf6;border-radius:18px;padding:16px 18px;margin-bottom:10px; }
  .rule.off { opacity:0.55; }
  .rulerow { display:flex; gap:10px; align-items:flex-start; flex-wrap:wrap; }
  .rulerow textarea { flex:1 1 380px; }
  .rulerow .side { display:flex; flex-direction:column; gap:8px; min-width:170px; }
  .preview { background:#f8fafc;border:1px solid #e2edf6;border-radius:18px;padding:18px;margin-bottom:22px; }
  .preview h3 { font-size:0.85rem;color:#1c5a7f;margin-bottom:8px; }
  .preview ol { margin:0 0 14px 20px;font-size:0.84rem;color:#34505f;line-height:1.7; }
  .preview .none { font-size:0.84rem;color:#8aa4b8; }
</style>
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

  <h1>Rules</h1>
  <p class="lede">
    What the reviewer must refuse, in your words. These are given to it with every submission and
    override its own judgement about what is acceptable &mdash; and when something is refused for
    breaking one, the writer is told which rule it broke, so they can fix it.
    <br>
    Write them as sentences, not keywords. &ldquo;No jokes at the expense of a named person&rdquo;
    works; &ldquo;jokes&rdquo; does not.
  </p>

  @if(session('status'))
    <div class="flash">{{ session('status') }}</div>
  @endif

  @if($errors->any())
    <div class="warn">{{ $errors->first() }}</div>
  @endif

  {{-- What the reviewer is actually being told right now, assembled the same
       way the prompt assembles it. --}}
  <div class="preview">
    <h3>What the reviewer is being told right now</h3>

    <strong style="font-size:0.8rem;color:#5f7f9a;">For reader submissions</strong>
    @if(count($inUse['contributor']))
      <ol>@foreach($inUse['contributor'] as $r)<li>{{ $r }}</li>@endforeach</ol>
    @else
      <p class="none">Nothing &mdash; only its built-in news-value test applies.</p>
    @endif

    <strong style="font-size:0.8rem;color:#5f7f9a;">For gathered articles</strong>
    @if(count($inUse['scraper']))
      <ol>@foreach($inUse['scraper'] as $r)<li>{{ $r }}</li>@endforeach</ol>
    @else
      <p class="none">Nothing &mdash; only its built-in news-value test applies.</p>
    @endif
  </div>

  <h2>Add a rule</h2>

  <form class="rule" method="post" action="{{ route('admin.rules.store') }}">
    @csrf
    <div class="rulerow">
      <textarea class="ta" name="rule" rows="2" maxlength="400" required
                placeholder="No personal opinion presented as reporting."></textarea>
      <div class="side">
        <select class="inp" name="applies_to">
          <option value="both">Everything</option>
          <option value="contributor">Reader submissions only</option>
          <option value="scraper">Gathered articles only</option>
        </select>
        <button class="btn btn-primary" type="submit">Add rule</button>
      </div>
    </div>
  </form>

  <h2>Rules ({{ count($rules) }})</h2>

  @if(count($rules) === 0)
    <p class="lede">
      No rules yet. There is a starting set drawn from the kinds of thing that get refused most
      often &mdash; add it and edit from there, or write your own above.
    </p>
    <form method="post" action="{{ route('admin.rules.seed') }}">
      @csrf
      <button class="btn btn-primary" type="submit">Add {{ count($suggested) }} starting rules</button>
    </form>
  @endif

  @foreach($rules as $rule)
    <form class="rule {{ $rule->is_active ? '' : 'off' }}" method="post"
          action="{{ route('admin.rules.update', ['id' => $rule->id]) }}">
      @csrf
      @method('PUT')

      <div class="rulerow">
        <textarea class="ta" name="rule" rows="2" maxlength="400" required>{{ $rule->rule }}</textarea>

        <div class="side">
          <select class="inp" name="applies_to">
            <option value="both" {{ $rule->applies_to === 'both' ? 'selected' : '' }}>Everything</option>
            <option value="contributor" {{ $rule->applies_to === 'contributor' ? 'selected' : '' }}>Reader submissions only</option>
            <option value="scraper" {{ $rule->applies_to === 'scraper' ? 'selected' : '' }}>Gathered articles only</option>
          </select>

          <label class="chk">
            <input type="checkbox" name="is_active" value="1" {{ $rule->is_active ? 'checked' : '' }}>
            In force
          </label>

          <div style="display:flex;gap:8px;">
            <button class="btn btn-primary" type="submit">Save</button>
          </div>
        </div>
      </div>
    </form>

    <form method="post" action="{{ route('admin.rules.destroy', ['id' => $rule->id]) }}"
          onsubmit="return confirm('Remove this rule?');" style="margin:-6px 0 14px 4px;">
      @csrf
      @method('DELETE')
      <button class="btn" type="submit" style="font-size:0.76rem;padding:5px 14px;">Remove</button>
    </form>
  @endforeach

  <h2>What is not a rule here</h2>
  <p class="lede">
    <strong>Duplicates</strong> are not handled by the reviewer and a rule about them would do
    nothing. The same story reaching us from two publishers is caught by comparing the stories
    themselves &mdash; by address, by headline, and by how similar the text is &mdash; both when a
    batch is gathered and again before anything is served. A model reading one story in isolation
    cannot know it has seen another.
  </p>
</div>
@endsection
