<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * DB-free regression guard for the "Error while loading page" 500 on Brand
 * Assets (see commit history).
 *
 * Root cause: the upload HEADER action used a reactive modal-submit-label,
 *   ->modalSubmitActionLabel(fn (callable $get) => $get('usage_intent') ...)
 * In Filament v5 the modal-label closure is evaluated on the ACTION, where
 * getSchemaComponent() is null, so resolving the `$get`/`$set` schema utility
 * fatals with "Call to a member function makeGetUtility() on null"
 * (Action.php:551) and crashes the whole Livewire page render.
 *
 * The `$get`/`$set` utilities are ONLY safe inside schema-component closures
 * (the fields built in uploadSchema()), never on the action's own modal-level
 * callbacks (modalSubmitActionLabel / modalHeading / modalDescription).
 *
 * This test reads the page source and fails if a `$get`/`$set` closure is ever
 * attached to one of those action-level modal callbacks again. It is DB-free
 * by design (local .env DB == prod — we never create rows in tests).
 */
class BrandAssetsActionClosureSafetyTest extends TestCase
{
    private function pageSource(): string
    {
        $path = app_path('Filament/Agency/Resources/BrandAssets/Pages/ManageBrandAssets.php');
        $this->assertFileExists($path);

        return file_get_contents($path);
    }

    /**
     * @return array<int, array{0:string}>
     */
    public static function unsafeActionModalCallbacks(): array
    {
        // Action-level modal callbacks that are evaluated WITHOUT a schema
        // component bound — attaching a `$get`/`$set` closure to any of these
        // reintroduces the makeGetUtility()-on-null fatal.
        return [
            ['modalSubmitActionLabel'],
            ['modalHeading'],
            ['modalDescription'],
            ['modalCancelActionLabel'],
        ];
    }

    #[DataProvider('unsafeActionModalCallbacks')]
    public function test_action_modal_callbacks_do_not_take_a_schema_get_or_set_closure(string $callback): void
    {
        $source = $this->pageSource();

        // Match e.g. ->modalSubmitActionLabel(fn (callable $get) ... or
        // ->modalHeading(fn ($get, $set) ...  — any closure that pulls in the
        // schema $get/$set utility on an action-level modal callback.
        $pattern = '/->' . preg_quote($callback, '/') . '\s*\(\s*(?:static\s+)?fn\b[^)]*\$(?:get|set)\b/i';

        $this->assertSame(
            0,
            preg_match($pattern, $source),
            "Brand Assets upload action attaches a \$get/\$set closure to ->{$callback}(). "
            . 'That 500s in Filament v5 (makeGetUtility() on null). Use a static value, '
            . 'or read live form state inside a schema-component closure instead.',
        );
    }

    public function test_schema_fields_may_still_use_get_closures(): void
    {
        // Sanity: the fix must NOT have stripped the legitimate `$get` usage in
        // the FileUpload/CheckboxList field closures (those run inside the
        // schema container and are correct). If this disappears, the upload UX
        // lost its intent-reactive labelling.
        $source = $this->pageSource();

        $this->assertStringContainsString(
            '->label(fn (callable $get)',
            $source,
            'Schema-field $get closures (e.g. FileUpload->label) must remain — they are the SAFE usage.',
        );
    }
}
