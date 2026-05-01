<x-filament-widgets::widget>
    <div
        x-data="{
            tempPassword: null,
            email: @js($email),
            error: null,
            copied: false,
            async fetchPassword() {
                try {
                    const r = await fetch(@js($welcomeTokenUrl), {
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json' },
                    });
                    if (!r.ok) {
                        this.error = 'expired';
                        return;
                    }
                    const j = await r.json();
                    this.tempPassword = j.tempPassword;
                    this.email = j.email || this.email;
                } catch {
                    this.error = 'network';
                }
            },
            async copy() {
                if (!this.tempPassword) return;
                try {
                    await navigator.clipboard.writeText(this.tempPassword);
                    this.copied = true;
                    setTimeout(() => this.copied = false, 2000);
                } catch {}
            },
            stripWelcomeFromUrl() {
                const u = new URL(window.location);
                u.searchParams.delete('welcome');
                window.history.replaceState({}, '', u.toString());
            },
        }"
        x-init="await fetchPassword(); stripWelcomeFromUrl();"
        style="background: #E5F4F1; border: 1px solid #1FA896; border-radius: 12px; padding: 20px 24px; margin-bottom: 16px;"
    >
        <div style="display: flex; align-items: flex-start; gap: 16px;">
            <div style="flex-shrink: 0; width: 32px; height: 32px; border-radius: 999px; background: #11766A; color: white; display: flex; align-items: center; justify-content: center; font-weight: 700; font-family: 'JetBrains Mono', SFMono-Regular, Menlo, monospace;">✓</div>
            <div style="flex: 1; min-width: 0;">
                <div style="font-family: 'JetBrains Mono', SFMono-Regular, Menlo, monospace; font-size: 11px; letter-spacing: 0.12em; text-transform: uppercase; color: #11766A; margin-bottom: 4px;">
                    Welcome &middot; Save these credentials
                </div>
                <div style="font-size: 16px; font-weight: 500; color: #0F1A1D; line-height: 1.4;">
                    Your account is live. Save your password before you navigate away.
                </div>

                <template x-if="tempPassword">
                    <div style="margin-top: 14px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
                        <div>
                            <div style="font-size: 11px; color: #6B7A7F; text-transform: uppercase; letter-spacing: 0.08em;">Email</div>
                            <div style="font-size: 14px; color: #0F1A1D;" x-text="email"></div>
                        </div>
                        <div>
                            <div style="font-size: 11px; color: #6B7A7F; text-transform: uppercase; letter-spacing: 0.08em;">Temporary password</div>
                            <code x-text="tempPassword" style="display: inline-block; padding: 4px 10px; background: white; border: 1px solid #D9CFBC; border-radius: 6px; font-family: 'JetBrains Mono', SFMono-Regular, Menlo, monospace; font-size: 14px; color: #0F1A1D;"></code>
                        </div>
                        <button
                            @click="copy()"
                            type="button"
                            style="display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 999px; background: #0F1A1D; color: white; border: none; font-size: 13px; cursor: pointer; font-family: inherit;"
                        >
                            <span x-text="copied ? 'Copied' : 'Copy password'"></span>
                        </button>
                    </div>
                </template>

                <template x-if="error === 'expired' || error === 'network'">
                    <div style="margin-top: 12px; font-size: 13px; color: #2A3438; line-height: 1.5;">
                        We've also emailed your password to <strong x-text="email"></strong>. If you can't find it, use
                        <a href="/agency/password-reset/request" style="color: #11766A; text-decoration: underline;">password reset</a>.
                    </div>
                </template>

                <div style="margin-top: 12px; font-size: 12px; color: #6B7A7F;">
                    Then go to <strong>Profile &rarr; Change password</strong> to set your own.
                </div>
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
