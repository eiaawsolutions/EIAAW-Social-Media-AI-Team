<?php

namespace App\Services\Monitoring;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cross-workspace agent health for the super-admin agents monitor page.
 *
 * Truth sources:
 *  - audit_log rows written by BaseAgent::logAudit() — every Agent::run()
 *    lands one row with action like "agent.<role>.completed|failed".
 *  - pipeline_runs (running/blocked/failed) for multi-step orchestrations.
 *  - Horizon (Redis) for live queue depth and recent waits, soft-failing
 *    when Redis is unreachable (we degrade to DB-only signals).
 *
 * Status is derived, not stored. The hierarchy below is intentional —
 * we surface the worst-known state first:
 *
 *   stuck    > failing > capped > active > healthy > idle
 *
 *   - stuck:   a pipeline_run for this agent's workflow is in 'running'
 *              but hasn't progressed within STUCK_PIPELINE_MIN, OR no
 *              audit_log completion within last STUCK_RUN_MIN despite a
 *              recent start. Worst — needs operator attention now.
 *   - failing: ≥ FAIL_RATIO of the last LOOKBACK_RUNS finished with
 *              outcome=failed FOR A REAL FAULT. The agent runs, but
 *              produces failures that need investigation.
 *   - capped:  the agent is running fine but its recent "failed" audit rows
 *              are BENIGN cap/policy refusals — a plan budget/allowance was
 *              hit, or it correctly skipped a platform that doesn't take the
 *              media. The agent is working AS DESIGNED; the remedy is wait
 *              for the reset or upgrade, never "read the stack trace". These
 *              rows are excluded from the failure ratio so a workspace that
 *              legitimately hits its cap can't flip the agent to red failing.
 *   - active:  a completed/failed audit_log row within last ACTIVE_MIN.
 *              Agent is alive and ticking.
 *   - healthy: ran successfully in last LOOKBACK_HOURS, all recent runs
 *              passed. Idle now but proven working.
 *   - idle:    no audit_log rows in the lookback window at all. The page
 *              shows this as neutral, not red — many agents only run when
 *              triggered (e.g. CompetitorIntelAgent runs on a weekly cron).
 *
 * Next-best-action is heuristic: we pattern-match the last error string
 * against a small catalogue of known failures. Unknown errors get a
 * generic "open the audit log" pointer, never a fabricated remedy.
 */
class AgentTelemetry
{
    private const LOOKBACK_HOURS = 24;
    private const LOOKBACK_RUNS = 10;
    private const ACTIVE_MIN = 5;
    private const STUCK_RUN_MIN = 15;
    private const STUCK_PIPELINE_MIN = 10;
    private const FAIL_RATIO = 0.5;

    /**
     * Substrings that mark a "failed" audit row as a BENIGN cap/policy refusal
     * rather than a real fault. When an agent returns AgentResult::fail() because
     * a plan budget/allowance was hit (DesignerAgent "Daily image budget reached",
     * VideoAgent "Daily video budget reached", PlanCaps "monthly AI-video
     * allowance reached") or because it correctly skipped a platform that doesn't
     * accept the media, the agent is working EXACTLY as designed — it refused to
     * overspend or do a no-op. These outcomes self-resolve (reset at midnight UTC
     * / on the 1st) or are simply not-applicable, so they must NOT count toward
     * the failure ratio and must NOT be surfaced as "needs investigation". Match
     * is case-insensitive on the error text stored in the audit row's context.
     *
     * Keep this list tight: only outcomes that are genuinely "the agent behaved
     * correctly" belong here. A provider running out of balance is NOT here — that
     * is a real operational event with its own top-up remedy (see nextActionFor).
     */
    private const BENIGN_CAP_NEEDLES = [
        'daily video budget reached',
        'daily image budget reached',
        'ai-video allowance reached',
        'does not accept short-form video',
    ];

