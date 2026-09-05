{{-- Reactions, report and comments for one community report, loaded into the
     feed popup (owner, 3 Sep: "allow to do all the rating, comment from the pop up").
     Every form here posts through fetch from the popup's script and the panel
     is reloaded; without JavaScript the same forms work as ordinary posts. --}}
<div class="community-actions" data-panel-actions>
  @foreach(['saw' => 'saw_this_too', 'helpful' => 'helpful', 'wrong' => 'something_wrong'] as $type => $key)
    <form method="post" action="{{ route('community.react', ['id' => $post->id]) }}" class="inline">
      @csrf
      <input type="hidden" name="type" value="{{ $type }}">
      <button type="submit" class="chip-btn {{ $type }} {{ $mine === $type ? 'on' : '' }}" aria-pressed="{{ $mine === $type ? 'true' : 'false' }}">{{ __('site.' . $key) }} <b>{{ $counts[$type] ?? 0 }}</b></button>
    </form>
  @endforeach
  <details class="report-box">
    <summary class="chip-btn">{{ __('site.report_this') }}</summary>
    <form method="post" action="{{ route('community.report', ['id' => $post->id]) }}">
      @csrf
      <select class="modal-input" name="reason" required>
        @foreach(\App\Http\Controllers\CommunityController::REPORT_REASONS as $r)
          <option value="{{ $r }}">{{ __('site.reason_' . $r) }}</option>
        @endforeach
      </select>
      <textarea class="modal-input" name="explanation" rows="2" maxlength="1000" placeholder="{{ __('site.report_explain') }}"></textarea>
      <button type="submit" class="desktop-action-btn primary">{{ __('site.send_report') }}</button>
    </form>
  </details>
</div>
@if(!empty($flash))<p class="notice">{{ $flash }}</p>@endif

<section class="comments">
  <h3>{{ __('site.comments') }} <small>{{ $comments->where('status', 'published')->count() }}</small></h3>
  @php $byParent = $comments->groupBy(fn ($c) => $c->parent_id ?: 0); @endphp
  @foreach($byParent->get(0, collect()) as $c)
    @include('partials.comment', ['c' => $c, 'post' => $post, 'blocked' => $blocked, 'replies' => $byParent->get($c->id, collect())])
  @endforeach
  @auth('web')
    <form method="post" action="{{ route('community.comment', ['id' => $post->id]) }}" class="comment-form">
      @csrf
      <textarea class="modal-input" name="body" rows="2" required minlength="2" maxlength="2000" placeholder="{{ __('site.write_comment') }}"></textarea>
      <button type="submit" class="desktop-action-btn primary">{{ __('site.post_comment') }}</button>
    </form>
  @else
    <p class="mini"><a href="{{ route('login') }}">{{ __('site.sign_in_to_comment') }}</a></p>
  @endauth
</section>
