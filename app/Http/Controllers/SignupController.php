<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;

/**
 * Public signup funnel. The landing CTAs all route here, NOT directly to
 * Filament's /agency/register, because we want a plan-first flow:
 *
 *   /signup           → tier picker (Solo / Studio / Agency)
 *   /signup/{plan}    → set plan in session, redirect to /agency/register
 *
 * The chosen plan is stashed in session under 'signup.plan' and consumed by
 * App\Filament\Agency\Auth\Register inside the registration transaction —
 * the user never has a chance to register without a plan attached, even if
 * they navigate to /agency/register directly (we default to 'solo').
 */
class SignupController extends Controller
{
    public const ALLOWED_PLANS = ['solo', 'studio', 'agency'];

    public function picker(): View
    {
        return view('signup.picker', [
            'tiers' => $this->tiers(),
        ]);
    }

    public function selectPlan(Request $request, string $plan): RedirectResponse
    {
        if (! in_array($plan, self::ALLOWED_PLANS, true)) {
            return redirect()->route('signup.picker')
                ->with('error', 'That plan does not exist. Please choose one below.');
        }

        $request->session()->put('signup.plan', $plan);

        // If the user is already logged in and just wants to start a NEW
        // workspace, send them straight to the panel (the registration flow
        // is for net-new accounts only). The trial-guard middleware will
        // pick up the new workspace from there.
        if ($request->user()) {
            return redirect('/agency');
        }

        return redirect('/agency/register');
    }

    /**
     * The 3 tiers shown on the picker page. Mirrors the pricing block in
     * resources/views/landing.blade.php — keep these in sync until they're
     * extracted into a shared partial or config.
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
