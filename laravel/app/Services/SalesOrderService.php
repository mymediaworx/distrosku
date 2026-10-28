<?php

namespace App\Services;

use App\Credit\CreditLimit;
use App\Delivery\SignatureService;
use App\Enums\SalesOrderStatus as S;
use App\Exceptions\ComplianceException;
use App\Exceptions\WorkflowException;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use App\Pricing\RebateLedger;
use App\Warehouse\LotService;
use Illuminate\Support\Facades\DB;

class SalesOrderService
{
    public function __construct(
        private ComplianceChecker $compliance,
        private InventoryService $inventory,
        private PostingService $posting,
    ) {}

    public static function nextNumber(string $prefix, string $modelClass): string
    {
        return Sequence::next($prefix, $modelClass);
    }

    public function recalc(SalesOrder $so): SalesOrder
    {
        $so->load('lines.product');
        foreach ($so->lines as $l) {
            $l->line_total = Money::mul($l->qty, $l->unit_price);
            if (Money::of($l->unit_cost)->isZero()) {
                $l->unit_cost = $l->product->cost;
            }
            if ($so->status !== S::Invoiced) { // 0.20.20: taxes follow the product until the invoice is posted, then they are frozen
                \App\Services\InvoiceTax::applyToLine($l);
            }
            $l->save();
        }
        $sub = Money::add(...($so->lines->pluck('line_total')->all() ?: [0]));
        $tax = Money::add(...($so->lines->pluck('tax_total')->all() ?: [0]));
        $so->subtotal = $sub;
        $so->tax_total = $tax;
        $so->total = Money::add($sub, $tax); // line_total stays qty x price; the invoice adds the taxes on top
        $so->save();

        return $so;
    }

    /** $accountCharge: null = decide from the customer's terms (COD/cash/prepaid... = paid at sale); POS/van pass their tender. */
    public function confirm(SalesOrder $so, ?bool $accountCharge = null): SalesOrder
    {
        $this->expect($so, [S::Draft]);
        if ($so->lines()->count() === 0) {
            throw new WorkflowException('Order has no lines.');
        }
        // 0.18: legacy rules (PACT, licenses, Rules Hub) + eligibility registry on the sale and ship dates.
        $reasons = $this->compliance->checkOrder($so, false);
        $elig = app(\App\Compliance\Eligibility::class)->checkOrder($so);
        if (! $so->elig_override_at) {
            foreach ($elig['block'] as $sku => $r) {
                $reasons[$sku] = array_values(array_unique(array_merge($reasons[$sku] ?? [], $r)));
            }
        }
        if ($reasons) {
            $so->update(['compliance_reasons' => $reasons]);
            if ($elig['block'] && ! $so->elig_override_at) {
                // durable: POS / van confirm inside a transaction that rolls back; the attempt must still be logged.
                \App\Audit\Audit::durable('eligibility_blocked', $so, "Confirm blocked by product eligibility on {$so->number} ({$so->channel})",
                    ['blocked' => $elig['block'], 'channel' => $so->channel, 'order' => $so->number, 'customer_id' => $so->customer_id, 'ship_to_id' => $so->ship_to_id]);
            }
            throw new ComplianceException($reasons);
        }
        $so->forceFill(['elig_warnings' => $elig['warn'] ?: null])->save();

        return DB::transaction(function () use ($so, $accountCharge) {
            $this->recalc($so);
            // 0.17.1: credit limit on charges to the account (owner/admin override on the order skips it).
            $so->refresh();
            if ($accountCharge ?? ! SignatureService::paidTerms($so->customer)) {
                app(CreditLimit::class)->check($so->customer, $so->total, $so);
            }
            foreach ($so->lines as $l) {
                $this->inventory->reserve($l->product, $so->warehouse, $l->qty);
            }
            $this->consumeOverrides($so);
            $so->update(['status' => S::Confirmed, 'confirmed_at' => now(), 'compliance_reasons' => null]);

            return $so;
        });
    }

    /** 0.19: one-time customer price overrides are used up by the first confirmed order line that carries them. */
    private function consumeOverrides(SalesOrder $so): void
    {
        foreach ($so->lines()->whereNotNull('price_override_id')->with('product')->get() as $l) {
            $o = \App\Models\CustomerPriceOverride::whereKey($l->price_override_id)->lockForUpdate()->first();
            if (! $o || $o->duration !== 'one_time') {
                continue;
            }
            if ($o->used_at) {
                throw new WorkflowException("The one-time special price on {$l->product->sku} was already used on another order. Re-enter the line to re-price it.");
            }
            $o->update(['used_at' => now(), 'used_sales_order_id' => $so->id]);
            \App\Audit\Audit::action('price_override_used', $o, "One-time special price \${$o->price} on {$l->product->sku} used by {$so->number}", ['sales_order_id' => $so->id, 'line_id' => $l->id]);
        }
    }