    /**
     * Sequence the operator expects to see top-to-bottom — mirrors the
     * actual writer→compliance→designer→video→scheduler→publish pipeline.
     * Agents present in app/Agents/ but not listed here append at the bottom
     * in alphabetical order so newly-added agents stay visible (no silent
     * gaps when someone drops a new role into app/Agents/).
     */
    private const PIPELINE_ORDER = [
        'onboarding',
        'strategist',
        'researcher',
        'competitor_intel',
        'writer',
        'compliance',
        'designer',
        'video',
        'scheduler',
        'repurpose',
        'optimizer',
        'community',
    ];

    /**
     * @return array<int, array<string, mixed>>
     */
    public function snapshot(): array
    {
        $agents = $this->discoverAgents();
        $auditRows = $this->loadRecentAuditRows();
        $pipelineRows = $this->loadActivePipelineRuns();
        $horizon = $this->safeLoadHorizon();

        $rows = [];
        foreach ($agents as $agent) {
            $role = $agent['role'];
            $agentAudit = $auditRows->get($role, collect());
            $agentPipelines = $pipelineRows->get($role, collect());

            $rows[] = $this->buildRow($agent, $agentAudit, $agentPipelines, $horizon);
        }

        return $this->sortByPipeline($rows);
    }

    /**
     * Scan app/Agents/ for non-abstract Agent classes. Each subclass exposes
     * its role() name — we instantiate the reflector and read it without
     * constructing the agent (constructors require LlmGateway etc).
     *
     * @return array<int, array{class: string, role: string, label: string}>
     */
    private function discoverAgents(): array
    {
        $dir = app_path('Agents');
        $files = glob($dir . DIRECTORY_SEPARATOR . '*Agent.php') ?: [];
        $skip = ['BaseAgent.php', 'AgentResult.php'];

        $agents = [];
        foreach ($files as $file) {
            $basename = basename($file);
            if (in_array($basename, $skip, true)) continue;

            $class = 'App\\Agents\\' . substr($basename, 0, -4);
            if (! class_exists($class)) continue;

            try {
                $reflection = new \ReflectionClass($class);
                if ($reflection->isAbstract()) continue;
                $role = $this->resolveRole($reflection);
            } catch (\Throwable) {
                continue;
            }

            $agents[] = [
                'class' => $class,
                'role' => $role,
                'label' => $this->labelForRole($role),
            ];
        }
        return $agents;
    }

    /**
     * The role() method is non-static on BaseAgent. We can't safely
     * construct an agent (deps), so we read role() either from a public
     * constant or by parsing the method body for the literal return.
     * Falls back to a kebab-case of the class name.
     */
    private function resolveRole(\ReflectionClass $reflection): string
    {
        if ($reflection->hasMethod('role')) {
            $method = $reflection->getMethod('role');
            $file = $reflection->getFileName();
            if ($file && $method->getDeclaringClass()->getName() === $reflection->getName()) {
                $start = $method->getStartLine();
                $end = $method->getEndLine();
                $lines = @file($file);
                if ($lines && $start && $end) {
                    $body = implode('', array_slice($lines, $start - 1, $end - $start + 1));
                    if (preg_match("/return\s+['\"]([a-z0-9_.-]+)['\"]/i", $body, $m)) {
                        return $m[1];
                    }
                }
            }
        }
        $short = $reflection->getShortName();
        $short = preg_replace('/Agent$/', '', $short) ?? $short;
        return Str::snake($short);
    }

    private function labelForRole(string $role): string
    {
        return ucwords(str_replace(['_', '-'], ' ', $role));
    }

    /**
     * Audit log within the lookback window, indexed by role. Each entry
     * is the parsed (role, outcome, occurred_at, context) tuple — context
     * already contains latency_ms + error from BaseAgent::logAudit().
     */
    private function loadRecentAuditRows(): \Illuminate\Support\Collection
    {
        $since = now()->subHours(self::LOOKBACK_HOURS);
        $rows = DB::table('audit_log')
            ->select(['action', 'occurred_at', 'context', 'workspace_id', 'brand_id'])
            ->where('action', 'LIKE', 'agent.%')
            ->where('occurred_at', '>=', $since)
            ->orderByDesc('occurred_at')
            ->limit(1500)
            ->get();

        $grouped = collect();
        foreach ($rows as $row) {
            if (! preg_match('/^agent\.([^.]+)\.(completed|failed|started)$/', $row->action, $m)) {
                continue;
            }
            $role = $m[1];
            $outcome = $m[2];
            $context = $this->decodeJson($row->context);

            $grouped->put($role, $grouped->get($role, collect())->push([
                'outcome' => $outcome,
                'occurred_at' => Carbon::parse($row->occurred_at),
                'latency_ms' => (int) ($context['latency_ms'] ?? 0),
                'error' => $context['error'] ?? null,
                'workspace_id' => $row->workspace_id,
                'brand_id' => $row->brand_id,
            ]));
        }
        return $grouped;
    }

