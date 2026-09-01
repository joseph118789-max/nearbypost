@extends('layouts.admin')
@section('title', 'Sources by country')
@push('styles')
@include('admin.sources._styles')
@include('admin.brain._styles')
@endpush

@section('content')
<div class="srcpage">
  @include('admin.brain._nav')

  <h1>Sources by country</h1>
  <p class="lede">
    Pick a country to work on its publishers. Writing this handbook is local knowledge &mdash; the
    person who knows a Malaysian paper mislabels its timezone is not the person who will know the
    equivalent about a British one &mdash; so each country can be handed to whoever can actually
    do it.
  </p>

  @if(session('status'))<div class="flash">{{ session('status') }}</div>@endif
  @if($errors->any())<div class="warn">{{ $errors->first() }}</div>@endif

  <table class="srctable">
    <thead><tr><th>Country</th><th>Publishers</th><th>Sections</th><th>Switched on</th><th>Stories</th><th>Documented</th></tr></thead>
    <tbody>
      @foreach($rows as $row)
        <tr>
          <td>
            <a class="srcname" href="{{ route('admin.sources.index', ['country' => $row->country]) }}">
              {{ $names[$row->country] ?? $row->country }}
            </a>
            <div class="srcurl">{{ $row->country }}</div>
          </td>
          <td class="num">{{ $row->publishers }}</td>
          <td class="num">{{ $row->sections }}</td>
          <td class="num">{{ $row->active }}</td>
          <td class="num">{{ number_format($row->items) }}</td>
          <td class="num">
            {{ $row->documented }}/{{ $row->total }}
            @if($row->documented == $row->total)
              <span class="badge badge-ok">all</span>
            @endif
          </td>
        </tr>
      @endforeach
    </tbody>
  </table>

  <h2>Start a new country</h2>
  <form class="srccard" method="post" action="{{ route('admin.sources.countries.add') }}">
    @csrf
    <div class="srcrow">
      <div>
        <label class="lbl" for="c">Country</label>
        <p class="hint">Opens an empty list you can add publishers to.</p>
        <select class="inp" id="c" name="country">
          @foreach($names as $code => $label)
            <option value="{{ $code }}">{{ $label }} ({{ $code }})</option>
          @endforeach
        </select>
      </div>
      <div class="onoff"><button class="btn btn-primary" type="submit">Open it</button></div>
    </div>
  </form>

  <h2>Not working</h2>
  <p class="lede">
    Sources whose feed has stopped answering, or whose article text never arrives &mdash; the
    quiet failure, where stories keep appearing but are classified and located from a one-line
    teaser. <a href="{{ route('admin.sources.failing') }}">See what is failing</a>.
  </p>

  <h2>Do not visit</h2>
  <p class="lede">
    {{ $blocked }} address(es) the crawler has been told to leave alone. Deleting a junk source is
    not enough on its own &mdash; discovery finds it again &mdash; so removals can be made to stick.
    <a href="{{ route('admin.sources.blocked') }}">See the list</a>.
  </p>
</div>
@endsection
