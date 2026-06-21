<?php

namespace App\Filament\Agency\Pages;

use App\Models\Brand;
use App\Models\Workspace;
use App\Services\Metricool\MetricoolClient;
use App\Services\Metricool\MetricoolConnectionService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Metricool Setup wizard — the Metricool replacement for the Blotato handoff
 * (PlatformSetup). Unlike Blotato, this is mostly SELF-SERVE on the detection
 * side: connection state is read live from /admin/profile, so there's a real
 * "Check connection" button instead of a best-effort ping.
 *
 * Per-BRAND (Metricool is natively multi-brand): each SMT brand maps to a
 * Metricool brand via metricool_blog_id ([[metricool-multitenancy]]). The
 * wizard operates on the workspace's brand(s).
 *
 * State machine — drives off Brand::metricoolSetupState():
 *
 *   not_mapped → customer clicks "Request setup" → emails HQ to create + map a
 *                Metricool brand (operator runs brand:set-metricool-blog).
 *   mapped     → brand mapped; show "Connect your socials" with the Metricool
 *                share-link guidance, plus "I've connected — check" button.
 *   link_sent  → connect-link shared; waiting; same check button.
 *   connected  → green panel; brand is publish-ready.
 *
 * Allow-listed in EnforceTrialOrSubscription so it's reachable while the panel
 * is otherwise gated.
 */
