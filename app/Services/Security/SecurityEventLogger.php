<?php

namespace App\Services\Security;

use App\Mail\SecurityAlert;
use App\Models\SecurityEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Persists detector verdicts to the security_events ledger and, when the
 * throttle allows, emails the operator. Sits between the detector (pure)
 * and the side-effecting world (DB writes, Resend, alert dedup).
 *
 * Failure handling:
 *   - DB write failures log and return null but never throw — the LLM
 *     call must proceed.
 *   - Mail send failures log and continue — we'd rather lose an email
 *     than block a customer request.
 *   - The throttle's "drain suppressed counter" runs only on the success
 *     path, so a suppressed-event count survives email-delivery failures.
 */
class SecurityEventLogger
{
    public function __construct(
        private readonly SecurityAlertThrottle $throttle,
    ) {}

    /**
     * Record the verdict, then decide whether to alert. Returns the saved
     * SecurityEvent or null if persistence failed.
     */
    public function record(
        InjectionContext $context,
        DetectorVerdict $verdict,
        bool $blocked,
    ): ?SecurityEvent {
        // SAFE verdicts are not persisted. They'd be ~99% of rows and
        // drown the ledger. If we ever want telemetry for false-positive
        // rate calibration, add a sampled-safe pipeline (1% sample) on a
        // separate table.
        if ($verdict->verdict === DetectorVerdict::VERDICT_SAFE) {
            return null;
        }

        try {
            $event = SecurityEvent::create([
                'workspace_id' => $context->workspace?->id,
                'brand_id' => $context->brand?->id,
                'user_id' => $context->user?->id,
                'event_type' => $this->buildEventType($verdict),
                'severity' => $verdict->severity,
                'detector_layer' => $verdict->detectorLayer,
                'verdict' => $verdict->verdict,
                'confidence' => $verdict->confidence,
                'category' => $verdict->category,
                // Evidence is capped at 1KB at write time. The full sample
                // (if needed for forensics) belongs in payload.
                'evidence' => $verdict->evidence
                    ? substr($verdict->evidence, 0, 1024)
                    : null,
                'payload' => $this->buildPayload($context, $verdict),
                'correlation_id' => $context->correlationId,
                'blocked' => $blocked,
                'alerted' => false,
                'occurred_at' => now(),
            ]);
        } catch (Throwable $e) {
            Log::error('SecurityEventLogger: failed to persist event', [
                'error' => $e->getMessage(),
                'verdict' => $verdict->verdict,
                'severity' => $verdict->severity,
            ]);
            return null;
        }

        // Throttle decision — must happen on the SAVED row so the email
        // (sent asynchronously below) carries the persisted id.
        if ($verdict->verdict === DetectorVerdict::VERDICT_DETECTOR_FAILURE) {
            // Detector-failure events log but never alert — they're our
            // operational signal, not a security incident in themselves.
            return $event;
        }

        $decision = $this->throttle->shouldAlert($verdict, $context->workspace?->id);
        if (! $decision['allow']) {
            return $event;
        }

        $this->dispatchAlert($event, $decision['suppressed_since_last_alert']);

        // Mark alerted=true via raw query (model.update() is blocked).
        // The append-only trigger has an exception for the alerted flag —
        // see migration. For now we just track it on the SecurityEvent
        // instance in memory; if we need DB-level alerted tracking later,
        // we add a small whitelist-column UPDATE policy.
        $event->alerted = true;

        return $event;
    }

    private function buildEventType(DetectorVerdict $verdict): string
    {
        $base = match (true) {
            str_starts_with($verdict->detectorLayer ?? '', 'layer1') => 'prompt_injection.heuristic',
            str_starts_with($verdict->detectorLayer ?? '', 'layer2') => 'prompt_injection.graded',
            $verdict->verdict === DetectorVerdict::VERDICT_DETECTOR_FAILURE => 'security.detector_failure',
            default => 'prompt_injection.unknown',
        };
        return $base;
    }

    private function buildPayload(InjectionContext $context, DetectorVerdict $verdict): array
    {
        // Sample of the offending text — capped at 2KB to keep payload
        // small. Full text never persisted: prompt content can include
        // customer drafts (potentially confidential). Evidence column
        // already holds the matched substring for forensics.
        $sample = strlen($context->text) > 2048
            ? substr($context->text, 0, 2048) . '…[truncated]'
            : $context->text;

        return [
            'surface' => $context->surface,
            'agent_role' => $context->agentRole,
            'model_id' => $context->modelId,
            'prompt_version' => $context->promptVersion,
            'text_sample' => $sample,
            'text_bytes' => $context->lengthBytes(),
            'detector_extra' => $verdict->extra,
        ];
    }

    private function dispatchAlert(SecurityEvent $event, int $suppressedCount): void
    {
        $recipient = (string) config('security.alerts.recipient', 'eiaawsolutions@gmail.com');
        $mailer = (string) config('security.alerts.mailer', 'resend');

        try {
            Mail::mailer($mailer)
                ->to($recipient)
                ->queue(new SecurityAlert($event, $suppressedCount));
        } catch (Throwable $e) {
            Log::error('SecurityEventLogger: alert email dispatch failed', [
                'event_id' => $event->id,
                'mailer' => $mailer,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
