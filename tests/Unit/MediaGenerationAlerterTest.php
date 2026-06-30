<?php

namespace Tests\Unit;

use App\Mail\MediaGenerationFailed;
use App\Models\Brand;
use App\Models\Draft;
use App\Services\Imagery\MediaGenerationAlerter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Locks the behaviour of the media-generation failure alerter — the immediate
 * admin email that turns a silent FAL lockout (which once broke media gen for
 * ~3 weeks unnoticed) into a reason + action-required alert.
 *
 * DB-free: Brand/Draft are unsaved in-memory models (the alerter only reads
 * scalar attributes), Mail is faked, Cache uses the array store. No rows are
 * written (local .env DB == prod — tests never touch it).
 */
class MediaGenerationAlerterTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Cache::flush(); // isolate the throttle buckets per test
        config()->set('media.alerts.throttle_minutes', 30);
        config()->set('media.alerts.recipient', 'admin@example.test');
        config()->set('media.alerts.mailer', 'array');
    }

    private function brand(): Brand
    {
        $b = new Brand();
        $b->name = 'Acme Co';

        return $b;
    }

    private function draft(int $id = 123, string $platform = 'instagram'): Draft
    {
        $d = new Draft();
        $d->id = $id;
        $d->platform = $platform;

        return $d;
    }

    public function test_account_lockout_sends_an_immediate_alert_with_topup_action(): void
    {
        app(MediaGenerationAlerter::class)->accountLocked('image', $this->brand(), $this->draft());

        Mail::assertQueued(MediaGenerationFailed::class, function (MediaGenerationFailed $m) {
            return $m->reason === MediaGenerationAlerter::REASON_ACCOUNT_LOCKED
                && $m->mediaKind === 'image'
                && str_contains($m->actionText, 'fal.ai/dashboard/billing') // top-up action
                && str_contains($m->reasonText, 'balance exhausted');        // reason named
        });
    }

    public function test_generation_failure_sends_an_investigate_action_not_topup(): void
    {
        app(MediaGenerationAlerter::class)->generationFailed('video', $this->brand(), $this->draft(456, 'tiktok'));

        Mail::assertQueued(MediaGenerationFailed::class, function (MediaGenerationFailed $m) {
            return $m->reason === MediaGenerationAlerter::REASON_GENERATION_FAILED
                && $m->mediaKind === 'video'
                && str_contains($m->actionText, 'drafts:regenerate-image 456 --video') // exact recovery cmd
                && ! str_contains($m->reasonText, 'balance exhausted');
        });
    }

    public function test_second_lockout_in_window_is_throttled_to_one_email(): void
    {
        $alerter = app(MediaGenerationAlerter::class);
        $alerter->accountLocked('image', $this->brand(), $this->draft(1));
        $alerter->accountLocked('image', $this->brand(), $this->draft(2));
        $alerter->accountLocked('image', $this->brand(), $this->draft(3));

        // A backlog run hammering the same lockout must NOT send three emails.
        Mail::assertQueued(MediaGenerationFailed::class, 1);
    }

    public function test_suppressed_count_is_reported_on_the_next_allowed_alert(): void
    {
        $alerter = app(MediaGenerationAlerter::class);
        // 1 sent + 2 suppressed within the window...
        $alerter->accountLocked('image', $this->brand(), $this->draft(1));
        $alerter->accountLocked('image', $this->brand(), $this->draft(2));
        $alerter->accountLocked('image', $this->brand(), $this->draft(3));

        // ...window elapses, next failure is allowed and must report the 2 suppressed.
        $this->travel(31)->minutes();
        $alerter->accountLocked('image', $this->brand(), $this->draft(4));

        Mail::assertQueued(MediaGenerationFailed::class, fn (MediaGenerationFailed $m) => $m->suppressedCount === 2);
        Mail::assertQueued(MediaGenerationFailed::class, 2); // first + post-window
    }

    public function test_lockout_and_generic_have_independent_buckets(): void
    {
        $alerter = app(MediaGenerationAlerter::class);
        // Different reason-classes must not throttle each other — both alert.
        $alerter->accountLocked('image', $this->brand(), $this->draft(1));
        $alerter->generationFailed('image', $this->brand(), $this->draft(2));

        Mail::assertQueued(MediaGenerationFailed::class, 2);
    }

    public function test_alerting_never_throws_even_if_mail_fails(): void
    {
        // The alerter must be a safe side-channel: a mail/cache failure cannot
        // break the agent's own fallback path. Force the mailer to blow up.
        Mail::shouldReceive('mailer')->andThrow(new \RuntimeException('resend down'));

        $this->expectNotToPerformAssertions();
        app(MediaGenerationAlerter::class)->accountLocked('image', $this->brand(), $this->draft());
        // No exception escaping == pass.
    }
}
