<?php

namespace App\Services\Imagery;

use RuntimeException;

/**
 * Thrown when a media file fails the publishability gate and cannot be
 * auto-fixed (videos always; images only when compression can't bring them
 * inside the envelope). Carries the structured violations so the UI can
 * render a fail popup listing every reason + the suggested fix.
 */
class MediaComplianceException extends RuntimeException
{
    /**
     * @param  array<int, array{kind:string, reason:string, suggestion:string, fixable_by_compression:bool, detail:array}>  $violations
     */
    public function __construct(
        public readonly array $violations,
        public readonly string $platform,
        public readonly string $mediaType,
        string $message = 'Media failed compliance checks.',
    ) {
        parent::__construct($message);
    }

    /** Flatten to a single human string (fallback for plain-text contexts). */
    public function toPlainText(): string
    {
        return collect($this->violations)
            ->map(fn (array $v) => '• ' . ($v['reason'] ?? '') . ' — ' . ($v['suggestion'] ?? ''))
            ->implode("\n");
    }
}
