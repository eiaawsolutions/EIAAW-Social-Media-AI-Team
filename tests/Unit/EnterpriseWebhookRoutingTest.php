<?php

namespace Tests\Unit;

use App\Http\Controllers\StripeWebhookController;
use App\Models\Workspace;
use App\Services\Billing\SignupProvisioner;
use ReflectionMethod;
use Tests\TestCase;

/**
 * Guards the webhook routing contract for the invoice-based Enterprise tier:
 * an invoice.payment_succeeded carrying metadata.intent=enterprise must be
 * recognised as enterprise (so the caller skips syncWorkspaceFromCashier, which
 * expects a Cashier subscription row Enterprise never has), and a non-enterprise
 * invoice must NOT be claimed by the enterprise path.
 *
 * DB-free: we only exercise the two branches that take no DB write —
 *   - non-enterprise intent  → returns false (defers to subscription sync)
 *   - enterprise but unpaid  → returns true, no activation
 * The paid → activate branch writes to the DB and is covered in integration.
 */
class EnterpriseWebhookRoutingTest extends TestCase
{
    private function invoke(array $payload, Workspace $ws): bool
    {
        $controller = new StripeWebhookController(app(SignupProvisioner::class));
        $m = new ReflectionMethod(StripeWebhookController::class, 'activateEnterpriseIfInvoicePaid');
        $m->setAccessible(true);

        return (bool) $m->invoke($controller, $payload, $ws);
    }

    public function test_non_enterprise_invoice_is_not_claimed_by_enterprise_path(): void
    {
        $ws = new Workspace();
        $ws->plan = 'solo';

        $payload = ['data' => ['object' => [
            'id' => 'in_test_solo',
            'paid' => true,
            'metadata' => [],            // a normal subscription invoice
        ]]];

        // false → caller proceeds to syncWorkspaceFromCashier (the subscription path).
        $this->assertFalse($this->invoke($payload, $ws));
    }

    public function test_enterprise_invoice_not_yet_paid_is_claimed_but_does_not_activate(): void
    {
        $ws = new Workspace();
        $ws->plan = 'enterprise';
        $ws->subscription_status = 'none';

        $payload = ['data' => ['object' => [
            'id' => 'in_test_ent',
            'paid' => false,
            'status' => 'open',
            'metadata' => ['intent' => 'enterprise'],
        ]]];

        // true → it IS an enterprise invoice (caller skips the subscription path),
        // but nothing is activated yet (no DB write on the unpaid branch).
        $this->assertTrue($this->invoke($payload, $ws));
        $this->assertSame('none', $ws->subscription_status);
    }
}
