<?php

namespace App\Filament\Agency\Pages;

use App\Support\Legal\LegalDocuments;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Mandatory legal-acceptance wall. The EnforceLegalAcceptance middleware
 * redirects here from any other panel route whenever the signed-in user has
 * not accepted the CURRENT legal version (config('legal.version')).
 *
 * Accept-once, version-gated: ticking the box records ONE immutable audit row
 * and stamps the user's denormalized version, after which the gate lets them
 * through until we bump the version. Hidden from navigation — reachable only
 * via the redirect.
 */
class LegalAcceptance extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-document-check';
    protected static ?string $title = 'Review and accept our terms';
    protected string $view = 'filament.agency.pages.legal-acceptance';

    public static function shouldRegisterNavigation(): bool
    {
        return false;
    }

    /** Bound to the checkbox; the Accept button is disabled until true. */
    public bool $accept = false;

    /** @var array<string, array{name: string, route: string, updated: string}> */
    public array $documents = [];

    public ?string $changeNote = null;

    /** True when the user previously accepted an older version (re-acceptance). */
    public bool $isReacceptance = false;

    public function mount(): void
    {
        $user = auth()->user();

        // If they're already current (e.g. accepted in another tab, or hit the
        // URL directly), don't show the wall. Use Livewire's own redirect()
        // (not a returned RedirectResponse): on the initial full-page GET it
        // aborts into a real HTTP redirect, and Livewire DISCARDS a value
        // returned from mount() anyway, so returning one is a silent no-op.
        if ($user && $user->hasAcceptedCurrentLegal()) {
            $this->redirect('/agency');

            return;
        }

        $this->documents = LegalDocuments::documents();
        $this->changeNote = LegalDocuments::changeNote();
        $this->isReacceptance = $user?->legal_accepted_version !== null;
    }

    /**
     * Record acceptance and release the user into the panel. Re-checks the box
     * server-side so a tampered client can't accept on the user's behalf with
     * the box unticked.
     */
    public function submit(): void
    {
        if (! $this->accept) {
            Notification::make()
                ->title('Please tick the box to confirm you have read and agree.')
                ->danger()
                ->send();

            return;
        }

        $user = auth()->user();
        if (! $user) {
            $this->redirect('/agency/login');

            return;
        }

        $user->recordLegalAcceptance(
            version: LegalDocuments::version(),
            ip: request()->ip(),
            ua: request()->userAgent(),
            source: 'panel',
        );

        // Livewire's redirect() emits a client-side redirect effect on the
        // wire:click roundtrip. Returning a RedirectResponse from a Livewire
        // action instead is the pattern that surfaces Filament's generic
        // "Error while loading page" toast, because the action response is
        // expected to be a Livewire JSON snapshot, not an HTTP redirect.
        $this->redirect('/agency');
    }
}
