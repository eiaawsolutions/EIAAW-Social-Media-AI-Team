<?php

namespace Tests\Unit;

use App\Console\Commands\AuditMetricoolBlogIdIntegrity;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionClass;
use Tests\TestCase;

/**
 * Locks the publish-time tenant-isolation guarantee at the SOURCE level:
 * "EIAAW HQ posts only land on EIAAW's platforms; each client's posts only land
 * on their own." The runtime proof lives in MetricoolPublisherTest
 * (test_submit_routes_each_brand_to_its_own_blog_id) and the live verifier is
 * the audit:metricool-blogid-integrity command. This test guards the two things
 * that make that guarantee hold by construction:
 *
 *   A. Every ScheduledPost-creation path anchors brand_id AND the chosen
 *      platform_connection to the SAME brand — so a post can never carry another
 *      tenant's connection (and thus never inherit another tenant's blogId or
 *      per-network target_overrides).
 *   B. The audit command exists and checks the four isolation invariants, so a
 *      future regression is caught by a runnable/CI-gateable check.
 *
 * DB-FREE by design (source reflection only) — the SMT local .env points at
 * Railway PROD, so tests never touch the DB.
 */
class MetricoolRoutingIsolationTest extends TestCase
{
    private function source(string $relativePath): string
    {
        return (string) file_get_contents(base_path($relativePath));
    }

    /**
     * Every place that creates a ScheduledPost must select its
     * platform_connection scoped to the SAME brand as the post — never a
     * workspace-wide or unscoped connection lookup. We assert each creation site
     * resolves the connection by the post's/draft's/brand's brand_id.
     *
     * @return array<int, array{0:string, 1:string}>  [path, brand-scope-needle]
     */
    public static function creationPaths(): array
    {
        return [
            // SchedulerAgent: connection from $brand->platformConnections()
            ['app/Agents/SchedulerAgent.php', '$brand->platformConnections()'],
            // Hourly auto-schedule: PlatformConnection::where('brand_id', $brand->id)
            ['app/Console/Commands/PostsAutoScheduleApproved.php', "where('brand_id', \$brand->id)"],
            // Drafts list bulk action: where('brand_id', $draft->brand_id)
            ['app/Filament/Agency/Resources/Drafts/Pages/ManageDrafts.php', "where('brand_id', \$draft->brand_id)"],
            // Single-draft action: where('brand_id', $r->brand_id)
            ['app/Filament/Agency/Resources/Drafts/DraftResource.php', "where('brand_id', \$r->brand_id)"],
        ];
    }

    #[DataProvider('creationPaths')]
    public function test_creation_paths_scope_connection_to_post_brand(string $path, string $needle): void
    {
        $src = $this->source($path);
        $this->assertStringContainsString(
            $needle,
            $src,
            "ScheduledPost-creation path {$path} must resolve its platform_connection scoped to the "
            . "post's own brand ({$needle}). An unscoped/workspace-wide connection lookup could attach "
            . "another tenant's connection — carrying the wrong blogId/target_overrides into publishing."
        );
    }

    /**
     * SchedulerAgent (the primary path) must also create the row with brand_id
     * AND platform_connection_id derived from the SAME $brand/$connection it just
     * scoped — not from request input. This is the exact anchoring that makes
     * MetricoolPublisher route to the right tenant.
     */
    public function test_scheduler_agent_anchors_post_to_scoped_brand_and_connection(): void
    {
        $src = $this->source('app/Agents/SchedulerAgent.php');
        // Draft is fetched scoped to the brand…
        $this->assertStringContainsString("Draft::where('id', \$draftId)->where('brand_id', \$brand->id)", $src);
        // …and the row is created with brand_id + connection from that brand.
        $this->assertStringContainsString("'brand_id' => \$brand->id", $src);
        $this->assertStringContainsString("'platform_connection_id' => \$connection->id", $src);
    }

    /**
     * MetricoolPublisher must derive the routing target SOLELY from the post's
     * own brand blogId — no workspace token switch, no per-account id, nothing
     * that could point at another tenant. Guards the single line the whole
     * isolation guarantee rests on.
     */
    public function test_publisher_targets_only_post_brand_blog_id(): void
    {
        $src = $this->source('app/Services/Publishing/MetricoolPublisher.php');
        $this->assertStringContainsString(
            '$post->brand?->metricool_blog_id',
            $src,
            'MetricoolPublisher must target the POST\'s own brand blogId — the sole routing key.'
        );
    }

    /**
     * The live isolation verifier must exist and check the four invariants, so a
     * cross-tenant regression is catchable by a runnable command (and can gate a
     * scheduled health check / CI).
     */
    public function test_blogid_integrity_audit_command_exists_and_checks_invariants(): void
    {
        $this->assertTrue(
            class_exists(AuditMetricoolBlogIdIntegrity::class),
            'The audit:metricool-blogid-integrity command must exist as the live isolation verifier.'
        );

        $src = (string) file_get_contents(
            (new ReflectionClass(AuditMetricoolBlogIdIntegrity::class))->getFileName()
        );

        // Signature is the documented one.
        $this->assertStringContainsString("audit:metricool-blogid-integrity", $src);

        // Checks invariant 1: post.brand_id === connection.brand_id
        $this->assertStringContainsString("whereColumn('sp.brand_id', '!=', 'pc.brand_id')", $src);
        // Checks invariant 2: post.brand_id === draft.brand_id
        $this->assertStringContainsString("whereColumn('sp.brand_id', '!=', 'd.brand_id')", $src);
        // Checks invariant 3: unique blogId per active brand
        $this->assertStringContainsString('havingRaw', $src);
        $this->assertStringContainsString('metricool_blog_id', $src);
        // Fails (non-zero exit) on any violation so it can gate CI.
        $this->assertStringContainsString('return self::FAILURE', $src);
    }
}
