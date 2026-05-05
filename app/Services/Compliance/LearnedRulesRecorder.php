<?php

namespace App\Services\Compliance;

use App\Models\Draft;
use App\Models\ScheduledPost;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Compliance learner. Every rejection — at any layer — flows through here:
 *
 *   - SubmitScheduledPost::markFailed (Blotato 4xx + platform-rejected)
 *   - ComplianceAgent::checkPlatformPublishability fail (caption/hashtag/media)
 *   - DraftsBackfillPublishability cancellations
 *
 * The recorder normalises the rejection into a (rule_kind, fingerprint)
 * tuple and atomically upserts a row in compliance_learned_rules. Same
 * root cause = one row, occurrences counter ticks. Different root cause =
 * new row.
 *
 * Output is consumed by:
 *
 *   - LearnedRulesProvider — injects directives into Writer/Designer prompts
 *   - ComplianceAgent::checkLearnedRules — fast-fails drafts that match a
 *     'block'-severity learned rule before LLM scoring runs
 *
 * Failure semantics: NEVER throws. The publish path runs this best-effort,
 * so a recorder bug must never block a publish or log spam-fail. All errors
 * are logged at warning and swallowed.
 */
class LearnedRulesRecorder
{
    /**
     * Record a rejection from SubmitScheduledPost (Blotato or platform-side).
     *
     * @param ScheduledPost $post  The post that just failed.
     * @param string        $reason  Operator-facing reason string (already truncated).
     * @param array|null    $blotatoStatus  Full Blotato status payload if available.
     */
    public function recordRejection(ScheduledPost $post, string $reason, ?array $blotatoStatus = null): void
    {
        try {
            $platform = (string) ($post->draft?->platform ?? '');
            if ($platform === '') return;

            $extracted = $this->extractFromBlotatoFailure($reason, $blotatoStatus);
            if (! $extracted) return;

            $this->upsert(
                workspaceId: $post->brand?->workspace_id,
                platform: $platform,
                ruleKind: $extracted['kind'],
                fingerprint: $extracted['fingerprint'],
                directive: $extracted['directive'],
                rejectionExcerpt: $extracted['excerpt'],
                draftId: $post->draft_id,
                scheduledPostId: $post->id,
            );
        } catch (\Throwable $e) {
            Log::warning('LearnedRulesRecorder: recordRejection swallowed error', [
                'scheduled_post_id' => $post->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Record a publishability-gate fail from ComplianceAgent or the
     * SubmitScheduledPost defence-in-depth gate. Each violation `kind`
     * (media_required, caption_too_long, etc) becomes its own learned rule.
     *
     * @param array{kind:string,reason:string,detail?:array} $violation
     */
    public function recordPublishabilityViolation(Draft $draft, array $violation): void
    {
        try {
            $kind = (string) ($violation['kind'] ?? '');
            $reason = (string) ($violation['reason'] ?? '');
            if ($kind === '' || $reason === '') return;

            $directive = $this->directiveForViolation($draft->platform, $kind, $reason);
            $fingerprint = $this->fingerprintFor($draft->platform, $kind, $kind);

            $this->upsert(
                workspaceId: $draft->brand?->workspace_id,
                platform: (string) $draft->platform,
                ruleKind: $kind,
                fingerprint: $fingerprint,
                directive: $directive,
                rejectionExcerpt: $reason,
                draftId: $draft->id,
                scheduledPostId: null,
            );
        } catch (\Throwable $e) {
            Log::warning('LearnedRulesRecorder: recordPublishabilityViolation swallowed error', [
                'draft_id' => $draft->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Atomic upsert + occurrence bump. Postgres ON CONFLICT keeps the
     * recorder concurrency-safe (two workers seeing the same fingerprint
     * at the same time settle into one row, not a duplicate-key error).
     */
    private function upsert(
        ?int $workspaceId,
        string $platform,
        string $ruleKind,
        string $fingerprint,
        string $directive,
        ?string $rejectionExcerpt,
        ?int $draftId,
        ?int $scheduledPostId,
    ): void {
        $now = now();
        $excerpt = $rejectionExcerpt !== null ? mb_substr($rejectionExcerpt, 0, 1000) : null;

        // Postgres-specific. We're already pgsql-locked across the project
        // (pgvector everywhere), so this is fine.
        DB::statement(
            <<<SQL
            INSERT INTO compliance_learned_rules
                (workspace_id, platform, rule_kind, fingerprint, severity,
                 directive, rejection_excerpt, occurrences,
                 first_seen_at, last_seen_at,
                 last_draft_id, last_scheduled_post_id,
                 disabled, created_at, updated_at)
            VALUES (?, ?, ?, ?, 'block', ?, ?, 1, ?, ?, ?, ?, false, ?, ?)
            ON CONFLICT ON CONSTRAINT compliance_learned_rules_unique
            DO UPDATE SET
                occurrences = compliance_learned_rules.occurrences + 1,
                last_seen_at = EXCLUDED.last_seen_at,
                last_draft_id = COALESCE(EXCLUDED.last_draft_id, compliance_learned_rules.last_draft_id),
                last_scheduled_post_id = COALESCE(EXCLUDED.last_scheduled_post_id, compliance_learned_rules.last_scheduled_post_id),
                directive = EXCLUDED.directive,
                rejection_excerpt = COALESCE(EXCLUDED.rejection_excerpt, compliance_learned_rules.rejection_excerpt),
                updated_at = EXCLUDED.updated_at
            SQL,
            [
                $workspaceId,
                strtolower($platform),
                $ruleKind,
                $fingerprint,
                $directive,
                $excerpt,
                $now,
                $now,
                $draftId,
                $scheduledPostId,
                $now,
                $now,
            ]
        );

        // Bust the read-side cache so Writer/Designer pick up the new
        // directive on their very next prompt build (not 60s later).
        try {
            app(LearnedRulesProvider::class)->bustCache($platform, $workspaceId);
        } catch (\Throwable) {
            // cache layer might be unavailable in tests — non-fatal
        }
    }

    /**
     * Pull a (kind, fingerprint, directive, excerpt) tuple out of a Blotato
     * failure. Returns null if the rejection is too vague to learn from
     * (e.g. plain "Platform rejected" with no payload).
     *
     * @return array{kind:string,fingerprint:string,directive:string,excerpt:string}|null
     */
    private function extractFromBlotatoFailure(string $reason, ?array $blotatoStatus): ?array
    {
        $reasonLc = strtolower($reason);

        // ── Blotato 400 — missing required property ─────────────────────
        // Reason shape: '... HTTP 400 — {"message":"body.post.target must
        // have required property \'pageId\'"}'. The property name is the
        // important signal — it tells us which connection field is missing
        // on this account, which is connection-config not content. We still
        // record it so an operator can see "FB rejections cluster on missing
        // pageId" without grepping logs.
        if (preg_match('/must have required property [\\\'"]([a-z0-9_]+)[\\\'"]/i', $reason, $m)) {
            $property = strtolower($m[1]);
            return [
                'kind' => 'missing_required_property',
                'fingerprint' => $this->fingerprintFor('', 'missing_required_property', $property),
                'directive' => sprintf(
                    'Connection target_overrides for this platform MUST include "%s" — Blotato rejects the create call with HTTP 400 otherwise. Operator action: open the platform-connection settings and supply %s for the destination account.',
                    $property,
                    $property,
                ),
                'excerpt' => mb_substr($reason, 0, 500),
            ];
        }

        // ── Blotato 422 — text-only on media-required platform ─────────
        if (str_contains($reasonLc, 'must have at least one image or video')
            || str_contains($reasonLc, 'requires at least 1 media')
            || str_contains($reasonLc, 'media is required')) {
            return [
                'kind' => 'media_required',
                'fingerprint' => $this->fingerprintFor('', 'media_required', 'media_required'),
                'directive' => 'This platform rejects text-only posts. Designer (image) or Video (clip) MUST attach at least one media item before publish. Writer should always assume a media slot will be filled and write a caption that complements the visual, not one that stands alone.',
                'excerpt' => mb_substr($reason, 0, 500),
            ];
        }

        // ── Blotato 422 — caption length ────────────────────────────────
        if (preg_match('/(caption|text|body|content).{0,20}(too long|exceeds|max(imum)? length)/i', $reason)
            || str_contains($reasonLc, 'must be shorter')
            || preg_match('/length.{0,20}\d+.{0,20}(maximum|cap|limit)/i', $reason)) {
            return [
                'kind' => 'caption_too_long',
                'fingerprint' => $this->fingerprintFor('', 'caption_too_long', 'caption_length'),
                'directive' => 'Caption (body + hashtags + mentions) length on this platform exceeded the platform cap. Writer must respect PlatformRules::caption_max_total — count the assembled caption (body + "\\n\\n" + hashtag block + "\\n" + mentions block), not just the body.',
                'excerpt' => mb_substr($reason, 0, 500),
            ];
        }

        // ── Blotato 422 — too many hashtags ─────────────────────────────
        if (preg_match('/(too many hashtags|hashtag(s)?\s+limit|max(imum)?\s+\d+\s+hashtags)/i', $reason)) {
            return [
                'kind' => 'too_many_hashtags',
                'fingerprint' => $this->fingerprintFor('', 'too_many_hashtags', 'hashtag_cap'),
                'directive' => 'Platform rejected for hashtag count. Writer must keep hashtags array length under PlatformRules::hashtag_cap for this platform — Blotato is more restrictive than the native API allows.',
                'excerpt' => mb_substr($reason, 0, 500),
            ];
        }

        // ── Generic Blotato 4xx with a parseable JSON message ───────────
        // Use the JSON message text as the fingerprint so distinct error
        // messages create distinct rule rows, but recurring identical
        // messages collapse to one rule with a rising occurrence count.
        if (preg_match('/HTTP\s+(4\d\d)\s+—?\s*(\{.*\})/', $reason, $m)) {
            $http = (string) $m[1];
            $json = $m[2];
            $decoded = json_decode($json, true);
            $message = is_array($decoded) ? (string) ($decoded['message'] ?? $decoded['error'] ?? $json) : $json;
            $key = strtolower(preg_replace('/\s+/', ' ', mb_substr($message, 0, 200)));
            return [
                'kind' => 'blotato_' . $http,
                'fingerprint' => $this->fingerprintFor('', 'blotato_' . $http, $key),
                'directive' => sprintf(
                    'Blotato HTTP %s observed: "%s". This is an unrecognised rejection pattern — operator should inspect, decide whether it is a Writer/Designer-fixable issue or a connection-config issue, and refine this rule.',
                    $http,
                    mb_substr($message, 0, 200),
                ),
                'excerpt' => mb_substr($reason, 0, 500),
            ];
        }

        // ── Pre-Blotato publishability gate fired ────────────────────────
        // SubmitScheduledPost catches drafts the ComplianceAgent didn't and
        // marks them with this exact prefix. Already covered by
        // recordPublishabilityViolation when we have the violation array,
        // but the string-only path can still be parsed back into a kind.
        if (str_contains($reason, 'Publishability gate (pre-Blotato)')) {
            // We deliberately don't try to re-parse — the actual violation was
            // already recorded by ComplianceAgent. Skip to avoid double-counting.
            return null;
        }

        // ── Status payload contained a state but no error string ─────────
        // The new SubmitScheduledPost code already injects the JSON payload
        // into the reason string. Fingerprint by the postId/state combo so
        // recurring same-state rejections coalesce.
        if (str_contains($reason, 'Platform rejected')) {
            $key = is_array($blotatoStatus)
                ? strtolower(($blotatoStatus['state'] ?? 'unknown') . '|' . substr(($blotatoStatus['error'] ?? ''), 0, 80))
                : 'unknown';
            return [
                'kind' => 'platform_rejected_unknown',
                'fingerprint' => $this->fingerprintFor('', 'platform_rejected_unknown', $key),
                'directive' => 'Platform rejected the post but Blotato did not return a structured error. Operator must check the Blotato dashboard or platform-side notifications to diagnose. Once the cause is known, demote this rule to a more specific one.',
                'excerpt' => mb_substr($reason, 0, 500),
            ];
        }

        return null;
    }

    /**
     * Map a publishability-violation kind to its directive text. Kept here
     * (not in PlatformRules) because PlatformRules is a deterministic gate
     * speaking to the operator; this is content steered at the writer LLM.
     */
    private function directiveForViolation(string $platform, string $kind, string $reason): string
    {
        $platformLabel = ucfirst($platform);
        return match ($kind) {
            'media_required' => "{$platformLabel} requires media. Writer/Designer agents must coordinate so EVERY draft for {$platformLabel} ships with at least one image or video URL on the draft. A text-only {$platformLabel} draft is a Compliance fast-fail.",
            'caption_too_long' => "{$platformLabel} caption (body + hashtag block + mentions block, with separators) must stay under PlatformRules::caption_max_total. Writer counts the assembled caption, not just the body.",
            'too_many_hashtags' => "{$platformLabel} hashtags array must be ≤ PlatformRules::hashtag_cap entries. Writer drops the lowest-impact ones rather than letting Compliance fail the draft.",
            'malformed_hashtag' => "{$platformLabel} hashtags must be short keywords (under ~50 chars each). Writer NEVER stuffs the post body into a hashtag entry.",
            default => sprintf('%s: %s', $platformLabel, mb_substr($reason, 0, 200)),
        };
    }

    /**
     * Stable hash that collapses identical rejections into one row but
     * keeps distinct ones separate. Includes platform so per-platform
     * rules don't merge across platforms.
     */
    private function fingerprintFor(string $platform, string $ruleKind, string $signature): string
    {
        return md5(strtolower(trim($platform)) . '|' . $ruleKind . '|' . strtolower(trim($signature)));
    }
}
