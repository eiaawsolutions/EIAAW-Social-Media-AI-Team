<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Trial / status header --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <h2 class="text-lg font-semibold tracking-tight">{{ $planLabel }}</h2>
                    <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">{{ $statusLabel }}</p>
                </div>
                @if ($trialBadge)
                    <span class="inline-flex items-center rounded-full bg-amber-100 px-3 py-1 text-xs font-medium text-amber-800 dark:bg-amber-900/40 dark:text-amber-300">
                        {{ $trialBadge }}
                    </span>
                @elseif ($hasActiveSub)
                    <span class="inline-flex items-center rounded-full bg-emerald-100 px-3 py-1 text-xs font-medium text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300">
                        Active
                    </span>
                @endif
            </div>
        </div>

        {{-- Usage this month: brands / posts published / AI videos. Shown
             only when the workspace has caps to display (skip for eiaaw_internal). --}}
        @if ($usage && $workspace?->plan !== 'eiaaw_internal')
            @php
                $postsPct = $usage['posts_cap'] > 0 ? min(100, (int) round($usage['posts_used'] / $usage['posts_cap'] * 100)) : 0;
                $videosPct = $usage['videos_cap'] > 0 ? min(100, (int) round($usage['videos_used'] / $usage['videos_cap'] * 100)) : 0;
                $brandsPct = $usage['brands_cap'] > 0 ? min(100, (int) round($usage['brands_used'] / $usage['brands_cap'] * 100)) : 0;
                $barClass = fn (int $pct) => $pct >= 100 ? 'bg-rose-500' : ($pct >= 80 ? 'bg-amber-500' : 'bg-emerald-500');
            @endphp
            <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
                <h3 class="text-base font-semibold tracking-tight">This month's usage</h3>
                <p class="mt-1 text-sm text-gray-500 dark:text-gray-400">
                    Resets at 00:05 on the 1st of next month. Posts past your cap auto-queue for next period; videos hard-stop at the cap.
                </p>
                <div class="mt-5 space-y-4">
                    <div>
                        <div class="flex items-baseline justify-between text-sm">
                            <span class="font-medium text-gray-700 dark:text-gray-300">Brands</span>
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ $usage['brands_used'] }} / {{ $usage['brands_cap'] >= PHP_INT_MAX ? '∞' : $usage['brands_cap'] }}
                            </span>
                        </div>
                        <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                            <div class="h-full {{ $barClass($brandsPct) }}" style="width: {{ $brandsPct }}%"></div>
                        </div>
                    </div>
                    <div>
                        <div class="flex items-baseline justify-between text-sm">
                            <span class="font-medium text-gray-700 dark:text-gray-300">Published posts</span>
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ $usage['posts_used'] }} / {{ $usage['posts_cap'] >= PHP_INT_MAX ? '∞' : $usage['posts_cap'] }}
                            </span>
                        </div>
                        <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                            <div class="h-full {{ $barClass($postsPct) }}" style="width: {{ $postsPct }}%"></div>
                        </div>
                    </div>
                    <div>
                        <div class="flex items-baseline justify-between text-sm">
                            <span class="font-medium text-gray-700 dark:text-gray-300">AI videos generated</span>
                            <span class="text-gray-500 dark:text-gray-400">
                                {{ $usage['videos_used'] }} / {{ $usage['videos_cap'] >= PHP_INT_MAX ? '∞' : $usage['videos_cap'] }}
                            </span>
                        </div>
                        <div class="mt-1 h-2 w-full overflow-hidden rounded-full bg-gray-100 dark:bg-gray-700">
                            <div class="h-full {{ $barClass($videosPct) }}" style="width: {{ $videosPct }}%"></div>
                        </div>
                    </div>
                </div>
            </div>
        @endif

        @if (request()->query('status') === 'success')
            <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900 dark:border-emerald-700 dark:bg-emerald-900/20 dark:text-emerald-200">
                Payment received. Your subscription will activate as soon as Stripe confirms — usually within seconds.
            </div>
        @elseif (request()->query('status') === 'cancel')
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-700 dark:bg-amber-900/20 dark:text-amber-200">
                Checkout was cancelled. You can try again any time.
            </div>
        @endif

        {{-- Subscribe / manage actions --}}
        <div class="rounded-xl border border-gray-200 bg-white p-6 dark:border-gray-700 dark:bg-gray-800">
            <h3 class="text-base font-semibold tracking-tight">
                @if ($hasActiveSub) Manage your subscription @else Subscribe to keep your brand running @endif
            </h3>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                @if ($hasActiveSub)
                    Update your card, switch plans, or download invoices through Stripe's secure portal.
                @else
                    Pick monthly to stay flexible, or annual for two months free. Cancel any time — no auto-renewal traps.
                @endif
            </p>

            @if ($pricing && ! $hasActiveSub)
                <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900">
                        <div class="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400">Monthly</div>
                        <div class="mt-1 text-2xl font-semibold tracking-tight">RM {{ number_format($pricing['monthly_myr']) }}<span class="text-sm font-normal text-gray-500"> /mo</span></div>
                    </div>
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-700 dark:bg-emerald-900/20">
                        <div class="text-xs uppercase tracking-wide text-emerald-700 dark:text-emerald-300">Annual · save 2 months</div>
                        <div class="mt-1 text-2xl font-semibold tracking-tight text-emerald-900 dark:text-emerald-100">RM {{ number_format($pricing['annual_myr']) }}<span class="text-sm font-normal text-emerald-700 dark:text-emerald-300"> /yr</span></div>
                        <div class="mt-1 text-xs text-emerald-700 dark:text-emerald-300">
                            Saves RM {{ number_format($pricing['annual_savings_myr']) }} vs paying monthly
                        </div>
                    </div>
                </div>
                <p class="mt-3 text-xs text-gray-500 dark:text-gray-400">{{ $pricing['tax_note'] }}</p>
            @endif

            <div class="mt-5 flex flex-wrap gap-3">
                @if (! $hasActiveSub)
                    {{ $this->subscribeAction }}
                    {{ $this->subscribeAnnualAction }}
                @else
                    {{ $this->manageAction }}
                @endif
            </div>
        </div>

        {{-- Opens the existing floating support form (the "Tell us what you're
             working on" lead-capture mounted panel-wide by AgencyPanelProvider's
             BODY_END hook). data-smt="contact" is handled by smt-chat.js, which
             posts to /api/contact → SupportChatController. Button, not anchor,
             so keyboard + screen-reader semantics are correct. --}}
        <p class="text-xs text-gray-400 text-center">
            Need help?
            <button type="button" data-smt="contact" class="text-primary-600 underline hover:text-primary-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-500 rounded">
                Talk to us
            </button>
            — we reply same day.
        </p>
    </div>
</x-filament-panels::page>
