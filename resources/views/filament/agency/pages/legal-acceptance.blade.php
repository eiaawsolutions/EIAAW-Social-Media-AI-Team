<x-filament-panels::page>
    <div class="fi-section-content-ctn">
        <div class="fi-section-content p-6 space-y-6">
            <div class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-primary-100">
                <x-filament::icon
                    icon="heroicon-o-document-check"
                    class="h-7 w-7 text-primary-700"
                />
            </div>

            <div class="space-y-2 text-center">
                <h2 class="text-2xl font-semibold tracking-tight">
                    Please review and accept our terms
                </h2>
                <p class="text-sm text-gray-500 dark:text-gray-400">
                    Before you continue, we need your agreement to the documents below.
                    You only need to do this once.
                </p>
            </div>

            @if ($isReacceptance && $changeNote)
                <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800 dark:border-amber-700/50 dark:bg-amber-900/20 dark:text-amber-200">
                    {{ $changeNote }}
                </div>
            @endif

            <div class="rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-900/40">
                <ul class="space-y-2 text-sm">
                    @foreach ($documents as $doc)
                        <li class="flex items-center justify-between gap-3">
                            <a
                                href="{{ route($doc['route']) }}"
                                target="_blank"
                                rel="noopener"
                                class="font-medium text-primary-600 underline dark:text-primary-400"
                            >
                                {{ $doc['name'] }}
                            </a>
                            <span class="text-xs text-gray-400">Updated {{ $doc['updated'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>

            <label class="flex cursor-pointer items-start gap-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                <input
                    type="checkbox"
                    wire:model.live="accept"
                    class="mt-1 h-5 w-5 rounded border-gray-300 text-primary-600 focus:ring-primary-500"
                >
                <span class="text-sm text-gray-700 dark:text-gray-300">
                    I have read and agree to the
                    <strong>Terms of Service</strong>,
                    <strong>Acceptable Use Policy</strong>,
                    <strong>AI Content Disclaimer</strong>, and
                    <strong>Privacy Policy</strong>.
                </span>
            </label>

            <div class="flex flex-col items-center gap-3 sm:flex-row sm:justify-center">
                <x-filament::button
                    wire:click="submit"
                    wire:loading.attr="disabled"
                    :disabled="! $accept"
                    color="primary"
                    size="lg"
                >
                    I agree — continue to my dashboard
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

            <p class="text-center text-xs text-gray-400">
                Questions? Email
                <a href="mailto:eiaawsolutions@gmail.com" class="text-primary-600 underline">
                    eiaawsolutions@gmail.com
                </a>.
            </p>
        </div>
    </div>
</x-filament-panels::page>
