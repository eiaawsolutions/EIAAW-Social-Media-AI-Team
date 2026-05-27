<?php

namespace Tests\Unit;

use App\Models\Workspace;
use App\Services\Blotato\BlotatoClient;
use App\Services\Secrets\InfisicalResolver;
use RuntimeException;
use Tests\TestCase;

/**
 * Per-workspace Blotato isolation — the unit-level invariants we cannot
 * regress without leaking cross-tenant data again.
 *
 * Background: before 2026-05-27, one shared BLOTATO_API_KEY meant every
 * workspace's sync saw every other workspace's social accounts. The fix
 * was to scope every Blotato call to the workspace's own handle. These
 * tests assert the isolation primitives, not the wire integration.
 */
class BlotatoIsolationTest extends TestCase
{
    public function test_workspace_with_no_handle_needs_setup(): void
    {
        $ws = new Workspace();
        $ws->id = 99;
        $ws->slug = 'unconfigured';
        $ws->blotato_api_key_handle = null;

        $this->assertTrue($ws->needsBlotatoSetup());
        $this->assertFalse($ws->hasBlotatoConnected());
    }

    public function test_workspace_with_handle_but_no_connected_at_is_set_up_but_not_connected(): void
    {
        $ws = new Workspace();
        $ws->id = 42;
        $ws->slug = 'wired-but-untested';
        $ws->blotato_api_key_handle = 'secret://eiaaw-smt-prod/prod/BLOTATO_API_KEY_WS_42';
        $ws->blotato_connected_at = null;

        $this->assertFalse($ws->needsBlotatoSetup());
        $this->assertFalse($ws->hasBlotatoConnected());
    }

    public function test_workspace_with_handle_and_connected_at_is_fully_connected(): void
    {
        $ws = new Workspace();
        $ws->id = 7;
        $ws->slug = 'ready';
        $ws->blotato_api_key_handle = 'secret://eiaaw-smt-prod/prod/BLOTATO_API_KEY_WS_7';
        $ws->blotato_connected_at = now();

        $this->assertFalse($ws->needsBlotatoSetup());
        $this->assertTrue($ws->hasBlotatoConnected());
    }

    public function test_for_workspace_refuses_workspace_with_no_handle(): void
    {
        $ws = new Workspace();
        $ws->id = 13;
        $ws->slug = 'no-handle';
        $ws->blotato_api_key_handle = null;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/Workspace #13 \(no-handle\) has no Blotato API key configured/');

        BlotatoClient::forWorkspace($ws);
    }

    public function test_for_workspace_refuses_empty_string_handle(): void
    {
        // Edge case: handle column set to empty string rather than null.
        // The cast is silent so we explicitly assert string-emptiness counts
        // as "needs setup" too — otherwise an operator's typo would silently
        // route the workspace through the global HQ fallback.
        $ws = new Workspace();
        $ws->id = 14;
        $ws->slug = 'empty-handle';
        $ws->blotato_api_key_handle = '';

        $this->expectException(RuntimeException::class);
        BlotatoClient::forWorkspace($ws);
    }

    public function test_for_workspace_builds_client_via_resolver_when_handle_set(): void
    {
        // Bind a fake resolver so we don't actually hit Infisical. The
        // resolver returns a `blt_…`-prefixed string so the BlotatoClient
        // constructor's prefix guard passes.
        $this->app->instance(InfisicalResolver::class, new class extends InfisicalResolver {
            public function __construct() {
                parent::__construct([]);
            }
            public function resolve(string $handle): string
            {
                if ($handle === 'secret://eiaaw-smt-prod/prod/BLOTATO_API_KEY_WS_77') {
                    return 'blt_fake_per_workspace_key_77';
                }
                throw new RuntimeException("unexpected handle in test: {$handle}");
            }
        });

        $ws = new Workspace();
        $ws->id = 77;
        $ws->slug = 'ws-77';
        $ws->blotato_api_key_handle = 'secret://eiaaw-smt-prod/prod/BLOTATO_API_KEY_WS_77';

        $client = BlotatoClient::forWorkspace($ws);

        $this->assertInstanceOf(BlotatoClient::class, $client);
    }

    public function test_for_workspace_propagates_resolver_failure_instead_of_silently_falling_back(): void
    {
        // Critical: when Infisical resolution fails, we MUST surface the
        // failure — not fall back to config('services.blotato.api_key')
        // (HQ's key). Falling back is exactly the leakage bug we're fixing.
        $this->app->instance(InfisicalResolver::class, new class extends InfisicalResolver {
            public function __construct() {
                parent::__construct([]);
            }
            public function resolve(string $handle): string
            {
                throw new RuntimeException('Infisical unreachable');
            }
        });

        $ws = new Workspace();
        $ws->id = 88;
        $ws->slug = 'infisical-down';
        $ws->blotato_api_key_handle = 'secret://eiaaw-smt-prod/prod/BLOTATO_API_KEY_WS_88';

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Infisical unreachable');

        BlotatoClient::forWorkspace($ws);
    }
}