class MetricoolSetup extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-link';
    protected static ?string $navigationLabel = 'Platform setup';
    protected static ?string $title = 'Connect your social accounts';
    protected static ?int $navigationSort = -3;
    protected string $view = 'filament.agency.pages.metricool-setup';

    public ?Workspace $workspace = null;

    /** @var array<int, array{id:int,name:string,state:string,blogId:?string,networks:array<int,string>}> */
    public array $brands = [];

    /**
     * This wizard is the Metricool setup surface. It is only the active setup
     * page when PUBLISH_PROVIDER=metricool (the default). Under the blotato
     * rollback the legacy PlatformSetup page takes over instead, so we hide
     * this one from nav and block direct access to avoid two competing
     * "Platform setup" entries pointing at different flows.
     */
    public static function publishProvider(): string
    {
        return strtolower((string) config('services.publishing.provider', 'metricool')) ?: 'metricool';
    }

    public static function canAccess(): bool
    {
        return self::publishProvider() === 'metricool';
    }

    public static function shouldRegisterNavigation(): bool
    {
        return self::canAccess();
    }

    public function mount(): void
    {
        abort_unless(self::canAccess(), 403);
        $this->refresh();
    }

    public function refresh(): void
    {
        $user = auth()->user();
        if (! $user) {
            return;
        }

        $this->workspace = $user->currentWorkspace
            ?? $user->workspaces()->first()
            ?? $user->ownedWorkspaces()->first();

        if (! $this->workspace instanceof Workspace) {
            return;
        }

        $this->brands = $this->workspace->brands()
            ->whereNull('archived_at')
            ->orderBy('id')
            ->get()
            ->map(fn (Brand $b) => [
                'id' => $b->id,
                'name' => $b->name,
                'state' => $b->metricoolSetupState(),
                'blogId' => $b->metricool_blog_id,
                // Dedupe by platform: a brand can hold more than one active
                // connection per network (e.g. a personal profile + a business
                // page on the same platform). The wizard shows one chip per
                // network — individual accounts are managed on the Platforms
                // page — so collapse duplicates here.
                'networks' => $b->platformConnections()
                    ->where('status', 'active')
                    ->pluck('platform')
                    ->unique()
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    /**
     * Customer requests Metricool setup for a brand that isn't mapped yet.
     * Emails HQ to create + map the Metricool brand. Idempotent.
     */
    public function requestSetup(int $brandId): void
    {
        $brand = $this->ownedBrand($brandId);
        if (! $brand || $brand->metricoolSetupState() !== 'not_mapped') {
            return;
        }

        try {
            // Pin to the Resend-backed support_enquiry mailer (same rationale
            // as cap_warning): this HQ notification is the trigger for the whole
            // connect-link provisioning chain — if it silently lands in `log`
            // (a per-env MAIL_MAILER override) the customer waits forever. Pin
            // the transport + recipient so it's immune to the default mailer.
            $hqMailer = (string) config('mail.support_enquiry.mailer', 'resend');
            $hqTo = (string) (config('mail.support_enquiry.to') ?: 'eiaawsolutions@gmail.com');
            $operatorEmail = (string) (config('mail.support_enquiry.from_address') ?: 'noreply@eiaawsolutions.com');
            $ws = $this->workspace;
            $body = sprintf(
                "Brand #%d (%s) in workspace #%d (%s) requested Metricool setup.\n\n"
                . "Steps:\n"
                . "  1. In Metricool, create (or locate) a brand for this client; note its blogId.\n"
                . "  2. Map it:  php artisan brand:set-metricool-blog %d <blogId>\n"
                . "  3. In Metricool → Connections → Share, generate a connect-link (71h expiry) and send it to the customer "
                . "(or have them connect from their own Metricool brand).\n"
                . "  4. Mark it sent:  php artisan brand:set-metricool-blog %d --mark-link-sent\n"
                . "  5. Once the customer connects, detection is automatic via the wizard's Check button, "
                . "or run:  php artisan brand:set-metricool-blog %d --detect\n",
                $brand->id,
                $brand->name,
                $ws->id,
                $ws->slug,
                $brand->id,
                $brand->id,
                $brand->id,
            );
            Mail::mailer($hqMailer)->raw($body, function ($m) use ($operatorEmail, $hqTo, $brand) {
                $m->to($hqTo)
                  ->subject(sprintf('[SMT ops] Metricool setup request — brand#%d %s', $brand->id, $brand->name))
                  ->from($operatorEmail, 'EIAAW SMT — Provisioning bot');
            });
        } catch (\Throwable $e) {
            Log::error('MetricoolSetup: HQ notification email failed', [
                'brand_id' => $brandId,
                'error' => $e->getMessage(),
            ]);
        }

        $this->refresh();

        Notification::make()
            ->title('Setup requested — our team is on it.')
            ->body('We\'ll set up your secure space and send you a secure link to connect your social accounts, usually within 1 business day.')
            ->success()
            ->send();
    }

    /**
     * "Manage connections" handler. ALWAYS requests a FRESH connect link rather
     * than re-using a stored one (admin-driven model — Amos 2026-06-07).
     *
     * Why never re-send the stored link: Metricool connect-links are hard-capped
     * at ~71h with NO non-expiring variant (verified — the only permanent option
     * is "Shared Brands", which requires the customer to make a Metricool account
     * and was explicitly declined to keep the "no extra logins" promise). So any
     * stored link is almost always expired by the time a customer clicks; sending
     * it would land them on a dead Metricool page. Instead we notify HQ to mint a
     * fresh 71h link and send it via brand:send-metricool-link. The customer gets
     * a clear "we're on it" confirmation — never a dead button, never a stale link.
     */
    public function requestFreshLink(int $brandId): void
    {
        $brand = $this->ownedBrand($brandId);
        if (! $brand) {
            return;
        }

        $ws = $this->workspace;
        $customerEmail = (string) (optional($ws?->owner)->email ?: optional(auth()->user())->email);

        // One-click deep-link into the HQ onboarding console, focused on this
        // brand's card (its paste-box + Send button). The /admin panel is
        // super-admin-only (User::canAccessPanel), so the panel gate is the real
        // authz — the brand id is not a secret. Falls back to a plain instruction
        // if URL generation ever fails (e.g. route cache mid-deploy).
        $adminLink = null;
        try {
            $adminLink = \App\Filament\Pages\MetricoolOnboarding::getUrl(
                ['brand' => $brand->id],
                panel: 'admin',
            );
        } catch (\Throwable $e) {
            Log::warning('MetricoolSetup: could not build onboarding deep-link', ['error' => $e->getMessage()]);
        }

        // Notify HQ to mint + send a fresh link. This is the whole flow now —
        // there is no customer-facing re-send of a (stale) stored link.
        try {
            $hqMailer = (string) config('mail.support_enquiry.mailer', 'resend');
            $hqTo = (string) (config('mail.support_enquiry.to') ?: 'eiaawsolutions@gmail.com');
            $operatorEmail = (string) (config('mail.support_enquiry.from_address') ?: 'noreply@eiaawsolutions.com');
            $body = sprintf(
                "Brand #%d (%s) in workspace #%d (%s) clicked \"Manage connections\" — send them a FRESH Metricool connect link.\n\n"
                . "Customer email: %s\n\n"
                . "FASTEST (one click — no SSH):\n"
                . "  1. Open the HQ console (focused on this brand):\n"
                . "       %s\n"
                . "  2. In Metricool → open this brand → Connections → Share icon → Create link (blogId %s).\n"
                . "  3. Paste the link into the brand's box and click \"Store & send\". Done.\n\n"
                . "OR via the terminal:\n"
                . "       php artisan brand:send-metricool-link %d <newUrl>\n",
                $brand->id,
                $brand->name,
                $ws?->id,
                $ws?->slug,
                $customerEmail ?: '(no email on file)',
                $adminLink ?: '(open /admin → Platform onboarding)',
                $brand->metricool_blog_id ?: '(not mapped)',
                $brand->id,
            );
            Mail::mailer($hqMailer)->raw($body, function ($m) use ($operatorEmail, $hqTo, $brand) {
                $m->to($hqTo)
                  ->subject(sprintf('[SMT ops] Fresh connect-link request — brand#%d %s', $brand->id, $brand->name))
                  ->from($operatorEmail, 'EIAAW SMT — Provisioning bot');
            });
        } catch (\Throwable $e) {
            Log::error('MetricoolSetup: HQ fresh-link notification failed', [
                'brand_id' => $brandId,
                'error' => $e->getMessage(),
            ]);
        }

        Notification::make()
            ->title('We\'re on it')
            ->body('We\'ll send a fresh link to manage your social accounts to ' . ($customerEmail ?: 'your email')
                . ' shortly — usually within 1 business day. Need it sooner? Email eiaawsolutions@gmail.com.')
            ->success()
            ->send();
    }

    /**
     * Customer clicks "I've connected my accounts — check now". Reads the live
     * Metricool profile, mirrors connected networks into platform_connections,
     * and flips the brand to 'connected' on first success.
     */
    public function checkConnection(int $brandId): void
    {
        $brand = $this->ownedBrand($brandId);
        if (! $brand) {
            return;
        }
        if (empty($brand->metricool_blog_id)) {
            Notification::make()
                ->title('Not set up yet')
                ->body('Our team hasn\'t set up your secure space yet. Once we do, you\'ll get a link to connect your accounts.')
                ->warning()
                ->send();
            return;
        }

        $client = MetricoolClient::fromConfig();
        if ($client === null) {
            Notification::make()
                ->title('Connection check unavailable')
                ->body('Our publishing integration isn\'t configured right now. Email eiaawsolutions@gmail.com and we\'ll sort it.')
                ->danger()
                ->send();
            return;
        }

        try {
            $result = (new MetricoolConnectionService($client))->sync($brand);
        } catch (\Throwable $e) {
            Log::error('MetricoolSetup: connection sync failed', [
                'brand_id' => $brandId,
                'error' => $e->getMessage(),
            ]);
            Notification::make()
                ->title('Couldn\'t check right now')
                ->body('Please try again in a moment. If it keeps failing, email eiaawsolutions@gmail.com.')
                ->danger()
                ->send();
            return;
        }

        if (empty($result['networks'])) {
            Notification::make()
                ->title('No connected accounts found yet')
                ->body('If you just connected, give it a minute and check again. Make sure you finished the connection in the link we sent you.')
                ->warning()
                ->send();
            $this->refresh();
            return;
        }

        if ($brand->metricool_connected_at === null) {
            $brand->forceFill(['metricool_connected_at' => now()])->save();
        }

        $this->refresh();

        Notification::make()
            ->title('Connected! 🎉')
            ->body(count($result['networks']) . ' account(s) detected: ' . implode(', ', $result['networks'])
                . '. You\'re ready to publish.')
            ->success()
            ->send();
    }

    /** Resolve a brand id to a Brand the current workspace actually owns. */
    private function ownedBrand(int $brandId): ?Brand
    {
        if (! $this->workspace instanceof Workspace) {
            return null;
        }
        return $this->workspace->brands()->whereKey($brandId)->first();
    }
}
