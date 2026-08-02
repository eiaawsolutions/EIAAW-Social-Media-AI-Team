<?php

namespace Tests\Unit;

use App\Services\Inbox\InboxReadiness;
use Tests\TestCase;

/**
 * Pure tests for the "why is this inbox empty, and what must a human DO" classifier.
 *
 * Fixtures are the real prod states measured 2026-08-02. The whole point is that
 * `missingScopes` alone gives the WRONG instruction in both live cases, so each
 * test asserts the remedy, not just the state.
 */
class InboxReadinessTest extends TestCase
{
    private const ALL_NETWORKS = ['facebookData', 'instagramData', 'threadsData', 'linkedinData', 'tiktokData', 'youtubeData'];

    public function test_personal_tiktok_is_an_account_type_problem_not_a_scope_problem(): void
    {
        // EIAAW + Orblly, both PERSONAL, both reporting comment scopes missing.
        // Bear Hug on BUSINESS reports missingScopes:[] through the SAME token
        // and OAuth app — so re-consent cannot be the remedy.
        $r = InboxReadiness::classify(
            'TIKTOKBUSINESS',
            self::ALL_NETWORKS,
            ['tiktokAccountType' => 'PERSONAL', 'tiktokBusinessTokenExpiration' => null],
            ['comment.list', 'comment.list.manage'],
        );

        $this->assertSame(InboxReadiness::WRONG_ACCOUNT_TYPE, $r['state']);
        $this->assertStringContainsString('Switch it in the TikTok app', $r['remedy']);
        $this->assertStringContainsString('will NOT help', $r['remedy'], 'must warn that reconnecting is futile');
    }

    public function test_business_tiktok_with_scopes_is_ready(): void
    {
        // Bear Hug — the control. 5 comments actually flowing.
        $r = InboxReadiness::classify(
            'TIKTOKBUSINESS',
            self::ALL_NETWORKS,
            ['tiktokAccountType' => 'BUSINESS', 'tiktokBusinessTokenExpiration' => '26156830'],
            [],
        );

        $this->assertSame(InboxReadiness::READY, $r['state']);
        $this->assertFalse(InboxReadiness::needsAction($r['state']));
    }

    public function test_absent_connection_is_not_reported_as_missing_scopes(): void
    {
        // Orblly: networksData is [tiktokData] only, instagram NULL — yet
        // /authorizations returns a missingScopes list identical in shape to an
        // under-scoped connection. Telling the operator to "grant permissions"
        // sends them hunting for a screen that does not exist.
        $r = InboxReadiness::classify(
            'INSTAGRAMBUSINESS',
            ['tiktokData'],
            ['instagramConnectionType' => null, 'instagram' => null],
            ['instagram_business_manage_messages', 'instagram_business_manage_comments'],
            false,
        );

        $this->assertSame(InboxReadiness::NOT_CONNECTED, $r['state']);
        $this->assertStringContainsString('No Instagram account is connected', $r['remedy']);
        // Must not hand out the MISSING_SCOPES instruction — sending someone to
        // a consent screen for an account that was never connected wastes their
        // time on a screen that does not exist for them.
        $this->assertStringNotContainsString('accept ALL permissions', $r['remedy']);
        $this->assertStringContainsString('Connect one', $r['remedy']);
    }

    public function test_connected_right_type_but_under_consented_is_a_scope_problem(): void
    {
        $r = InboxReadiness::classify(
            'INSTAGRAMBUSINESS',
            self::ALL_NETWORKS,
            ['instagramConnectionType' => 'BUSINESS'],
            ['instagram_business_manage_messages'],
            false,
        );

        $this->assertSame(InboxReadiness::MISSING_SCOPES, $r['state']);
        $this->assertStringContainsString('accept ALL permissions', $r['remedy']);
    }

    public function test_account_type_outranks_scopes(): void
    {
        // Both signals present. The type problem must win, because fixing scopes
        // first is wasted effort.
        $r = InboxReadiness::classify(
            'TIKTOKBUSINESS',
            self::ALL_NETWORKS,
            ['tiktokAccountType' => 'PERSONAL'],
            ['comment.list'],
        );

        $this->assertSame(InboxReadiness::WRONG_ACCOUNT_TYPE, $r['state']);
    }

    public function test_not_connected_outranks_everything(): void
    {
        $r = InboxReadiness::classify(
            'TIKTOKBUSINESS',
            [],
            ['tiktokAccountType' => 'PERSONAL'],
            ['comment.list'],
        );

        $this->assertSame(InboxReadiness::NOT_CONNECTED, $r['state']);
    }

    public function test_clean_connection_needs_no_action(): void
    {
        $r = InboxReadiness::classify('INSTAGRAM', self::ALL_NETWORKS, ['instagramConnectionType' => 'BUSINESS'], []);

        $this->assertSame(InboxReadiness::READY, $r['state']);
        $this->assertSame('', $r['remedy']);
    }

    public function test_facebook_login_instagram_is_not_penalised(): void
    {
        // Bear Hug is FACEBOOK_LOGIN and measured CLEAN (allowAccessToMessages
        // true, 25 DMs). An earlier analysis claimed that connection type
        // structurally cannot carry message scopes; direct measurement refuted
        // it, so the classifier must not encode that claim.
        $r = InboxReadiness::classify(
            'INSTAGRAM',
            self::ALL_NETWORKS,
            ['instagramConnectionType' => 'FACEBOOK_LOGIN'],
            [],
            true,
        );

        $this->assertSame(InboxReadiness::READY, $r['state']);
    }

    public function test_unknown_account_type_does_not_block(): void
    {
        // A missing/unreadable field must not manufacture a WRONG_ACCOUNT_TYPE
        // verdict — that would send an operator to change something that is
        // already correct.
        $r = InboxReadiness::classify('TIKTOKBUSINESS', self::ALL_NETWORKS, [], []);

        $this->assertSame(InboxReadiness::READY, $r['state']);
    }
}
