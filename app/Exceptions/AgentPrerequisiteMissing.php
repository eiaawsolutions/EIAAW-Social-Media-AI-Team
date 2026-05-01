<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by an agent when it can't proceed because a setup stage is missing.
 *
 * The wizard catches these and surfaces "Run X first → click here" instead of
 * a generic 500 page. Failing loud with a specific reason is the simplicity move.
 */
class AgentPrerequisiteMissing extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly array $context = [],
    ) {
        parent::__construct($message);
    }

    public function missingStage(): ?string
    {
        return $this->context['missing_stage'] ?? null;
    }

    public function brandId(): ?int
    {
        return $this->context['brand_id'] ?? null;
    }
}
