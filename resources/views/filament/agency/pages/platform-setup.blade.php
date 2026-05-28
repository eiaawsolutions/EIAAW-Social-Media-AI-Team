<x-filament-panels::page>
    @push('styles')
        <style>
            .ps-shell {
                background: #FAF7F2;
                border: 1px solid #D9CFBC;
                border-radius: 16px;
                padding: 32px;
            }
            .ps-eyebrow {
                font-family: 'JetBrains Mono', SFMono-Regular, Menlo, monospace;
                font-size: 11px; letter-spacing: 0.12em;
                text-transform: uppercase; color: #11766A;
            }
            .ps-title {
                font-size: 30px; font-weight: 600; letter-spacing: -0.025em;
                color: #0F1A1D; margin: 14px 0 12px; line-height: 1.15;
            }
            .ps-lead { font-size: 15px; line-height: 1.6; color: #2A3438; max-width: 60ch; }
            .ps-panel {
                background: white;
                border: 1px solid #D9CFBC;
                border-radius: 12px;
                padding: 24px;
                margin-top: 24px;
            }
            .ps-panel-success {
                background: #E5F4F1;
                border-color: #11766A;
            }
            .ps-panel-pending {
                background: #F3EDE0;
                border-color: #D9CFBC;
            }
            .ps-cta {
                display: inline-flex; align-items: center; gap: 8px;
                background: #0F1A1D; color: #FAF7F2;
                padding: 12px 22px; border-radius: 999px;
                font-size: 14px; font-weight: 500;
                text-decoration: none; border: 0; cursor: pointer;
                transition: transform .15s, background .15s;
            }
            .ps-cta:hover { background: #11766A; transform: translateY(-1px); }
            .ps-cta-ghost {
                background: transparent; color: #0F1A1D; border: 1px solid #D9CFBC;
            }
            .ps-cta-ghost:hover { border-color: #0F1A1D; background: transparent; }
            .ps-cta[disabled] { opacity: .55; cursor: wait; }
            .ps-step {
                display: grid; grid-template-columns: 32px 1fr; gap: 14px;
                margin-bottom: 14px;
            }
            .ps-step-num {
                width: 28px; height: 28px;
                border-radius: 999px;
                background: #0F1A1D; color: white;
                display: flex; align-items: center; justify-content: center;
                font-family: 'JetBrains Mono', monospace; font-size: 12px; font-weight: 600;
            }
            .ps-step-num-done {
                background: #11766A;
            }
            .ps-step-num-todo {
                background: #FAF7F2; color: #0F1A1D; border: 1px solid #D9CFBC;
            }
            .ps-step-title { font-size: 14px; font-weight: 500; color: #0F1A1D; }
            .ps-step-desc { font-size: 13px; color: #6B7A7F; margin-top: 2px; line-height: 1.55; }
            .ps-data-row {
                display: grid; grid-template-columns: 130px 1fr; gap: 10px;
                font-size: 14px; padding: 6px 0;
            }
            .ps-data-label { color: #6B7A7F; }
            .ps-data-value { color: #0F1A1D; }
            .ps-data-value code {
                font-family: 'JetBrains Mono', monospace;
                background: #F3EDE0; border: 1px solid #D9CFBC;
                border-radius: 6px; padding: 2px 8px;
                font-size: 13px;
            }
            .ps-foot {
                margin-top: 24px; padding-top: 16px;
                border-top: 1px dashed #D9CFBC;
                font-family: 'JetBrains Mono', monospace;
                font-size: 11px; letter-spacing: .12em;
                text-transform: uppercase; color: #6B7A7F;
            }
        </style>
    @endpush

    <div class="ps-shell">
        <div class="ps-eyebrow">Platform setup &middot; Blotato handoff</div>

        @if ($state === 'connected')
            {{-- ─── State 4: connected ─── --}}
            <h2 class="ps-title">Your Blotato publishing account is live.</h2>
            <p class="ps-lead">
                EIAAW is wired to your dedicated Blotato account. We can now publish to any social handle you connect inside Blotato.
                Manage per-brand connections on the platforms page.
            </p>
            <div class="ps-panel ps-panel-success">
                <div class="ps-data-row">
                    <div class="ps-data-label">Verified</div>
                    <div class="ps-data-value">{{ optional($workspace->blotato_connected_at)->format('M j, Y \a\t H:i') }} ({{ optional($workspace->blotato_connected_at)->diffForHumans() }})</div>
                </div>
                @if ($workspace->blotato_account_email)
                <div class="ps-data-row">
                    <div class="ps-data-label">Blotato email</div>
                    <div class="ps-data-value"><strong>{{ $workspace->blotato_account_email }}</strong></div>
                </div>
                @endif
                @if ($workspace->blotato_login_url)
                <div class="ps-data-row">
                    <div class="ps-data-label">Blotato login</div>
                    <div class="ps-data-value"><a href="{{ $workspace->blotato_login_url }}" target="_blank" rel="noopener" style="color: #11766A; text-decoration: underline;">{{ $workspace->blotato_login_url }}</a></div>
                </div>
                @endif
            </div>
            <div style="margin-top: 24px; display: flex; gap: 14px; flex-wrap: wrap;">
                <a href="{{ url('/agency/platforms') }}" class="ps-cta">Manage per-brand platform connections <span aria-hidden="true">→</span></a>
                <a href="{{ url('/agency/setup-wizard') }}" class="ps-cta ps-cta-ghost">Back to setup wizard</a>
                <button type="button" wire:click="verifyConnection" wire:loading.attr="disabled" class="ps-cta ps-cta-ghost">
                    <span wire:loading.remove wire:target="verifyConnection">Re-verify connection</span>
                    <span wire:loading wire:target="verifyConnection">Checking…</span>
                </button>
            </div>

        @elseif ($state === 'credentialed')
            {{-- ─── State 3: credentialed (HQ has provisioned, awaiting customer verify) ─── --}}
            <h2 class="ps-title">Your Blotato login is ready &mdash; verify it here.</h2>
            <p class="ps-lead">
                Our team has created your dedicated Blotato account. Sent you an email with the login URL and temp password
                {{ optional($workspace->blotato_credentials_sent_at)->diffForHumans() }}. Once you've logged in to Blotato and connected
                your social handles (Instagram, LinkedIn, TikTok, etc.), come back here and click <strong>Verify connection</strong>.
            </p>
            <div class="ps-panel">
                <div class="ps-data-row">
                    <div class="ps-data-label">Blotato login</div>
                    <div class="ps-data-value">
                        @if ($workspace->blotato_login_url)
                            <a href="{{ $workspace->blotato_login_url }}" target="_blank" rel="noopener" style="color: #11766A; text-decoration: underline;">{{ $workspace->blotato_login_url }}</a>
                        @else
                            <span style="color: #6B7A7F;">Check the email we sent you for the login URL.</span>
                        @endif
                    </div>
                </div>
                @if ($workspace->blotato_account_email)
                <div class="ps-data-row">
                    <div class="ps-data-label">Blotato email</div>
                    <div class="ps-data-value"><strong>{{ $workspace->blotato_account_email }}</strong></div>
                </div>
                @endif
                <div class="ps-data-row">
                    <div class="ps-data-label">Password</div>
                    <div class="ps-data-value" style="color: #6B7A7F;">In the email we sent {{ optional($workspace->blotato_credentials_sent_at)->diffForHumans() }} &mdash; please change it on first login.</div>
                </div>
            </div>

            <div style="margin-top: 28px;">
                <div class="ps-step">
                    <div class="ps-step-num ps-step-num-done">1</div>
                    <div>
                        <div class="ps-step-title">Open the email from EIAAW with subject "Your Blotato publishing account is ready"</div>
                        <div class="ps-step-desc">Can't find it? Check spam, then email eiaawsolutions@gmail.com.</div>
                    </div>
                </div>
                <div class="ps-step">
                    <div class="ps-step-num">2</div>
                    <div>
                        <div class="ps-step-title">Log in to Blotato and change the temp password</div>
                        <div class="ps-step-desc">Use Blotato's "Forgot password" if you'd rather skip the temp.</div>
                    </div>
                </div>
                <div class="ps-step">
                    <div class="ps-step-num">3</div>
                    <div>
                        <div class="ps-step-title">Connect your social handles inside Blotato</div>
                        <div class="ps-step-desc">Instagram, LinkedIn, TikTok, X, Threads, Facebook &mdash; whichever you publish to. Each one is an OAuth click.</div>
                    </div>
                </div>
                <div class="ps-step">
                    <div class="ps-step-num">4</div>
                    <div>
                        <div class="ps-step-title">Come back and click "Verify connection" below</div>
                        <div class="ps-step-desc">We'll ping Blotato with your API key and confirm everything's live.</div>
                    </div>
                </div>
            </div>

            <div style="margin-top: 28px; display: flex; gap: 14px; flex-wrap: wrap;">
                <button type="button" wire:click="verifyConnection" wire:loading.attr="disabled" class="ps-cta">
                    <span wire:loading.remove wire:target="verifyConnection">Verify connection <span aria-hidden="true">→</span></span>
                    <span wire:loading wire:target="verifyConnection">Pinging Blotato…</span>
                </button>
                @if ($workspace->blotato_login_url)
                    <a href="{{ $workspace->blotato_login_url }}" target="_blank" rel="noopener" class="ps-cta ps-cta-ghost">Open Blotato <span aria-hidden="true">↗</span></a>
                @endif
            </div>

        @elseif ($state === 'requested')
            {{-- ─── State 2: requested, awaiting HQ ─── --}}
            <h2 class="ps-title">We're provisioning your Blotato account.</h2>
            <p class="ps-lead">
                You requested setup {{ optional($workspace->blotato_setup_requested_at)->diffForHumans() }}. Our team is creating a dedicated Blotato
                account for {{ $workspace->name }} now. <strong>You'll receive an email with your Blotato login within 1 business day</strong> &mdash;
                this page will then unlock the next step.
            </p>
            <div class="ps-panel ps-panel-pending">
                <div class="ps-eyebrow" style="color: #6B7A7F; margin-bottom: 10px;">Status</div>
                <div style="font-size: 14px; color: #0F1A1D; line-height: 1.6;">
                    <strong>Awaiting provision.</strong> Why this isn't instant: Blotato has no multi-tenant API, so each customer needs a dedicated account.
                    Our team creates it manually so we never share API keys between customers. In return you get full isolation &mdash; no other customer's
                    handles ever appear in your dashboard. Cost is bundled into your subscription.
                </div>
            </div>
            <p class="ps-lead" style="margin-top: 24px;">
                Need it faster? Email <a href="mailto:eiaawsolutions@gmail.com" style="color: #11766A; text-decoration: underline;">eiaawsolutions@gmail.com</a> with subject
                <em>"Urgent: Blotato setup &mdash; {{ $workspace->name }}"</em>. We try to do same-business-day for urgent requests.
            </p>

        @else
            {{-- ─── State 1: not_requested ─── --}}
            <h2 class="ps-title">First, we provision your dedicated publishing account.</h2>
            <p class="ps-lead">
                EIAAW publishes through Blotato, which connects to Instagram, LinkedIn, TikTok, X, Threads, and Facebook on your behalf.
                You get a <strong>dedicated Blotato account</strong> (no shared API keys, no cross-customer leakage) &mdash; our team creates
                it for you because Blotato doesn't yet support automated provisioning.
            </p>

            <div class="ps-panel">
                <div class="ps-eyebrow" style="color: #6B7A7F;">What happens when you click below</div>
                <div style="margin-top: 14px;">
                    <div class="ps-step">
                        <div class="ps-step-num ps-step-num-done">1</div>
                        <div>
                            <div class="ps-step-title">You request setup</div>
                            <div class="ps-step-desc">We get notified instantly with your workspace ID and email.</div>
                        </div>
                    </div>
                    <div class="ps-step">
                        <div class="ps-step-num">2</div>
                        <div>
                            <div class="ps-step-title">Our team creates your Blotato account</div>
                            <div class="ps-step-desc">Within 1 business day. We email you the login URL + temp password &mdash; you change it on first login.</div>
                        </div>
                    </div>
                    <div class="ps-step">
                        <div class="ps-step-num">3</div>
                        <div>
                            <div class="ps-step-title">You log in to Blotato, connect your social handles</div>
                            <div class="ps-step-desc">Instagram, LinkedIn, TikTok, X, Threads, Facebook &mdash; OAuth flows live inside Blotato.</div>
                        </div>
                    </div>
                    <div class="ps-step">
                        <div class="ps-step-num">4</div>
                        <div>
                            <div class="ps-step-title">You come back here and verify the connection</div>
                            <div class="ps-step-desc">One click. We ping Blotato to confirm your key is live, then you're unblocked.</div>
                        </div>
                    </div>
                </div>
            </div>

            <div style="margin-top: 28px; display: flex; gap: 14px; flex-wrap: wrap;">
                <button type="button" wire:click="requestSetup" wire:loading.attr="disabled" class="ps-cta">
                    <span wire:loading.remove wire:target="requestSetup">Request Blotato setup <span aria-hidden="true">→</span></span>
                    <span wire:loading wire:target="requestSetup">Notifying our team…</span>
                </button>
                <a href="{{ url('/agency/billing') }}" class="ps-cta ps-cta-ghost">View billing</a>
            </div>
        @endif

        <div class="ps-foot">
            Page auto-refreshes when you act &middot; status is sourced from this workspace's database row, never cached more than 30 seconds
        </div>
    </div>
</x-filament-panels::page>
