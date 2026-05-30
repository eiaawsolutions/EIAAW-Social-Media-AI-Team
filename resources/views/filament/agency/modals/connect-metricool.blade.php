<div class="space-y-5 text-sm leading-relaxed">
    @php
        $noMappedBrand = method_exists($this, 'workspaceHasNoMappedBrand') ? $this->workspaceHasNoMappedBrand() : true;
        $setupUrl = method_exists($this, 'setupUrl') ? $this->setupUrl() : null;
    @endphp

    @if ($noMappedBrand)
        <div class="rounded-xl border border-amber-300/50 bg-amber-50 dark:bg-amber-900/20 p-4 text-amber-900 dark:text-amber-200">
            <p class="font-semibold">Your brand needs its secure space set up first.</p>
            <p class="mt-1 text-xs">
                We set up a dedicated secure space for each brand, then send you a secure link to connect your
                social accounts. Request setup from the Platform setup page and our team will get you a link,
                usually within one business day.
            </p>
        </div>
    @else
        <ol class="space-y-3 list-decimal list-inside text-gray-700 dark:text-gray-300">
            <li>Open the secure <strong>connect-link</strong> we sent you (also available in <strong>Platform setup</strong>).</li>
            <li>Connect any platform you want — Instagram, Facebook, LinkedIn, TikTok, YouTube, Pinterest, Threads, or X.</li>
            <li>Come back here and click <strong>Refresh connections</strong>. Your connected accounts appear in the table.</li>
        </ol>

        <div class="rounded-xl border border-gray-200 dark:border-white/10 bg-gray-50 dark:bg-white/5 p-4 text-xs text-gray-500 dark:text-gray-400">
            Connection links are minted per brand and expire after about 3 days. If yours has expired,
            request a fresh one from Platform setup.
        </div>
    @endif

    @if ($setupUrl)
        <div class="flex items-center justify-end">
            <a
                href="{{ $setupUrl }}"
                class="inline-flex items-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-400"
            >
                Go to Platform setup
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4 h-4">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M13.5 4.5 21 12m0 0-7.5 7.5M21 12H3" />
                </svg>
            </a>
        </div>
    @endif
</div>
