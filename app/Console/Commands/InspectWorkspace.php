<?php

namespace App\Console\Commands;

use App\Models\Workspace;
use Illuminate\Console\Command;

class InspectWorkspace extends Command
{
    protected $signature = 'workspaces:inspect {--id=}';
    protected $description = 'Dump full workspace state.';

    public function handle(): int
    {
        $w = Workspace::find((int) $this->option('id'));
        if (! $w) {
            $this->error('not found');
            return self::FAILURE;
        }
        $this->line('id:                     ' . $w->id);
        $this->line('slug:                   ' . $w->slug);
        $this->line('plan:                   ' . $w->plan);
        $this->line('subscription_status:    ' . $w->subscription_status);
        $this->line('trial_ends_at:          ' . ($w->trial_ends_at?->toIso8601String() ?? '(NULL)'));
        $this->line('past_due_at:            ' . ($w->past_due_at?->toIso8601String() ?? '(NULL)'));
        $this->line('canceled_at:            ' . ($w->canceled_at?->toIso8601String() ?? '(NULL)'));
        $this->line('suspended_at:           ' . ($w->suspended_at?->toIso8601String() ?? '(NULL)'));
        $this->line('stripe_customer_id:     ' . ($w->stripe_customer_id ?? '(NULL)'));
        $this->line('hasActiveAccess():      ' . ($w->hasActiveAccess() ? 'true' : 'false'));
        $this->line('updated_at:             ' . $w->updated_at?->toIso8601String());

        $this->newLine();
        $this->info('Cashier subscriptions for this workspace:');
        foreach ($w->subscriptions as $s) {
            $this->line(sprintf('  id=%d type=%s stripe_id=%s status=%s trial_ends=%s ends_at=%s',
                $s->id, $s->type, $s->stripe_id, $s->stripe_status,
                $s->trial_ends_at?->toIso8601String() ?? '-',
                $s->ends_at?->toIso8601String() ?? '-'
            ));
        }

        return self::SUCCESS;
    }
}
