<div class="space-y-5 text-sm leading-relaxed">
    @php
        $needsSetup = method_exists($this, 'workspaceNeedsBlotatoSetup') ? $this->workspaceNeedsBlotatoSetup() : false;
        $blotatoEmail = method_exists($this, 'workspaceBlotatoEmail') ? $this->workspaceBlotatoEmail() : null;
    @endphp

    @if ($needsSetup)
        <div class="rounded-xl border border-amber-300/50 bg-amber-50 dark:bg-amber-900/20 p-4 text-amber-900 dark:text-amber-200">
            <p class="font-semibold">Your workspace needs its own Blotato account first.</p>
            <p class="mt-1 text-xs">
                We isolate every workspace's social connections by giving each one its own Blotato account.
                Your EIAAW administrator provisions this — once done, you'll see the "Open Blotato" button here.
                Contact <a class="underline" href="mailto:support@eiaawsolutions.com">support@eiaawsolutions.com</a> to request setup.
            </p>
        </div>
    @else
        <ol class="space-y-3 list-decimal list-inside text-gray-700 dark:text-gray-300">
            <li>Click <strong>Open Blotato</strong> below. A new tab opens to <strong>your workspace's</strong> Blotato settings @if($blotatoEmail) (signed in as <code class="text-xs">{{ $blotatoEmail }}</code>) @endif.</li>
            <li>Sign in to Blotato and connect any platform you want — Instagram, Facebook, LinkedIn, TikTok, X, YouTube, Pinterest, Threads, or Bluesky.</li>
            <li>Come back to this tab. Your new connection appears here automatically within a few seconds — no refresh needed.</li>
        </ol>

        <div class="flex items-center justify-between gap-3 rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-white/5 p-4">
            <div class="text-xs text-gray-500 dark:text-gray-400">
                We auto-detect your new platform for 5 minutes after you click below.
            </div>
            <a
                href="https://my.blotato.com/settings"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-400"
            >
                Open Blotato
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 0 0 3 8.25v10.5A2.25 2.25 0 0 0 5.25 21h10.5A2.25 2.25 0 0 0 18 18.75V10.5m-10.5 6 9.75-9.75M21 3v6m0-6h-6" />
                </svg>
            </a>
        </div>

        <div
            x-data="{ pollOpen: @js((bool) $this->autoSyncStartedAt) }"
            x-init="$watch('pollOpen', v => v && setTimeout(() => $wire.autoSyncTick(), 500))"
            class="text-xs text-gray-500 dark:text-gray-400"
        >
            <span x-show="pollOpen" class="inline-flex items-center gap-2">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5 animate-spin">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992V4.356M3.04 12.751a8.978 8.978 0 0 1 16.973-3.397m-13.974 8.27a9 9 0 0 0 14.79-2.532M3.985 15v4.992h4.991" />
                </svg>
                Watching for new connections…
            </span>
            <span x-show="!pollOpen">
                Click "Open Blotato" to start watching.
            </span>
        </div>
    @endif
</div>
