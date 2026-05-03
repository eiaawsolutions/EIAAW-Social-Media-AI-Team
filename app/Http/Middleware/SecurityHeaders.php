<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Defense-in-depth response headers.
 *
 * Closes the easy-points pentest findings:
 *  - X-Content-Type-Options: nosniff      (MIME confusion)
 *  - X-Frame-Options: SAMEORIGIN          (clickjacking)
 *  - Referrer-Policy: strict-origin...    (referrer leakage)
 *  - Permissions-Policy                   (feature isolation)
 *  - Strict-Transport-Security (prod)     (HTTPS pinning)
 *  - Content-Security-Policy (report-only first; relaxed for Filament + Vite)
 *
 * The CSP is intentionally permissive at first (report-only) so Filament's
 * inline-styled Livewire components keep working. Once the report endpoint
 * shows zero violations on the agency panel, flip CSP to enforcing mode.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Cheap MIME / clickjacking / referrer guards — no compatibility risk.
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');

        // Disable powerful browser features by default; opt back in on the
        // surfaces that actually need them (none today).
        $response->headers->set(
            'Permissions-Policy',
            'camera=(), microphone=(), geolocation=(), payment=(), usb=(), magnetometer=(), gyroscope=(), accelerometer=()'
        );

        // HSTS only over HTTPS, only outside debug mode. Avoid pinning the
        // browser to HTTPS on local dev (http://localhost) — it strands you
        // when LARAGON serves http.
        if ($request->isSecure() && ! config('app.debug')) {
            $response->headers->set(
                'Strict-Transport-Security',
                'max-age=31536000; includeSubDomains; preload'
            );
        }

        // CSP — start report-only on a fresh deploy, flip to enforcing once
        // /csp-report shows zero (or fully-understood) violations. The
        // header name is config-driven so the flip is one env var, not a
        // code change. Both directives end with `report-uri` (legacy) and
        // `report-to` (modern Reporting API, which also requires the
        // `Report-To` header below).
        if (! $response->headers->has('Content-Security-Policy')
            && ! $response->headers->has('Content-Security-Policy-Report-Only')
        ) {
            $reportUri = '/csp-report';
            $reportTo  = 'csp-endpoint';

            // Policy budget — keep narrow. 'unsafe-inline' + 'unsafe-eval' on
            // script-src is required by Livewire/Alpine and by Stripe.js;
            // dropping them would need a nonce-per-request migration that
            // Filament 5 doesn't support yet. Everything else is tight.
            // Add a directive ONLY when tested — broad allow-lists undo the
            // value of CSP. Validate any change against `storage/logs/csp.log`
            // for at least 1 week before enforcing.
            $policy = "default-src 'self'; "
                . "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://js.stripe.com https://checkout.stripe.com; "
                . "script-src-attr 'unsafe-inline'; "
                . "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
                . "style-src-attr 'unsafe-inline'; "
                . "font-src 'self' data: https://fonts.gstatic.com; "
                . "img-src 'self' data: blob: https:; "
                . "media-src 'self' blob: https:; "
                . "worker-src 'self' blob:; "
                . "manifest-src 'self'; "
                . "frame-src 'self' https://js.stripe.com https://checkout.stripe.com https://hooks.stripe.com; "
                . "connect-src 'self' https://api.stripe.com https://*.eiaawsolutions.com wss: ws:; "
                . "frame-ancestors 'self'; "
                . "base-uri 'self'; "
                . "object-src 'none'; "
                . "form-action 'self' https://checkout.stripe.com; "
                . "upgrade-insecure-requests; "
                . "report-uri {$reportUri}; "
                . "report-to {$reportTo};";

            // Enforce vs report-only: flip via CSP_ENFORCE=true once the
            // /csp-report stream is clean for ~1 week on prod.
            $headerName = (bool) env('CSP_ENFORCE', false)
                ? 'Content-Security-Policy'
                : 'Content-Security-Policy-Report-Only';
            $response->headers->set($headerName, $policy);

            // Reporting API — group named in `report-to` directive above.
            // Browsers use this in preference to `report-uri` when they
            // support the Reporting API (Chromium ≥ 96, Firefox 124+).
            $response->headers->set(
                'Report-To',
                json_encode([
                    'group'     => $reportTo,
                    'max_age'   => 10886400, // 18 weeks
                    'endpoints' => [['url' => url($reportUri)]],
                ], JSON_UNESCAPED_SLASHES)
            );
            $response->headers->set('Reporting-Endpoints', "{$reportTo}=\"".url($reportUri).'"');
        }

        // Strip the version-disclosure server header if it slipped through
        // upstream proxies. Cheap and one of the first things scanners flag.
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('Server');

        return $response;
    }
}
