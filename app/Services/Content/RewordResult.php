<?php

namespace App\Services\Content;

/**
 * Result of one AI reword call. The editor pages read only `rewrittenText`
 * (which they drop into a textarea for the operator to Accept) and `note` (a
 * short, optional "what changed" line shown beside the proposal). Model output
 * is data, never control flow.
 */
final class RewordResult
{
    public function __construct(
        public readonly string $rewrittenText,
        public readonly string $note = '',
    ) {}
}
