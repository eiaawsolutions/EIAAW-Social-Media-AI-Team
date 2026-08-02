<?php

namespace App\Console\Commands;

use App\Agents\CommunityAgent;
use App\Models\Brand;
use App\Models\InboxConversation;
use Illuminate\Console\Command;

/**
 * Drafts replies for conversations that are awaiting one. Writes drafts only —
 * nothing here reaches a person. See CommunityIngest (read) and CommunitySend
 * (human-approved write).
 *
 * Ordered by window expiry so the most perishable conversation is drafted first:
 * with a 24h comment window, spending the budget on the freshest message while
 * an 18-hour-old one expires is exactly backwards.
 */
class CommunityDraft extends Command
{
    protected $signature = 'community:draft
                            {--brand= : Restrict to a single brand id}
                            {--limit=25 : Max conversations to draft for in one run}
                            {--force : Re-draft even when an open draft already exists}';

    protected $description = 'Draft replies (for human approval) to inbound DMs and comments awaiting a response.';

    public function handle(CommunityAgent $agent): int
    {
        $limit = max(1, (int) $this->option('limit'));

        $conversations = InboxConversation::query()
            ->awaitingReply()
            ->when($this->option('brand'), fn ($q, $id) => $q->where('brand_id', $id))
            // Nulls last: a known deadline always outranks an unknown one.
            ->orderByRaw('window_expires_at IS NULL, window_expires_at ASC')
            ->limit($limit)
            ->get();

        if ($conversations->isEmpty()) {
            $this->info('Nothing awaiting a reply.');

            return self::SUCCESS;
        }

        $brands = Brand::whereIn('id', $conversations->pluck('brand_id')->unique())->get()->keyBy('id');
        $totals = ['drafted' => 0, 'no_reply' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($conversations as $conversation) {
            $brand = $brands[$conversation->brand_id] ?? null;
            if (! $brand) {
                $totals['skipped']++;
                continue;
            }

            try {
                $result = $agent->run($brand, [
                    'conversation_id' => $conversation->id,
                    'force' => (bool) $this->option('force'),
                ]);

                if (! $result->ok) {
                    $totals['failed']++;
                    $this->warn(sprintf('  conv#%d failed: %s', $conversation->id, $result->errorMessage));
                    continue;
                }

                if (! empty($result->data['skipped'])) {
                    $totals['skipped']++;
                    $this->line(sprintf('  conv#%d skipped: %s', $conversation->id, $result->data['reason'] ?? '?'));
                    continue;
                }

                if (! empty($result->data['recommends_no_reply'])) {
                    $totals['no_reply']++;
                    $this->line(sprintf('  conv#%d → no reply recommended', $conversation->id));
                } else {
                    $totals['drafted']++;
                    $this->info(sprintf(
                        '  conv#%d → draft #%d (%d chars) awaiting approval',
                        $conversation->id,
                        $result->data['draft_id'] ?? 0,
                        $result->data['chars'] ?? 0,
                    ));
                }
            } catch (\Throwable $e) {
                $totals['failed']++;
                $this->warn(sprintf('  conv#%d crashed: %s', $conversation->id, substr($e->getMessage(), 0, 120)));
            }
        }

        $this->line('');
        $this->line(sprintf(
            'drafted=%d no_reply=%d skipped=%d failed=%d',
            $totals['drafted'], $totals['no_reply'], $totals['skipped'], $totals['failed'],
        ));

        return self::SUCCESS;
    }
}
