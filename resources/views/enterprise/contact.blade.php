@extends('layouts.eiaaw')

@section('title', 'Enterprise — talk to us | EIAAW Social Media Team')
@section('description', 'Bespoke brand counts, image and video allowances, per-client guardrail isolation and priority support. Tell us what you need and our team will scope an Enterprise plan with you.')

@section('content')

<section class="section-pad" style="padding-top: clamp(140px, 18vh, 220px);">
  <div class="wrap">
    <div class="grid-12" style="gap: 32px; align-items: start;">

      {{-- Left: pitch + form --}}
      <div style="grid-column: 1 / span 7;">
        <div class="rvl">
          <div style="font-family: var(--mono); font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase; color: var(--mute); margin-bottom: 12px;">
            Enterprise &middot; talk to us
          </div>
          <h1 style="font-family: var(--sans); font-weight: 500; font-size: clamp(36px, 4vw, 56px); letter-spacing: -0.025em; line-height: 1.05; color: var(--ink); margin: 0;">
            Built around your operation.
          </h1>
          <p class="lead" style="margin-top: 18px; max-width: 56ch;">
            Enterprise isn't a checkout — it's a conversation. Tell us how many brands you run, the image and video volume you need, and how your team works. We'll scope a plan with the right allowances, per-client guardrail isolation across every brand, all 6 agents with full receipts, and priority support. We reply within one business day.
          </p>
        </div>

        @if (session('enterprise_sent'))
          <div class="rvl" style="margin-top: 28px; padding: 18px 22px; border: 1px solid var(--primary); border-radius: 12px; background: rgba(17, 118, 106, 0.06); color: var(--ink); font-size: 14px; line-height: 1.55;">
            <strong style="color: var(--primary);">Thank you — we've got your enquiry.</strong><br>
            Our team will be in touch within one business day to scope your Enterprise plan.
          </div>
        @endif

        @if ($errors->any())
          <div class="rvl" style="margin-top: 28px; padding: 16px 20px; border: 1px solid var(--danger, #B4412B); border-radius: 12px; background: rgba(180, 65, 43, 0.06); color: var(--danger, #B4412B); font-size: 14px;">
            <ul style="margin: 0; padding-left: 18px; line-height: 1.55;">
              @foreach ($errors->all() as $err)
                <li>{{ $err }}</li>
              @endforeach
            </ul>
          </div>
        @endif

        <form method="POST" action="{{ route('enterprise.contact.store') }}" class="rvl" style="margin-top: 32px; display: flex; flex-direction: column; gap: 18px;">
          @csrf
          {{-- Honeypot — bots fill it, humans never see it. --}}
          <input type="text" name="company_website" tabindex="-1" autocomplete="off" style="position: absolute; left: -9999px;" aria-hidden="true">

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 18px;">
            <label style="display: flex; flex-direction: column; gap: 6px;">
              <span style="font-size: 13px; color: var(--ink-2); font-weight: 500;">Your name</span>
              <input type="text" name="name" required maxlength="120" value="{{ old('name') }}" placeholder="Amos Lee"
                style="padding: 14px 16px; border: 1px solid var(--line); border-radius: 10px; background: var(--surface); font-size: 15px; color: var(--ink); font-family: var(--sans);">
            </label>
            <label style="display: flex; flex-direction: column; gap: 6px;">
              <span style="font-size: 13px; color: var(--ink-2); font-weight: 500;">Work email</span>
              <input type="email" name="email" required maxlength="160" value="{{ old('email') }}" placeholder="amos@company.com"
                style="padding: 14px 16px; border: 1px solid var(--line); border-radius: 10px; background: var(--surface); font-size: 15px; color: var(--ink); font-family: var(--sans);">
            </label>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 18px;">
            <label style="display: flex; flex-direction: column; gap: 6px;">
              <span style="font-size: 13px; color: var(--ink-2); font-weight: 500;">Company</span>
              <input type="text" name="company" required maxlength="160" value="{{ old('company') }}" placeholder="Company Sdn Bhd"
                style="padding: 14px 16px; border: 1px solid var(--line); border-radius: 10px; background: var(--surface); font-size: 15px; color: var(--ink); font-family: var(--sans);">
            </label>
            <label style="display: flex; flex-direction: column; gap: 6px;">
              <span style="font-size: 13px; color: var(--ink-2); font-weight: 500;">Phone <span style="color: var(--mute);">(optional)</span></span>
              <input type="text" name="phone" maxlength="40" value="{{ old('phone') }}" placeholder="+60 12-345 6789"
                style="padding: 14px 16px; border: 1px solid var(--line); border-radius: 10px; background: var(--surface); font-size: 15px; color: var(--ink); font-family: var(--sans);">
            </label>
          </div>

          <label style="display: flex; flex-direction: column; gap: 6px;">
            <span style="font-size: 13px; color: var(--ink-2); font-weight: 500;">Website <span style="color: var(--mute);">(optional)</span></span>
            <input type="text" name="website" maxlength="200" value="{{ old('website') }}" placeholder="company.com"
              style="padding: 14px 16px; border: 1px solid var(--line); border-radius: 10px; background: var(--surface); font-size: 15px; color: var(--ink); font-family: var(--sans);">
          </label>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 18px;">
            <label style="display: flex; flex-direction: column; gap: 6px;">
              <span style="font-size: 13px; color: var(--ink-2); font-weight: 500;">Company size <span style="color: var(--mute);">(optional)</span></span>
              <select name="company_size"
                style="padding: 14px 16px; border: 1px solid var(--line); border-radius: 10px; background: var(--surface); font-size: 15px; color: var(--ink); font-family: var(--sans);">
                <option value="">Select…</option>
                @foreach ($companySizes as $size)
                  <option value="{{ $size }}" @selected(old('company_size') === $size)>{{ $size }} employees</option>
                @endforeach
              </select>
            </label>
            <label style="display: flex; flex-direction: column; gap: 6px;">
              <span style="font-size: 13px; color: var(--ink-2); font-weight: 500;">Budget band <span style="color: var(--mute);">(optional)</span></span>
              <select name="budget_band"
                style="padding: 14px 16px; border: 1px solid var(--line); border-radius: 10px; background: var(--surface); font-size: 15px; color: var(--ink); font-family: var(--sans);">
                <option value="">Select…</option>
                @foreach ($budgetBands as $band)
                  <option value="{{ $band }}" @selected(old('budget_band') === $band)>{{ $band === 'not-sure' ? 'Not sure yet' : $band . ' / mo' }}</option>
                @endforeach
              </select>
            </label>
          </div>

          <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 18px;">
            <label style="display: flex; flex-direction: column; gap: 6px;">
              <span style="font-size: 13px; color: var(--ink-2); font-weight: 500;">Brands you manage <span style="color: var(--mute);">(optional)</span></span>
              <input type="number" name="brands_needed" min="1" max="100000" value="{{ old('brands_needed') }}" placeholder="e.g. 25"
                style="padding: 14px 16px; border: 1px solid var(--line); border-radius: 10px; background: var(--surface); font-size: 15px; color: var(--ink); font-family: var(--sans);">
            </label>
            <label style="display: flex; flex-direction: column; gap: 6px;">
              <span style="font-size: 13px; color: var(--ink-2); font-weight: 500;">Videos / month <span style="color: var(--mute);">(optional)</span></span>
              <input type="number" name="videos_per_month" min="0" max="1000000" value="{{ old('videos_per_month') }}" placeholder="e.g. 200"
                style="padding: 14px 16px; border: 1px solid var(--line); border-radius: 10px; background: var(--surface); font-size: 15px; color: var(--ink); font-family: var(--sans);">
            </label>
          </div>

          <label style="display: flex; flex-direction: column; gap: 6px;">
            <span style="font-size: 13px; color: var(--ink-2); font-weight: 500;">What do you need?</span>
            <textarea name="message" required maxlength="2000" rows="4" placeholder="Tell us about your brands, your team, your platforms, and what success looks like."
              style="padding: 14px 16px; border: 1px solid var(--line); border-radius: 10px; background: var(--surface); font-size: 15px; color: var(--ink); font-family: var(--sans); resize: vertical;">{{ old('message') }}</textarea>
          </label>

          <button type="submit" class="btn btn-primary" style="margin-top: 8px; align-self: flex-start;">
            Send enquiry <span class="arrow">&rarr;</span>
          </button>

          <p style="font-size: 12px; color: var(--mute); line-height: 1.5; margin: 4px 0 0;">
            We store only what you submit here — never guessed or enriched. We'll use it solely to scope your plan and reply.
          </p>
        </form>
      </div>

      {{-- Right: what Enterprise includes --}}
      <aside style="grid-column: 9 / span 4;" class="rvl">
        <div style="padding: 28px; border: 1px solid var(--line); border-radius: 16px; background: var(--surface);">
          <strong style="font-family: var(--sans); font-size: 18px; letter-spacing: -0.015em; color: var(--ink);">Every Enterprise plan includes</strong>
          <ul style="margin-top: 18px; list-style: none; padding: 0; display: flex; flex-direction: column; gap: 12px;">
            @foreach ([
              'Custom brand count — as many as you run',
              'Custom AI image + video allowances',
              '6 platforms: Facebook, Instagram, Threads, TikTok, YouTube, LinkedIn',
              'All 6 agents + full receipts',
              'Tiered autonomy (green/amber)',
              'Per-client guardrail isolation across every brand',
              'Priority support + bespoke onboarding',
            ] as $feature)
              <li style="display: flex; align-items: flex-start; gap: 10px; font-size: 14px; color: var(--ink-2); line-height: 1.45;">
                <span style="flex: 0 0 auto; margin-top: 7px; width: 4px; height: 4px; border-radius: 50%; background: var(--primary);"></span>{{ $feature }}
              </li>
            @endforeach
          </ul>
          <p style="margin-top: 20px; font-size: 13px; color: var(--mute); line-height: 1.5;">
            Already know you're smaller? <a href="{{ url('/signup') }}" style="color: var(--primary);">See Solo, Studio &amp; Agency &rarr;</a>
          </p>
        </div>
      </aside>

    </div>
  </div>
</section>

@endsection
