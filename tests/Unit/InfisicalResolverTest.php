<?php

namespace Tests\Unit;

use App\Services\Secrets\InfisicalResolver;
use ReflectionMethod;
use Tests\TestCase;

/**
 * InfisicalResolver project-routing + handle-parsing contracts.
 *
 * DB-free and network-free: we exercise the pure routing logic
 * (resolveProjectId) and the handle parser directly. This is a
 * security-critical class — every SMT secret flows through it — so the routing
 * default MUST stay back-compatible: any handle that doesn't explicitly name a
 * mapped OTHER project resolves against the bootstrap project, unchanged.
 */
class InfisicalResolverTest extends TestCase
{
    private const BOOTSTRAP = 'eeea2ae9-4bf3-4ffd-9605-6fab3e1ee665';

    private const ALL_PROJECTS = '2bca9bc9-330d-4664-b371-6b8ee2758438';

    private function resolver(): InfisicalResolver
    {
        return new InfisicalResolver([
            'project_id' => self::BOOTSTRAP,
            'projects' => [
                'eiaaw-all-projects' => self::ALL_PROJECTS,
            ],
        ]);
    }

    private function routeProject(InfisicalResolver $r, string $slug): ?string
    {
        $m = new ReflectionMethod($r, 'resolveProjectId');
        $m->setAccessible(true);

        return $m->invoke($r, $slug);
    }

    public function test_empty_project_segment_uses_bootstrap(): void
    {
        $this->assertSame(self::BOOTSTRAP, $this->routeProject($this->resolver(), ''));
    }

    public function test_unknown_slug_falls_back_to_bootstrap(): void
    {
        // A typo or an unlisted project must NOT guess — it fails closed onto
        // the bootstrap project (where the lookup simply won't find the secret).
        $this->assertSame(self::BOOTSTRAP, $this->routeProject($this->resolver(), 'eiaaw-smt-prod'));
        $this->assertSame(self::BOOTSTRAP, $this->routeProject($this->resolver(), 'totally-unknown'));
    }

    public function test_mapped_slug_routes_to_its_workspace(): void
    {
        // The whole point of the change: a handle naming eiaaw-all-projects
        // resolves against THAT workspace, not the bootstrap one.
        $this->assertSame(self::ALL_PROJECTS, $this->routeProject($this->resolver(), 'eiaaw-all-projects'));
    }

    public function test_uuid_segment_is_used_verbatim(): void
    {
        $uuid = '11111111-2222-3333-4444-555555555555';
        $this->assertSame($uuid, $this->routeProject($this->resolver(), $uuid));
    }

    public function test_handle_parsing_extracts_project_env_path_name(): void
    {
        $parsed = InfisicalResolver::parseHandle('secret://eiaaw-all-projects/prod/RAILWAY_API_TOKEN');

        $this->assertNotNull($parsed);
        $this->assertSame('eiaaw-all-projects', $parsed['project']);
        $this->assertSame('prod', $parsed['environment']);
        $this->assertSame('/', $parsed['path']);
        $this->assertSame('RAILWAY_API_TOKEN', $parsed['name']);
    }

    public function test_handle_parsing_extracts_nested_path(): void
    {
        $parsed = InfisicalResolver::parseHandle('secret://eiaaw-smt-prod/prod/billing/STRIPE_SECRET');

        $this->assertNotNull($parsed);
        $this->assertSame('eiaaw-smt-prod', $parsed['project']);
        $this->assertSame('/billing', $parsed['path']);
        $this->assertSame('STRIPE_SECRET', $parsed['name']);
    }

    public function test_non_handle_passes_through_untouched(): void
    {
        // A literal value (not a secret:// handle) must be returned as-is.
        $this->assertSame('sk_live_plainvalue', $this->resolver()->resolve('sk_live_plainvalue'));
    }

    public function test_malformed_handle_is_returned_unchanged(): void
    {
        // Lowercase name doesn't match the [A-Z0-9_]+ name rule → not a valid
        // handle → returned verbatim (and logged), never sent upstream.
        $this->assertSame('secret://bad', $this->resolver()->resolve('secret://bad'));
    }
}
