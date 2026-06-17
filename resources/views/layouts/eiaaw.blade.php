<!DOCTYPE html>
<html lang="en-MY">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">

  <!-- Meta Pixel Code -->
  <script>
  !function(f,b,e,v,n,t,s)
  {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
  n.callMethod.apply(n,arguments):n.queue.push(arguments)};
  if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
  n.queue=[];t=b.createElement(e);t.async=!0;
  t.src=v;s=b.getElementsByTagName(e)[0];
  s.parentNode.insertBefore(t,s)}(window,document,'script',
  'https://connect.facebook.net/en_US/fbevents.js');
  fbq('init', '1516303113491153');
  fbq('track', 'PageView');
  </script>
  <noscript><img height="1" width="1" style="display:none"
  src="https://www.facebook.com/tr?id=1516303113491153&ev=PageView&noscript=1"/></noscript>
  <!-- End Meta Pixel Code -->

  <title>@yield('title', 'EIAAW Social Media Team — AI that shows you the receipts')</title>
  <meta name="description" content="@yield('description', 'The autonomous AI social media team for SMBs and agencies. Six specialised agents, hard compliance gate, and provenance receipts on every post. Built for Malaysia and APAC.')">
  {{-- Canonical: keep the real per-page path but pin the host to the production
       host, so crawls of alternate hosts (e.g. via Railway's *.up.railway.app or
       a www variant) self-canonicalize to one URL instead of creating duplicate
       indexable pages. --}}
  @php
      $smtPath = '/'.ltrim(request()->path() === '/' ? '' : request()->path(), '/');
      $smtCanonical = rtrim('https://smt.eiaawsolutions.com'.$smtPath, '/');
      $smtCanonical = $smtCanonical === 'https://smt.eiaawsolutions.com' ? $smtCanonical.'/' : $smtCanonical;
  @endphp
  <link rel="canonical" href="{{ $smtCanonical }}">
  <meta property="og:url" content="{{ $smtCanonical }}">
  <meta name="theme-color" content="#11766A">
  <meta name="apple-mobile-web-app-title" content="EIAAW SMT">
  <meta name="application-name" content="EIAAW SMT">
  <meta name="format-detection" content="telephone=no">
  <link rel="icon" type="image/png" href="/brand/shield.png">
  <link rel="apple-touch-icon" href="/brand/shield.png">

  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Instrument+Serif:ital@0;1&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">

  <link rel="stylesheet" href="/brand/eiaaw.css">

  @stack('head')
</head>
<body class="@yield('body_class')">

<nav class="nav" id="siteNav">
  <a href="/" class="nav-logo">
    <img src="/brand/shield.png" alt="EIAAW Solutions shield">
    <span class="nav-logo-text">
      <strong>EIAAW SOLUTIONS</strong>
      <small>Social Media Team</small>
    </span>
  </a>
  <div class="nav-links">
    <a href="{{ url('/') }}#how">How it works</a>
    <a href="{{ url('/') }}#agents">The team</a>
    <a href="{{ url('/') }}#receipts">Receipts</a>
    <a href="{{ url('/') }}#pricing">Pricing</a>
    @auth
      <a href="{{ url('/agency') }}" class="btn btn-primary">Open dashboard <span class="arrow">&rarr;</span></a>
    @else
      <a href="{{ url('/login') }}" class="btn btn-outline">Log in</a>
      <a href="{{ url('/signup') }}" class="btn btn-primary">Start free <span class="arrow">&rarr;</span></a>
    @endauth
  </div>
</nav>

@yield('content')

