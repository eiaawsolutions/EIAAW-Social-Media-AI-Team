@extends('layouts.eiaaw')

@section('title', 'Security — EIAAW Social Media Team')
@section('description', 'How EIAAW Social Media Team protects your account, your brand content, and your connected social accounts.')

@section('content')
<x-legal-shell
  eyebrow="Trust · Security"
  heading="Security at <em>EIAAW</em>"
  updated="28 May 2026"
  intro="We build AI that shows its receipts — and we hold our own security to the same standard. This page describes the controls protecting your account, your brand evidence, and your connected social accounts."
>
  <h2>Payments</h2>
  <p>
    Card payments are processed entirely by <strong>Stripe</strong>, a PCI-DSS Level 1 provider. We never see or store full card numbers. Stripe webhooks are verified by signature before we act on them, and processed idempotently so a replayed event cannot double-charge or corrupt subscription state.
  </p>

  <h2>Secrets management</h2>
  <p>
    Production secrets — API keys, signing keys, provider credentials — are never committed to source and never pasted into plain config. They are stored in a dedicated secrets manager and resolved at runtime via short-lived references. The application reads only the values it needs, scoped to its own machine identity.
  </p>

  <h2>Tenant &amp; brand isolation</h2>
  <ul>
    <li>Each workspace is isolated. Brand evidence, drafts, and receipts belong to a single workspace and are not shared across customers.</li>
    <li>Every workspace gets its <strong>own dedicated Blotato publishing account</strong> — connected social tokens are never pooled across customers.</li>
    <li>Authorization is enforced on every request, not just in the UI, to prevent cross-tenant access.</li>
  </ul>

  <h2>Application hardening</h2>
  <ul>
    <li>HTTPS everywhere, with security headers (CSP, HSTS, X-Frame-Options).</li>
    <li>CSRF protection on state-changing requests; CSP violations are reported and monitored.</li>
    <li>Rate limiting on signup, login, checkout, and public endpoints to blunt scripted abuse.</li>
    <li>Passwords are stored hashed; the health endpoint and error responses are built not to leak version or environment details.</li>
    <li>Parameterized database access and input validation at every boundary.</li>
  </ul>

  <h2>AI safety controls</h2>
  <ul>
    <li>A hard <strong>Compliance gate</strong> runs seven checks on every post before it can reach the publishing queue — off-brand or unverified content is held with the reason shown.</li>
    <li>Cost circuit breakers and per-plan caps prevent runaway generation spend.</li>
    <li>AI-generated media is flagged for honest disclosure under platform synthetic-media policies.</li>
  </ul>

  <h2>Monitoring &amp; recovery</h2>
  <p>
    Application and agent activity is logged for security and reliability. Data is hosted on managed infrastructure with automated backups; we test restores so a recovery path actually works rather than only existing on paper.
  </p>

  <h2>Reporting a vulnerability</h2>
  <p>
    If you believe you've found a security issue, please email
    <a href="mailto:eiaawsolutions@gmail.com">eiaawsolutions@gmail.com</a>
    with steps to reproduce. We investigate every credible report and ask that you give us reasonable time to remediate before any public disclosure. Please do not run automated scans that degrade service for other customers.
  </p>

  <p class="meta" style="margin-top: 40px;">
    This page describes our current posture and is not a certification. See also our
    <a href="/privacy">Privacy Policy</a> and <a href="/terms">Terms of Service</a>.
  </p>
</x-legal-shell>
@endsection
