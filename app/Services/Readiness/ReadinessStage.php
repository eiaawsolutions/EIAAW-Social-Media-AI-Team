<?php

namespace App\Services\Readiness;

/**
 * Single stage in the brand readiness ladder.
 *
 * Pure value object — no DB calls. SetupReadiness builds an array of these
 * after running its detectors against Postgres. The Filament wizard renders them.
 */
final class ReadinessStage
{
    public function __construct(
        public readonly string $id,           // e.g. 'brand_style'
        public readonly int $order,           // 1..9 — render order
        public readonly string $label,        // e.g. 'Brand voice synthesised'
        public readonly string $description,  // sentence shown under the title
        public readonly bool $done,           // detector result
        public readonly bool $skippable,      // some stages are optional (e.g. corpus seeding)
        public readonly string $ctaLabel,     // e.g. 'Run brand onboarding'
        public readonly ?string $ctaUrl,      // null when current stage cannot yet be acted on
        public readonly ?string $blockedBy,   // id of the prerequisite stage if user cannot act yet
        public readonly ?string $evidence,    // human-readable proof when done — e.g. 'Synthesised 2026-04-30 by Amos'
    ) {}

    public function status(): string
    {
        if ($this->done) return 'done';
        if ($this->blockedBy !== null) return 'blocked';
        return 'todo';
    }

    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'order' => $this->order,
            'label' => $this->label,
            'description' => $this->description,
            'done' => $this->done,
            'skippable' => $this->skippable,
            'cta_label' => $this->ctaLabel,
            'cta_url' => $this->ctaUrl,
            'blocked_by' => $this->blockedBy,
            'evidence' => $this->evidence,
            'status' => $this->status(),
        ];
    }
}
