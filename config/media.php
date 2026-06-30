<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Media-generation failure alerts
    |--------------------------------------------------------------------------
    | When DesignerAgent / VideoAgent cannot produce media — FAL account lockout
    | (balance exhausted) or a generic generation failure — the admin gets an
    | immediate email naming the REASON and the ACTION REQUIRED. This exists
    | because a silent FAL lockout once broke media generation for ~3 weeks
    | before anyone noticed; the drafts simply piled up as compliance_failed.
    |
    | Pinned to Resend explicitly (same rationale as security.alerts.mailer and
    | mail.cap_warning.mailer): a future product MAIL_MAILER swap must not move
    | this operational alert onto a less reliable transport.
    */
    'alerts' => [
        // Where media-generation failure alerts get emailed.
        'recipient' => env('MEDIA_ALERT_RECIPIENT', env('SECURITY_ALERT_RECIPIENT', 'eiaawsolutions@gmail.com')),

        // From identity. Must be a verified Resend sender.
        'from_address' => env('MEDIA_ALERT_FROM', 'noreply@eiaawsolutions.com'),
        'from_name' => env('MEDIA_ALERT_FROM_NAME', 'EIAAW Social Media Team'),

        // Pinned mailer (see note above).
        'mailer' => env('MEDIA_ALERT_MAILER', 'resend'),

        // Throttle: one alert per (reason-class) per this many minutes. A backlog
        // run can hit the SAME failure on dozens of drafts in seconds; without a
        // throttle that is dozens of identical emails. The next allowed alert
        // reports how many failures were suppressed in between so the admin still
        // sees the true blast radius. Account-lockout and generic-failure are
        // separate classes, each with its own bucket.
        'throttle_minutes' => (int) env('MEDIA_ALERT_THROTTLE_MINUTES', 30),
    ],

];