    /** Reduce a confirmed order line (short pick): releases the reservation difference; qty 0 removes the line. */
    public function reduceLine(SalesOrder $so, SalesOrderLine $line, mixed $newQty): void
    {
        $this->expect($so, [S::Confirmed, S::Picked]); // 0.15: Picked too (driver partial delivery)
        $new = Money::of($newQty);
        $old = Money::of($line->qty);
        if ($new->isNegative() || ! $new->isLessThan($old)) {
            throw new WorkflowException('The new quantity must be less than the ordered '.Money::s($old).'.');
        }
        DB::transaction(function () use ($so, $line, $new, $old) {
            $this->inventory->release($line->product, $so->warehouse, $old->minus($new));
            $new->isZero() ? $line->delete() : $line->update(['qty' => Money::s($new)]);
            $this->recalc($so->fresh());
        });
    }

    public function pick(SalesOrder $so): SalesOrder
    {
        $this->expect($so, [S::Confirmed]);
        $so->update(['status' => S::Picked]);

        return $so;
    }

    /** 0.20: delivering ALWAYS invoices too (one transaction): stock out, lots, then GL posting + buydowns. Returns the Invoiced order. */
    public function deliver(SalesOrder $so): SalesOrder
    {
        $this->expect($so, [S::Confirmed, S::Picked]);
        // 0.15: regulated products never leave without a signed 21+ (and account-charge) acknowledgment.
        app(SignatureService::class)->assertSigned($so);

        return DB::transaction(function () use ($so) {
            $so->load('lines.product', 'warehouse');
            foreach ($so->lines as $l) {
                $this->inventory->release($l->product, $so->warehouse, $l->qty);
                $this->inventory->move($l->product, $so->warehouse, Money::of($l->qty)->negated(), 'sale', $so, $l->unit_cost);
            }
            app(LotService::class)->consumeForOrder($so); // lot-tracked items: record which lots went to this customer
            $so->update(['status' => S::Delivered, 'delivered_at' => now()]);

            return $this->invoice($so);
        });
    }

    /** Posts the invoice. Idempotent: an order that is already Invoiced is returned unchanged (deliver() now invoices). */
    public function invoice(SalesOrder $so): SalesOrder
    {
        if ($so->status === S::Invoiced) {
            return $so;
        }
        $this->expect($so, [S::Delivered]);

        return DB::transaction(function () use ($so) {
            $this->recalc($so);
            // 0.19.1: no compliance re-check at invoice; all compliance checks run at confirm only (Michael, 2026-10-21).
            $this->posting->salesInvoice($so);
            $this->applyBuydowns($so);
            $so->update(['status' => S::Invoiced, 'invoiced_at' => now()]);

            return $so;
        });
    }

    /** 0.20: Save & confirm. Returns [order, null] when confirmed, [order, reason] when it stays a Draft. */
    public function tryConfirm(SalesOrder $so): array
    {
        try {
            return [$this->confirm($so->fresh()), null];
        } catch (ComplianceException $e) {
            return [$so->fresh(), 'Saved as draft, not confirmed. '.$e->getMessage()];
        } catch (\Throwable $e) {
            return [$so->fresh(), 'Saved as draft, not confirmed: '.$e->getMessage()];
        }
    }

    public const METHODS = ['cash' => 'Cash', 'check' => 'Check', 'card' => 'Card (manual)', 'ach' => 'ACH', 'on_account' => 'On account (no payment now)'];

    /**
     * 0.20 one-stop settle for a Delivered/Invoiced order: invoices first if needed, then records the payment.
     * on_account records no payment; it is blocked when the open balance pushes the customer over the credit limit,
     * unless an owner/admin passes an override reason (logged). Returns the Payment, or null for on account.
     */
    public function settle(SalesOrder $so, string $method, mixed $amount = null, ?string $reference = null, ?string $overrideReason = null): ?Payment
    {
        if (! array_key_exists($method, self::METHODS)) {
            throw new WorkflowException("Unknown payment method {$method}.");
        }
        $this->expect($so, [S::Delivered, S::Invoiced]);
        $reference = filled($reference) ? trim((string) $reference) : null;
        if ($method === 'check' && ! $reference) {
            throw new WorkflowException('Enter the check number.');
        }

        return DB::transaction(function () use ($so, $method, $amount, $reference, $overrideReason) {
            $so = $this->invoice($so);
            if ($method === 'on_account') {
                $this->chargeToAccount($so, $overrideReason);

                return null;
            }

            return $this->recordPayment($so, $amount ?? $so->balance(), $method, $reference);
        });
    }

