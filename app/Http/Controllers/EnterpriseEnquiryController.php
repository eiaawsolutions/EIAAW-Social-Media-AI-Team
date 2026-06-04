<?php

namespace App\Http\Controllers;

use App\Mail\EnterpriseEnquiryReceived;
use App\Models\EnterpriseEnquiry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\View\View;

/**
 * The Enterprise tier is a "Talk to us" lead flow, NOT a self-serve checkout.
 *
 *   GET  /enterprise        → the dedicated enquiry form (tailored sales fields)
 *   POST /enterprise        → persist an EnterpriseEnquiry + notify HQ
 *
 * There is intentionally no Stripe anywhere in this controller: Enterprise has
 * no fixed price or caps (config/billing.php → caps=null), and 'enterprise' is
 * absent from SignupController::ALLOWED_PLANS so it can never reach checkout.
 * After a deal closes, an operator provisions a bespoke workspace manually.
 *
 * Lead Generation Contract (global): persist ONLY what the visitor submitted —
 * never a fabricated email/phone. Name + work email + company + message are
 * required; phone + scoping fields are optional and stored blank/null if absent.
 */
class EnterpriseEnquiryController extends Controller
{
    /** Allowed select values — server-validated so the form can't be spoofed. */
    private const COMPANY_SIZES = ['1-10', '11-50', '51-200', '201-500', '500+'];
    private const BUDGET_BANDS = ['<RM10k', 'RM10-30k', 'RM30-100k', 'RM100k+', 'not-sure'];

    public function show(): View
    {
        return view('enterprise.contact', [
            'companySizes' => self::COMPANY_SIZES,
            'budgetBands' => self::BUDGET_BANDS,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email:rfc', 'max:160'],
            'phone' => ['nullable', 'string', 'max:40'],
            'company' => ['required', 'string', 'max:160'],
            'website' => ['nullable', 'string', 'max:200'],
            'company_size' => ['nullable', 'string', 'in:' . implode(',', self::COMPANY_SIZES)],
            'brands_needed' => ['nullable', 'integer', 'min:1', 'max:100000'],
            'videos_per_month' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'budget_band' => ['nullable', 'string', 'in:' . implode(',', self::BUDGET_BANDS)],
            'message' => ['required', 'string', 'max:2000'],
        ]);

        $enquiry = EnterpriseEnquiry::create([
            'name' => trim($data['name']),
            'email' => trim($data['email']),
            'phone' => trim((string) ($data['phone'] ?? '')),
            'company' => trim($data['company']),
            'website' => trim((string) ($data['website'] ?? '')),
            'company_size' => (string) ($data['company_size'] ?? ''),
            'brands_needed' => $data['brands_needed'] ?? null,
            'videos_per_month' => $data['videos_per_month'] ?? null,
            'budget_band' => (string) ($data['budget_band'] ?? ''),
            'message' => trim($data['message']),
            'ip_hash' => hash('sha256', (string) $request->ip()),
            'user_agent' => substr((string) $request->userAgent(), 0, 255),
            'referer' => substr((string) $request->headers->get('referer', ''), 0, 255),
            'status' => 'new',
        ]);

        // Notify HQ. Soft-fail: the lead is already persisted, so a mail outage
        // must not lose it or 500 the visitor — log and still show success. Reuse
        // the support_enquiry mailer pin so we use the same verified transport.
        try {
            $to = (string) config('mail.support_enquiry.to', 'eiaawsolutions@gmail.com');
            $mailer = (string) config('mail.support_enquiry.mailer', config('mail.default'));
            Mail::mailer($mailer)->to($to)->send(new EnterpriseEnquiryReceived($enquiry));
        } catch (\Throwable $e) {
            Log::error('EnterpriseEnquiryController: notification email failed', [
                'enquiry_id' => $enquiry->id,
                'error' => $e->getMessage(),
            ]);
        }

        return redirect()
            ->route('enterprise.contact')
            ->with('enterprise_sent', true);
    }
}
