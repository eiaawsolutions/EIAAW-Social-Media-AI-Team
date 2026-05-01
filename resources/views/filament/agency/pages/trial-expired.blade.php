<x-filament-panels::page>
    <div class="fi-section-content-ctn">
        <div class="fi-section-content p-6 space-y-6 text-center">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-amber-100">
                <x-filament::icon
                    icon="heroicon-o-lock-closed"
                    class="h-7 w-7 text-amber-700"
                />
            </div>

            <div class="space-y-2">
                <h2 class="text-2xl font-semibold tracking-tight">
                    Your 14-day trial has ended
                </h2>
                @if ($endedAtHuman)
                    <p class="text-sm text-gray-500 dark:text-gray-400">
                        Trial ended {{ $endedAtHuman }}.
                        Subscribe to {{ $planLabel }} to keep your brand running.
                    </p>
                @endif
            </div>

            <div class="rounded-lg border border-dashed border-gray-200 bg-gray-50 p-4 text-left text-sm text-gray-600 dark:border-gray-700 dark:bg-gray-900/40 dark:text-gray-300">
                <strong class="block text-gray-900 dark:text-white">What happens to your data?</strong>
                Your brands, posts, and receipts are kept on a 30-day grace period.
                Subscribe any time before then and you'll be back in the dashboard with everything intact.
            </div>

            <div class="flex flex-col items-center gap-3 sm:flex-row sm:justify-center">
                <x-filament::button
                    tag="a"
                    :href="url('/agency/billing')"
                    color="primary"
                    size="lg"
                >
                    Subscribe to {{ $planLabel }}
                </x-filament::button>
                <x-filament::button
                    tag="a"
                    :href="url('/agency/logout')"
                    color="gray"
                    outlined
                    size="lg"
                >
                    Log out
                </x-filament::button>
            </div>

            <p class="text-xs text-gray-400">
                Need a custom plan or can't see your tier? Email
                <a href="mailto:eiaawsolutions@gmail.com" class="text-primary-600 underline">
                    eiaawsolutions@gmail.com
                </a>.
            </p>
        </div>
    </div>
</x-filament-panels::page>
