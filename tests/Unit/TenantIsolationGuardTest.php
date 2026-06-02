<?php

namespace Tests\Unit;

use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * TENANT ISOLATION GUARD — the durable invariant behind the security audit
 * (2026-05-30). Every customer-facing (Agency panel) Filament resource that
 * exposes tenant data MUST scope its base query to the current user's
 * workspace, so customer A can never see customer B's brands / drafts /
 * scheduled posts / platform connections (which hold tokens) / metrics.
 *
 * Today every Agency resource hand-rolls the same getEloquentQuery() pattern:
 *
 *     $workspaceId = $user?->current_workspace_id ?? $user?->ownedWorkspaces()->value('id');
 *     if ($user?->is_super_admin) { return <archived-filter-only>; }   // HQ support
 *     if (! $workspaceId)         { return ...->whereRaw('1 = 0'); }    // deny-by-default
 *     return ...->where('workspace_id', $workspaceId);                 // the tenant gate
 *
 * That correctness is enforced by CONVENTION, not by the type system — so the
 * realistic way the next cross-tenant leak ships is: someone adds a new Agency
 * resource and forgets getEloquentQuery (Filament then serves the UNSCOPED
 * parent query = every tenant's rows), or removes the workspace_id constraint.
 *
 * This test converts the convention into an enforced invariant. It is
 * source-level reflection only (DB-FREE — the SMT local .env points at Railway
 * PROD, so tests never touch the DB; see [[metricool-evaluation]] / [[support-chatbot]]).
 *
 * If you add a genuinely workspace-agnostic Agency resource (rare — e.g. a
 * read-only reference list with no tenant data), add its class to
 * INTENTIONALLY_UNSCOPED with a comment justifying why it is safe.
 */
class TenantIsolationGuardTest extends TestCase
{
    /**
     * Agency resources that legitimately do NOT scope by workspace. Empty by
     * design: every current Agency resource exposes tenant data and must scope.
     * Adding a class here is a deliberate security decision — justify it.
     *
     * @var array<int, class-string>
     */
    private const INTENTIONALLY_UNSCOPED = [
        // (none)
    ];

    /**
     * Discover every Filament Resource class under the Agency panel namespace
     * by scanning the directory — so a newly-added resource is automatically
     * covered without editing this test.
     *
     * @return array<int, class-string>
     */
    private function agencyResourceClasses(): array
    {
        $dir = app_path('Filament/Agency/Resources');
        if (! is_dir($dir)) {
            return [];
        }

        // Plain recursive glob — no external dependency (Symfony Finder is only
        // a transitive dep of the framework; a test guard must not rely on a
        // package it doesn't own, so its result is identical in any clean CI
        // checkout).
        // Match resources directly under Resources/ AND one level deep
        // (Filament's default is Resources/<Name>/<Name>Resource.php).
        $files = array_merge(
            glob($dir . '/*Resource.php') ?: [],
            glob($dir . '/*/*Resource.php') ?: [],
        );

        $classes = [];
        foreach ($files as $file) {
            $fqcn = $this->fqcnFor($file);
            if ($fqcn === null || ! class_exists($fqcn)) {
                continue;
            }
            $ref = new ReflectionClass($fqcn);
            if ($ref->isAbstract() || ! $ref->isSubclassOf(\Filament\Resources\Resource::class)) {
                continue;
            }
            $classes[] = $fqcn;
        }

        sort($classes);
        return $classes;
    }

    /** Map an absolute file path to its fully-qualified class name (PSR-4 root App\). */
    private function fqcnFor(string $path): ?string
    {
        $appPath = app_path();
        $real = realpath($path) ?: $path;
        $relative = str_replace($appPath, '', $real);
        $relative = ltrim(str_replace(['\\', '/'], '\\', $relative), '\\');
        $relative = preg_replace('/\.php$/', '', $relative) ?? $relative;
        return 'App\\' . $relative;
    }

    public function test_every_agency_resource_defines_get_eloquent_query(): void
    {
        $resources = $this->agencyResourceClasses();
        $this->assertNotEmpty($resources, 'No Agency resources discovered — the scan path is wrong.');

        foreach ($resources as $class) {
            if (in_array($class, self::INTENTIONALLY_UNSCOPED, true)) {
                continue;
            }

            $method = new ReflectionMethod($class, 'getEloquentQuery');
            $this->assertSame(
                $class,
                $method->getDeclaringClass()->getName(),
                "SECURITY: {$class} does not declare its OWN getEloquentQuery(). It inherits "
                . "the UNSCOPED parent query, so every tenant's rows are exposed cross-tenant. "
                . 'Add a workspace-scoped getEloquentQuery() (see BrandResource) or, if it is '
                . 'genuinely tenant-agnostic, add it to INTENTIONALLY_UNSCOPED with justification.'
            );
        }
    }

    public function test_every_agency_resource_query_is_workspace_scoped(): void
    {
        $resources = $this->agencyResourceClasses();

        foreach ($resources as $class) {
            if (in_array($class, self::INTENTIONALLY_UNSCOPED, true)) {
                continue;
            }

            $src = $this->getEloquentQuerySource($class);

            // The tenant gate: the scoped query must constrain by workspace_id
            // (directly on Brand, or via whereHas('brand', ... workspace_id)).
            $this->assertMatchesRegularExpression(
                "/workspace_id/",
                $src,
                "SECURITY: {$class}::getEloquentQuery() never references workspace_id. "
                . 'Without a workspace constraint the list/edit/delete queries return other '
                . "tenants' records (cross-tenant IDOR). Scope it like BrandResource."
            );

            // Deny-by-default: a user with no resolvable workspace must see
            // NOTHING, not the unscoped fallback. We assert the explicit empty
            // guard is present.
            $this->assertMatchesRegularExpression(
                "/whereRaw\\(\\s*['\"]1 ?= ?0['\"]/",
                $src,
                "SECURITY: {$class}::getEloquentQuery() lacks the deny-by-default guard "
                . "(whereRaw('1 = 0')) for a user with no resolvable workspace. Without it, a "
                . 'half-provisioned account could fall through to an unscoped query.'
            );

            // NO super-admin bypass on the Agency (customer-facing) panel
            // (invariant added 2026-06-02 after the cross-tenant view of an HQ
            // super-admin's own Agency account READ as a data breach — it showed
            // a client's brand next to EIAAW's with no workspace label). The
            // Agency panel is now hard own-workspace for EVERYONE, including HQ.
            // HQ administers other tenants from the dedicated /admin panel
            // (App\Filament\Resources\ClientBrandResource /
            // ClientPlatformConnectionResource), which is gated by
            // User::canAccessPanel('admin') => is_super_admin (locked by
            // test_admin_panel_is_super_admin_only below). If a getEloquentQuery
            // branches on is_super_admin to widen the result set, the leak is
            // back — so this is forbidden here.
            $this->assertDoesNotMatchRegularExpression(
                '/is_super_admin/',
                $src,
                "SECURITY: {$class}::getEloquentQuery() references is_super_admin. A super-admin "
                . 'bypass on the customer-facing Agency panel reintroduces the 2026-06-02 '
                . "cross-tenant exposure (HQ's own Agency account shows every client's rows). "
                . 'Cross-tenant administration belongs in the /admin panel, not here. Remove the '
                . 'bypass — the Agency panel must be own-workspace for everyone.'
            );
        }
    }

    /**
     * Lock the panel-boundary half of the isolation model: only super-admins can
     * reach the /admin panel, where the cross-tenant client-administration
     * resources (ClientBrandResource, ClientPlatformConnectionResource) live.
     * The Agency-resource bypass was removed on the assumption this gate holds;
     * if someone widens canAccessPanel('admin'), the cross-tenant resources would
     * be reachable by a customer — so pin it here.
     *
     * Source-level: we read User::canAccessPanel() rather than execute it, to
     * stay DB-free like the rest of this guard.
     */
    public function test_admin_panel_is_super_admin_only(): void
    {
        $ref = new ReflectionClass(\App\Models\User::class);
        $method = new ReflectionMethod(\App\Models\User::class, 'canAccessPanel');
        $lines = file((string) $ref->getFileName()) ?: [];
        $src = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        // The 'admin' panel arm must resolve to is_super_admin and nothing
        // weaker. We assert the literal mapping is present.
        $this->assertMatchesRegularExpression(
            "/['\"]admin['\"]\\s*=>\\s*\\\$this->is_super_admin/",
            $src,
            'SECURITY: User::canAccessPanel() no longer gates the admin panel on is_super_admin. '
            . 'The /admin panel hosts cross-tenant client-administration resources; widening this '
            . 'gate would let a customer reach every tenant\'s brands and platform connections.'
        );
    }

    /**
     * Pull just the getEloquentQuery() method body from the class file. We read
     * source rather than execute, so this stays DB-free and needs no auth/tenant
     * context.
     */
    private function getEloquentQuerySource(string $class): string
    {
        $ref = new ReflectionClass($class);
        $file = (string) $ref->getFileName();
        $lines = file($file) ?: [];

        $method = new ReflectionMethod($class, 'getEloquentQuery');
        // If the method is inherited the declaring-class test already failed;
        // guard here so we don't read an unrelated file.
        if ($method->getDeclaringClass()->getName() !== $class) {
            return '';
        }

        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();
        return implode('', array_slice($lines, $start, $end - $start));
    }
}
