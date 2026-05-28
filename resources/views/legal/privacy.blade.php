@extends('layouts.eiaaw')

@section('title', 'Privacy Policy — EIAAW Social Media Team')
@section('description', 'How EIAAW Solutions Sdn Bhd collects, uses, stores, and protects your data under the Malaysian Personal Data Protection Act 2010 (PDPA).')

@section('content')
<x-legal-shell
  eyebrow="Legal · Privacy"
  heading="Privacy <em>Policy</em>"
  updated="28 May 2026"
  intro="This policy explains what personal data EIAAW Social Media Team collects, why we collect it, who we share it with, and the rights you hold under Malaysia's <strong>Personal Data Protection Act 2010 (PDPA)</strong>. The service is offered in Malaysia only in v1."
>
  <p class="meta">EIAAW Solutions Sdn Bhd · Kuala Lumpur, Malaysia · Data controller</p>

  <h2>1. Who we are</h2>
  <p>
    EIAAW Social Media Team is operated by <strong>EIAAW Solutions Sdn Bhd</strong> ("EIAAW", "we", "us"), a company registered in Malaysia. For the purposes of the PDPA, we are the <strong>data user</strong> (controller) for account and billing data, and a <strong>data processor</strong> for the brand content you submit for processing. Questions about this policy can be sent to
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
        <td>Social publishing handles and tokens managed via Blotato</td>
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
    <li><strong>FAL.AI</strong> — image (Flux) and short-form video (Wan 2.6) generation.</li>
    <li><strong>Stripe</strong> — payment processing and subscription management.</li>
    <li><strong>Blotato</strong> — social scheduling and publishing.</li>
    <li><strong>Cloud infrastructure &amp; email</strong> — hosting, storage, and transactional email delivery.</li>
  </ul>
  <p>
    Each provider processes data only to deliver its part of the service and under its own data-processing terms.
  </p>

  <h2>5. Where data is stored &amp; how long</h2>
  <p>
    Data is hosted on managed cloud infrastructure. Some sub-processors process data outside Malaysia; where that happens, we rely on the provider's contractual safeguards. We retain account and billing data for as long as your account is active and for the period required by Malaysian tax and accounting law after closure. Brand content is deleted on workspace deletion, subject to short-lived backups that age out.
  </p>

  <h2>6. Your rights under the PDPA</h2>
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

  <h2>7. Cookies</h2>
  <p>
    We use strictly necessary cookies for authentication and session security, and minimal first-party analytics to keep the product reliable. We do not run third-party advertising trackers on this site.
  </p>

  <h2>8. Children</h2>
  <p>
    The service is for businesses and is not directed at anyone under 18. We do not knowingly collect data from minors.
  </p>

  <h2>9. Changes</h2>
  <p>
    We will post any material change here and update the date above. Continued use after a change means you accept the updated policy.
  </p>

  <h2>10. Contact</h2>
  <p>
    EIAAW Solutions Sdn Bhd, Kuala Lumpur, Malaysia ·
    <a href="mailto:eiaawsolutions@gmail.com">eiaawsolutions@gmail.com</a>.
    See also our <a href="/terms">Terms of Service</a> and <a href="/security">Security</a> page.
  </p>
</x-legal-shell>
@endsection
