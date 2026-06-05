<div class="space-y-5 text-sm leading-relaxed">
    @php
        $noMappedBrand = method_exists($this, 'workspaceHasNoMappedBrand') ? $this->workspaceHasNoMappedBrand() : true;
        $setupUrl = method_exists($this, 'setupUrl') ? $this->setupUrl() : null;
        // The customer's OWN brand connect-link (https://f.mtr.cool/...), or null
        // when none has been stored yet. When present, the primary CTA opens it
        // directly so the customer lands on their own Metricool connect page;
        // otherwise we send them to Platform setup to request a fresh link.
        $connectLink = method_exists($this, 'connectLink') ? $this->connectLink() : null;
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
    @elseif ($connectLink)
        <div class="rounded-xl border border-primary-300/50 bg-primary-50 dark:bg-primary-900/20 p-4 text-primary-900 dark:text-primary-200">
            <p class="font-semibold">Connect or edit your social accounts</p>
            <p class="mt-1 text-xs">
                Click below to open your brand's secure connect page. Add any platform — Instagram, Facebook,
                LinkedIn, TikTok, YouTube, Pinterest, Threads, or X — then come back here and click
                <strong>Refresh connections</strong>.
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

    @if ($connectLink)
        {{-- Primary action: open the customer's own brand connect page directly. --}}
        <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-end">
            @if ($setupUrl)
                <a
                    href="{{ $setupUrl }}"
                    class="inline-flex items-center justify-center rounded-lg border border-gray-300 dark:border-white/15 px-4 py-2 text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-gray-50 dark:hover:bg-white/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-400"
                >
                    Platform setup
                </a>
            @endif
            <a
                href="{{ $connectLink }}"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-400"
            >
                Open my connect page
                <span aria-hidden="true" style="font-size:1em;line-height:1;">&rarr;</span>
            </a>
        </div>
    @elseif ($setupUrl)
        {{-- No stored link yet: send the customer to Platform setup to request one. --}}
        <div class="flex items-center justify-end">
            <a
                href="{{ $setupUrl }}"
                class="inline-flex items-center justify-center gap-2 rounded-lg bg-primary-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-primary-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-400"
            >
                Go to Platform setup
                <span aria-hidden="true" style="font-size:1em;line-height:1;">&rarr;</span>
            </a>
        </div>
    @endif
</div>
