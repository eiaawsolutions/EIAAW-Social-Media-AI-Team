<?php

namespace App\Services\Security;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Redis-backed token bucket that rate-limits security alert emails so a
 * burst attack can't flood the operator inbox. Two bucket tiers:
 *
 *   - Per-workspace bucket (`security:alert:ws:{id}`) — caps the noise
 *     a single tenant can generate. Spans MEDIUM + HIGH events.
 *   - Global HIGH-severity bucket (`security:alert:global_high`) — protects
 *     against a coordinated cross-tenant attack from filling the inbox.
 *
 * HIGH events bypass the per-workspace bucket (they're meant to alert)
 * but are still capped by the global bucket. MEDIUM events are subject
 * to the per-workspace bucket AND require the burst threshold to be
 * reached before they alert at all.
 *
 * When an alert is suppressed, the bucket counter still increments so
 * the next allowed alert can report "N additional events suppressed
 * since last alert."
 */
class SecurityAlertThrottle
{
    /**
     * Should we send an alert for this event right now? Increments the
     * relevant counter as a side effect.
     *
     * @return array{allow: bool, suppressed_since_last_alert: int, reason: ?string}
     */
    public function shouldAlert(DetectorVerdict $verdict, ?int $workspaceId): array
    {
        $perWsCap = (int) config('security.alerts.per_workspace_per_hour', 6);
        $globalHighCap = (int) config('security.alerts.global_high_per_hour', 12);
        $window = 3600; // 1h, matches the per-hour cap semantics

        if ($verdict->severity === DetectorVerdict::SEVERITY_HIGH) {
            // HIGH bypasses the per-workspace bucket but is capped globally.
            $globalKey = 'security:alert:global_high';
            $globalCount = $this->increment($globalKey, $window);
            if ($globalCount > $globalHighCap) {
                return [
                    'allow' => false,
                    'suppressed_since_last_alert' => $globalCount - $globalHighCap,
                    'reason' => 'global_high_cap_reached',
                ];
            }
            return [
                'allow' => true,
                'suppressed_since_last_alert' => $this->drainSuppressedCounter($workspaceId),
                'reason' => null,
            ];
        }

        // MEDIUM path — must reach burst threshold first, then per-ws cap.
        $burstThreshold = (int) config('security.alerts.medium_burst_threshold', 5);
        $burstKey = 'security:medium_burst:ws:' . ($workspaceId ?: 'null');
        $burstCount = $this->increment(
            $burstKey,
            (int) config('security.alerts.medium_burst_window_minutes', 60) * 60,
        );

        if ($burstCount < $burstThreshold) {
            return [
                'allow' => false,
                'suppressed_since_last_alert' => 0,
                'reason' => "below_burst_threshold ({$burstCount}/{$burstThreshold})",
            ];
        }

        $perWsKey = 'security:alert:ws:' . ($workspaceId ?: 'null');
        $perWsCount = $this->increment($perWsKey, $window);
        if ($perWsCount > $perWsCap) {
            $this->incrementSuppressedCounter($workspaceId);
            return [
                'allow' => false,
                'suppressed_since_last_alert' => 0,
                'reason' => "per_workspace_cap_reached ({$perWsCount}/{$perWsCap})",
            ];
        }

        return [
            'allow' => true,
            'suppressed_since_last_alert' => $this->drainSuppressedCounter($workspaceId),
            'reason' => null,
        ];
    }

    /**
     * Atomic-ish increment with TTL. Uses Cache::add to set the initial
     * value on first hit (which sets the expiry); subsequent calls use
     * Cache::increment. Race window for double-init is fine — losing 1
     * event in the alert counter does not change the security posture.
     */
    private function increment(string $key, int $ttlSeconds): int
    {
        if (Cache::add($key, 1, $ttlSeconds)) {
            return 1;
        }
        try {
            return (int) Cache::increment($key);
        } catch (\Throwable $e) {
            // Cache backend down — log and pretend we incremented once.
            // Don't fail the security path on a cache hiccup.
            Log::warning('SecurityAlertThrottle: cache increment failed', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return 1;
        }
    }

    /** Counts events that were suppressed since the last allowed alert. */
    private function incrementSuppressedCounter(?int $workspaceId): void
    {
        $key = 'security:alert_suppressed:ws:' . ($workspaceId ?: 'null');
        if (! Cache::add($key, 1, 86400)) {
            try {
                Cache::increment($key);
            } catch (\Throwable) {
                // ignore — best-effort counter
            }
        }
    }

    /**
     * Read + delete the suppressed counter — call when an alert IS being
     * sent so the next email can say "N more events suppressed."
     */
    private function drainSuppressedCounter(?int $workspaceId): int
    {
        $key = 'security:alert_suppressed:ws:' . ($workspaceId ?: 'null');
        $value = (int) Cache::get($key, 0);
        Cache::forget($key);
        return $value;
    }
}
