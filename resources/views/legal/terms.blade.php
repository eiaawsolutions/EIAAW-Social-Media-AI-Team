@extends('layouts.eiaaw')

@section('title', 'Terms of Service — EIAAW Social Media Team')
@section('description', 'The terms governing your subscription to EIAAW Social Media Team, operated by EIAAW SOLUTIONS in Malaysia.')

@section('content')
<x-legal-shell
  eyebrow="Legal · Terms"
  heading="Terms of <em>Service</em>"
  updated="28 May 2026"
  intro="These terms govern your use of EIAAW Social Media Team. By subscribing you agree to them. If you are accepting on behalf of a company, you confirm you are authorised to bind it."
>
  <p class="meta">EIAAW SOLUTIONS · SSM Reg. No. 202603133419 (CT0164540-H) · Governed by the laws of Malaysia</p>

  <h2>1. The service</h2>
  <p>
    EIAAW Social Media Team is an autonomous AI social-media system: six specialist agents draft, design, and schedule content, gated by a hard Compliance check, with provenance receipts on every post. The service is offered in <strong>Malaysia only</strong> in v1.
  </p>

  <h2>2. Your account</h2>
  <ul>
    <li>You are responsible for keeping your login credentials secure and for all activity under your account.</li>
    <li>You must provide accurate billing and contact information.</li>
    <li>You must be a business or acting in a business capacity, and at least 18 years old.</li>
  </ul>

  <h2>3. Subscriptions, billing &amp; plan caps</h2>
  <ul>
    <li>Plans are billed in advance — monthly or annually — via Stripe. You are charged at signup.</li>
    <li>Each plan has hard caps on brands, published posts, and AI videos per month. When a cap is reached, posts may be deferred and video generation may be blocked until the next cycle or an upgrade.</li>
    <li>Prices are in Malaysian Ringgit (RM) and exclusive of any applicable taxes.</li>
    <li>A dedicated Metricool publishing account is provisioned for your workspace by our team, typically within one business day of signup.</li>
  </ul>

  <h2>4. Cancellation &amp; refunds</h2>
  <p>
    You can cancel any time from your billing settings. <strong>There is no auto-renewal trap and no cancellation penalty.</strong> When you cancel, your subscription stays active until the end of the current paid period and is not renewed. We do not provide pro-rated refunds for partial periods unless required by Malaysian consumer law.
  </p>

  <h2>5. Acceptable use</h2>
  <p>You agree not to use the service to:</p>
  <ul>
    <li>Publish unlawful, infringing, defamatory, or deceptive content.</li>
    <li>Impersonate others or misrepresent affiliation.</li>
    <li>Bypass platform policies, the Compliance gate, or technical limits.</li>
    <li>Reverse-engineer, resell, or abuse the service or its providers.</li>
  </ul>
  <p>
    You are responsible for the content you approve for publishing and for compliance with the terms of any connected social platform.
  </p>

  <h2>6. AI-generated content</h2>
  <p>
    The service uses AI to generate captions, images, and video. While every output passes a seven-check Compliance gate and ships with receipts, <strong>AI output can still contain errors</strong>. You are responsible for reviewing content before it is published — especially anything on the Amber lane, which requires your approval by design. AI-generated media is flagged for honest disclosure under Meta, TikTok, and YouTube synthetic-media policies.
  </p>

  <h2>7. Intellectual property</h2>
  <ul>
    <li><strong>Your content</strong> (brand assets, generated drafts you own the inputs to) remains yours. You grant us the licence needed to operate the service on your behalf.</li>
    <li><strong>Our platform</strong> — software, agents, and design — remains the property of EIAAW SOLUTIONS.</li>
  </ul>

  <h2>8. Third-party services</h2>
  <p>
    The service depends on providers including Anthropic, FAL.AI, Stripe, and Metricool. Their availability and terms are outside our control; an outage at a provider may affect the service.
  </p>

  <h2>9. Warranties &amp; liability</h2>
  <p>
    The service is provided "as is". To the extent permitted by Malaysian law, we exclude implied warranties and our total liability for any claim is limited to the fees you paid in the three months before the claim. We are not liable for indirect or consequential loss.
  </p>

  <h2>10. Suspension &amp; termination</h2>
  <p>
    We may suspend or terminate accounts that breach these terms, fail payment, or pose a security or legal risk. You may close your account at any time.
  </p>

  <h2>11. Changes</h2>
  <p>
    We may update these terms; material changes will be posted here with an updated date. Continued use means acceptance.
  </p>

  <h2>12. Governing law</h2>
  <p>
    These terms are governed by the laws of Malaysia, and the courts of Malaysia have exclusive jurisdiction.
  </p>

  <h2>13. Contact</h2>
  <p>
    EIAAW SOLUTIONS (SSM Reg. No. 202603133419 / CT0164540-H), Kuala Lumpur, Malaysia ·
    <a href="mailto:eiaawsolutions@gmail.com">eiaawsolutions@gmail.com</a>.
    See also our <a href="/privacy">Privacy Policy</a> and <a href="/security">Security</a> page.
  </p>
</x-legal-shell>
@endsection
