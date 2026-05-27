<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Prompt-injection detector
    |--------------------------------------------------------------------------
    | The detector hooks into LlmGateway::call() and runs three layers:
    |   1. Heuristic prefilter (in-process, ~1ms)
    |   2. LLM grader on Haiku, only when L1 returns suspicious or input > 4KB
    |   3. Output canary that checks the model's response for prompt leakage
    |
    | If any layer raises severity HIGH the call is BLOCKED — the caller sees
    | a generic exception, never the detector verdict (don't leak the rule set
    | to the attacker). MEDIUM events log + count toward the throttle bucket
    | and trigger an alert once the burst threshold trips.
    */
    'injection_detector' => [
        'enabled' => env('INJECTION_DETECTOR_ENABLED', true),

        // When false, the detector evaluates and logs but never blocks the
        // LLM call. Useful for the first 48h of production to baseline the
        // false-positive rate before turning enforcement on.
        'enforce' => env('INJECTION_DETECTOR_ENFORCE', true),

        // Input length above which we always escalate to the LLM grader,
        // regardless of heuristic verdict. Large pasted inputs are the
        // common smuggling surface (poisoned scraped competitor ads, etc.).
        'grader_input_threshold_bytes' => 4096,

        // Haiku as the grader — cheap, fast, and the same provider as the
        // primary calls so no extra credential surface.
        'grader_model' => 'claude-haiku-4-5',
    ],

    /*
    |--------------------------------------------------------------------------
    | Alert delivery
    |--------------------------------------------------------------------------
    */
    'alerts' => [
        // Where HIGH-severity events get emailed. Single recipient for v1;
        // when the team grows, switch to a distribution list managed in
        // Resend / Infisical so we don't redeploy to add operators.
        'recipient' => env('SECURITY_ALERT_RECIPIENT', 'eiaawsolutions@gmail.com'),

        // From address shown in the alert email. Must be a verified Resend
        // sender — see https://resend.com/domains.
        'from_address' => env('SECURITY_ALERT_FROM', 'security@eiaawsolutions.com'),
        'from_name' => env('SECURITY_ALERT_FROM_NAME', 'EIAAW Security'),

        // Mailer override — we pin alerts to Resend explicitly so that a
        // future MAIL_MAILER change (e.g. swapping product mail to Postmark)
        // doesn't accidentally move security alerts onto a less reliable path.
        'mailer' => 'resend',

        // Throttle: max emails per hour per workspace. Bursts above this
        // are still logged to security_events but coalesced into a single
        // "N more events suppressed" line on the next allowed alert.
        // HIGH-severity events bypass the per-workspace bucket but are
        // capped at `global_high_per_hour` to prevent inbox flooding from
        // a deliberate burst attack.
        'per_workspace_per_hour' => 6,
        'global_high_per_hour' => 12,

        // MEDIUM-event burst threshold: alert when this many MEDIUM events
        // fire within the rolling window. Below threshold they accumulate
        // silently in security_events for the weekly digest.
        'medium_burst_threshold' => 5,
        'medium_burst_window_minutes' => 60,
    ],

];