    /**
     * Active pipeline_runs (state in running/failed/blocked) keyed by the
     * agent role that owns the workflow. The convention is workflow names
     * like 'writer.draft', 'compliance.gate', etc. — we split on '.' and
     * take the first segment as the owning role.
     */
    private function loadActivePipelineRuns(): \Illuminate\Support\Collection
    {
        $rows = DB::table('pipeline_runs')
            ->select(['workflow', 'state', 'current_step', 'last_error', 'started_at', 'next_run_at', 'attempt', 'max_attempts'])
            ->whereIn('state', ['running', 'failed', 'blocked', 'pending'])
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        $grouped = collect();
        foreach ($rows as $row) {
            $role = explode('.', (string) $row->workflow, 2)[0] ?: 'unknown';
            $grouped->put($role, $grouped->get($role, collect())->push([
                'workflow' => $row->workflow,
                'state' => $row->state,
                'current_step' => $row->current_step,
                'last_error' => $row->last_error,
                'started_at' => $row->started_at ? Carbon::parse($row->started_at) : null,
                'next_run_at' => $row->next_run_at ? Carbon::parse($row->next_run_at) : null,
                'attempt' => (int) ($row->attempt ?? 0),
                'max_attempts' => (int) ($row->max_attempts ?? 0),
            ]));
        }
        return $grouped;
    }

    /**
     * Horizon snapshot — pending + reserved + recently-failed counts per
     * queue. Returns an empty array if Redis is unreachable; the UI shows
     * "Horizon unreachable" instead of failing the whole page.
     *
     * @return array{available: bool, queues: array<string, array{pending: int, reserved: int, recent_failures: int}>, error: ?string}
     */
    private function safeLoadHorizon(): array
    {
        if (! class_exists(\Laravel\Horizon\Contracts\WorkloadRepository::class)) {
            return ['available' => false, 'queues' => [], 'error' => 'Horizon package not installed.'];
        }

        try {
            $workload = app(\Laravel\Horizon\Contracts\WorkloadRepository::class)->get();
            $queues = [];
            foreach ($workload as $entry) {
                $name = is_array($entry) ? ($entry['name'] ?? '') : ($entry->name ?? '');
                if ($name === '') continue;
                $queues[$name] = [
                    'pending' => (int) (is_array($entry) ? ($entry['length'] ?? 0) : ($entry->length ?? 0)),
                    'reserved' => (int) (is_array($entry) ? ($entry['reserved'] ?? 0) : ($entry->reserved ?? 0)),
                    'wait_seconds' => (int) (is_array($entry) ? ($entry['wait'] ?? 0) : ($entry->wait ?? 0)),
                    'recent_failures' => 0,
                ];
            }
            return ['available' => true, 'queues' => $queues, 'error' => null];
        } catch (\Throwable $e) {
            return [
                'available' => false,
                'queues' => [],
                'error' => 'Horizon/Redis unreachable: ' . Str::limit($e->getMessage(), 140),
            ];
        }
    }

