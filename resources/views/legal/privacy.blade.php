@extends('layouts.eiaaw')

@section('title', 'Privacy Policy — EIAAW Social Media Team')
@section('description', 'How EIAAW SOLUTIONS collects, uses, stores, and protects your data under the Malaysian Personal Data Protection Act 2010 (PDPA).')

@section('content')
<x-legal-shell
  eyebrow="Legal · Privacy"
  heading="Privacy <em>Policy</em>"
  :updated="config('legal.documents.privacy.updated')"
  intro="This policy explains what personal data EIAAW Social Media Team collects, why we collect it, who we share it with, and the rights you hold under Malaysia's <strong>Personal Data Protection Act 2010 (PDPA)</strong>. Where you or your audience are outside Malaysia, the &lsquo;International users&rsquo; section below explains how the GDPR (EU/UK) and Singapore's PDPA are also respected. The service is offered in Malaysia only in v1."
>
  <p class="meta">EIAAW SOLUTIONS · SSM Reg. No. 202603133419 (CT0164540-H) · Kuala Lumpur, Malaysia · Data controller</p>

  <h2>1. Who we are</h2>
  <p>
    EIAAW Social Media Team is operated by <strong>EIAAW SOLUTIONS</strong> (SSM Reg. No. 202603133419 / CT0164540-H) ("EIAAW", "we", "us"), a business registered in Malaysia. For the purposes of the PDPA, we are the <strong>data user</strong> (controller) for account and billing data, and a <strong>data processor</strong> for the brand content you submit for processing. Questions about this policy can be sent to
    <a href="mailto:eiaawsolutions@gmail.com">eiaawsolutions@gmail.com</a>.
  </p>

  <h2>2. What we collect</h2>
  <table>
    <thead>
      <tr><th>Category</th><th>Examples</th><th>Why</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>Account data</td>
        <td>Name, email, workspace name, hashed password</td>
        <td>Create and secure your account</td>
      </tr>
      <tr>
        <td>Billing data</td>
        <td>Plan, subscription status, Stripe customer ID, partial card metadata (last 4, brand)</td>
        <td>Process payments and manage your subscription</td>
      </tr>
      <tr>
        <td>Brand content</td>
        <td>Brand voice docs, prior posts, logos, palettes, uploaded assets, generated drafts</td>
        <td>Ground every caption, image, and recommendation in your real evidence</td>
      </tr>
      <tr>
        <td>Connected accounts</td>
        <td>Social publishing handles and tokens used to schedule and publish on your behalf</td>
        <td>Schedule and publish approved content on your behalf</td>
      </tr>
      <tr>
        <td>Usage &amp; logs</td>
        <td>IP address, browser, actions taken, agent run telemetry, cost-per-post</td>
        <td>Security, abuse prevention, receipts, and product reliability</td>
      </tr>
    </tbody>
  </table>
  <p>
    We do <strong>not</strong> store full card numbers — card payments are handled entirely by Stripe. We never sell your personal data.
  </p>

  <h2>3. How we use it</h2>
  <ul>
    <li>To run the six AI agents that draft, design, and schedule your content.</li>
    <li>To enforce the Compliance gate and produce the provenance receipts on every post.</li>
    <li>To bill your subscription and send service notices (cap warnings, security alerts).</li>
    <li>To keep the service secure, detect abuse, and meet legal obligations.</li>
  </ul>
  <p>
    We do not use your brand content to train third-party foundation models, and we do not use it to benefit other customers.
  </p>

  <h2>4. AI sub-processors</h2>
  <p>
    Generating content requires sending your prompts and relevant brand evidence to specialist providers. We use:
  </p>
  <ul>
    <li><strong>Anthropic (Claude)</strong> — caption drafting, strategy, and compliance reasoning.</li>
    <li><strong>FAL.AI</strong> — image (Nano Banana / Gemini 2.5 Flash Image) and short-form video (Google Veo 3) generation.</li>
    <li><strong>Stripe</strong> — payment processing and subscription management.</li>
    <li><strong>Metricool</strong> — social scheduling, publishing, and per-post analytics.</li>
    <li><strong>Blotato</strong> — social-account connection and standby publishing.</li>
    <li><strong>Cloud infrastructure &amp; email</strong> — hosting, storage, and transactional email delivery.</li>
  </ul>
  <p>
    Each provider processes data only to deliver its part of the service and under its own data-processing terms. This is a living list: we keep it current and will note changes here. For business customers whose end-customer data we process, our <a href="/dpa">Data Processing Addendum</a> governs sub-processor changes and your right to object.
  </p>

  <h2>5. Where data is stored &amp; how long</h2>
  <p>
    Data is hosted on managed cloud infrastructure. Some sub-processors process data outside Malaysia; where that happens, we rely on the safeguards described under &ldquo;International users&rdquo; below. We keep personal data only as long as needed for the purpose it was collected for:
  </p>
  <table>
    <thead>
      <tr><th>Data</th><th>Retention</th></tr>
    </thead>
    <tbody>
      <tr><td>Account data</td><td>While your account is active; deleted or anonymised after closure, except where law requires longer.</td></tr>
      <tr><td>Billing data</td><td>For the period required by Malaysian tax and accounting law after closure.</td></tr>
      <tr><td>Brand content</td><td>Deleted on workspace deletion, subject to short-lived backups that age out.</td></tr>
      <tr><td>Usage &amp; security logs</td><td>Retained for a limited period for security, abuse prevention, and reliability, then deleted or aggregated.</td></tr>
    </tbody>
  </table>

  <h2>6. International users (GDPR, UK GDPR &amp; Singapore PDPA)</h2>
  <p>
    Although v1 is offered in Malaysia, where the EU/UK General Data Protection Regulation or Singapore's PDPA applies to you or your audience, we honour their requirements:
  </p>
  <ul>
    <li><strong>Lawful basis.</strong> We process personal data on the basis of contract performance (to deliver the service), your consent (where required), and our legitimate interests in operating, securing, and improving the service, balanced against your rights.</li>
    <li><strong>Your rights.</strong> Where these laws apply you have rights of access, rectification, erasure, restriction, portability, and objection, and the right to withdraw consent. You may also lodge a complaint with your local supervisory authority.</li>
    <li><strong>International transfers.</strong> Where personal data leaves your jurisdiction, we rely on a lawful transfer mechanism — an adequacy/whitelist decision, the provider's standard contractual clauses, or your consent, as applicable.</li>
    <li><strong>EU/UK representative.</strong> If and when we are required to appoint an Article 27 representative for EU or UK data subjects, their contact details will be published here.</li>
  </ul>

  <h2>7. Your rights under the PDPA</h2>
  <p>You may, at any time:</p>
  <ul>
    <li><strong>Access</strong> the personal data we hold about you.</li>
    <li><strong>Correct</strong> data that is inaccurate or out of date.</li>
    <li><strong>Withdraw consent</strong> to processing (note this may end the service).</li>
    <li><strong>Request deletion</strong> of your account and associated data.</li>
    <li><strong>Limit</strong> direct-marketing communications.</li>
  </ul>
  <p>
    To exercise any right, email <a href="mailto:eiaawsolutions@gmail.com">eiaawsolutions@gmail.com</a>. We respond within 21 days as required by the PDPA.
  </p>

  <h2>8. Data-breach notification</h2>
  <p>
    We maintain technical and organisational measures to protect personal data (see our <a href="/security">Security</a> page). If a personal-data breach occurs that is likely to affect you, we will act without undue delay to contain it and will notify affected users and any required authority in line with the applicable law. For business customers whose end-customer data we process, breach handling is governed by our <a href="/dpa">Data Processing Addendum</a>.
  </p>

  <h2>9. Cookies</h2>
  <p>
    We use strictly necessary cookies for authentication and session security, and minimal first-party analytics to keep the product reliable. We do not run third-party advertising trackers on this site.
  </p>

  <h2>10. Children</h2>
  <p>
    The service is for businesses and is not directed at anyone under 18. We do not knowingly collect data from minors.
  </p>

  <h2>11. Changes</h2>
  <p>
    We will post any material change here and update the date above. Material changes may require you to re-accept in the app before continuing. Continued use after a change means you accept the updated policy.
  </p>

  <h2>12. Contact</h2>
  <p>
    EIAAW SOLUTIONS (SSM Reg. No. 202603133419 / CT0164540-H), Kuala Lumpur, Malaysia ·
    <a href="mailto:eiaawsolutions@gmail.com">eiaawsolutions@gmail.com</a>.
    See also our <a href="/terms">Terms of Service</a>, <a href="/acceptable-use">Acceptable Use Policy</a>, <a href="/ai-disclaimer">AI Content Disclaimer</a>, <a href="/dpa">Data Processing Addendum</a>, and <a href="/security">Security</a> page.
  </p>
</x-legal-shell>
@endsection
