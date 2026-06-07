<?php

namespace App\Filament\Pages;

use App\Models\Brand;
use App\Models\Workspace;
use App\Services\Metricool\MetricoolClient;
use App\Services\Metricool\MetricoolConnectionService;
use App\Services\Metricool\MetricoolConnectLinkSender;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Log;

/**
 * HQ-only onboarding console for the per-BRAND Metricool connect-link handoff —
 * the operator counterpart to the customer's MetricoolSetup wizard.
 *
 * This is the page you keep open next to the terminal. It does NOT replace the
 * manual steps Metricool gives no API for (creating/locating the brand in the
 * shared agency account, and minting the Connections → Share connect-link). What
 * it DOES do is remove every hand-typed, error-prone bit around them:
 *
 *   - lists customer brands that aren't connected yet, grouped by Metricool state
 *   - generates the exact `brand:set-metricool-blog` command per brand for its
 *     current state (map blogId → mark-link-sent → detect)
 *   - lets you DETECT a brand's connections from the browser instead of SSHing in
 *     (real /admin/profile read via MetricoolConnectionService, not a guess-ping)
 *
 * How this differs from the old Blotato handoff this replaces ([[metricool-multitenancy]]):
 *   - Unit is the BRAND, not the workspace. Metricool is natively multi-brand:
 *     ONE shared agency account, ONE token, N brands (each a numeric blogId).
 *   - The blogId is NOT a secret — it's an account-scoped id, a plain command
 *     argument. There is no per-unit Infisical handle (Blotato needed one per
 *     workspace). The single shared METRICOOL_API_TOKEN is the only secret and
 *     it's already wired globally via Infisical.
 *   - "Connected" is detected live from Metricool's /admin/profile, so the
 *     button below is a real detection, not a best-effort ping.
 *
 * Lives in the Admin panel (super-admin only via canAccess()).
 */
