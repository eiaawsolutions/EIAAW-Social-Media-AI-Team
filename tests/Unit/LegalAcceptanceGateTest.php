<?php

namespace Tests\Unit;

use App\Http\Middleware\EnforceLegalAcceptance;
use App\Support\Legal\LegalDocuments;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Tests\TestCase;

/**
 * Locks the mandatory, version-gated legal-acceptance gate.
 *
 * DB-free by design (the local .env DB == prod): the gate decides purely on the
 * user's denormalized legal_accepted_version vs config('legal.version'), so we
 * drive it with a lightweight stub user and a real Request whose route() we
 * fake via a closure resolver. No connection is ever opened.
 *
 * @see App\Http\Middleware\EnforceLegalAcceptance
 * @see App\Support\Legal\LegalDocuments
 */
class LegalAcceptanceGateTest extends TestCase
{
    /** A user stand-in exposing only what the gate reads. */
    private function stubUser(?string $acceptedVersion): object
    {
        return new class($acceptedVersion) {
            public function __construct(public ?string $legal_accepted_version) {}
        };
    }

    /**
     * Build a Request that reports a given matched route name (so routeIs()
     * works) and carries the stub user. `routeName` may be null for a normal
     * panel page that is NOT allow-listed.
     */
    private function request(?object $user, ?string $routeName, string $path = 'agency'): Request
    {
        $request = Request::create('/' . ltrim($path, '/'), 'GET');
        $request->setUserResolver(fn () => $user);

        if ($routeName !== null) {
            $route = new \Illuminate\Routing\Route(['GET'], '/' . ltrim($path, '/'), []);
            $route->name($routeName);
            $request->setRouteResolver(fn () => $route);
        }

        return $request;
    }

    private function pass(Request $request): SymfonyResponse
    {
        return (new EnforceLegalAcceptance())->handle(
            $request,
            fn () => new Response('next', 200)
        );
    }

    // ── config + helper ─────────────────────────────────────────────────────

    public function test_helper_version_matches_config(): void
    {
        $this->assertSame(config('legal.version'), LegalDocuments::version());
        $this->assertNotSame('', LegalDocuments::version());
    }

    public function test_manifest_lists_every_configured_document(): void
    {
        $manifest = LegalDocuments::manifest();

        $this->assertSame(LegalDocuments::version(), $manifest['version']);
        foreach (['terms', 'acceptable_use', 'ai_disclaimer', 'privacy', 'dpa'] as $key) {
            $this->assertArrayHasKey($key, $manifest['documents']);
            $this->assertArrayHasKey('name', $manifest['documents'][$key]);
            $this->assertArrayHasKey('updated', $manifest['documents'][$key]);
        }
    }

    // ── gate decision logic ─────────────────────────────────────────────────

    public function test_no_user_passes_through(): void
    {
        $response = $this->pass($this->request(null, null));
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('next', $response->getContent());
    }

    public function test_current_version_passes_through(): void
    {
        $user = $this->stubUser(LegalDocuments::version());
        $response = $this->pass($this->request($user, null));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('next', $response->getContent());
    }

    public function test_null_version_on_normal_page_is_redirected_to_acceptance(): void
    {
        // Pre-gate user (NULL accepted version) hitting a non-allow-listed page.
        $user = $this->stubUser(null);
        $response = $this->pass($this->request($user, 'filament.agency.pages.dashboard'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/agency/legal-acceptance', $response->headers->get('Location'));
    }

    public function test_stale_version_is_redirected(): void
    {
        $user = $this->stubUser('1999-01-01'); // accepted an old version
        $response = $this->pass($this->request($user, 'filament.agency.pages.dashboard'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/agency/legal-acceptance', $response->headers->get('Location'));
    }

    public function test_acceptance_page_itself_is_never_blocked(): void
    {
        $user = $this->stubUser(null);
        $response = $this->pass($this->request(
            $user,
            'filament.agency.pages.legal-acceptance',
            'agency/legal-acceptance'
        ));

        $this->assertSame(200, $response->getStatusCode());
    }

    public function test_billing_and_profile_and_auth_stay_reachable_while_gated(): void
    {
        $user = $this->stubUser(null);

        foreach ([
            'filament.agency.pages.billing',
            'filament.agency.profile',
            'filament.agency.auth.login',
        ] as $allowed) {
            $response = $this->pass($this->request($user, $allowed));
            $this->assertSame(
                200,
                $response->getStatusCode(),
                "Route [{$allowed}] must remain reachable while a user is gated."
            );
        }
    }

    public function test_logout_post_path_is_never_blocked(): void
    {
        $user = $this->stubUser(null);

        $request = Request::create('/agency/logout', 'POST');
        $request->setUserResolver(fn () => $user);
        // No route name resolver — exercises the $request->is('agency/logout') escape.

        $response = $this->pass($request);
        $this->assertSame(200, $response->getStatusCode());
    }

    // ── version-bump forces re-acceptance ───────────────────────────────────

    public function test_bumping_the_version_forces_a_previously_accepted_user_to_reaccept(): void
    {
        $accepted = LegalDocuments::version();
        $user = $this->stubUser($accepted);

        // Currently passes.
        $this->assertSame(200, $this->pass($this->request($user, null))->getStatusCode());

        // Bump the version: the same stored acceptance is now stale → redirect.
        config()->set('legal.version', $accepted . '-bumped');
        $response = $this->pass($this->request($user, 'filament.agency.pages.dashboard'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertStringContainsString('/agency/legal-acceptance', $response->headers->get('Location'));
    }
}