    /** "On account": no payment. Blocks over the credit limit unless an owner/admin override reason is given. */
    public function chargeToAccount(SalesOrder $so, ?string $overrideReason = null): void
    {
        $credit = app(CreditLimit::class);
        $c = $so->customer;
        $l = $credit->limit($c);
        if ($l !== null && ! $so->credit_override_at) {
            // invoiced orders are already in the customer's AR balance: exposure = AR + other open orders.
            $exp = Money::of($credit->exposure($c, $so->id));
            if ($so->status !== S::Invoiced) {
                $exp = $exp->plus(Money::of($so->balance()));
            }
            if ($exp->isGreaterThan(Money::of($l))) {
                $u = auth()->user();
                if (! filled($overrideReason)) {
                    throw new \App\Credit\CreditLimitException(sprintf('On account would put %s over the credit limit: limit $%s, open balance and orders $%s. Collect payment, or an owner/admin can approve it with a credit override reason.',
                        $c->name, CreditLimit::fmt($l), CreditLimit::fmt($exp)), $l, Money::s($exp), $so->balance());
                }
                if (! $u) {
                    throw new WorkflowException('Only an owner or admin can override a credit limit.');
                }
                $credit->override($so, $u, $overrideReason); // admin-only, reason required, Activity log
            }
        }
        \App\Audit\Audit::action('order_on_account', $so, "{$so->number} left on account (\${$so->balance()} open) for {$c->name}",
            ['balance' => $so->balance(), 'credit_override' => (bool) $so->credit_override_at]);
    }

    /** 0.20 COD in one step: deliver (auto-invoices) + collect. */
    public function deliverAndCollect(SalesOrder $so, string $method, mixed $amount = null, ?string $reference = null, ?string $overrideReason = null): ?Payment
    {
        if ($method === 'check' && ! filled($reference)) {
            throw new WorkflowException('Enter the check number.');
        }

        return DB::transaction(fn () => $this->settle($this->deliver($so), $method, $amount, $reference, $overrideReason));
    }

    public function cancel(SalesOrder $so): SalesOrder
    {
        $this->expect($so, [S::Draft, S::Confirmed, S::Picked]);

        return DB::transaction(function () use ($so) {
            if ($so->status !== S::Draft) {
                foreach ($so->lines()->with('product')->get() as $l) {
                    $this->inventory->release($l->product, $so->warehouse, $l->qty);
                }
            }
            $so->update(['status' => S::Cancelled]);

            return $so;
        });
    }

    public function recordPayment(SalesOrder $so, mixed $amount, string $method = 'cash', ?string $reference = null): Payment
    {
        $so = $this->invoice($so); // 0.20: a Delivered order is invoiced first (no-op when Invoiced)
        $this->expect($so, [S::Invoiced]);
        $amt = Money::of($amount);
        if ($amt->isNegativeOrZero()) {
            throw new WorkflowException('Payment amount must be more than 0.');
        }
        if ($amt->isGreaterThan(Money::of($so->balance()))) {
            throw new WorkflowException("Payment is more than the open balance \${$so->balance()} on {$so->number}.");
        }

        return DB::transaction(function () use ($so, $amount, $method, $reference) {
            $p = Payment::create([
                'customer_id' => $so->customer_id, 'sales_order_id' => $so->id,
                'amount' => Money::s($amount), 'method' => $method, 'reference' => $reference,
                'paid_on' => now()->toDateString(),
            ]);
            $this->posting->payment($p);
            $so->update(['amount_paid' => Money::add($so->amount_paid, $amount)]);
            \App\Audit\Audit::action('payment_recorded', $so, "Payment \$".Money::s($amount)." ({$method}) on {$so->number}", ['payment_id' => $p->id, 'method' => $method, 'amount' => Money::s($amount)]);

            return $p;
        });
    }

    /** Phase 14/14.1: accrue buydowns/rebates per invoiced line and post Dr 1250 / Cr 5050 (see RebateLedger). */
    private function applyBuydowns(SalesOrder $so): void
    {
        app(RebateLedger::class)->accrue($so);
    }

    /** @param array<int, S> $allowed */
    private function expect(SalesOrder $so, array $allowed): void
    {
        if (! in_array($so->status, $allowed, true)) {
            throw new WorkflowException("Order {$so->number} is {$so->status->value}; expected ".implode('/', array_map(fn ($s) => $s->value, $allowed)).'.');
        }
    }
}
