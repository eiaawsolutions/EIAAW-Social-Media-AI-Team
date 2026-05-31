<?php

namespace App\Support;

/**
 * Tiny transport-sanity helper shared by the critical-mail dispatch sites
 * (welcome credentials, HQ provisioning notification, connect-link command).
 *
 * The recurring failure class on this codebase is "mail enqueued OK, then
 * silently dropped in the worker" — because the resolved transport was `log`,
 * `array`, or a transport whose key wasn't wired (the Resend ApiKeyIsMissing
 * bug, commit 99504fd). ShouldQueue mailables only confirm ENQUEUE, never
 * delivery, so these failures are invisible at the call site.
 *
 * canDeliver() lets a dispatch site refuse to *pretend* it sent credential-
 * bearing mail through a no-op transport. It does NOT replace the existing
 * BrandSendMetricoolLink CLI guard — it's the same contract, reusable.
 */
final class MailTransport
{
    /** Transports that never actually deliver real email. */
    public const NON_DELIVERING = ['log', 'array'];

    /**
     * Resolve the underlying transport name for a configured mailer.
     * Falls back to the mailer name itself (the transport key may equal it).
     */
    public static function transportFor(string $mailer): string
    {
        return (string) config("mail.mailers.{$mailer}.transport", $mailer);
    }

    /** True when the mailer maps to a no-op (log/array) transport. */
    public static function isNonDelivering(string $mailer): bool
    {
        return in_array(self::transportFor($mailer), self::NON_DELIVERING, true);
    }

    /**
     * Can this mailer actually deliver? Checks the transport isn't a no-op AND,
     * for Resend, that BOTH key config paths are populated (the transport key
     * and the package client key — the exact split that caused the worker-only
     * ApiKeyIsMissing failure). Returns a short reason string when it can't,
     * or null when it can.
     */
    public static function cannotDeliverReason(string $mailer): ?string
    {
        $transport = self::transportFor($mailer);

        if (in_array($transport, self::NON_DELIVERING, true)) {
            return "mailer '{$mailer}' uses the non-delivering '{$transport}' transport";
        }

        if ($transport === 'resend') {
            if (empty(config('services.resend.key'))) {
                return "resend transport but services.resend.key is empty";
            }
            if (empty(config('resend.api_key'))) {
                return "resend transport but resend.api_key (package client key) is empty";
            }
        }

        if ($transport === 'mailgun' && empty(config('services.mailgun.secret'))) {
            return "mailgun transport but services.mailgun.secret is empty";
        }

        return null;
    }

    public static function canDeliver(string $mailer): bool
    {
        return self::cannotDeliverReason($mailer) === null;
    }

    /**
     * The mailer the credential-bearing welcome email should ride. Pinned to
     * the same Resend-backed transport as operational mail so it never inherits
     * a per-env MAIL_MAILER override that points at log/mailgun-without-a-key.
     */
    public static function welcomeMailer(): string
    {
        return (string) config('mail.cap_warning.mailer', 'resend');
    }
}
