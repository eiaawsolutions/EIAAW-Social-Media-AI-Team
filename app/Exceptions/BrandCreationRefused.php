<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by PlanCaps::createBrandOrFail() when a brand create is refused —
 * either because the workspace is at its plan's brand cap, or because a
 * non-archived brand with the same name already exists in the workspace.
 *
 * Both are caught at the call site (the Filament create action) and surfaced
 * as a friendly notification rather than a 500.
 *
 * WHY THIS EXISTS: the cap used to be enforced only by a read-then-write check
 * in the Filament create action (canAddBrand → insert), which leaked under a
 * stale-relation / double-submit race and let a solo workspace (max_brands=1)
 * accumulate TWO brands — splitting onboarding work across them. See
 * [[onboarding-split-brain-brands]]. The fix moves enforcement INTO the same
 * locked transaction as the insert, and this typed reason lets the UI explain
 * which rule fired.
 */
class BrandCreationRefused extends RuntimeException
{
    public const REASON_CAP_REACHED = 'cap_reached';
    public const REASON_DUPLICATE_NAME = 'duplicate_name';

    public function __construct(
        string $message,
        public readonly string $reason,
    ) {
        parent::__construct($message);
    }

    public static function capReached(int $max, string $plan): self
    {
        return new self(
            sprintf(
                'Your %s plan allows %d brand%s. Archive an unused brand or upgrade to add more.',
                ucfirst($plan),
                $max,
                $max === 1 ? '' : 's',
            ),
            self::REASON_CAP_REACHED,
        );
    }

    public static function duplicateName(string $name): self
    {
        return new self(
            sprintf(
                'You already have a brand called "%s". Open that brand to continue setting it up, '
                .'or pick a different name.',
                $name,
            ),
            self::REASON_DUPLICATE_NAME,
        );
    }

    public function isCapReached(): bool
    {
        return $this->reason === self::REASON_CAP_REACHED;
    }

    public function isDuplicateName(): bool
    {
        return $this->reason === self::REASON_DUPLICATE_NAME;
    }
}
