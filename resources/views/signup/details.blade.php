@extends('layouts.eiaaw')

@section('title', 'Tell us about you — EIAAW Social Media Team')
@section('description', 'Step 2 of 2. Your details, then a Stripe-hosted card capture. We never see your card. Trial starts the moment you confirm.')

@section('content')

<section class="section-pad" style="padding-top: clamp(140px, 18vh, 220px);">
  <div class="wrap">
    <div class="grid-12" style="gap: 32px; align-items: start;">

      {{-- Left: form --}}
      <div style="grid-column: 1 / span 7;">
        <div class="rvl">
          <div style="font-family: var(--mono); font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--mute); margin-bottom: 12px;">
            Sign up &middot; step 2 of 2
          </div>
          <h1 style="font-family: var(--sans); font-weight: 500; font-size: clamp(36px, 4vw, 56px); letter-spacing: -0.025em; line-height: 1.05; color: var(--ink); margin: 0;">
            Tell us your name and email.
          </h1>
          <p class="lead" style="margin-top: 18px; max-width: 56ch;">
            We need these to set up your workspace. The next screen is Stripe — we never see your card. Your 14-day trial starts the moment you confirm.
          </p>
        </div>

        @if ($errors->any())
          <div class="rvl" style="margin-top: 28px; padding: 16px 20px; border: 1px solid var(--danger, #B4412B); border-radius: 12px; background: rgba(180, 65, 43, 0.06); color: var(--danger, #B4412B); font-size: 14px;">
            <ul style="margin: 0; padding-left: 18px; line-height: 1.55;">
              @foreach ($errors->all() as $err)
                <li>{{ $err }}</li>
              @endforeach
            </ul>
          </div>
        @endif

        <form method="POST" action="{{ route('billing.checkout', ['plan' => $plan['key']]) }}" class="rvl" style="margin-top: 32px; display: flex; flex-direction: column; gap: 18px;">
          @csrf
          {{-- Honeypot --}}
          <input type="text" name="company_website" tabindex="-1" autocomplete="off" style="position: absolute; left: -9999px;">

          <label style="display: flex; flex-direction: column; gap: 6px;">
            <span style="font-size: 13px; color: var(--ink-2); font-weight: 500;">Your name</span>
            <input
              type="text"
              name="name"
              required
              maxlength="120"
              value="{{ old('name') }}"
              placeholder="Amos Lee"
              style="padding: 14px 16px; border: 1px solid var(--line); border-radius: 10px; background: var(--surface); font-size: 15px; color: var(--ink); font-family: var(--sans);"
            >
          </label>

          <label style="display: flex; flex-direction: column; gap: 6px;">
            <span style="font-size: 13px; color: var(--ink-2); font-weight: 500;">Email</span>
            <input
              type="email"
              name="email"
              required
              maxlength="190"
              value="{{ old('email') }}"
              placeholder="you@company.com"
              style="padding: 14px 16px; border: 1px solid var(--line); border-radius: 10px; background: var(--surface); font-size: 15px; color: var(--ink); font-family: var(--sans);"
            >
            <span style="font-size: 12px; color: var(--mute);">We'll email you a temporary password — change it on first login.</span>
          </label>

          <label style="display: flex; flex-direction: column; gap: 6px;">
            <span style="font-size: 13px; color: var(--ink-2); font-weight: 500;">Brand or company name</span>
            <input
              type="text"
              name="workspace_name"
              required
              maxlength="120"
              value="{{ old('workspace_name') }}"
              placeholder="Acme Studio"
              style="padding: 14px 16px; border: 1px solid var(--line); border-radius: 10px; background: var(--surface); font-size: 15px; color: var(--ink); font-family: var(--sans);"
            >
            <span style="font-size: 12px; color: var(--mute);">Shown on receipts and at the top of your dashboard. You can change this later.</span>
          </label>

          <div style="margin-top: 12px; display: flex; flex-wrap: wrap; align-items: center; gap: 18px;">
            <button type="submit" class="btn btn-primary btn-lg">
              Continue to secure checkout <span class="arrow">&rarr;</span>
            </button>
            <a href="{{ url('/signup') }}" class="btn btn-ghost">Change plan</a>
          </div>

          <p style="margin-top: 12px; font-size: 12px; line-height: 1.55; color: var(--mute);">
            By continuing you agree to our <a href="/terms" style="color: var(--primary-dark);">Terms</a> and <a href="/privacy" style="color: var(--primary-dark);">Privacy</a>. The trial is fully refundable until your first charge.
          </p>
        </form>
      </div>

      {{-- Right: order summary --}}
      <div style="grid-column: 9 / span 4; padding-top: 80px;">
        <div class="rvl elegant-figure receipt-card" style="position: sticky; top: 100px;">
          <div class="receipt-card-inner">
            <div class="eyebrow" style="margin-bottom: 18px;">Your selection</div>
            <div style="font-family: var(--sans); font-size: 26px; font-weight: 500; letter-spacing: -0.02em; color: var(--ink);">
              {{ $plan['name'] }}
            </div>
            <div style="margin-top: 4px; font-size: 14px; color: var(--ink-2); line-height: 1.5;">
              {{ $plan['description'] }}
            </div>
            <div style="margin-top: 22px; padding-top: 22px; border-top: 1px dashed var(--line); font-size: 14px; color: var(--ink-2); line-height: 1.7;">
              <div style="display: flex; justify-content: space-between;"><span>Trial</span><strong style="color: var(--ink);">{{ $plan['trial_days'] }} days free</strong></div>
              <div style="display: flex; justify-content: space-between;"><span>Then</span><strong style="color: var(--ink);">RM {{ number_format($plan['price_myr'], 0) }} / month</strong></div>
              <div style="display: flex; justify-content: space-between;"><span>Today</span><strong style="color: var(--primary-dark);">RM 0.00</strong></div>
            </div>
            <p style="margin-top: 22px; font-size: 12px; color: var(--mute); line-height: 1.5;">
              You can cancel any time during the trial and you won't be charged. After the trial we charge automatically &mdash; cancel from your billing page.
            </p>
          </div>
        </div>
      </div>

    </div>
  </div>
</section>

@endsection
