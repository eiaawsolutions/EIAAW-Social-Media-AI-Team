<?php

namespace App\Services\Security;

/**
 * Outcome of running PromptInjectionDetector::evaluate(). The detector
 * never decides what to do with a verdict — it only describes what it
 * found. The caller (LlmGateway / SecurityEventLogger) maps verdict +
 * config('security.injection_detector.enforce') → block / log / alert.
 */
final class DetectorVerdict
{
    /** No issue detected. The call should proceed normally. */
    public const VERDICT_SAFE = 'safe';

    /** Heuristic or grader flagged something but confidence is moderate. */
    public const VERDICT_SUSPICIOUS = 'suspicious';

    /** High-confidence injection. Caller should block the call when enforcing. */
    public const VERDICT_MALICIOUS = 'malicious';

    /** The detector itself failed — log this, but proceed with the call. */
    public const VERDICT_DETECTOR_FAILURE = 'detector_failure';

    public const SEVERITY_LOW = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH = 'high';

    public function __construct(
        public readonly string $verdict,
        public readonly string $severity,
        public readonly string $detectorLayer,
        public readonly ?string $category = null,
        public readonly ?int $confidence = null,
        public readonly ?string $evidence = null,
        public readonly array $extra = [],
    ) {}

    public static function safe(string $layer): self
    {
        return new self(
            verdict: self::VERDICT_SAFE,
            severity: self::SEVERITY_LOW,
            detectorLayer: $layer,
        );
    }

    public static function detectorFailure(string $layer, string $reason): self
    {
        return new self(
            verdict: self::VERDICT_DETECTOR_FAILURE,
            severity: self::SEVERITY_LOW,
            detectorLayer: $layer,
            evidence: substr($reason, 0, 500),
        );
    }

    public function isBlockable(): bool
    {
        return $this->verdict === self::VERDICT_MALICIOUS
            || $this->severity === self::SEVERITY_HIGH;
    }

    public function shouldAlertImmediately(): bool
    {
        return $this->severity === self::SEVERITY_HIGH;
    }
}