class MetricoolOnboarding extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rocket-launch';
    protected static ?string $navigationLabel = 'Platform onboarding';
    protected static ?string $title = 'Metricool onboarding';
    protected static \UnitEnum|string|null $navigationGroup = 'Operations';
    protected static ?int $navigationSort = 1;
    protected static ?string $slug = 'metricool-onboarding';
    protected string $view = 'filament.pages.metricool-onboarding';

    public const METRICOOL_APP_URL = 'https://app.metricool.com/';

    /**
     * Per-brand paste-box for the Metricool connect-link, keyed by brand id.
     * Bound from the blade so the operator pastes the link straight into the
     * brand's card and clicks Send — no SSH, no artisan, no copying ids.
     *
     * @var array<int, string>
     */
    public array $connectUrlInputs = [];

    /**
     * Brand to scroll-to / highlight, taken from ?brand=N on the URL. The HQ
     * "fresh link request" email deep-links here with the requesting brand so
     * the operator lands on the exact card to action.
     */
    public ?int $focusBrandId = null;

    public function mount(): void
    {
        $brand = (int) request()->integer('brand');
        $this->focusBrandId = $brand > 0 ? $brand : null;
    }

    public static function canAccess(): bool
    {
        return (bool) auth()->user()?->is_super_admin;
    }

    public function getSubheading(): ?string
    {
        return 'The keep-it-next-to-the-terminal checklist. Creating the brand and minting the connect-link are manual (Metricool has no API for them); every command in between is generated for you below.';
    }

    /**
     * True iff the shared Metricool account is wired (token + user id resolved).
     * Drives the "Detect now" button — without it, detection can't run.
     */
    public function metricoolConfigured(): bool
    {
        return MetricoolClient::fromConfig() !== null;
    }

    /**
     * Customer brands grouped by where they are in the Metricool handoff.
     * Recomputed on every render — these are operator counts, not hot-path
     * queries, so a live read is fine (and we WANT it fresh after a command).
     *
     * The queue is every NON-connected brand belonging to a customer-facing
     * workspace. There is no separate "requested" flag on a brand — a customer
     * brand with no blogId yet IS the work waiting on HQ. (The customer's
     * "Request setup" click only emails ops; it stamps nothing on the brand.)
     *
     * @return array{queue: array<int, array<string,mixed>>, connected_count: int}
     */
    public function getBoard(): array
    {
        // Customer-facing workspaces only — HQ/internal brands use the shared
        // account too but are connected by ops directly, not via this queue.
        $brands = Brand::query()
            ->whereNull('archived_at')
            ->whereHas('workspace', fn ($q) => $q->where('plan', '!=', 'eiaaw_internal'))
            ->with('workspace.owner')
            ->orderByRaw('metricool_blog_id IS NOT NULL')   // unmapped first (most work)
            ->orderBy('metricool_connect_link_sent_at')
            ->orderBy('id')
            ->get();

        $queue = [];
        $connected = 0;

        foreach ($brands as $brand) {
            $state = $brand->metricoolSetupState();
            if ($state === 'connected') {
                $connected++;
                continue;
            }

            $ws = $brand->workspace;
            $queue[] = [
                'brand_id'      => $brand->id,
                'brand_name'    => $brand->name,
                'workspace_id'  => $ws?->id,
                'workspace_name'=> $ws?->name,
                'plan'          => $ws?->plan,
                'owner_email'   => optional($ws?->owner)->email,
                'state'         => $state,
                'blog_id'       => $brand->metricool_blog_id,
                'link_sent_at'  => optional($brand->metricool_connect_link_sent_at)->diffForHumans(),
                'commands'      => $this->commandsFor($brand),
                'next'          => $this->nextStepFor($state),
            ];
        }

        return ['queue' => $queue, 'connected_count' => $connected];
    }

    /**
     * The exact, copy-paste-ready command(s) for a brand's current state. We
     * surface only the command relevant to the NEXT action so the operator
     * never has to think about which flag applies:
     *
     *   not_mapped → map the blogId you noted in Metricool
     *   mapped     → mark the connect-link sent (after you've shared it)
     *   link_sent  → detect connected networks (or wait for the customer's check)
     *
     * @return array<int, array{label:string, command:string}>
     */
    public function commandsFor(Brand $brand): array
    {
        $id = $brand->id;
        $state = $brand->metricoolSetupState();

        return match ($state) {
            'not_mapped' => [[
                'label'   => 'Map this brand to its Metricool blogId',
                'command' => "php artisan brand:set-metricool-blog {$id} PASTE_BLOG_ID_HERE",
            ]],
            'mapped' => [[
                'label'   => 'Record that you sent the connect-link',
                'command' => "php artisan brand:set-metricool-blog {$id} --mark-link-sent",
            ]],
            'link_sent' => [[
                'label'   => 'Detect connected networks now (or use the button)',
                'command' => "php artisan brand:set-metricool-blog {$id} --detect",
            ]],
            default => [],
        };
    }

    /** Human "what to do next" line shown under each brand card. */
    public function nextStepFor(string $state): string
    {
        return match ($state) {
            'not_mapped' => 'In Metricool, create or locate this client\'s brand and note its blogId, then run the command above to map it.',
            'mapped'     => 'In Metricool → Connections → Share, mint a connect-link (71h expiry) and send it to the customer, then run the command to mark it sent.',
            'link_sent'  => 'The customer connects their socials via the link. Press Detect now once they have — or they\'ll self-check in their own Platform Setup page.',
            default      => '',
        };
    }

    /**
     * Detect a brand's connected networks straight from the browser — the same
     * /admin/profile read the artisan --detect command and the customer's
     * "Check connection" button run. Mirrors connected networks into
     * platform_connections and stamps metricool_connected_at on first success.
     */
    public function detect(int $brandId): void
    {
        $brand = Brand::find($brandId);
        if (! $brand) {
            Notification::make()->title('Brand not found')->danger()->send();
            return;
        }

        if (empty($brand->metricool_blog_id)) {
            Notification::make()
                ->title('Not mapped yet')
                ->body('Map a blogId first — there\'s no Metricool brand to read for this client.')
                ->warning()
                ->send();
            return;
        }

        $client = MetricoolClient::fromConfig();
        if ($client === null) {
            Notification::make()
                ->title('Metricool not configured')
                ->body('The shared Metricool token/user id isn\'t resolved in this environment. Check METRICOOL_API_TOKEN (Infisical handle) + METRICOOL_USER_ID.')
                ->danger()
                ->persistent()
                ->send();
            return;
        }

        try {
            $result = (new MetricoolConnectionService($client))->sync($brand);
        } catch (\Throwable $e) {
            Notification::make()
                ->title('Detection failed')
                ->body('Couldn\'t read the Metricool profile for this brand. Check the blogId is correct for the shared account.')
                ->danger()
                ->persistent()
                ->send();
            Log::error('MetricoolOnboarding: detect failed', [
                'brand_id' => $brandId,
                'error'    => $e->getMessage(),
            ]);
            return;
        }

        if (empty($result['networks'])) {
            Notification::make()
                ->title('No connected accounts found yet')
                ->body('The brand is mapped but Metricool reports no connected networks. Has the customer finished connecting via the link?')
                ->warning()
                ->send();
            return;
        }

        if ($brand->metricool_connected_at === null) {
            $brand->forceFill(['metricool_connected_at' => now()])->save();
        }

        Notification::make()
            ->title('Connected — brand #' . $brandId . ' verified')
            ->body(sprintf(
                '%d network(s) detected: %s. metricool_connected_at stamped — the customer\'s panel is now unblocked.',
                count($result['networks']),
                implode(', ', $result['networks']),
            ))
            ->success()
            ->send();
    }

    /**
     * One-click "Store & send to customer": the operator pastes the Metricool
     * connect-link they just minted into the brand's card and clicks Send. This
     * is the whole automation win for the "fresh link request" flow — it does
     * exactly what `brand:send-metricool-link` does (store the durable link,
     * email the customer via the pinned Resend mailer, stamp link_sent) but from
     * the browser, deep-linked straight from the HQ request email. No SSH, no
     * artisan, no copying brand ids.
     *
     * Delegates to MetricoolConnectLinkSender so it shares the command's exact
     * guards (valid https, delivering transport, synchronous send, stamp only
     * after a confirmed send) — the UI can never silently diverge from the CLI.
     */
    public function sendConnectLink(int $brandId): void
    {
        $brand = Brand::find($brandId);
        if (! $brand) {
            Notification::make()->title('Brand not found')->danger()->send();
            return;
        }

        $url = trim((string) ($this->connectUrlInputs[$brandId] ?? ''));
        if ($url === '') {
            Notification::make()
                ->title('Paste the connect-link first')
                ->body('Mint it in Metricool → Connections → Share → Create link, then paste it into this brand\'s box.')
                ->warning()
                ->send();
            return;
        }

        $result = app(MetricoolConnectLinkSender::class)->send($brand, $url);

        if (! $result['ok']) {
            Notification::make()
                ->title('Couldn\'t send')
                ->body($result['message'])
                ->danger()
                ->persistent()
                ->send();
            return;
        }

        // Clear the box on success so the card visibly resets.
        $this->connectUrlInputs[$brandId] = '';

        Notification::make()
            ->title('Sent to the customer 🎉')
            ->body($result['message'] . ' Brand state: ' . ($result['state'] ?? 'link_sent') . '.')
            ->success()
            ->send();
    }
}