    /**
     * Per-agent row: derive status, last-run timestamps, the error to surface,
     * and the next-best-action. Pure function of the inputs.
     *
     * @param array{class: string, role: string, label: string} $agent
     */
    private function buildRow(array $agent, \Illuminate\Support\Collection $audit, \Illuminate\Support\Collection $pipelines, array $horizon): array
    {
        $now = now();
        $lastRun = $audit->first();
        $lastSuccess = $audit->firstWhere('outcome', 'completed');
        $totalRuns = $audit->count();

        // Split "failed" rows into REAL faults vs BENIGN cap/policy refusals.
        // A budget/allowance cap firing (or a correct platform skip) is the agent
        // working as designed, not breakage — it must not drive the failure ratio
        // nor surface as "needs investigation". The failure ratio is computed off
        // real faults only; benign refusals are reported separately as 'capped'.
        $allFailures = $audit->where('outcome', 'failed')->values();
        $benignFailures = $allFailures->filter(fn (array $r) => $this->isBenignCapOrPolicy($r['error'] ?? null))->values();
        $realFailures = $allFailures->reject(fn (array $r) => $this->isBenignCapOrPolicy($r['error'] ?? null))->values();

        $failedRuns = $realFailures->count();
        // Denominator excludes benign refusals so caps can't dilute or inflate the
        // ratio: a workspace that hits its cap 5× shouldn't read as 5/6 failing.
        $ratioDenominator = $totalRuns - $benignFailures->count();
        $failRatio = $ratioDenominator > 0 ? $failedRuns / $ratioDenominator : 0.0;

        // For the surfaced reason: the most recent REAL fault drives 'failing';
        // the most recent BENIGN refusal drives 'capped'.
        $lastFailure = $realFailures->first();
        $lastBenign = $benignFailures->first();

        $blockedPipelines = $pipelines->whereIn('state', ['failed', 'blocked'])->values();
        $longRunningPipelines = $pipelines->filter(function (array $p) use ($now) {
            return $p['state'] === 'running'
                && $p['started_at']
                && $p['started_at']->diffInMinutes($now) >= self::STUCK_PIPELINE_MIN;
        })->values();

        $status = $this->deriveStatus(
            failRatio: $failRatio,
            totalRuns: $totalRuns,
            ratioDenominator: $ratioDenominator,
            lastRun: $lastRun,
            lastBenign: $lastBenign,
            blockedPipelines: $blockedPipelines,
            longRunningPipelines: $longRunningPipelines,
            now: $now,
        );

        $reason = $this->reasonForStatus(
            status: $status,
            lastFailure: $lastFailure,
            lastBenign: $lastBenign,
            blockedPipelines: $blockedPipelines,
            longRunningPipelines: $longRunningPipelines,
        );

        $nextAction = $this->nextActionFor($status, $reason['error_text'] ?? null, $agent['role']);

        $p50 = $this->p50LatencyMs($audit);
        $queueName = $this->guessQueueName($agent['role']);
        $queueDepth = $horizon['queues'][$queueName] ?? null;

        return [
            'role' => $agent['role'],
            'label' => $agent['label'],
            'class' => $agent['class'],
            'status' => $status,
            'reason_headline' => $reason['headline'],
            'reason_detail' => $reason['detail'],
            'error_text' => $reason['error_text'],
            'next_action' => $nextAction,
            'runs_24h' => $totalRuns,
            // failed_24h counts REAL faults only — benign cap/policy refusals are
            // reported separately as capped_24h so the operator sees "5 capped"
            // (expected, self-resolving) instead of "5 failed" (alarming).
            'failed_24h' => $failedRuns,
            'capped_24h' => $benignFailures->count(),
            'last_success_at' => $lastSuccess['occurred_at'] ?? null,
            'last_failure_at' => $lastFailure['occurred_at'] ?? null,
            'last_run_at' => $lastRun['occurred_at'] ?? null,
            'p50_latency_ms' => $p50,
            'queue_name' => $queueName,
            'queue_depth' => $queueDepth,
            'active_pipelines' => $pipelines->count(),
            'blocked_pipelines' => $blockedPipelines->count(),
            'stuck_pipelines' => $longRunningPipelines->count(),
        ];
    }

