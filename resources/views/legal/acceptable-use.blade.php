@extends('layouts.eiaaw')

@section('title', 'Acceptable Use Policy — EIAAW Social Media Team')
@section('description', 'The rules governing how EIAAW Social Media Team may and may not be used, including AI-specific prohibitions, operated by EIAAW SOLUTIONS in Malaysia.')

@section('content')
<x-legal-shell
  eyebrow="Legal · Acceptable Use"
  heading="Acceptable Use <em>Policy</em>"
  :updated="config('legal.documents.acceptable_use.updated')"
  intro="This Acceptable Use Policy (&ldquo;AUP&rdquo;) sets out what you may not do with EIAAW Social Media Team. It forms part of, and is incorporated by reference into, our <a href='/terms'>Terms of Service</a>. Breaking it can result in immediate suspension or termination without refund."
>
  <p class="meta">EIAAW SOLUTIONS · SSM Reg. No. 202603133419 (CT0164540-H) · Governed by the laws of Malaysia</p>

  <h2>1. Who this applies to</h2>
  <p>
    This AUP applies to every user of the service, to everyone you allow to use your account, and to all content you create, upload, generate, schedule, approve, or publish through the service (&ldquo;your content&rdquo;). You are responsible for ensuring everyone using your account follows it.
  </p>

  <h2>2. Prohibited content</h2>
  <p>You must not use the service to create, store, schedule, or publish content that:</p>
  <ul>
    <li>Is unlawful, fraudulent, deceptive, defamatory, harassing, threatening, or hateful.</li>
    <li>Infringes any third party's intellectual property, privacy, publicity, or other rights.</li>
    <li>Promotes violence, terrorism, self-harm, or discrimination against any protected group.</li>
    <li>Is sexually explicit, exploits or endangers minors, or is otherwise obscene.</li>
    <li>Contains malware, or links to malicious, phishing, or scam destinations.</li>
    <li>Markets regulated goods or services (e.g. financial products, health claims, alcohol, gambling) in breach of the applicable advertising standards or law of the relevant market.</li>
  </ul>

  <h2>3. Prohibited conduct</h2>
  <ul>
    <li>Breaching the terms, community standards, or automation rules of any connected platform (Meta, TikTok, YouTube, LinkedIn, X, and others).</li>
    <li>Spamming, bulk-posting, or operating inauthentic or coordinated accounts.</li>
    <li>Impersonating any person, brand, or organisation, or misrepresenting your affiliation.</li>
    <li>Sending us personal data you are not lawfully entitled to provide, or processing personal data through the service without a valid legal basis.</li>
    <li>Interfering with, overloading, or attempting to gain unauthorised access to the service, other accounts, or our providers' systems.</li>
    <li>Reselling, sublicensing, or providing the service to third parties except as expressly permitted in writing.</li>
  </ul>

  <h2>4. AI-specific prohibitions</h2>
  <p>
    Because the service generates synthetic media, the following are strictly prohibited. These apply regardless of whether the output passed the Compliance gate — the gate is a safeguard, not a licence.
  </p>
  <ul>
    <li>Creating non-consensual deepfakes, or any synthetic likeness, voice, or persona of a real, identifiable person without their explicit, documented consent.</li>
    <li>Impersonating real individuals or organisations, or generating content designed to deceive about who is speaking.</li>
    <li>Producing or amplifying disinformation, including coordinated, electoral, public-health, or financial misinformation.</li>
    <li>Generating sexual content involving real people without consent, or any content that sexualises minors. We operate a zero-tolerance policy on child sexual abuse material (CSAM) and will report it to the relevant authorities.</li>
    <li>Attempting to reverse-engineer, extract, copy, or train competing models on our prompts, agents, model outputs, or system behaviour.</li>
    <li>Scraping or harvesting data from the service or its providers, or circumventing the Compliance gate, rate limits, plan caps, or other technical controls.</li>
    <li>Removing, hiding, or falsifying AI-content or synthetic-media disclosures where a platform or the law requires them.</li>
  </ul>

  <h2>5. You are the publisher</h2>
  <p>
    AI assists you; it does not replace your judgement. You review and approve content before it is published &mdash; especially anything on the Amber lane, which requires your approval by design. As the person who approves and publishes, <strong>you are the publisher</strong> and are responsible for your content's legality, accuracy, and compliance with every connected platform's rules. See our <a href="/ai-disclaimer">AI Content Disclaimer</a>.
  </p>

  <h2>6. Enforcement</h2>
  <p>
    We may investigate suspected breaches and may remove content, throttle, suspend, or terminate access &mdash; immediately and without prior notice where the breach is serious, unlawful, or poses a risk to others, our providers, or us. We are not required to monitor your content, but we may do so. A termination for breach of this AUP does not entitle you to a refund. We may preserve and disclose information where required by law or to protect rights, safety, or the integrity of the service.
  </p>

  <h2>7. Reporting</h2>
  <p>
    To report a breach of this policy, email
    <a href="mailto:eiaawsolutions@gmail.com">eiaawsolutions@gmail.com</a>.
    See also our <a href="/terms">Terms of Service</a>, <a href="/ai-disclaimer">AI Content Disclaimer</a>, and <a href="/privacy">Privacy Policy</a>.
  </p>
</x-legal-shell>
@endsection
