<?php

namespace App\Services;

use App\Enums\SalesOrderStatus;
use App\Exceptions\WorkflowException;
use App\Models\Customer;
use App\Models\Journal;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use App\Models\SalesOrder;
use App\Payments\GatewayResult;
use App\Payments\PaymentGateway;
use Illuminate\Support\Facades\DB;

/** Card / ACH payments through the bound gateway, posted via PostingService. */
class PaymentService
{
    public function __construct(private PaymentGateway $gateway, private SalesOrderService $orders, private PostingService $posting) {}

    public function gateway(): PaymentGateway
    {
        return $this->gateway;
    }

    private function ensureConfigured(): void
    {
        if (! $this->gateway->isConfigured()) {
            throw new WorkflowException($this->gateway->configurationProblem() ?? 'Payments not configured.');
        }
    }

    private function log(string $action, GatewayResult $r, array $ctx = []): PaymentTransaction
    {
        return PaymentTransaction::create($ctx + [
            'provider' => $this->gateway->name(), 'action' => $action, 'transaction_id' => $r->transactionId,
            'success' => $r->success, 'response_code' => $r->code, 'message' => mb_substr((string) $r->message, 0, 250),
            'user_id' => auth()->id(),
        ]);
    }

    /** Store an Accept.js token as a CIM payment profile. $label must not contain full card/account numbers. */
    public function addMethod(Customer $c, string $descriptor, string $value, string $type, string $label): PaymentMethod
    {
        $this->ensureConfigured();
        $type = $type === 'ach' ? 'ach' : 'card';
        $label = preg_replace('/\d{5,}/', '••', $label); // defensive: never keep long digit runs
        $existing = $c->paymentMethods()->where('provider', $this->gateway->name())->value('customer_profile_id');
        $r = $this->gateway->storeToken($c, $descriptor, $value, $existing);
        $this->log('store_token', $r, ['customer_id' => $c->id]);
        if (! $r->success) {
            throw new WorkflowException("Could not save {$type}: {$r->message}");
        }

        return $c->paymentMethods()->create([
            'provider' => $this->gateway->name(), 'type' => $type, 'label' => $label,
            'customer_profile_id' => $r->extra['customer_profile_id'], 'payment_profile_id' => $r->extra['payment_profile_id'],
            'is_default' => ! $c->paymentMethods()->where('active', true)->exists(),
        ]);
    }

    public function chargeInvoice(SalesOrder $so, PaymentMethod $pm, mixed $amount = null): Payment
    {
        $this->ensureConfigured();
        if ($so->status !== SalesOrderStatus::Invoiced) {
            throw new WorkflowException("Order {$so->number} is not invoiced.");
        }
        if ((int) $pm->customer_id !== (int) $so->customer_id || ! ($pm->active ?? true)) {
            throw new WorkflowException('Payment method does not belong to this customer.');
        }
        $amount = Money::s($amount ?? $so->balance());
        if (Money::of($amount)->isNegativeOrZero() || Money::of($amount)->isGreaterThan(Money::of($so->balance()))) {
            throw new WorkflowException("Charge amount must be between 0 and the open balance {$so->balance()}.");
        }
        $r = $this->gateway->charge($pm->customer_profile_id, $pm->payment_profile_id, $amount, $so->number);
        $tx = $this->log('charge', $r, ['amount' => $amount, 'sales_order_id' => $so->id, 'customer_id' => $so->customer_id]);
        if (! $r->success) {
            throw new WorkflowException("Declined / failed: {$r->message}");
        }

        return DB::transaction(function () use ($so, $pm, $amount, $r, $tx) {
            $p = $this->orders->recordPayment($so, $amount, $pm->type, $r->transactionId);
            $p->update(['provider' => $this->gateway->name(), 'provider_transaction_id' => $r->transactionId, 'payment_method_id' => $pm->id]);
            $tx->update(['payment_id' => $p->id]);

            return $p;
        });
    }

