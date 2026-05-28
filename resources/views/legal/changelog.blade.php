@extends('layouts.eiaaw')

@section('title', 'Changelog — EIAAW Social Media Team')
@section('description', 'What we shipped, and when. EIAAW Social Media Team product changelog.')

@section('content')
<x-legal-shell
  eyebrow="Product · Changelog"
  heading="What we <em>shipped</em>"
  intro="We ship in the open. Every entry below is a real change to the product — no vapourware, no roadmap theatre."
>
  <h2>28 May 2026 — Launch shape</h2>
  <ul>
    <li>Retired the 14-day trial. You subscribe and your dedicated publishing account is provisioned by our team, typically within one business day.</li>
    <li>Rebased pricing to flat, brand-based tiers (RM 549 / 1,099 / 3,499 per month) with annual billing that gives you two months free. Hard plan caps enforced on brands, posts, and AI videos.</li>
    <li>Every workspace now gets its own isolated Blotato publishing account — connected social tokens are never pooled across customers.</li>
    <li>Added a prompt-injection detector and real-time security alerts.</li>
    <li>Made the product tax-ready (SST scaffolding in place, inactive until thresholds apply) and gated the service to Malaysia for v1.</li>
  </ul>

  <h2>24 May 2026 — Operator &amp; workflow</h2>
  <ul>
    <li>Retired the two-human "Red" autonomy lane — Green (auto-publish on Compliance pass) and Amber (single approval) are the two lanes, with operators notified of affected drafts on switch.</li>
    <li>Added per-post analytics on Live feed cards (views, impressions, likes, comments).</li>
    <li>Added a cross-workspace Agents monitor for super-admins.</li>
    <li>Reordered the dashboard to daily-action order — Drafts first, Performance last — with a one-line subheading on every page.</li>
    <li>Added delete and bulk-delete to Brand Assets, cascading cleanly to storage.</li>
  </ul>

  <h2>23 May 2026 — Filtering &amp; reliability</h2>
  <ul>
    <li>Added date-range, platform, and caption-search filters across Schedule, Drafts, Calendar, and Live feed, with filter bars pinned above each table.</li>
    <li>Split the worker and scheduler into independent services for more predictable publishing.</li>
  </ul>

  <h2>13 May 2026 — Performance receipts</h2>
  <ul>
    <li>Added a Performance-page CSV importer so native-analytics metrics flow into your receipts.</li>
    <li>Improved dispatcher logging — queue, poll, and retry counts every minute — for honest reliability telemetry.</li>
  </ul>

  <p class="meta" style="margin-top: 40px;">
    Want something on the list? <a href="mailto:eiaawsolutions@gmail.com">Tell us</a>.
  </p>
</x-legal-shell>
@endsection
