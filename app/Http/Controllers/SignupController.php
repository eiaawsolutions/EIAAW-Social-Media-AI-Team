<?php

namespace App\Http\Controllers;

use App\Services\StripePriceCache;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Public signup funnel — plan-first, then a lightweight details capture,
 * then Stripe Checkout. Mirrors the Sales-marketing-agent flow.
 *
 *   /signup           → tier picker (3 plans)
 *   /signup/{plan}    → renders the name+email+workspace_name form
 *   POST /billing/checkout/{plan}  → BillingController::checkout (Stripe redirect)
 *   /billing/success?session_id    → BillingController::success (provisions account)
 *
 * No DB writes happen here. The user record is created only after
 * Stripe Checkout completes (success URL handler).
 */
class SignupController extends Controller
{
    public const ALLOWED_PLANS = ['solo', 'studio', 'agency'];

    public function picker(Request $request): View
    {
        return view('signup.picker', [
            'tiers' => $this->tiers(),
            'canceled' => $request->boolean('canceled'),
        ]);
    }

    public function selectPlan(Request $request, string $plan): View|RedirectResponse
    {
        if (! in_array($plan, self::ALLOWED_PLANS, true)) {
            return redirect()->route('signup.picker')
                ->with('error', 'That plan does not exist. Please choose one below.');
        }

        // Logged-in users hit /agency directly — they're not signing up,
        // they're navigating. The trial-guard middleware decides what to
        // render once they arrive.
        if ($request->user()) {
            return redirect('/agency');
        }

        $plans = config('billing.plans', []);
        $planConfig = $plans[$plan] ?? null;
        if (! $planConfig) {
            return redirect()->route('signup.picker')
                ->with('error', 'That plan is not available right now.');
        }

        return view('signup.details', [
            'plan' => array_merge(['key' => $plan], $planConfig),
        ]);
    }

    /**
     * Tier card data shown on the picker page. SINGLE SOURCE OF TRUTH is
     * config/billing.php — prices, caps, plan names all derive from there.
     * The landing page uses the same shape via the public static helper
     * tiersFromConfig() below so we cannot drift between the two surfaces.
     *
     * The non-config bits (sales copy: best-for, highlight flag) stay here
     * because they're marketing decisions, not billing data — but they're
     * keyed by plan slug so adding a new tier in config
     * doesn't silently break the picker if no copy is supplied (we skip
     * unknown tiers).
     */
    private function tiers(): array
    {
        return self::tiersFromConfig();
    }

    /**
     * Tiers shown on the public pricing surfaces (landing + signup picker).
     * These are the self-serve checkout plans PLUS the Enterprise "Talk to us"
     * tier. Distinct from ALLOWED_PLANS (which gates self-serve CHECKOUT only) —
     * Enterprise is displayed but routes to /enterprise, never to /signup.
     *
     * @var list<string>
     */
    public const DISPLAY_PLANS = ['solo', 'studio', 'agency', 'enterprise'];