    private function deriveStatus(
        float $failRatio,
        int $totalRuns,
        int $ratioDenominator,
        ?array $lastRun,
        ?array $lastBenign,
        \Illuminate\Support\Collection $blockedPipelines,
        \Illuminate\Support\Collection $longRunningPipelines,
        Carbon $now,
    ): string {
        if ($blockedPipelines->isNotEmpty() || $longRunningPipelines->isNotEmpty()) {
            return 'stuck';
        }

        // No-progress detection: a started-but-no-completion run that's older
        // than STUCK_RUN_MIN looks stuck even without a pipeline_runs row.
        if ($lastRun && $lastRun['outcome'] === 'started'
            && $lastRun['occurred_at']->diffInMinutes($now) >= self::STUCK_RUN_MIN) {
            return 'stuck';
        }

        // Real-fault failing: ratio is computed off non-benign runs only, so a
        // workspace hammering its cap can't tip this. Needs ≥3 non-benign runs
        // to avoid flapping on a single early fault.
        if ($ratioDenominator >= 3 && $failRatio >= self::FAIL_RATIO) {
            return 'failing';
        }

        // Benign cap/policy refusal is the most recent thing this agent did, and
        // there's no real-fault failing above it. Surface it as 'capped' — the
        // agent is working as designed (it refused to overspend / correctly
        // skipped), so this reads amber-neutral, not red, with a wait/upgrade
        // remedy rather than "investigate". Recent enough to be the live state.
        if ($lastRun && $lastBenign
            && $lastRun['outcome'] === 'failed'
            && $this->isBenignCapOrPolicy($lastRun['error'] ?? null)) {
            return 'capped';
        }

        if ($lastRun && $lastRun['occurred_at']->diffInMinutes($now) <= self::ACTIVE_MIN) {
            return 'active';
        }

        if ($totalRuns > 0) {
            return 'healthy';
        }

        return 'idle';
    }

    /**
     * True when a "failed" audit row is a benign cap/policy refusal (plan budget
     * or allowance reached, or a correct platform skip) rather than a real fault.
     * These don't count toward the failure ratio and get a wait/upgrade remedy.
     */
    private function isBenignCapOrPolicy(?string $error): bool
    {
        if ($error === null || $error === '') {
            return false;
        }
        $haystack = strtolower($error);
        foreach (self::BENIGN_CAP_NEEDLES as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }
        return false;
    }

    /**
     * @return array{headline: string, detail: string, error_text: ?string}
     */
    private function reasonForStatus(
        string $status,
        ?array $lastFailure,
        ?array $lastBenign,
        \Illuminate\Support\Collection $blockedPipelines,
        \Illuminate\Support\Collection $longRunningPipelines,
    ): array {
        if ($status === 'stuck') {
            $first = $blockedPipelines->first() ?? $longRunningPipelines->first();
            $err = $first['last_error'] ?? null;
            $step = $first['current_step'] ?? null;
            $headline = $blockedPipelines->isNotEmpty()
                ? 'Pipeline is blocked or failed and won\'t resume on its own.'
                : 'A run started but hasn\'t reported back within ' . self::STUCK_PIPELINE_MIN . ' min.';
            $detail = trim(($step ? "Step: {$step}. " : '') . ($err ? "Last error: {$err}" : ''));
            if ($detail === '') {
                $detail = 'No error text captured. Check the worker logs for this run.';
            }
            return ['headline' => $headline, 'detail' => $detail, 'error_text' => $err];
        }

        if ($status === 'failing') {
            $err = $lastFailure['error'] ?? null;
            return [
                'headline' => 'Recent runs keep failing — needs investigation.',
                'detail' => $err ? "Most recent failure: {$err}" : 'Failure recorded but no error text was captured.',
                'error_text' => $err,
            ];
        }

        if ($status === 'capped') {
            $err = $lastBenign['error'] ?? null;
            return [
                'headline' => 'At a plan limit — working as designed, no action needed.',
                'detail' => $err
                    ? "Most recent: {$err}"
                    : 'A plan budget or allowance was reached. This self-resolves at the reset.',
                'error_text' => $err,
            ];
        }

        if ($status === 'active') {
            return ['headline' => 'Running now — last completion within ' . self::ACTIVE_MIN . ' min.', 'detail' => '', 'error_text' => null];
        }

        if ($status === 'healthy') {
            return ['headline' => 'Healthy. Quiet at the moment.', 'detail' => '', 'error_text' => null];
        }

        return [
            'headline' => 'Idle — no activity in last ' . self::LOOKBACK_HOURS . 'h.',
            'detail' => 'Many agents only run when triggered (cron, user click, or upstream chain). Idle is not a failure.',
            'error_text' => null,
        ];
    }

