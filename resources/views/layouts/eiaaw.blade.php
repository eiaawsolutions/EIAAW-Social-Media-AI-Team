<!DOCTYPE html>
<html lang="en-MY">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>@yield('title', 'EIAAW Social Media Team — AI that shows you the receipts')</title>
  <meta name="description" content="@yield('description', 'The autonomous AI social media team for SMBs and agencies. Six specialised agents, hard compliance gate, and provenance receipts on every post. Built for Malaysia and APAC.')">
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
    <a href="#how">How it works</a>
    <a href="#agents">The team</a>
    <a href="#receipts">Receipts</a>
    <a href="#pricing">Pricing</a>
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
      </div>
      <div class="footer-col">
        <strong>Product</strong>
        <a href="#how">How it works</a>
        <a href="#agents">The six agents</a>
        <a href="#receipts">Receipts &amp; provenance</a>
        <a href="#pricing">Pricing</a>
      </div>
      <div class="footer-col">
        <strong>Company</strong>
        <a href="https://eiaawsolutions.com" target="_blank" rel="noopener">EIAAW Solutions</a>
        <a href="mailto:eiaawsolutions@gmail.com">Contact</a>
        <a href="/changelog">Changelog</a>
      </div>
      <div class="footer-col">
        <strong>Legal</strong>
        <a href="/privacy">Privacy</a>
        <a href="/terms">Terms</a>
        <a href="/security">Security</a>
      </div>
    </div>
    <div class="footer-bottom">
      <span>&copy; {{ date('Y') }} EIAAW Solutions Sdn Bhd</span>
      <span>Kuala Lumpur &middot; Malaysia</span>
    </div>
  </div>
</footer>

<script src="/brand/eiaaw-motion.js" defer></script>
@stack('scripts')
</body>
</html>