<footer>
  <div class="wrap">
    <div class="footer-grid">
      <div class="footer-lockup-blk">
        <div class="lockup">
          <img src="/brand/shield.png" alt="EIAAW Solutions">
          <div class="brand">
            <strong>EIAAW SOLUTIONS</strong>
            <span>AI &middot; Human Partnerships</span>
          </div>
        </div>
        <p class="footer-statement">
          We build AI that <em>shows you the receipts</em> — every caption, every visual, every recommendation grounded in your real brand evidence. No hallucinated metrics. No fabricated predictions. Just work you can audit.
        </p>
        <div class="social-row">
          <a class="social-link" href="https://www.linkedin.com/in/eiaawsolutions" target="_blank" rel="noopener me" aria-label="EIAAW Solutions on LinkedIn" title="LinkedIn">
            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="currentColor"><path d="M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.85 0-2.14 1.45-2.14 2.94v5.67H9.34V9h3.42v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28zM5.34 7.43a2.07 2.07 0 1 1 0-4.14 2.07 2.07 0 0 1 0 4.14zM7.12 20.45H3.55V9h3.57v11.45zM22.22 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.22.79 24 1.77 24h20.45c.98 0 1.78-.78 1.78-1.73V1.73C24 .77 23.2 0 22.22 0z"/></svg>
          </a>
          <a class="social-link" href="https://www.youtube.com/@EIAAWSOLUTIONS" target="_blank" rel="noopener me" aria-label="EIAAW Solutions on YouTube" title="YouTube">
            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="currentColor"><path d="M23.5 6.2a3.02 3.02 0 0 0-2.12-2.14C19.5 3.55 12 3.55 12 3.55s-7.5 0-9.38.51A3.02 3.02 0 0 0 .5 6.2C0 8.08 0 12 0 12s0 3.92.5 5.8a3.02 3.02 0 0 0 2.12 2.14c1.88.51 9.38.51 9.38.51s7.5 0 9.38-.51a3.02 3.02 0 0 0 2.12-2.14C24 15.92 24 12 24 12s0-3.92-.5-5.8zM9.6 15.6V8.4l6.27 3.6-6.27 3.6z"/></svg>
          </a>
          <a class="social-link" href="https://www.instagram.com/eiaawsolutions" target="_blank" rel="noopener me" aria-label="EIAAW Solutions on Instagram" title="Instagram">
            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="currentColor"><path d="M12 2.16c3.2 0 3.58.01 4.85.07 1.17.05 1.8.25 2.23.41.56.22.96.48 1.38.9.42.42.68.82.9 1.38.16.42.36 1.06.41 2.23.06 1.27.07 1.65.07 4.85s-.01 3.58-.07 4.85c-.05 1.17-.25 1.8-.41 2.23-.22.56-.48.96-.9 1.38-.42.42-.82.68-1.38.9-.42.16-1.06.36-2.23.41-1.27.06-1.65.07-4.85.07s-3.58-.01-4.85-.07c-1.17-.05-1.8-.25-2.23-.41a3.72 3.72 0 0 1-1.38-.9 3.72 3.72 0 0 1-.9-1.38c-.16-.42-.36-1.06-.41-2.23-.06-1.27-.07-1.65-.07-4.85s.01-3.58.07-4.85c.05-1.17.25-1.8.41-2.23.22-.56.48-.96.9-1.38.42-.42.82-.68 1.38-.9.42-.16 1.06-.36 2.23-.41C8.42 2.17 8.8 2.16 12 2.16zM12 0C8.74 0 8.33.01 7.05.07 5.78.13 4.9.33 4.14.63c-.79.3-1.46.72-2.12 1.38C1.36 2.67.94 3.34.63 4.14c-.3.76-.5 1.64-.56 2.91C.01 8.33 0 8.74 0 12s.01 3.67.07 4.95c.06 1.27.26 2.15.56 2.91.3.8.72 1.47 1.38 2.13.66.66 1.33 1.08 2.12 1.38.76.3 1.64.5 2.91.56C8.33 23.99 8.74 24 12 24s3.67-.01 4.95-.07c1.27-.06 2.15-.26 2.91-.56a5.88 5.88 0 0 0 2.13-1.38c.66-.66 1.08-1.33 1.38-2.13.3-.76.5-1.64.56-2.91.06-1.28.07-1.69.07-4.95s-.01-3.67-.07-4.95c-.06-1.27-.26-2.15-.56-2.91a5.88 5.88 0 0 0-1.38-2.13A5.88 5.88 0 0 0 19.86.63c-.76-.3-1.64-.5-2.91-.56C15.67.01 15.26 0 12 0zm0 5.84a6.16 6.16 0 1 0 0 12.32 6.16 6.16 0 0 0 0-12.32zM12 16a4 4 0 1 1 0-8 4 4 0 0 1 0 8zm7.85-10.41a1.44 1.44 0 1 1-2.88 0 1.44 1.44 0 0 1 2.88 0z"/></svg>
          </a>
          <a class="social-link" href="https://www.threads.com/@eiaawsolutions" target="_blank" rel="noopener me" aria-label="EIAAW Solutions on Threads" title="Threads">
            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="currentColor"><path d="M12.19 0h-.38C5.46.04.04 5.46 0 11.81v.38C.04 18.54 5.46 23.96 11.81 24h.38c6.35-.04 11.77-5.46 11.81-11.81v-.38C23.96 5.46 18.54.04 12.19 0zm.06 18.36c-2.7 0-4.34-1.25-5.27-2.95-.66-1.2-1-2.7-1.06-4.48v-.01c.06-1.78.4-3.27 1.06-4.47.93-1.71 2.57-2.96 5.27-2.96 1.86 0 3.4.58 4.49 1.7.65.66 1.13 1.48 1.45 2.45l-1.74.6c-.24-.7-.57-1.27-.99-1.7-.7-.71-1.71-1.08-3.21-1.08-1.93 0-3.06.86-3.7 2.02-.42.77-.66 1.83-.72 3.17v.27c0 1.34.24 2.4.66 3.17.64 1.16 1.77 2.02 3.7 2.02 1.5 0 2.51-.37 3.21-1.08.3-.3.55-.68.74-1.13-.71.18-1.5.27-2.35.27-2.4 0-3.92-1.13-3.92-2.91 0-1.6 1.45-2.79 3.57-2.79 1.52 0 2.78.46 3.57 1.36.04-.18.06-.37.06-.57 0-1.3-.86-2.3-2.66-2.3-.1 0-.2 0-.29.01l-.18-1.71c.16-.01.31-.02.47-.02 2.85 0 4.36 1.74 4.36 4.02 0 2.81-2.13 5.42-6.99 5.42z"/></svg>
          </a>
          <a class="social-link" href="https://www.tiktok.com/@eiaawsolutions" target="_blank" rel="noopener me" aria-label="EIAAW Solutions on TikTok" title="TikTok">
            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="currentColor"><path d="M12.53.02C13.84 0 15.14.01 16.44 0c.08 1.53.63 3.09 1.75 4.17 1.12 1.11 2.7 1.62 4.24 1.79v4.03c-1.44-.05-2.89-.35-4.2-.97-.57-.26-1.1-.59-1.62-.93-.01 2.92.01 5.84-.02 8.75-.08 1.4-.54 2.79-1.35 3.94-1.31 1.92-3.58 3.17-5.91 3.21-1.43.08-2.86-.31-4.08-1.03-2.02-1.19-3.44-3.37-3.65-5.71-.02-.5-.03-1-.01-1.49.18-1.9 1.12-3.72 2.58-4.96 1.66-1.44 3.98-2.13 6.15-1.72.02 1.48-.04 2.96-.04 4.44-.99-.32-2.15-.23-3.02.37-.63.41-1.11 1.04-1.36 1.75-.21.51-.15 1.07-.14 1.61.24 1.64 1.82 3.02 3.5 2.87 1.12-.01 2.19-.66 2.77-1.61.19-.33.4-.67.41-1.06.1-1.79.06-3.57.07-5.36.01-4.03-.01-8.05.02-12.07z"/></svg>
          </a>
          <a class="social-link" href="https://www.facebook.com/profile.php?id=61590414930468" target="_blank" rel="noopener me" aria-label="EIAAW Solutions on Facebook" title="Facebook">
            <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" focusable="false" fill="currentColor"><path d="M24 12.07C24 5.4 18.63 0 12 0S0 5.4 0 12.07c0 6.03 4.39 11.03 10.13 11.93v-8.44H7.08v-3.49h3.05V9.41c0-3.02 1.79-4.69 4.53-4.69 1.31 0 2.68.24 2.68.24v2.97h-1.51c-1.49 0-1.96.93-1.96 1.89v2.25h3.33l-.53 3.49h-2.8V24C19.61 23.1 24 18.1 24 12.07z"/></svg>
          </a>
        </div>
      </div>
      <div class="footer-col">
        <strong>Product</strong>
        <a href="{{ url('/') }}#how">How it works</a>
        <a href="{{ url('/') }}#agents">The six agents</a>
        <a href="{{ url('/') }}#receipts">Receipts &amp; provenance</a>
        <a href="{{ url('/') }}#pricing">Pricing</a>
      </div>
      <div class="footer-col">
        <strong>Company</strong>
        <a href="https://eiaawsolutions.com" target="_blank" rel="noopener">EIAAW Solutions</a>
        <a href="https://eiaawsolutions.com/#contact" target="_blank" rel="noopener">Contact</a>
      </div>
      <div class="footer-col">
        <strong>Legal</strong>
        <a href="/terms">Terms</a>
        <a href="/acceptable-use">Acceptable Use</a>
        <a href="/ai-disclaimer">AI Content</a>
        <a href="/privacy">Privacy</a>
        <a href="/dpa">Data Processing</a>
        <a href="/security">Security</a>
        <a href="/legal">All legal</a>
      </div>
    </div>
    <div class="footer-bottom">
      <span>&copy; {{ date('Y') }} EIAAW SOLUTIONS &middot; SSM Reg. No. 202603133419 (CT0164540-H)</span>
      <span>Kuala Lumpur &middot; Malaysia</span>
    </div>
  </div>
</footer>

<script src="/brand/eiaaw-motion.js" defer></script>
@stack('scripts')

{{-- Floating support chatbot on EVERY public page (landing, legal, signup,
     enterprise). Surface 'landing' = sale-conversion/public mode; the server
     re-derives + clamps the surface, so this is a hint, not a trust boundary.
     The Filament panels inject their own surfaces ('client'/'hq') via render
     hooks. The AI is gated behind a name+email+phone contact form (smt-chat.js)
     before it answers anything. --}}
@include('partials.smt-chat-widget', ['surface' => 'landing'])
</body>
</html>
