<?php

namespace Tests\Unit;

use ReflectionClass;
use Tests\TestCase;

/**
 * AI-EDITOR IDOR GUARD — the durable invariant behind the new Draft / Brand-asset
 * editor pages.
 *
 * Custom Filament Pages are NOT covered by the resource getEloquentQuery() tenant
 * gate (which TenantIsolationGuardTest enforces for Resources) and the ?id in
 * the URL is attacker-controllable. So each editor page MUST re-scope the loaded
 * record to the current workspace — on mount AND on every write method, because
 * the id rehydrates from the Livewire snapshot across requests.
 *
 * This converts that convention into an enforced invariant via source-level
 * reflection. DB-FREE by design (SMT local .env points at Railway PROD; tests
 * never touch the DB — see [[support-chatbot]]).
 */
class AiEditorIdorGuardTest extends TestCase
{
    /** @return array<int, array{0: class-string}> */
    public static function editorPages(): array
    {
        return [
            [\App\Filament\Agency\Pages\DraftEditor::class],
            [\App\Filament\Agency\Pages\BrandAssetEditor::class],
        ];
    }

    /**
     * @dataProvider editorPages
     * @param  class-string  $page
     */
    public function test_editor_resolver_scopes_to_workspace_and_aborts(string $page): void
    {
        $src = $this->source($page);

        // A single resolveXOrAbort() helper does the scoping.
        $this->assertMatchesRegularExpression('/private function resolve\w+OrAbort/', $src,
            "{$page} must have a resolve…OrAbort() helper");

        // It scopes by workspace_id and denies-by-default with abort.
        $this->assertStringContainsString('workspace_id', $src, "{$page} must scope by workspace_id");
        $this->assertStringContainsString('abort_unless', $src, "{$page} must abort on a non-owned / missing record");
    }

    /**
     * @dataProvider editorPages
     * @param  class-string  $page
     */
    public function test_resolver_called_in_mount_and_every_write_method(string $page): void
    {
        $src = $this->source($page);

        // Find the resolver name actually used.
        preg_match('/private function (resolve\w+OrAbort)/', $src, $m);
        $resolver = $m[1] ?? null;
        $this->assertNotNull($resolver, "{$page} resolver not found");

        // Every method that reads/writes the record must re-scope. mount() loads
        // it; save()/sendChat()/runPreset() all run on subsequent requests where
        // $recordId came back from the snapshot, so each must re-resolve.
        foreach (['mount', 'save', 'runReword'] as $method) {
            $this->assertStringContainsString($method, $src, "{$page} missing {$method}()");
        }
        // The resolver is invoked at least 3 times (mount + save + runReword/
        // preview), proving it isn't a mount-only check.
        $calls = substr_count($src, '$this->' . $resolver . '(');
        $this->assertGreaterThanOrEqual(3, $calls,
            "{$page} must call {$resolver}() on every write path, not just mount");
    }

    /**
     * @dataProvider editorPages
     * @param  class-string  $page
     */
    public function test_editor_pages_are_hidden_from_navigation(string $page): void
    {
        $ref = new ReflectionClass($page);
        $prop = $ref->getProperty('shouldRegisterNavigation');
        $prop->setAccessible(true);
        // Static prop default — these record-bound pages must not appear in the sidebar.
        $this->assertFalse($prop->getDefaultValue(), "{$page} must set shouldRegisterNavigation = false");
    }

    /** @param class-string $class */
    private function source(string $class): string
    {
        $file = (new ReflectionClass($class))->getFileName();
        $this->assertNotFalse($file);

        return (string) file_get_contents($file);
    }
}
