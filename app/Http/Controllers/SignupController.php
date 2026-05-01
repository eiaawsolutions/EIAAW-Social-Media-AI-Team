<?php

namespace App\Http\Controllers;

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
     * Tier card data shown on the picker page. Mirrors the pricing block in
     * the landing page; keep both pointing at the same SOURCE OF TRUTH
     * (config/billing.php) when copy iteration ramps up.
     */
    private function tiers(): array
    {
        return [
            [
                'key' => 'solo',
                'name' => 'Solo',
                'price' => 'RM 99',
                'unit' => '/ month',
                'brands' => '1 brand',
                'posts' => '60 posts/mo',
                'whitelabel' => false,
                'best' => 'For founders running their own brand.',
            ],
            [
                'key' => 'studio',
                'name' => 'Studio',
                'price' => 'RM 299',
                'unit' => '/ month',
                'brands' => '3 brands',
                'posts' => '300 posts/mo',
                'whitelabel' => true,
                'best' => 'For freelancers and small studios. White-label included.',
            ],
            [
                'key' => 'agency',
                'name' => 'Agency',
                'price' => 'RM 799',
                'unit' => '/ month',
                'brands' => '12 brands',
                'posts' => 'Unlimited',
                'whitelabel' => true,
                'best' => 'For agencies with full client portal + per-client guardrail isolation.',
                'highlight' => true,
            ],
        ];
    }
}
