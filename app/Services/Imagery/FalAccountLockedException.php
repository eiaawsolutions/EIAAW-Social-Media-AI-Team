<?php

namespace App\Services\Imagery;

use RuntimeException;

/**
 * Thrown when FAL.AI rejects a call because the ACCOUNT (not the request) is
 * unusable — the prepaid balance is exhausted and FAL has locked the user, so
 * every subsequent call will 403 identically until an operator tops up.
 *
 * This is deliberately a distinct type from the generic RuntimeException the
 * client throws for per-request failures (a bad prompt, a 422, a transient
 * 5xx). The difference matters operationally:
 *
 *   - A per-request failure → retry the draft, maybe with a different prompt.
 *   - An account lockout → retrying ANY draft is futile and just burns worker
 *     time hammering a locked account. The only remedy is "top up the FAL
 *     balance at fal.ai/dashboard/billing". The agents catch this type to
 *     short-circuit (open the breaker, degrade to the library, surface the
 *     correct operator action) instead of treating it like a flaky image call.
 *
 * Detection lives in FalAiClient::isAccountLockoutBody(). The breaker that
 * stops the hammering lives in FalAiClient::tripLockout()/lockoutActive().
 */
class FalAccountLockedException extends RuntimeException
{
}
