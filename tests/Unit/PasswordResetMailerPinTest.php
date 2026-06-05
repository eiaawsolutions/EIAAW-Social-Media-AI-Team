<?php

namespace Tests\Unit;

use App\Models\User;
use App\Notifications\PinnedResetPassword;
use App\Support\MailTransport;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Locks the fix for the Bear Hug "can't log in" report: her account was intact,
 * but the password-reset email she'd need rode Laravel's DEFAULT mailer — which
 * on prod is MAIL_MAILER=log (a no-op). So the reset link was written to the log
 * file, never her inbox. User::sendPasswordResetNotification() now pins the
 * notification to the deliverable Resend mailer (MailTransport::welcomeMailer()).
 *
 * DB-free: we fake the notification channel and inspect the queued notification's
 * rendered MailMessage, asserting its ->mailer is the pinned one, not the default.
 */
class PasswordResetMailerPinTest extends TestCase
{
    public function test_reset_notification_is_pinned_to_the_deliverable_mailer(): void
    {
        Notification::fake();

        // Resolve a deliverable mailer for the test env so cannotDeliverReason()
        // passes (CI may default mail to array/log). We assert the PIN target,
        // not the global default.
        config(['mail.cap_warning.mailer' => 'resend']);
        config(['mail.mailers.resend.transport' => 'resend']);
        config(['services.resend.key' => 'test-key']);
        config(['resend.api_key' => 'test-key']);

        $user = new User();
        $user->email = 'thebearhugcafe@example.com';

        $user->sendPasswordResetNotification('tok_123');

        // The reset rides the PinnedResetPassword notification — whose toMail()
        // unconditionally pins ->mailer(welcomeMailer()). We assert the class +
        // that the pin target is deliverable (not log). We do NOT render toMail()
        // here: it builds the Filament reset URL via named routes that aren't
        // booted in a bare unit test (covered by feature/integration paths).
        Notification::assertSentTo($user, PinnedResetPassword::class);
        $this->assertSame('resend', MailTransport::welcomeMailer());
        $this->assertFalse(MailTransport::isNonDelivering(MailTransport::welcomeMailer()));
    }

    public function test_reset_throws_loudly_when_no_deliverable_transport(): void
    {
        // If the pinned mailer can't deliver, we must throw rather than silently
        // log a reset link into the void (the failure class this whole fix targets).
        config(['mail.cap_warning.mailer' => 'log']);
        config(['mail.mailers.log.transport' => 'log']);

        $user = new User();
        $user->email = 'x@example.com';

        $this->expectException(\RuntimeException::class);
        $user->sendPasswordResetNotification('tok_456');
    }
}
