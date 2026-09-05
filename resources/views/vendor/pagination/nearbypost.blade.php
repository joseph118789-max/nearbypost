{{-- One paginator for every page. Laravel's default draws its arrows as
     inline SVGs sized by Tailwind, which none of these pages load, so the
     arrows filled the screen. Text arrows, inline styles, nothing to load. --}}
@if ($paginator->hasPages())
  <nav class="nbp-pages" role="navigation" aria-label="Pagination"
       style="display:flex;flex-wrap:wrap;align-items:center;gap:6px;margin:16px 0;font-size:0.9rem;">
    @if ($paginator->onFirstPage())
      <span style="padding:6px 12px;border-radius:40px;color:#b6c3ce;">&lsaquo; Previous</span>
    @else
      <a href="{{ $paginator->previousPageUrl() }}" rel="prev" style="padding:6px 12px;border-radius:40px;border:1px solid #d4e2ef;color:#1c5a7f;text-decoration:none;">&lsaquo; Previous</a>
    @endif

    @if (method_exists($paginator, 'links') && method_exists($paginator, 'lastPage'))
      @foreach ($elements ?? [] as $element)
        @if (is_string($element))
          <span style="padding:6px 4px;color:#8aa4b8;">{{ $element }}</span>
        @endif
        @if (is_array($element))
          @foreach ($element as $page => $url)
            @if ($page == $paginator->currentPage())
              <span aria-current="page" style="padding:6px 12px;border-radius:40px;background:#1c5a7f;color:#fff;font-weight:600;">{{ $page }}</span>
            @else
              <a href="{{ $url }}" style="padding:6px 12px;border-radius:40px;border:1px solid #d4e2ef;color:#1c5a7f;text-decoration:none;">{{ $page }}</a>
            @endif
          @endforeach
        @endif
      @endforeach
    @endif

    @if ($paginator->hasMorePages())
      <a href="{{ $paginator->nextPageUrl() }}" rel="next" style="padding:6px 12px;border-radius:40px;border:1px solid #d4e2ef;color:#1c5a7f;text-decoration:none;">Next &rsaquo;</a>
    @else
      <span style="padding:6px 12px;border-radius:40px;color:#b6c3ce;">Next &rsaquo;</span>
    @endif

    @if (method_exists($paginator, 'total'))
      <span style="margin-left:auto;color:#8aa4b8;font-size:0.82rem;">{{ $paginator->firstItem() }}&ndash;{{ $paginator->lastItem() }} of {{ number_format($paginator->total()) }}</span>
    @endif
  </nav>
@endif
