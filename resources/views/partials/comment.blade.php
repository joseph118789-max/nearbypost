<div class="comment {{ $c->parent_id ? 'reply' : '' }} {{ $c->status }}" id="c{{ $c->id }}">
  @if($c->status === 'hidden')
    <p>{{ __('site.comment_hidden') }}</p>
  @elseif(isset($blocked[$c->user_id]) && $blocked[$c->user_id] === 'mute')
    <p class="mini">{{ __('site.comment_muted') }}</p>
  @else
    <span class="who"><a href="{{ url('/@' . $c->username) }}">{{ '@' . $c->username }}</a></span>
    <span class="mini" title="{{ __('site.credibility_help') }}">{{ \App\Services\Community\CommunityTrust::levelName((int) ($c->credibility ?? 50)) }} · {{ (int) ($c->credibility ?? 50) }}/100 · {{ trans_choice('site.reports_by_poster', (int) ($c->posts ?? 0)) }} · {{ \Carbon\Carbon::parse($c->created_at)->diffForHumans() }}@if($c->edited_at) · {{ __('site.edited') }}@endif @if($c->kind === 'correction') · <b>{{ __('site.community_correction') }}</b>@endif</span>
    <p>{{ $c->body }}</p>
    <p class="translated" id="ct{{ $c->id }}" hidden></p>
    <div class="tools">
      <button type="button" class="tr-comment" data-id="{{ $c->id }}" data-url="{{ route('community.comment.translate', ['comment' => $c->id]) }}?to={{ app()->getLocale() }}"
              data-show="{{ __('site.translate_comment') }}" data-hide="{{ __('site.hide_translation') }}" data-same="{{ __('site.already_in_language') }}" data-note="{{ __('site.translated_by_ai') }}">{{ __('site.translate_comment') }}</button>
      @auth('web')
        @if(!$c->parent_id)
          <form method="post" action="{{ route('community.comment', ['id' => $post->id]) }}" class="inline" onsubmit="var t=prompt('{{ __('site.reply') }}'); if(!t) return false; this.body.value=t;">@csrf<input type="hidden" name="parent_id" value="{{ $c->id }}"><input type="hidden" name="body" value=""><button type="submit">{{ __('site.reply') }}</button></form>
        @endif
        @if((int) $c->user_id === (int) auth('web')->id())
          @if(abs(now()->diffInMinutes($c->created_at)) <= 30)
            <form method="post" action="{{ route('community.comment.edit', ['comment' => $c->id]) }}" class="inline" onsubmit="var t=prompt('{{ __('site.edit') }}', this.body.value); if(!t) return false; this.body.value=t;">@csrf @method('PATCH')<input type="hidden" name="body" value="{{ $c->body }}"><button type="submit">{{ __('site.edit') }}</button></form>
          @endif
          <form method="post" action="{{ route('community.comment.delete', ['comment' => $c->id]) }}" class="inline" onsubmit="return confirm('{{ __('site.delete') }}?')">@csrf @method('DELETE')<button type="submit">{{ __('site.delete') }}</button></form>
        @else
          <form method="post" action="{{ route('community.comment.report', ['comment' => $c->id]) }}" class="inline">@csrf<input type="hidden" name="reason" value="other"><button type="submit">{{ __('site.report_this') }}</button></form>
          <form method="post" action="{{ route('community.block', ['username' => $c->username]) }}" class="inline">@csrf<input type="hidden" name="kind" value="mute"><button type="submit">{{ __('site.mute') }}</button></form>
          <form method="post" action="{{ route('community.block', ['username' => $c->username]) }}" class="inline">@csrf<input type="hidden" name="kind" value="block"><button type="submit">{{ __('site.block') }}</button></form>
        @endif
      @endauth
    </div>
  @endif
  @foreach($replies ?? [] as $r)
    @include('partials.comment', ['c' => $r, 'post' => $post, 'blocked' => $blocked, 'replies' => collect()])
  @endforeach
</div>
