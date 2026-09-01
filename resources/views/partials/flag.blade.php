{{-- A flag drawn rather than typed.

     Emoji flags are regional-indicator pairs, and Windows ships no glyphs for
     them at all - so 🇬🇧 renders as the literal letters "GB" on the most common
     desktop platform there is. These are simplified but recognisable at 20px,
     and identical on every device.

     Flags label countries and not languages, which is the usual imperfect
     compromise: Chinese is not China, and the readers of the Chinese edition
     are Malaysian. It stands in for a language only where there is room for a
     single mark; the open menu names each language in its own script. --}}
@php $code = $code ?? \App\Support\Loc::current(); @endphp

@switch($code)
  @case('ms')
    <svg class="flag" viewBox="0 0 60 30" role="img" aria-label="Bahasa Melayu">
      <rect width="60" height="30" fill="#fff"/>
      @for($i = 0; $i < 7; $i++)
        <rect y="{{ $i * 4.286 }}" width="60" height="2.143" fill="#cc0001"/>
      @endfor
      <rect width="34" height="17.14" fill="#010066"/>
      <circle cx="12" cy="8.6" r="5" fill="#fc0"/>
      <circle cx="14.2" cy="8.6" r="4.2" fill="#010066"/>
      <path d="M22 4.4l1.1 3.3h3.4l-2.8 2 1.1 3.3-2.8-2-2.8 2 1.1-3.3-2.8-2h3.4z" fill="#fc0"/>
    </svg>
    @break

  @case('zh')
    <svg class="flag" viewBox="0 0 60 30" role="img" aria-label="中文">
      <rect width="60" height="30" fill="#ee1c25"/>
      <path d="M10 4l1.8 5.5h5.8l-4.7 3.4 1.8 5.5-4.7-3.4-4.7 3.4 1.8-5.5-4.7-3.4h5.8z" fill="#ff0"/>
      <circle cx="21" cy="4" r="1.5" fill="#ff0"/>
      <circle cx="25" cy="7.5" r="1.5" fill="#ff0"/>
      <circle cx="25" cy="12.5" r="1.5" fill="#ff0"/>
      <circle cx="21" cy="16" r="1.5" fill="#ff0"/>
    </svg>
    @break

  @default
    <svg class="flag" viewBox="0 0 60 30" role="img" aria-label="English">
      <rect width="60" height="30" fill="#012169"/>
      <path d="M0 0l60 30M60 0L0 30" stroke="#fff" stroke-width="6"/>
      <path d="M0 0l60 30M60 0L0 30" stroke="#c8102e" stroke-width="3.5"/>
      <path d="M30 0v30M0 15h60" stroke="#fff" stroke-width="10"/>
      <path d="M30 0v30M0 15h60" stroke="#c8102e" stroke-width="6"/>
    </svg>
@endswitch