    /**
     * Static so the landing page Blade can call it without a controller
     * instance. Returns a list of tier cards ready to render: name + MYR
     * price + caps (brands, posts/mo) + marketing copy + annual savings.
     *
     * For a contact tier (Enterprise: is_contact=true) the price/caps become
     * "Custom" strings and `is_contact=true` so the Blade renders a "Talk to us"
     * CTA → /enterprise instead of a price + Subscribe button.
     *
     * @return array<int, array{
     *   key:string, name:string, is_contact:bool,
     *   price:string, unit:string, price_myr:int,
     *   annual_myr:int, annual_savings_myr:int,
     *   brands:string, posts:string, videos:string,
     *   best:string, highlight?:bool,
     * }>
     */
    public static function tiersFromConfig(): array
    {
        // Marketing copy per plan slug. Plans without copy here are skipped
        // (eg. eiaaw_internal is never shown on signup).
        $copy = [
            'solo' => [
                'best' => 'For founders running their own brand.',
            ],
            'studio' => [
                'best' => 'For freelancers and small studios.',
            ],
            'agency' => [
                'best' => 'For agencies with per-client guardrail isolation across every brand.',
                'highlight' => true,
            ],
            'enterprise' => [
                'best' => 'For Enterprise levels.',
            ],
        ];

        $tiers = [];
        $plans = (array) config('billing.plans', []);

        foreach (self::DISPLAY_PLANS as $key) {
            $plan = $plans[$key] ?? null;
            $cfg = $copy[$key] ?? null;
            if (! $plan || ! $cfg) continue;

            // Contact tier (Enterprise): bespoke price + caps, "Talk to us" CTA.
            if (! empty($plan['is_contact'])) {
                $tiers[] = self::contactTier($key, $plan, $cfg);
                continue;
            }

            $brands = (int) ($plan['caps']['max_brands'] ?? 0);
            // Image-post allowance drives the card copy; fall back to the total
            // publish ceiling for older configs without the split key.
            $imagePosts = (int) ($plan['caps']['max_ai_image_posts_per_month']
                ?? $plan['caps']['max_published_posts_per_month'] ?? 0);
            $videos = (int) ($plan['caps']['max_ai_videos_per_month'] ?? 0);
            $platforms = (array) ($plan['platforms'] ?? []);

            // Human-readable platform list with correct brand casing.
            $platformCasing = [
                'linkedin' => 'LinkedIn', 'tiktok' => 'TikTok', 'youtube' => 'YouTube',
                'facebook' => 'Facebook', 'instagram' => 'Instagram', 'threads' => 'Threads',
            ];
            $platformLabels = array_map(
                static fn (string $p): string => $platformCasing[$p] ?? ucfirst($p),
                $platforms,
            );

            $tiers[] = [
                'key' => $key,
                'name' => (string) ($plan['name'] ?? ucfirst($key)),
                'is_contact' => false,
                'price' => 'RM ' . number_format((int) ($plan['price_myr'] ?? 0)),
                'unit' => '/ month',
                'price_myr' => (int) ($plan['price_myr'] ?? 0),
                'annual_myr' => StripePriceCache::annualMyr($plan),
                'annual_savings_myr' => StripePriceCache::annualSavingsMyr($plan),
                'brands' => $brands . ' brand' . ($brands === 1 ? '' : 's'),
                'posts' => number_format($imagePosts) . ' AI image posts/mo',
                'videos' => $videos . ' AI 15-sec video posts/mo',
                'platforms' => $platformLabels,
                'platforms_label' => $platformLabels === []
                    ? ''
                    : (count($platformLabels) . ' platforms: ' . implode(', ', $platformLabels)),
                'best' => (string) $cfg['best'],
                'highlight' => (bool) ($cfg['highlight'] ?? false),
                'cta_label' => 'Subscribe now',
                'cta_url' => url('/signup/' . $key),
            ];
        }

        return $tiers;
    }

    /**
     * Build the Enterprise "Talk to us" card. No price, no caps numbers — those
     * are bespoke — and the CTA routes to /enterprise (the enquiry form), never
     * to /signup/enterprise (which would 404 the picker since enterprise is not
     * in ALLOWED_PLANS).
     *
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $cfg
     * @return array<string,mixed>
     */
    private static function contactTier(string $key, array $plan, array $cfg): array
    {
        $platforms = (array) ($plan['platforms'] ?? []);
        $platformCasing = [
            'linkedin' => 'LinkedIn', 'tiktok' => 'TikTok', 'youtube' => 'YouTube',
            'facebook' => 'Facebook', 'instagram' => 'Instagram', 'threads' => 'Threads',
        ];
        $platformLabels = array_map(
            static fn (string $p): string => $platformCasing[$p] ?? ucfirst($p),
            $platforms,
        );

        return [
            'key' => $key,
            'name' => (string) ($plan['name'] ?? ucfirst($key)),
            'is_contact' => true,
            'price' => 'Custom',
            'unit' => '',
            'price_myr' => 0,
            'annual_myr' => 0,
            'annual_savings_myr' => 0,
            'brands' => 'Custom brands',
            'posts' => 'Custom AI image posts/mo',
            'videos' => 'Custom AI 15-sec video posts/mo',
            'platforms' => $platformLabels,
            'platforms_label' => $platformLabels === []
                ? ''
                : (count($platformLabels) . ' platforms: ' . implode(', ', $platformLabels)),
            'best' => (string) $cfg['best'],
            'highlight' => (bool) ($cfg['highlight'] ?? false),
            'cta_label' => 'Talk to us',
            'cta_url' => url('/enterprise'),
        ];
    }
}
