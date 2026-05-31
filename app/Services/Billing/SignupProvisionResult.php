<?php

namespace App\Services\Billing;

use App\Models\User;
use App\Models\Workspace;

/**
 * Outcome of SignupProvisioner::provisionFromSession(). Immutable value object
 * so both call sites (success() redirect, webhook safety net) can branch on the
 * outcome without re-querying, and tests can assert the decision without a DB.
 *
 * Outcomes:
 *   - provisioned         : this call created the account (carries temp password)
 *   - alreadyProvisioned  : an earlier call already created it (idempotent no-op)
 *   - skipped             : not a signup session (e.g. an upgrade checkout)
 *   - failed              : metadata invalid or the transaction threw
 */
class SignupProvisionResult
{
    public const PROVISIONED = 'provisioned';
    public const ALREADY_PROVISIONED = 'already_provisioned';
    public const SKIPPED = 'skipped';
    public const FAILED = 'failed';

    private function __construct(
        public readonly string $status,
        public readonly ?User $user = null,
        public readonly ?Workspace $workspace = null,
        public readonly ?string $tempPassword = null,
        public readonly ?string $reason = null,
    ) {}

    public static function provisioned(User $user, Workspace $workspace, string $tempPassword): self
    {
        return new self(self::PROVISIONED, $user, $workspace, $tempPassword);
    }

    public static function alreadyProvisioned(User $user): self
    {
        return new self(self::ALREADY_PROVISIONED, $user);
    }

    public static function skipped(string $reason): self
    {
        return new self(self::SKIPPED, reason: $reason);
    }

    public static function failed(string $reason): self
    {
        return new self(self::FAILED, reason: $reason);
    }

    /** A new account was just created by THIS call. */
    public function wasProvisioned(): bool
    {
        return $this->status === self::PROVISIONED;
    }

    /** An account exists for this session — created now or earlier. */
    public function hasAccount(): bool
    {
        return in_array($this->status, [self::PROVISIONED, self::ALREADY_PROVISIONED], true);
    }
}
