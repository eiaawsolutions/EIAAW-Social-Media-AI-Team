<?php

namespace App\Agents;

use App\Models\Brand;

/**
 * Drafts replies to incoming comments + DMs.
 *
 * STATUS: STUB. Full implementation needs platform-specific webhook ingest
 * (Instagram Graph API, LinkedIn webhooks, etc.) — v1.1.
 */
class CommunityAgent extends BaseAgent
{
    protected array $requiredStages = ['platform_connected'];

    public function role(): string { return 'community'; }
    public function promptVersion(): string { return 'community.stub.v0'; }

    protected function handle(Brand $brand, array $input): AgentResult
    {
        return AgentResult::fail(
            'Community agent will be wired up in v1.1. Reply ingest needs per-platform webhook integration.'
        );
    }
}
