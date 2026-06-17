@extends('layouts.eiaaw')

@section('title', 'Data Processing Addendum — EIAAW Social Media Team')
@section('description', 'The data-processing terms that apply when EIAAW SOLUTIONS processes personal data on your behalf, aligned with the Malaysian PDPA 2010 and ready for GDPR and Singapore PDPA.')

@section('content')
<x-legal-shell
  eyebrow="Legal · Data Processing"
  heading="Data Processing <em>Addendum</em>"
  :updated="config('legal.documents.dpa.updated')"
  intro="This Data Processing Addendum (&ldquo;DPA&rdquo;) applies where EIAAW SOLUTIONS processes personal data on your behalf as part of EIAAW Social Media Team. It forms part of, and is incorporated by reference into, our <a href='/terms'>Terms of Service</a>, and supplements our <a href='/privacy'>Privacy Policy</a>. It is drafted for Malaysia's Personal Data Protection Act 2010 (PDPA) and is structured to also support GDPR (EU/UK) and Singapore PDPA where those apply to you."
>
  <p class="meta">EIAAW SOLUTIONS · SSM Reg. No. 202603133419 (CT0164540-H) · Kuala Lumpur, Malaysia</p>

  <h2>1. Roles</h2>
  <p>
    For the brand content and audience data you submit to the service, <strong>you are the data controller</strong> (under the PDPA, the &ldquo;data user&rdquo;) and <strong>we are the data processor</strong>, processing personal data only on your documented instructions &mdash; principally, your use of the service's features. For your own account and billing data we act as controller; that is governed by our <a href="/privacy">Privacy Policy</a>, not this DPA.
  </p>

  <h2>2. Scope, nature &amp; purpose of processing</h2>
  <table>
    <thead>
      <tr><th>Aspect</th><th>Detail</th></tr>
    </thead>
    <tbody>
      <tr><td>Subject matter</td><td>Provision of the AI social-media service to you.</td></tr>
      <tr><td>Duration</td><td>For as long as your account is active, plus the limited retention described below.</td></tr>
      <tr><td>Nature &amp; purpose</td><td>Drafting, designing, scheduling, publishing, and measuring social content; running the Compliance gate; producing receipts.</td></tr>
      <tr><td>Types of data</td><td>Brand assets and prior posts, social handles and publishing tokens, and any personal data contained in the content you submit or generate.</td></tr>
      <tr><td>Data subjects</td><td>Your team, your audience, and any individuals depicted or referenced in your content.</td></tr>
    </tbody>
  </table>

  <h2>3. Our obligations as processor</h2>
  <ul>
    <li>Process personal data only on your instructions and for the purposes above, unless required otherwise by law (in which case we notify you where lawful to do so).</li>
    <li>Ensure personnel authorised to process the data are bound by confidentiality.</li>
    <li>Implement appropriate technical and organisational security measures (see our <a href="/security">Security</a> page).</li>
    <li>Assist you, taking into account the nature of processing, to respond to data-subject requests and to meet your security, breach-notification, and impact-assessment obligations.</li>
    <li>Make available the information reasonably necessary to demonstrate compliance with this DPA.</li>
  </ul>

  <h2>4. Sub-processors</h2>
  <p>
    You authorise us to engage sub-processors to deliver the service. Our current sub-processors are listed in our <a href="/privacy">Privacy Policy</a> (including Anthropic, FAL.AI, Stripe, Blotato, and our cloud-hosting and email providers). We impose data-protection obligations on each sub-processor that are no less protective than those in this DPA, and we remain responsible for their performance. We will give reasonable notice of any new sub-processor so you may object on reasonable data-protection grounds.
  </p>

  <h2>5. Data-subject requests</h2>
  <p>
    Where a data subject contacts us directly about content you control, we will refer them to you. We will provide reasonable assistance to help you fulfil access, correction, deletion, and objection requests within the timelines the applicable law requires.
  </p>

  <h2>6. Security incidents</h2>
  <p>
    We will notify you without undue delay after becoming aware of a personal-data breach affecting data we process for you, and provide the information reasonably available to help you meet your own notification obligations.
  </p>

  <h2>7. International transfers</h2>
  <p>
    Some sub-processors process data outside Malaysia. Where that happens we rely on the safeguards the relevant law permits &mdash; the provider's contractual commitments, an adequacy/whitelist mechanism, standard contractual clauses, or your consent, as applicable to your jurisdiction (including GDPR Chapter V and Singapore PDPA transfer rules where they apply to you).
  </p>

  <h2>8. Return &amp; deletion</h2>
  <p>
    On termination, and on your request, we delete or return the personal data we process for you, except where retention is required by law. Brand content is deleted on workspace deletion, subject to short-lived backups that age out.
  </p>

  <h2>9. Audit</h2>
  <p>
    On reasonable prior written notice, and no more than once a year (unless a regulator requires otherwise or following a confirmed breach), we will respond to a reasonable, confidential request for information needed to verify our compliance with this DPA. On-site audits, where strictly required by law, are by prior agreement and at your cost.
  </p>

  <h2>10. Contact</h2>
  <p>
    EIAAW SOLUTIONS (SSM Reg. No. 202603133419 / CT0164540-H), Kuala Lumpur, Malaysia ·
    <a href="mailto:eiaawsolutions@gmail.com">eiaawsolutions@gmail.com</a>.
    See also our <a href="/terms">Terms of Service</a>, <a href="/privacy">Privacy Policy</a>, and <a href="/security">Security</a> page.
  </p>
</x-legal-shell>
@endsection
