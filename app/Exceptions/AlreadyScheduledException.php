<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by CustomisedPostScheduler::schedule() when the asset has already been
 * scheduled (its customised_calendar_entry_id is set under a row lock).
 *
 * This is the concurrency/idempotency guard: a double-click in the UI or two
 * concurrent recovery-command workers can both load an asset with a NULL entry
 * id; the lock serialises them and the loser gets this exception instead of
 * creating a duplicate calendar entry + drafts. Callers treat it as a benign
 * "already done" skip, not a hard error.
 */
class AlreadyScheduledException extends RuntimeException
{
}
