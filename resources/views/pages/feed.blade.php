@extends('layouts.public')

@section('main')
  <p class="lede">{{ $intro }}</p>

  @if(!empty($unresolved))
    <div class="notice">
      {{ __('site.not_found_place', ['place' => $place]) }}
    </div>
  @endif

  <ol class="feed">
    @forelse($stories as $i => $story)
      @include('partials.story-card', ['story' => $story, 'i' => $i])
    @empty
      <li class="empty">
        <p>{{ __('site.empty_title') }}</p>
        <p>
          {{ __('site.try') }}
          <a href="{{ request()->fullUrlWithQuery(['days' => 30]) }}">{{ __('site.longer_period') }}</a>
          @if(!empty($showRadius))
            {{ __('site.or') }} <a href="{{ request()->fullUrlWithQuery(['radius' => 50]) }}">{{ __('site.wider_radius') }}</a>
          @endif
          @if(!empty($category))
            {{ __('site.or') }} <a href="{{ request()->fullUrlWithQuery(['category' => null, 'sub' => null]) }}">{{ __('site.all_topics') }}</a>
          @endif.
        </p>
      </li>
    @endforelse
  </ol>
@endsection

@section('after')
  {{-- One dialog reused by every headline. The summary is already in the page
       for a crawler and for a reader with no JavaScript; this only moves it
       into view. Without JavaScript the headline is an ordinary link to the
       publisher, which is where the reader was going anyway. --}}
  <dialog id="peek" class="peek">
    <article>
      <p class="peek-meta" id="peek-meta"></p>
      <h2 id="peek-title"></h2>
      <p id="peek-summary"></p>
      <div class="peek-actions">
        <a id="peek-link" href="#" target="_blank" rel="noopener nofollow">{{ __('site.read_at_source') }} &#8599;</a>
        <button type="button" onclick="document.getElementById('peek').close()">{{ __('site.close') }}</button>
      </div>
    </article>
  </dialog>

  <script>
    (function () {
      var dialog = document.getElementById('peek');
      if (!dialog || typeof dialog.showModal !== 'function') { return; }

      document.querySelectorAll('.feed .headline').forEach(function (link) {
        link.addEventListener('click', function (event) {
          var card = link.closest('li');
          var summary = card.querySelector('.summary');
          // Nothing to show is not worth a dialog: let the link do its job.
          if (!summary || !summary.textContent.trim()) { return; }

          event.preventDefault();
          document.getElementById('peek-title').textContent = link.textContent.trim();
          document.getElementById('peek-summary').textContent = summary.textContent.trim();
          document.getElementById('peek-meta').textContent = card.dataset.meta || '';
          document.getElementById('peek-link').href = link.href;
          dialog.showModal();
        });
      });

      // Tapping the backdrop closes it, which is what a phone user expects.
      dialog.addEventListener('click', function (event) {
        if (event.target === dialog) { dialog.close(); }
      });
    })();
  </script>
@endsection