    /** Charge a stored method against the customer's remaining beginning balance (posts like any payment). */
    public function chargeOpening(Customer $c, PaymentMethod $pm, mixed $amount): Payment
    {
        $this->ensureConfigured();
        if ((int) $pm->customer_id !== (int) $c->id || ! ($pm->active ?? true)) {
            throw new WorkflowException('Payment method does not belong to this customer.');
        }
        $balances = app(CustomerBalanceService::class);
        $amount = Money::s($amount);
        if (Money::of($amount)->isNegativeOrZero() || Money::of($amount)->isGreaterThan(Money::of($balances->openingRemaining($c)))) {
            throw new WorkflowException('Charge amount must be between 0 and the open beginning balance.');
        }
        $r = $this->gateway->charge($pm->customer_profile_id, $pm->payment_profile_id, $amount, 'BB-'.$c->code);
        $tx = $this->log('charge', $r, ['amount' => $amount, 'customer_id' => $c->id]);
        if (! $r->success) {
            throw new WorkflowException("Declined / failed: {$r->message}");
        }

        return DB::transaction(function () use ($c, $pm, $amount, $r, $tx, $balances) {
            $p = $balances->payOpening($c, $amount, $pm->type, $r->transactionId);
            $p->update(['provider' => $this->gateway->name(), 'provider_transaction_id' => $r->transactionId, 'payment_method_id' => $pm->id]);
            $tx->update(['payment_id' => $p->id]);

            return $p;
        });
    }

    /** Refund a settled gateway payment (full or partial). Posts Dr AR / Cr Cash. */
    public function refund(Payment $p, mixed $amount = null): Payment
    {
        $this->ensureConfigured();
        $this->assertRefundable($p);
        $refunded = Money::of(Payment::where('refund_of_id', $p->id)->where('status', 'posted')->sum('amount'));
        $left = Money::of($p->amount)->minus($refunded);
        $amount = Money::s($amount ?? $left);
        if (Money::of($amount)->isNegativeOrZero() || Money::of($amount)->isGreaterThan($left)) {
            throw new WorkflowException("Refund must be between 0 and {$left}.");
        }
        $pm = $p->paymentMethod;
        $r = $this->gateway->refund($pm->customer_profile_id, $pm->payment_profile_id, $p->provider_transaction_id, $amount);
        $tx = $this->log('refund', $r, ['amount' => $amount, 'sales_order_id' => $p->sales_order_id, 'customer_id' => $p->customer_id]);
        if (! $r->success) {
            throw new WorkflowException("Refund failed: {$r->message}");
        }

        return DB::transaction(function () use ($p, $amount, $r, $tx) {
            $refund = Payment::create([
                'customer_id' => $p->customer_id, 'sales_order_id' => $p->sales_order_id, 'amount' => $amount, 'method' => $p->method,
                'kind' => 'refund', 'provider' => $p->provider, 'provider_transaction_id' => $r->transactionId,
                'payment_method_id' => $p->payment_method_id, 'refund_of_id' => $p->id, 'reference' => "Refund of {$p->provider_transaction_id}",
                'paid_on' => now()->toDateString(),
            ]);
            $this->posting->post(now()->toDateString(), "Refund {$r->transactionId} of payment #{$p->id}", [
                [PostingService::AR, $amount, 0], [PostingService::CASH, 0, $amount],
            ], $refund);
            if ($so = $p->salesOrder) {
                $so->update(['amount_paid' => Money::s(Money::of($so->amount_paid)->minus(Money::of($amount)))]);
            }
            $tx->update(['payment_id' => $refund->id]);

            return $refund;
        });
    }

    /** Void an unsettled gateway payment: reverses its journal. */
    public function void(Payment $p): Payment
    {
        $this->ensureConfigured();
        $this->assertRefundable($p);
        if (Payment::where('refund_of_id', $p->id)->exists()) {
            throw new WorkflowException('Payment already has refunds; cannot void.');
        }
        $r = $this->gateway->void($p->provider_transaction_id);
        $this->log('void', $r, ['amount' => $p->amount, 'sales_order_id' => $p->sales_order_id, 'customer_id' => $p->customer_id, 'payment_id' => $p->id]);
        if (! $r->success) {
            throw new WorkflowException("Void failed: {$r->message} (if settled, refund instead)");
        }

        return DB::transaction(function () use ($p) {
            $j = Journal::where('source_type', $p->getMorphClass())->where('source_id', $p->id)->whereNull('reversal_of_id')->first();
            if ($j) {
                $this->posting->reverse($j, 'gateway void');
            }
            if ($so = $p->salesOrder) {
                $so->update(['amount_paid' => Money::s(Money::of($so->amount_paid)->minus(Money::of($p->amount)))]);
            }
            $p->update(['status' => 'voided']);

            return $p;
        });
    }

    private function assertRefundable(Payment $p): void
    {
        $p->refresh(); // DB defaults (kind/status) and concurrent changes
        if ($p->kind !== 'payment' || $p->status !== 'posted' || ! $p->provider_transaction_id || ! $p->paymentMethod) {
            throw new WorkflowException('Only posted gateway payments can be refunded or voided.');
        }
    }
}
