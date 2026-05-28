{{--
  Anonymous Blade component shell for long-form info pages
  (Privacy, Terms, Security, Changelog). Keeps prose styling in one place
  so the four pages cannot drift.

  Props:
    eyebrow  — small uppercase kicker (e.g. "Legal · Privacy")
    heading  — page H1 HTML (may contain <em>)
    updated  — effective / last-updated date string (optional)
    intro    — lead paragraph HTML (optional)
  Slot:
    the page body (Blade markup)
--}}
@props([
  'eyebrow' => '',
  'heading' => '',
  'updated' => null,
  'intro' => null,
])

<section class="section-pad" style="padding-top: clamp(140px, 18vh, 220px);">
  <div class="wrap">
    <div class="section-head">
      <div class="label"><span class="eyebrow">{{ $eyebrow }}</span></div>
      <h1 class="title section-h">{!! $heading !!}</h1>
      @if ($updated)
        <div class="aside">Last updated {{ $updated }}</div>
      @endif
    </div>

    <div class="grid-12">
      <div class="legal-prose rvl" style="grid-column: 1 / span 8;">
        @if ($intro)
          <p class="lead" style="margin-bottom: 36px;">{!! $intro !!}</p>
        @endif
        {{ $slot }}
      </div>
    </div>
  </div>
</section>

@push('head')
<style>
  .legal-prose { color: var(--ink-2); line-height: 1.7; font-size: 16px; }
  .legal-prose h2 {
    font-family: var(--sans); font-weight: 500;
    font-size: clamp(20px, 1.6vw, 26px); line-height: 1.25;
    letter-spacing: -0.02em; color: var(--ink);
    margin: 48px 0 14px;
  }
  .legal-prose h2:first-child { margin-top: 0; }
  .legal-prose h3 {
    font-family: var(--sans); font-weight: 600; font-size: 16px;
    color: var(--ink); margin: 28px 0 8px;
  }
  .legal-prose p { margin: 0 0 16px; max-width: 64ch; }
  .legal-prose ul, .legal-prose ol { margin: 0 0 16px; padding-left: 22px; max-width: 64ch; }
  .legal-prose li { margin-bottom: 8px; }
  .legal-prose a { color: var(--primary-dark); text-decoration: underline; }
  .legal-prose strong { color: var(--ink); font-weight: 600; }
  .legal-prose .meta {
    font-family: var(--mono); font-size: 12px; letter-spacing: 0.08em;
    text-transform: uppercase; color: var(--mute);
  }
  .legal-prose table { width: 100%; border-collapse: collapse; margin: 0 0 24px; font-size: 14px; }
  .legal-prose th, .legal-prose td {
    text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--line-soft); vertical-align: top;
  }
  .legal-prose th { color: var(--ink); font-weight: 600; }
  @media (max-width: 860px) {
    .legal-prose { grid-column: 1 / -1 !important; }
  }
</style>
@endpush
