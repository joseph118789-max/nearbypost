@extends('layouts.admin')

@section('title', 'Briefing')

@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
<style>
  .addterm { display:grid; grid-template-columns:150px 160px 1fr; gap:8px; }
  .addterm input, .addterm select, .addterm textarea {
    padding:7px 10px; border:1px solid #cfe0ec; border-radius:8px; font-size:0.83rem; width:100%;
  }
  .addterm .wide { grid-column:1 / -1; }
  .addterm textarea { min-height:60px; font-family:inherit; }
  .term td form { display:flex; gap:6px; flex-wrap:wrap; align-items:flex-start; }
  .term input[type=text] { padding:5px 8px; border:1px solid #cfe0ec; border-radius:7px; font-size:0.8rem; width:100%; }
</style>
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

  <h1>Briefing</h1>
  <p class="lede">
    @if($countryName)
      Terms the model is told for {{ $countryName }}'s stories: this country's own, and the ones marked for every country.
      Switch the country beside Overview to see another country's briefing; a term added here belongs to {{ $countryName }}.
    @else
      All countries at once. A term added while "All countries" is selected is told to the model for every country;
      switch to one country to add a term that belongs to it alone.
    @endif
    <br>
    What an AI trained somewhere else does not know about this country. The model running today
    reads a lot of Southeast Asian text and arrives already knowing what a Menteri Besar is. A model
    trained mostly on American English will not &mdash; and it will not say so. It will file a Kedah
    story under whatever city the article was written in and move on.
    <br>
    <strong>The implication is the part that matters.</strong> “MB means Menteri Besar” is trivia.
    “A story about the MB of Kedah is a Kedah story whatever its dateline says” is the answer. A term
    with no implication is not sent at all.
  </p>

  @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="warn">{{ $errors->first() }}</div>@endif

  <div class="card">
    <h2>Add a term</h2>
    <p class="sub">{{ $sent }} of {{ count($terms) }} terms are being sent to the model right now.</p>
    <form method="POST" action="{{ route('admin.brain.briefing.add') }}" class="addterm">
      @csrf
      <input type="text" name="term" placeholder="MB" required>
      <select name="kind">
        @foreach($kinds as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach
      </select>
      <input type="text" name="expansion" placeholder="Menteri Besar, the head of a state government" required>
      <textarea class="wide" name="implication" placeholder="What does it mean for the answer? e.g. A story about the MB of Kedah is a Kedah story whatever its dateline says."></textarea>
      <div class="wide"><button class="btn-sm go" type="submit">Add</button></div>
    </form>
  </div>

  @foreach($kinds as $kind => $label)
    @php($group = collect($terms)->where('kind', $kind))
    @continue($group->isEmpty())

    <div class="card">
      <h2>{{ $label }}</h2>
      <table class="tidy">
        <thead><tr><th style="width:120px;">Term</th><th>Means / implies</th><th style="width:150px;"></th></tr></thead>
        <tbody>
          @foreach($group as $t)
            <tr class="term" style="{{ $t->is_active ? '' : 'opacity:.55;' }}">
              <td><strong>{{ $t->term }}</strong>
                @if(empty($t->country))<span class="tag" title="told to the model for every country">all countries</span>@elseif(empty($country))<span class="tag">{{ $t->country }}</span>@endif
                @if(!$t->implication)<br><span class="pill nowhere">not sent</span>@endif
              </td>
              <td>
                <form method="POST" action="{{ route('admin.brain.briefing.update', $t->id) }}" style="display:block;">
                  @csrf @method('PUT')
                  <input type="text" name="expansion" value="{{ $t->expansion }}" required>
                  <input type="text" name="implication" value="{{ $t->implication }}"
                         placeholder="What it means for the answer — without this it is not sent" style="margin-top:5px;">
                  <div style="margin-top:6px;display:flex;gap:10px;align-items:center;">
                    <label class="mini"><input type="checkbox" name="is_active" value="1" @checked($t->is_active)> in use</label>
                    <button class="btn-sm go" type="submit">Save</button>
                  </div>
                </form>
              </td>
              <td style="text-align:right;">
                <form method="POST" action="{{ route('admin.brain.briefing.delete', $t->id) }}"
                      onsubmit="return confirm('Remove {{ $t->term }} from the briefing?');">
                  @csrf @method('DELETE')
                  <button class="btn-sm warn" type="submit">Remove</button>
                </form>
              </td>
            </tr>
          @endforeach
        </tbody>
      </table>
    </div>
  @endforeach
</div>
@endsection
