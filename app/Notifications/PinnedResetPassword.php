<?php

namespace App\Notifications;

use App\Support\MailTransport;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

/**
 * Laravel's stock password-reset notification, but pinned to the DELIVERABLE
 * mailer instead of the default.
 *
 * WHY: prod runs MAIL_MAILER=log (default mailer is a no-op); only the
 * operational mailers are pinned to Resend (see App\Support\MailTransport). The
 * stock ResetPassword rides the default mailer, so a customer's reset link was
 * written to the log file, never their inbox — the silent-drop class this
 * codebase keeps hitting ([[queued_mail_verify_at_provider]], [[resend_mail_wiring]]).
 * That was the real cause of the Bear Hug "can't log in" report: account intact,
 * but no deliverable way to (re)set a password.
 *
 * Named (not anonymous) so it's assertable in tests and explicit in tracing.
 */
class PinnedResetPassword extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        return parent::toMail($notifiable)->mailer(MailTransport::welcomeMailer());
    }
}