    /**
     * Pattern-match the last error string against known failure modes.
     * If nothing matches, point the operator at the audit log — never
     * invent a remedy.
     */
    private function nextActionFor(string $status, ?string $errorText, string $role): string
    {
        if ($status === 'idle') {
            return $this->idleNextAction($role);
        }

        if ($status === 'healthy') {
            return 'No action needed. Spot-check Live feed / Drafts to confirm output looks right.';
        }

        if ($status === 'active') {
            return 'No action needed. Refresh in a minute to see the result.';
        }

        $error = strtolower((string) $errorText);

        // Benign cap/policy refusal — the agent is working as designed. Give the
        // wait/upgrade remedy straight away; NEVER fall through to "read the stack
        // trace" (there is no stack — it's an intentional AgentResult::fail()).
        // Matched first so it wins regardless of the derived status.
        if ($this->isBenignCapOrPolicy($errorText)) {
            if (str_contains($error, 'does not accept short-form video')) {
                return 'No action needed. This platform is text/image-only — the Video agent correctly skipped it. The post still ships with its image.';
            }
            if (str_contains($error, 'ai-video allowance reached')) {
                return 'Expected — the monthly AI-video allowance is used up. It resets on the 1st. To make more videos this cycle, upgrade the plan at /agency/billing; affected drafts can ship with a still image now.';
            }

            return 'Expected — the daily AI-media budget cap fired (the agent refused to overspend). It resets at midnight UTC. For a higher daily ceiling now, upgrade the plan at /agency/billing; affected drafts can ship with a still image in the meantime.';
        }

        if ($error === '') {
            return 'Open the audit log for this agent: tail -f storage/logs/laravel.log | grep ' . $role . '. Then re-run the failing draft from the Drafts page.';
        }

        $patterns = [
            // Provider billing exhaustion MUST be matched before the generic
            // '403'/'401' rules below — FAL/Anthropic return 403 on a locked
            // (out-of-balance) account, and "re-check OAuth scope" is the wrong
            // remedy. The fix is a top-up, not a key rotation.
            'balance exhausted' => 'FAL.AI prepaid balance is exhausted — the account is locked. Top up at fal.ai/dashboard/billing. Image/video generation auto-resumes within ~2 min of the top-up (the lockout breaker re-probes); drafts fall back to the brand library in the meantime.',
            'exhausted balance' => 'FAL.AI prepaid balance is exhausted — the account is locked. Top up at fal.ai/dashboard/billing. Image/video generation auto-resumes within ~2 min of the top-up; drafts fall back to the brand library in the meantime.',
            'account locked' => 'FAL.AI account is locked (balance exhausted). Top up at fal.ai/dashboard/billing — generation auto-resumes once the breaker re-probes a funded account.',
            'top up your balance' => 'FAL.AI prepaid balance is exhausted. Top up at fal.ai/dashboard/billing to restore image/video generation.',
            'insufficient' => 'A provider reported insufficient funds/credits. Top up that provider\'s balance (FAL: fal.ai/dashboard/billing) — this is a billing remedy, not a key rotation.',
            'horizon' => 'Horizon/Redis is the queue runner. Check Railway worker logs; restart the worker service if needed.',
            'redis' => 'Redis appears unreachable. Verify REDIS_URL on Railway and that the Redis service is running.',
            'rate limit' => 'Provider rate-limited us. Wait 5–15 min before re-running, or lower agent concurrency.',
            '429' => 'Provider returned HTTP 429. Wait 5–15 min before re-running, or lower agent concurrency.',
            '401' => 'API key was rejected. Re-check the relevant Infisical secret and roll if needed.',
            '403' => 'Permissions error from a provider. Re-check OAuth scope or API key permissions.',
            'unauth' => 'Authentication failed against an external API. Re-check the relevant Infisical secret.',
            'connect' => 'Network/connection error to a provider. Check provider status page; retry once.',
            'timeout' => 'A downstream call timed out. Try once more; if it repeats, escalate or extend the timeout cap.',
            'sqlstate' => 'Database error. Check migration state and column types; the error text holds the failing SQL.',
            'blotato' => 'Blotato API returned an error. Inspect the violation kind in compliance_checks.details, fix the draft, and re-run.',
            'platformrules' => 'A draft failed publishability rules — open Drafts, view the failing check, fix the caption/media, and re-run Compliance.',
            'connection target' => 'A platform connection is missing a required target_override (pageId / boardId). Reconnect the platform on Platforms.',
            'no current brand_style' => 'The brand has no current brand_style row. Run the Setup wizard step that publishes brand-style.md.',
            'undefined' => 'Probable code bug. Capture the full stack from storage/logs/laravel.log and file a ticket — do not retry blindly.',
        ];

        foreach ($patterns as $needle => $action) {
            if (str_contains($error, $needle)) {
                return $action;
            }
        }

        return 'Unfamiliar error. Open storage/logs/laravel.log for the full stack, then re-run the affected draft from the Drafts page.';
    }

