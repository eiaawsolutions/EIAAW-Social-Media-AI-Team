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

            <div class="mt-5 flex flex-wrap gap-3">
                @if (! $hasActiveSub)
                    {{ $this->subscribeAction }}
                    {{ $this->subscribeAnnualAction }}
                @else
                    {{ $this->manageAction }}
                @endif
            </div>
        </div>

        <p class="text-xs text-gray-400 text-center">
            Need help? Email <a href="mailto:eiaawsolutions@gmail.com" class="text-primary-600 underline">eiaawsolutions@gmail.com</a> — we reply same day.
        </p>
    </div>
</x-filament-panels::page>
