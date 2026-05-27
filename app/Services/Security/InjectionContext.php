<?php

namespace App\Services\Security;

use App\Models\Brand;
use App\Models\User;
use App\Models\Workspace;

/**
 * Input bundle passed to the PromptInjectionDetector. Holds everything we
 * need to:
 *   - run the heuristic + grader checks on the text
 *   - attribute the event to a workspace / brand / user
 *   - correlate later with ai_costs + Horizon job traces
 *
 * Constructed at the LlmGateway hook point. Pure value object — no methods
 * beyond access.
 */
final class InjectionContext
{
    /**
     * @param  string  $surface  Where the text came from. One of:
     *   'user_input'    — direct user-typed message (chat, prompt field)
     *   'tool_result'   — string the model received from a tool call
     *   'scraped'       — content pulled from an external URL / API
     *   'agent_output'  — the model's own response (output canary layer)
     * @param  string  $text     The actual content to scan.
     * @param  string  $agentRole For attribution: 'writer.linkedin.v3.2', etc.
     * @param  string|null  $correlationId  Job / request id, for ledger joins.
     */
    public function __construct(
        public readonly string $surface,
        public readonly string $text,
        public readonly string $agentRole,
        public readonly ?Workspace $workspace = null,
        public readonly ?Brand $brand = null,
        public readonly ?User $user = null,
        public readonly ?string $correlationId = null,
        public readonly ?string $modelId = null,
        public readonly ?string $promptVersion = null,
    ) {}

    /** Used by the grader threshold check. */
    public function lengthBytes(): int
    {
        return strlen($this->text);
    }
}
