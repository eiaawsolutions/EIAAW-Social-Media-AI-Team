<?php

namespace App\Services\Imagery;

use RuntimeException;

/**
 * Thrown when FAL.AI rejects a SPECIFIC request because its content tripped the
 * model's safety/content-policy checker (HTTP 422 content_policy_violation, or
 * the "material flagged by a content checker" / "unsafe content" / "could not
 * generate images with the given prompts and images" family).
 *
 * Distinct from both the generic RuntimeException (transient/bad request) and
 * FalAccountLockedException (account-level, retrying anything is futile):
 *
 *   - Account lockout  → stop, top up; retrying any draft is pointless.
 *   - Content policy   → the ACCOUNT is fine; THIS input was refused. Retrying
 *                        the same i2v call is pointless, but the same scene
 *                        often passes as TEXT-to-video once the flagged keyframe
 *                        (a photoreal still Veo's i2v checker dislikes) is
 *                        dropped. VideoAgent catches this type to retry once
 *                        without image_url before giving up.
 *
 * Detection lives in FalAiClient::isContentPolicyBody().
 */
class FalContentPolicyException extends RuntimeException
{
}
