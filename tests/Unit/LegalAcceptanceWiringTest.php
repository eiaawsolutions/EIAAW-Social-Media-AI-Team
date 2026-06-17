<?php

namespace Tests\Unit;

use Illuminate\Support\Facades\Blade;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Source- and view-level guards for the legal-acceptance feature. Complements
 * LegalAcceptanceGateTest (which exercises the gate's runtime decision) by
 * locking the wiring that a runtime stub can't see:
 *
 *   - the public legal blades COMPILE (they live OUTSIDE resources/views/filament
 *     so FilamentViewCompilesTest does not cover them);
 *   - the gate middleware is registered AFTER the trial gate, so we only ever
 *     show the wall to a paying, authenticated user;
 *   - the acceptance recorder is called from every capture surface;
 *   - the signup checkbox is genuinely required.
 *
 * DB-free: pure file/string inspection + the Blade compiler. Mirrors
 * FilamentViewCompilesTest and SetupPageProviderGateTest.
 */
class LegalAcceptanceWiringTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function read(string $relative): string
    {
        return file_get_contents(self::root() . '/' . ltrim($relative, '/'));
    }

    /** @return array<string, array{0:string}> */
    public static function legalViews(): array
    {
        $root = dirname(__DIR__, 2);
        $cases = [];
        foreach (glob($root . '/resources/views/legal/*.blade.php') as $path) {
            $cases[basename($path)] = [$path];
        }

        return $cases;
    }

    #[DataProvider('legalViews')]
    public function test_public_legal_views_compile(string $path): void
    {
        $compiled = Blade::compileString(file_get_contents($path));

        // No control directive should survive compilation (the /agency/performance
        // 500 class — see FilamentViewCompilesTest).
        $pattern = '/^\s*@(php|endphp|if|elseif|else|endif|foreach|endforeach|forelse|empty|endforelse)\b/m';
        $this->assertSame(
            0,
            preg_match($pattern, $compiled),
            "Legal view {$path} lost PHP context during compilation."
        );
    }

    public function test_gate_middleware_runs_after_the_trial_gate(): void
    {
        $provider = self::read('app/Providers/Filament/AgencyPanelProvider.php');

        $trialPos = strpos($provider, 'EnforceTrialOrSubscription::class');
        $legalPos = strpos($provider, 'EnforceLegalAcceptance::class');

        $this->assertNotFalse($trialPos, 'EnforceTrialOrSubscription must be registered.');
        $this->assertNotFalse($legalPos, 'EnforceLegalAcceptance must be registered.');

        // The LAST occurrence of each is the authMiddleware registration (the
        // first is the use-import). The legal gate must come after the trial gate.
        $this->assertGreaterThan(
            strrpos($provider, 'EnforceTrialOrSubscription::class'),
            strrpos($provider, 'EnforceLegalAcceptance::class'),
            'EnforceLegalAcceptance must run AFTER EnforceTrialOrSubscription in authMiddleware.'
        );
    }

    public function test_middleware_allowlists_acceptance_page_auth_and_logout(): void
    {
        $mw = self::read('app/Http/Middleware/EnforceLegalAcceptance.php');

        $this->assertStringContainsString('filament.agency.pages.legal-acceptance', $mw);
        $this->assertStringContainsString('filament.agency.auth.*', $mw);
        $this->assertStringContainsString("agency/logout", $mw);
        $this->assertStringContainsString('/agency/legal-acceptance', $mw);
    }

    public function test_acceptance_page_records_via_the_recorder(): void
    {
        $page = self::read('app/Filament/Agency/Pages/LegalAcceptance.php');

        $this->assertStringContainsString('recordLegalAcceptance(', $page);
        $this->assertStringContainsString("source: 'panel'", $page);
        // Server-side re-check so a tampered client can't accept unticked.
        $this->assertStringContainsString('if (! $this->accept)', $page);
    }

    /**
     * Regression: the "I agree" button did nothing because the action method and
     * the checkbox-bound property shared the name "accept". Livewire resolves a
     * wire:click against the PROPERTY of the same name first, so the method never
     * ran. The button's wire:click action must NOT name a public property.
     */
    public function test_acceptance_buttons_wire_click_is_not_a_property_name(): void
    {
        $blade = self::read('resources/views/filament/agency/pages/legal-acceptance.blade.php');

        $this->assertSame(
            1,
            preg_match('/wire:click="([a-zA-Z_]\w*)"/', $blade, $m),
            'The acceptance page must have exactly one wire:click action button.'
        );
        $action = $m[1];

        // The checkbox binds the "accept" property — the action must differ from it
        // and from any public property declared on the page class.
        $page = self::read('app/Filament/Agency/Pages/LegalAcceptance.php');
        preg_match_all('/public\s+(?:\??\w+\s+)?\$(\w+)/', $page, $props);

        $this->assertNotContains(
            $action,
            $props[1],
            "wire:click=\"{$action}\" collides with a public property of the same name; "
                . 'Livewire resolves the property first and the action never fires.'
        );
    }

    /**
     * Regression: the page surfaced Filament's generic "Error while loading
     * page" toast because mount() and the wire:click action RETURNED a
     * RedirectResponse. Livewire discards a value returned from mount(), and a
     * Livewire action must hand back a JSON snapshot, not an HTTP redirect — the
     * framework-native way to navigate is $this->redirect(). Lock that in: the
     * Livewire page must redirect via $this->redirect(...) and must not return a
     * raw redirect() response from its lifecycle/action methods.
     */
    public function test_acceptance_page_redirects_the_livewire_native_way(): void
    {
        $page = self::read('app/Filament/Agency/Pages/LegalAcceptance.php');

        $this->assertStringContainsString(
            '$this->redirect(',
            $page,
            'The page must navigate via Livewire\'s $this->redirect(), not a returned RedirectResponse.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\breturn\s+redirect\s*\(/',
            $page,
            'Returning redirect() from a Livewire mount()/action triggers the '
                . '"Error while loading page" toast; use $this->redirect() instead.'
        );
    }

    public function test_register_fallback_requires_and_records_acceptance(): void
    {
        $register = self::read('app/Filament/Agency/Auth/Register.php');

        $this->assertStringContainsString("Checkbox::make('accept_terms')", $register);
        $this->assertStringContainsString('->accepted()', $register);
        $this->assertStringContainsString('recordLegalAcceptance(', $register);
        $this->assertStringContainsString("source: 'register'", $register);
    }

    public function test_signup_form_has_a_required_acceptance_checkbox(): void
    {
        $blade = self::read('resources/views/signup/details.blade.php');

        $this->assertStringContainsString('name="accept_terms"', $blade);
        $this->assertStringContainsString('required', $blade);
        // All four documents are linked from the checkbox label.
        $this->assertStringContainsString('/terms', $blade);
        $this->assertStringContainsString('/acceptable-use', $blade);
        $this->assertStringContainsString('/ai-disclaimer', $blade);
        $this->assertStringContainsString('/privacy', $blade);
    }

    public function test_checkout_requires_acceptance_and_carries_the_version(): void
    {
        $controller = self::read('app/Http/Controllers/BillingController.php');

        $this->assertStringContainsString("'accept_terms'   => ['accepted']", $controller);
        // The accepted version is carried through Stripe metadata to provisioning.
        $this->assertStringContainsString("'legal_version'", $controller);
        $this->assertStringContainsString('LegalDocuments::version()', $controller);
    }

    public function test_provisioner_stamps_acceptance_inside_the_transaction(): void
    {
        $provisioner = self::read('app/Services/Billing/SignupProvisioner.php');

        // The recorder call must sit between the transaction open and its close,
        // so it commits atomically with the account creation.
        $txOpen = strpos($provisioner, 'DB::transaction(');
        $record = strpos($provisioner, 'recordLegalAcceptance(');

        $this->assertNotFalse($txOpen);
        $this->assertNotFalse($record);
        $this->assertGreaterThan($txOpen, $record, 'recordLegalAcceptance must be called inside the provisioning transaction.');
        $this->assertStringContainsString("source: 'signup'", $provisioner);
        // IP/UA guarded for the webhook (console) path.
        $this->assertStringContainsString('runningInConsole()', $provisioner);
    }

    public function test_user_model_does_not_mass_assign_legal_columns(): void
    {
        $user = self::read('app/Models/User.php');

        // The two denorm columns must be written via forceFill only, never $fillable.
        $this->assertStringNotContainsString("'legal_accepted_version'", substr($user, 0, strpos($user, 'protected $hidden')));
        $this->assertStringContainsString('recordLegalAcceptance', $user);
        $this->assertStringContainsString('forceFill([', $user);
    }
}
