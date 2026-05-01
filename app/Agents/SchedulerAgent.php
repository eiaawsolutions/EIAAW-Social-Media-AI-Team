<?php

namespace App\Agents;

use App\Models\Brand;
use App\Models\Draft;
use App\Models\ScheduledPost;
use App\Services\Readiness\SetupReadiness;
use Illuminate\Support\Carbon;

/**
 * Schedules approved drafts via Blotato.
 *
 * v1: persists ScheduledPost rows as queued — actual Blotato API call happens
 * in a Horizon job (deferred to next phase). The agent's job here is to validate
 * the draft is approved + lane allows it + scheduled_for is in the future.
 *
 * Required input:
 *   - draft_id (int)
 *   - scheduled_for (ISO datetime string)
 */
class SchedulerAgent extends BaseAgent
{
    protected array $requiredStages = ['platform_connected', 'autonomy_decided', 'first_draft_passed'];

    public function role(): string { return 'scheduler'; }
    public function promptVersion(): string { return 'scheduler.v1.0'; }

    protected function handle(Brand $brand, array $input): AgentResult
    {
        $draftId = $input['draft_id'] ?? null;
        $scheduledFor = $input['scheduled_for'] ?? null;
        if (! $draftId || ! $scheduledFor) {
            return AgentResult::fail('SchedulerAgent requires draft_id and scheduled_for.');
        }

        $draft = Draft::where('id', $draftId)->where('brand_id', $brand->id)->first();
        if (! $draft) {
            return AgentResult::fail('Draft not found for this brand.');
        }

        if (! in_array($draft->status, ['approved', 'scheduled'])) {
            return AgentResult::fail("Draft is in status '{$draft->status}' — only approved drafts can be scheduled.");
        }

        $when = Carbon::parse($scheduledFor, $brand->timezone);
        if ($when->isPast()) {
            return AgentResult::fail('Scheduled time must be in the future.');
        }

        $connection = $brand->platformConnections()
            ->where('platform', $draft->platform)
            ->where('status', 'active')
            ->first();
        if (! $connection) {
            return AgentResult::fail("No active connection for {$draft->platform}.");
        }

        $scheduled = ScheduledPost::create([
            'draft_id' => $draft->id,
            'brand_id' => $brand->id,
            'platform_connection_id' => $connection->id,
            'scheduled_for' => $when,
            'status' => 'queued',
            'attempt_count' => 0,
        ]);

        $draft->update(['status' => 'scheduled']);

        app(SetupReadiness::class)->invalidate($brand);

        return AgentResult::ok([
            'scheduled_post_id' => $scheduled->id,
            'platform' => $draft->platform,
            'scheduled_for' => $scheduled->scheduled_for->toIso8601String(),
        ]);
    }
}
