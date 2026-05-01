<?php

namespace App\Agents;

use App\Models\Brand;

/**
 * Generates on-brand visuals via FAL.AI Flux + brand-DNA classifier.
 *
 * STATUS: STUB. FAL.AI integration and the brand-DNA classifier are v1.1.
 * The wizard shows this agent in the team list; clicking "Run Designer" returns
 * a friendly "coming soon" instead of a 500.
 */
class DesignerAgent extends BaseAgent
{
    protected array $requiredStages = ['brand_style'];

    public function role(): string { return 'designer'; }
    public function promptVersion(): string { return 'designer.stub.v0'; }

    protected function handle(Brand $brand, array $input): AgentResult
    {
        return AgentResult::fail(
            'Designer agent will be wired up in v1.1. For now, attach images manually in the draft inbox or have your team upload to the brand asset library.'
        );
    }
}
