{{--
  Floating support chatbot loader. Include with a $surface of 'landing',
  'client', or 'hq'. Emits the stylesheet + the self-contained widget script
  with surface + endpoints as data-* attributes. Cache-busted by file mtime.

  The server re-derives + clamps the surface (a logged-out caller can't get the
  guide prompt by passing surface=hq), so this attribute is a hint, not a trust
  boundary. CSRF token is only meaningful inside panels; the public routes are
  CSRF-exempt and the landing page leaves it blank.
--}}
@php
    $smtSurface = $surface ?? 'landing';
    $smtCssVer = @filemtime(public_path('brand/smt-chat.css')) ?: '1';
    $smtJsVer = @filemtime(public_path('brand/smt-chat.js')) ?: '1';
    $smtUser = auth()->user();
@endphp
<link rel="stylesheet" href="{{ asset('brand/smt-chat.css') }}?v={{ $smtCssVer }}">
<script
    src="{{ asset('brand/smt-chat.js') }}?v={{ $smtJsVer }}"
    data-surface="{{ $smtSurface }}"
    data-chat-url="{{ url('/api/chatbot') }}"
    data-contact-url="{{ url('/api/contact') }}"
    data-identify-url="{{ url('/api/chatbot/identify') }}"
    @if ($smtUser)
        data-user-name="{{ $smtUser->name }}"
        data-user-email="{{ $smtUser->email }}"
    @endif
    data-csrf="{{ $smtSurface === 'landing' ? '' : csrf_token() }}"
    defer></script>
