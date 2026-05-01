@extends('layouts.eiaaw')

@section('title', 'Choose your plan — EIAAW Social Media Team')
@section('description', 'Start your 14-day free trial. Pick the plan that fits your brand count. Flat pricing, no per-user tax. Cancel any time.')

@section('content')

<section class="section-pad" style="padding-top: clamp(140px, 18vh, 220px);">
  <div class="wrap">
    <div class="section-head">
      <div class="label"><span class="eyebrow">Sign up &middot; step 1 of 2</span></div>
      <h1 class="title section-h">
        Pick your plan. <em>14 days free.</em>
      </h1>
      <div class="aside">Card required &middot; you won't be charged until your trial ends. All tiers include the full 6-agent team and complete receipts.</div>
    </div>

    @if (session('error'))
      <div class="rvl" style="margin-bottom: 32px; padding: 16px 20px; border: 1px solid var(--danger, #b32418); border-radius: 12px; background: rgba(179, 36, 24, 0.06); color: var(--danger, #b32418); font-size: 14px;">
        {{ session('error') }}
      </div>
    @endif

    <div class="grid-12" style="gap: 24px;">
      @foreach ($tiers as $t)
        <div class="rvl" style="grid-column: span 4; padding: 36px; border-radius: 16px; background: var(--surface); border: 1px solid var(--line); {{ ($t['highlight'] ?? false) ? 'border: 2px solid var(--ink); transform: translateY(-12px);' : '' }}">
          <strong style="font-family: var(--sans); font-size: 22px; letter-spacing: -0.015em; color: var(--ink);">{{ $t['name'] }}</strong>
          <div style="margin-top: 18px; display: flex; align-items: baseline; gap: 6px;">
            <span style="font-family: var(--sans); font-weight: 500; font-size: 56px; letter-spacing: -0.04em; color: var(--ink);">{{ $t['price'] }}</span>
            <span style="font-size: 14px; color: var(--mute);">{{ $t['unit'] }}</span>
          </div>
          <ul style="margin-top: 24px; list-style: none; padding: 0; display: flex; flex-direction: column; gap: 10px;">
            <li style="display: flex; align-items: center; gap: 10px; font-size: 14px; color: var(--ink-2);"><span style="width: 4px; height: 4px; border-radius: 50%; background: var(--primary);"></span>{{ $t['brands'] }}</li>
            <li style="display: flex; align-items: center; gap: 10px; font-size: 14px; color: var(--ink-2);"><span style="width: 4px; height: 4px; border-radius: 50%; background: var(--primary);"></span>{{ $t['posts'] }}</li>
            <li style="display: flex; align-items: center; gap: 10px; font-size: 14px; color: var(--ink-2);"><span style="width: 4px; height: 4px; border-radius: 50%; background: var(--primary);"></span>All 6 agents + full receipts</li>
            <li style="display: flex; align-items: center; gap: 10px; font-size: 14px; color: var(--ink-2);"><span style="width: 4px; height: 4px; border-radius: 50%; background: var(--primary);"></span>Tiered autonomy (green/amber/red)</li>
            <li style="display: flex; align-items: center; gap: 10px; font-size: 14px; color: {{ $t['whitelabel'] ? 'var(--ink-2)' : 'var(--mute)' }};"><span style="width: 4px; height: 4px; border-radius: 50%; background: {{ $t['whitelabel'] ? 'var(--primary)' : 'var(--mute)' }};"></span>{{ $t['whitelabel'] ? 'White-label client portal' : 'No white-label (upgrade to Studio)' }}</li>
          </ul>
          <p style="margin-top: 20px; font-size: 13px; color: var(--ink-2); line-height: 1.5; font-style: italic;">{{ $t['best'] }}</p>
          <a href="{{ url('/signup/' . $t['key']) }}" class="btn {{ ($t['highlight'] ?? false) ? 'btn-primary' : 'btn-outline' }}" style="margin-top: 24px; width: 100%; justify-content: center;">Start 14-day trial <span class="arrow">&rarr;</span></a>
        </div>
      @endforeach
    </div>

    <p class="rvl" style="margin-top: 48px; font-family: var(--mono); font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--mute); text-align: center;">
      No card required &middot; Trial ends in 14 days &middot; Cancel any time &middot; FPX (Malaysia) + card billing
    </p>

    <p class="rvl" style="margin-top: 24px; text-align: center; font-size: 14px; color: var(--ink-2);">
      Already have an account? <a href="{{ url('/login') }}" style="color: var(--primary-dark, #0d5e54); text-decoration: underline;">Log in</a>.
    </p>
  </div>
</section>

@endsection
