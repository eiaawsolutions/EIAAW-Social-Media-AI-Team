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

    /**
     * Regression guard for "AI generate doesn't recognise the uploaded image".
     *
     * Root cause: the hint action runs MID-FORM (before submit), so the
     * FileUpload state value is a Livewire TemporaryUploadedFile, not a final
     * `brand-assets/...` disk path. The old action passed `(string) $file` to a
     * disk read that found nothing → the writer got NO image → the model
     * truthfully replied "no image is showing". The fix reads the temp upload's
     * BYTES directly and hands them to CustomisedNarrativeWriter::draftForUpload.
     *
     * These assertions are source-level + reflection (DB-free, no network).
     */
    /** Source of just the aiWriterAction() method body (the hint-action closure). */
    private function aiWriterActionSource(): string
    {
        $source = $this->pageSource();
        // Grab from `private function aiWriterAction` to the next `private function`.
        if (! preg_match('/private function aiWriterAction\(\).*?(?=\n    private function )/s', $source, $m)) {
            $this->fail('Could not isolate aiWriterAction() in ManageBrandAssets.');
        }

        return $m[0];
    }

    public function test_ai_writer_action_reads_temp_upload_bytes_not_a_disk_path(): void
    {
        $action = $this->aiWriterActionSource();

        // Must branch on the temp-upload type and read its bytes.
        $this->assertStringContainsString(
            'instanceof TemporaryUploadedFile',
            $action,
            'AI writer action must detect a Livewire TemporaryUploadedFile (pre-submit state) '
            . 'and read its bytes — passing the temp value as a disk path is the bug that made '
            . 'the writer say "no image is showing".',
        );
        $this->assertStringContainsString(
            '->draftForUpload(',
            $action,
            'AI writer action must call CustomisedNarrativeWriter::draftForUpload() with the '
            . 'temp upload bytes for the pre-submit path.',
        );

        // Must NOT regress to the fragile numeric-key access WITHIN THE ACTION.
        // FileUpload state is keyed by UUID mid-form, so $files[0] is unreliable
        // here. (The submit handler handleCustomisedUpload() legitimately uses
        // $files[0] on the post-dehydration saved array — that's a different
        // method and out of scope for this guard.)
        $this->assertSame(
            0,
            preg_match('/\$files\[0\]/', $action),
            'AI writer action must not index FileUpload state by $files[0] — that array is '
            . 'keyed by UUID mid-form. Use reset()/array_values() to get the first file.',
        );
    }

    public function test_narrative_writer_exposes_a_bytes_entry_point(): void
    {
        $m = new \ReflectionMethod(
            \App\Services\Imagery\CustomisedNarrativeWriter::class,
            'draftForUpload',
        );
        $this->assertTrue($m->isPublic(), 'draftForUpload() must be public for the action to call it.');

        $params = array_map(fn ($p) => $p->getName(), $m->getParameters());
        $this->assertSame(['brand', 'imageBytes', 'mimeType', 'platform'], $params);

        // imageBytes must be nullable (video / unreadable → text-only voice draft).
        $imageBytesParam = $m->getParameters()[1];
        $this->assertTrue(
            $imageBytesParam->allowsNull(),
            'draftForUpload($imageBytes) must accept null so a video / unreadable upload '
            . 'degrades to a voice-only draft instead of erroring.',
        );
    }

    public function test_narrative_writer_only_sends_an_image_block_when_bytes_are_present(): void
    {
        // The shared core builds the Anthropic content array; the image block must
        // be gated on "not video AND bytes present" so a null-bytes call is text-only
        // (and never sends an empty/`null` base64 source, which the API rejects).
        $src = (string) file_get_contents(
            app_path('Services/Imagery/CustomisedNarrativeWriter.php'),
        );
        $this->assertMatchesRegularExpression(
            '/if\s*\(\s*!\s*\$isVideo\s*&&\s*\$imageBytes\s*!==\s*null\s*\)/',
            $src,
            'The image content block must be gated on (!$isVideo && $imageBytes !== null).',
        );
    }
}
