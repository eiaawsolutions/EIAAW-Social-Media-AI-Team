@extends('layouts.eiaaw')

@section('title', 'Terms of Service — EIAAW Social Media Team')
@section('description', 'The terms governing your subscription to EIAAW Social Media Team, operated by EIAAW SOLUTIONS in Malaysia.')

@section('content')
<x-legal-shell
  eyebrow="Legal · Terms"
  heading="Terms of <em>Service</em>"
  :updated="config('legal.documents.terms.updated')"
  intro="These terms govern your use of EIAAW Social Media Team. By subscribing, ticking the acceptance box, or using the service you agree to them. If you are accepting on behalf of a company, you confirm you are authorised to bind it. These terms incorporate our <a href='/acceptable-use'>Acceptable Use Policy</a>, <a href='/ai-disclaimer'>AI Content Disclaimer</a>, <a href='/privacy'>Privacy Policy</a>, and (for business customers) our <a href='/dpa'>Data Processing Addendum</a> by reference."
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
    <li>We help you connect your social accounts to the publishing layer, typically within one business day of signup.</li>
  </ul>

  <h2>4. Cancellation &amp; refunds</h2>
  <p>
    You can cancel any time from your billing settings. <strong>There is no auto-renewal trap and no cancellation penalty.</strong> When you cancel, your subscription stays active until the end of the current paid period and is not renewed. We do not provide pro-rated refunds for partial periods unless required by Malaysian consumer law.
  </p>

  <h2>5. Acceptable use</h2>
  <p>
    Your use of the service is governed by our <a href="/acceptable-use">Acceptable Use Policy</a>, which is incorporated into these terms. In summary, you must not use the service to publish unlawful, infringing, defamatory, or deceptive content; impersonate others; bypass platform policies, the Compliance gate, or technical limits; or reverse-engineer, resell, or abuse the service or its providers. You are responsible for the content you approve for publishing and for compliance with the terms of any connected social platform.
  </p>

  <h2>6. AI-generated content</h2>
  <p>
    The service uses AI to generate captions, images, and video. While every output passes a hard Compliance gate and ships with receipts, <strong>AI output can still be inaccurate, incomplete, biased, or fabricated</strong>. You must review content before it is published — especially anything on the Amber lane, which requires your approval by design — and you assume the risk of any content you approve. AI-generated media is flagged to help you disclose it under Meta, TikTok, and YouTube synthetic-media policies, but the obligation to label and disclose rests with you. Your use of AI features is further governed by our <a href="/ai-disclaimer">AI Content Disclaimer</a>, which is incorporated into these terms.
  </p>

  <h2>7. Intellectual property</h2>
  <ul>
    <li><strong>Our platform.</strong> The service — including its software, the six agents, prompts, models and model configurations, design, and all improvements, derivatives, and aggregated or de-identified learnings — is and remains the exclusive property of EIAAW SOLUTIONS and its licensors. We grant you only a limited, revocable, non-exclusive, non-transferable right to use the service during your subscription. No other rights are granted by implication.</li>
    <li><strong>Your inputs.</strong> Brand assets and other inputs you provide remain yours. You grant us a worldwide, royalty-free licence to host, process, and transmit them as needed to operate the service on your behalf, including sending them to the sub-processors named in our <a href="/privacy">Privacy Policy</a>.</li>
    <li><strong>AI output.</strong> As between you and us, we assign to you such rights as we hold in the captions, images, and video the service generates for you from your inputs, so you can use and publish them. <strong>However, we make no warranty that any output is original, owned by you, or free of third-party rights, and you bear all risk and responsibility for confirming you have the rights to use and publish it.</strong> AI models may generate similar output for other users, and output may reflect material from model training data outside our control. See our <a href="/ai-disclaimer">AI Content Disclaimer</a>.</li>
  </ul>

  <h2>8. Third-party services</h2>
  <p>
    The service depends on providers including Anthropic, FAL.AI, Stripe, Metricool, and Blotato. Their availability and terms are outside our control; an outage at a provider may affect the service.
  </p>

  <h2>9. Disclaimer of warranties</h2>
  <p>
    The service is provided <strong>&ldquo;as is&rdquo; and &ldquo;as available&rdquo;</strong>, with all faults and without warranty of any kind. To the fullest extent permitted by Malaysian law, we disclaim all express, implied, and statutory warranties, including any implied warranties of merchantability, fitness for a particular purpose, title, non-infringement, accuracy, reliability, and uninterrupted or error-free operation. We do not warrant that AI output is accurate, original, lawful, or fit for your purpose, nor that the service or any connected platform will be uninterrupted or secure.
  </p>

  <h2>10. Limitation of liability</h2>
  <p>
    To the fullest extent permitted by Malaysian law:
  </p>
  <ul>
    <li>We are <strong>not liable for any indirect, incidental, consequential, special, exemplary, or punitive damages</strong>, or for any loss of profits, revenue, data, goodwill, or business opportunity, however caused and on any theory of liability, even if advised of the possibility.</li>
    <li>Our <strong>total aggregate liability</strong> for all claims arising out of or relating to the service and these terms is limited to the greater of (a) the fees you actually paid to us in the twelve (12) months before the event giving rise to the claim, or (b) one thousand Malaysian Ringgit (RM 1,000).</li>
  </ul>
  <p>
    Nothing in these terms excludes or limits any liability that cannot be excluded or limited under Malaysian law, including liability under the Consumer Protection Act 1999 where it applies. The allocation of risk in these terms is reflected in our pricing and is a fundamental basis of the agreement between us.
  </p>

  <h2>11. Indemnification</h2>
  <p>
    You agree to defend, indemnify, and hold harmless EIAAW SOLUTIONS, its officers, employees, and providers from and against any claims, demands, losses, liabilities, damages, costs, and expenses (including reasonable legal fees) arising out of or relating to: (a) your content and your inputs; (b) your use of the service and of any AI output you publish; (c) your breach of these terms, the <a href="/acceptable-use">Acceptable Use Policy</a>, or any connected platform's terms; (d) your infringement of any third party's intellectual-property, privacy, publicity, or other rights; and (e) your breach of any law or regulation, including data-protection law.
  </p>

  <h2>12. Assumption of risk for AI content</h2>
  <p>
    You acknowledge that AI-generated content carries inherent risk and that you, as the person who reviews, approves, and publishes, are the publisher of that content. You assume all risk arising from AI output you choose to publish, including the risk of inaccuracy, infringement, regulatory breach, or reputational harm. See our <a href="/ai-disclaimer">AI Content Disclaimer</a>.
  </p>

  <h2>13. Suspension &amp; termination</h2>
  <p>
    We may suspend or terminate accounts that breach these terms or the Acceptable Use Policy, fail payment, or pose a security or legal risk — immediately and without notice where the breach is serious or unlawful. A termination for breach does not entitle you to a refund. You may close your account at any time. Sections that by their nature should survive termination (including intellectual property, disclaimers, limitation of liability, indemnification, and governing law) survive.
  </p>

  <h2>14. Dispute resolution</h2>
  <p>
    If a dispute arises, the parties will first attempt to resolve it in good faith by negotiation within thirty (30) days of written notice. Any dispute not so resolved may be referred to arbitration administered by the Asian International Arbitration Centre (AIAC) in Kuala Lumpur, in English, under its rules, before the courts are engaged. This clause does not prevent either party from seeking urgent injunctive relief from a court.
  </p>

  <h2>15. Governing law &amp; jurisdiction</h2>
  <p>
    These terms are governed by the laws of Malaysia, and (subject to the arbitration clause above) the courts of Malaysia have exclusive jurisdiction.
  </p>

  <h2>16. Changes</h2>
  <p>
    We may update these terms; material changes will be posted here with an updated date and may require you to re-accept in the app before continuing. Continued use after a change means acceptance.
  </p>

  <h2>17. General</h2>
  <ul>
    <li><strong>Entire agreement.</strong> These terms, together with the Acceptable Use Policy, AI Content Disclaimer, Privacy Policy, and (for business customers) the Data Processing Addendum, are the entire agreement between us and supersede any prior understanding.</li>
    <li><strong>Severability.</strong> If any provision is held unenforceable, the rest remain in full force, and the unenforceable provision is modified to the minimum extent needed to make it enforceable.</li>
    <li><strong>Assignment.</strong> You may not assign these terms without our consent; we may assign them to an affiliate or successor.</li>
    <li><strong>No waiver.</strong> Our failure to enforce a provision is not a waiver of it.</li>
    <li><strong>Force majeure.</strong> Neither party is liable for delay or failure caused by events beyond its reasonable control, including provider outages, network failures, or acts of government.</li>
    <li><strong>Notices.</strong> We may give notice by email or in-app; you may contact us at the address below.</li>
  </ul>

  <h2>18. Contact</h2>
  <p>
    EIAAW SOLUTIONS (SSM Reg. No. 202603133419 / CT0164540-H), Kuala Lumpur, Malaysia ·
    <a href="mailto:eiaawsolutions@gmail.com">eiaawsolutions@gmail.com</a>.
    See also our <a href="/acceptable-use">Acceptable Use Policy</a>, <a href="/ai-disclaimer">AI Content Disclaimer</a>, <a href="/privacy">Privacy Policy</a>, <a href="/dpa">Data Processing Addendum</a>, and <a href="/security">Security</a> page.
  </p>
</x-legal-shell>
@endsection
