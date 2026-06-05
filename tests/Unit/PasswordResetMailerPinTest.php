<?php

namespace Tests\Unit;

use App\Notifications\PinnedResetPassword;
use App\Support\MailTransport;
use Filament\Auth\Notifications\ResetPassword as FilamentResetPassword;
use Tests\TestCase;

/**
 * Locks the fix for the Bear Hug "can't log in" report. Her account was intact;
 * the password-reset email she needed was silently dropped because it rode the
 * DEFAULT mailer (MAIL_MAILER=log on prod), not the Resend-pinned one.
 *
 * The live reset path is Filament's RequestPasswordReset page, which resolves
 * Filament\Auth\Notifications\ResetPassword from the CONTAINER, sets ->url, and
 * notifies the user directly. We bind that id to PinnedResetPassword (rides
 * Resend). These tests lock both halves:
 *   1. the container resolves the pinned subclass (so Filament uses it)
 *   2. the pinned notification renders its mail on the deliverable mailer
 *
 * DB-free. Filament's notification builds its link from a plain ->url property
 * (no named-route lookup), so toMail() renders fine in a bare unit test.
 */
class PasswordResetMailerPinTest extends TestCase
{
    private function pinResendConfig(): void
    {
        config(['mail.cap_warning.mailer' => 'resend']);
        config(['mail.mailers.resend.transport' => 'resend']);
        config(['services.resend.key' => 'test-key']);
        config(['resend.api_key' => 'test-key']);
    }

    public function test_container_resolves_filament_reset_notification_as_pinned(): void
    {
        // Filament constructs: app(ResetPasswordNotification::class, ['token'=>$t]).
        // Our AppServiceProvider binding must return the pinned subclass.
        $resolved = app(FilamentResetPassword::class, ['token' => 'tok_123']);

        $this->assertInstanceOf(PinnedResetPassword::class, $resolved);
    }

    public function test_pinned_notification_mail_rides_the_deliverable_mailer(): void
    {
        $this->pinResendConfig();

        $notification = app(FilamentResetPassword::class, ['token' => 'tok_123']);
        // Filament sets the URL on the notification before notifying; mimic that
        // so toMail() doesn't need any named route.
        $notification->url = 'https://smt.eiaawsolutions.com/agency/password-reset/reset?token=tok_123';

        $mail = $notification->toMail((object) ['email' => 'thebearhugcafe@example.com']);

        $this->assertSame('resend', $mail->mailer);
        $this->assertNotSame('log', $mail->mailer);
    }

    public function test_welcome_mailer_is_a_real_delivering_transport(): void
    {
        $this->pinResendConfig();

        $this->assertSame('resend', MailTransport::welcomeMailer());
        $this->assertFalse(MailTransport::isNonDelivering(MailTransport::welcomeMailer()));
    }
}