    private function idleNextAction(string $role): string
    {
        return match ($role) {
            'strategist' => 'Run Strategist from Setup wizard / Calendar — produces the monthly plan that drives Writer.',
            'writer' => 'Writer fires per-calendar-entry. Trigger from Calendar > Run Writer on a row.',
            'compliance' => 'Compliance runs automatically after Writer. Idle here is normal until a draft exists.',
            'designer' => 'Designer runs after a draft is in awaiting_approval / approved. Idle is normal between drafts.',
            'video' => 'Video runs only for reel / video / story formats. Idle is normal otherwise.',
            'scheduler' => 'Scheduler is the cron path posts:auto-schedule-approved. Idle = no approved drafts waiting.',
            'repurpose' => 'Repurpose runs on demand from the Drafts page. Idle is expected.',
            'competitor_intel' => 'CompetitorIntel runs on a weekly cron — idle is normal mid-week.',
            'researcher' => 'Researcher runs on demand. Idle is expected.',
            'onboarding' => 'Onboarding runs once per new brand. Idle is expected after first setup.',
            'optimizer' => 'Optimizer is a follow-up agent. Idle is expected.',
            'community' => 'Community agent runs on inbound comments. Idle is expected during quiet periods.',
            default => 'Idle. Trigger from the agent\'s associated UI surface, or wait for its cron.',
        };
    }

    private function p50LatencyMs(\Illuminate\Support\Collection $audit): ?int
    {
        $values = $audit->pluck('latency_ms')->filter(fn ($v) => $v > 0)->sort()->values();
        if ($values->isEmpty()) return null;
        $mid = (int) floor($values->count() / 2);
        return (int) $values->get($mid);
    }

    private function guessQueueName(string $role): string
    {
        // Horizon config uses queue names like 'agents', 'default', 'media'.
        // We don't have a canonical map of role→queue, so we return 'default'
        // and let the UI show the queue depth only when it's present in the
        // workload snapshot.
        return match ($role) {
            'designer', 'video' => 'media',
            default => 'default',
        };
    }

    private function decodeJson($value): array
    {
        if (is_array($value)) return $value;
        if (! is_string($value) || $value === '') return [];
        try {
            $decoded = json_decode($value, true, 32, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @param array<int, array{role: string}> $rows
     * @return array<int, array<string, mixed>>
     */
    private function sortByPipeline(array $rows): array
    {
        $order = array_flip(self::PIPELINE_ORDER);
        usort($rows, function (array $a, array $b) use ($order) {
            $ai = $order[$a['role']] ?? 999;
            $bi = $order[$b['role']] ?? 999;
            if ($ai !== $bi) return $ai <=> $bi;
            return strcmp($a['role'], $b['role']);
        });
        return $rows;
    }
}
