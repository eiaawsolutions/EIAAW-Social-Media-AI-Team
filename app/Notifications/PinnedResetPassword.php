<?php

namespace App\Notifications;

use App\Support\MailTransport;
use Filament\Auth\Notifications\ResetPassword as FilamentResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Filament's password-reset notification, but pinned to the DELIVERABLE mailer
 * instead of the default.
 *
 * WHY: prod runs MAIL_MAILER=log (default mailer is a no-op); only operational
 * mail is pinned to Resend (see App\Support\MailTransport). BOTH reset entry
 * points rode the default mailer, so the reset link was written to the log file,
 * never the customer's inbox — the silent-drop class this codebase keeps hitting
 * ([[queued_mail_verify_at_provider]], [[resend_mail_wiring]]). That was the real
 * cause of the Bear Hug "can't log in" report: account intact, but no deliverable
 * way to (re)set a password.
 *
 * IMPORTANT: this extends FILAMENT's ResetPassword (not Laravel's). The live
 * self-serve page (Filament\Auth\Pages\PasswordReset\RequestPasswordReset) builds
 * Filament\Auth\Notifications\ResetPassword via the container and calls
 * $user->notify() DIRECTLY — it never calls User::sendPasswordResetNotification().
 * So pinning only the Laravel notification (User override) would miss the real
 * page. We bind THIS class for Filament\Auth\Notifications\ResetPassword in the
 * container (see AppServiceProvider) so Filament resolves the pinned version, and
 * User::sendPasswordResetNotification() also routes through a pin for the bare
 * broker path. Filament sets $url itself, so the reset link is correct.
 */
class PinnedResetPassword extends FilamentResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        return parent::toMail($notifiable)->mailer(MailTransport::welcomeMailer());
    }
}
