<?php

namespace App\Accounting;

use App\Exceptions\WorkflowException;
use App\Models\Account;
use App\Models\BankAccount;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorPayment;
use App\Models\VendorPaymentAllocation;
use App\Services\Money;
use App\Services\PostingService as P;
use App\Services\Sequence;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Accounts payable.
 *  - PO receipt: Dr Inventory / Cr 2050 Received Not Billed (PostingService::purchaseReceipt).
 *  - Bill from PO (3-way match: PO price x received qty x billed qty): Dr 2050 at PO cost, price difference to 5100, extra lines to their accounts, Cr 2000 AP.
 *    Quantity received before phase 8 already sits in AP, so its share is cleared from 2000 instead of 2050.
 *  - Standalone bill: Dr expense/asset accounts / Cr 2000.
 *  - Payment: Dr 2000 / Cr the bank account's GL account.
 */
class ApService
{
    public function __construct(private P $posting) {}

    public static function tolerancePct(): string
    {
        return (string) (\App\Models\Setting::get('ap_price_tolerance_pct', '0.5'));
    }

    /** Draft bill pre-filled with every PO line's received-but-unbilled quantity at PO cost. */
    public function draftFromPurchaseOrder(PurchaseOrder $po, ?string $vendorInvoice = null, ?string $billDate = null, int $termsDays = 30): VendorBill
    {
        $po->load('lines.product');
        $lines = [];
        foreach ($po->lines as $l) {
            $open = Money::of($l->qty_received)->minus(Money::of($l->qty_billed));
            if ($open->isPositive()) {
                $lines[] = ['purchase_order_line_id' => $l->id, 'product_id' => $l->product_id, 'description' => "{$l->product->sku} {$l->product->name}",
                    'qty' => Money::s($open), 'unit_cost' => Money::s($l->unit_cost), 'amount' => Money::mul($open, $l->unit_cost)];
            }
        }
        if (! $lines) {
            throw new WorkflowException("PO {$po->number} has nothing received and unbilled.");
        }
        $date = $billDate ?: now()->toDateString();

        return DB::transaction(function () use ($po, $vendorInvoice, $date, $termsDays, $lines) {
            $b = VendorBill::create(['number' => Sequence::next('BILL-', VendorBill::class), 'vendor_id' => $po->vendor_id, 'purchase_order_id' => $po->id,
                'vendor_invoice' => $vendorInvoice, 'bill_date' => $date, 'due_date' => CarbonImmutable::parse($date)->addDays($termsDays)->toDateString()]);
            $b->lines()->createMany($lines);

            return $this->recalc($b);
        });
    }

    /** @param array<int, array{account_code?:string,description?:string,qty?:mixed,unit_cost?:mixed,amount?:mixed,purchase_order_line_id?:int,product_id?:int}> $lines */
    public function createStandalone(Vendor $v, array $lines, ?string $vendorInvoice, string $billDate, ?string $dueDate = null, ?string $notes = null): VendorBill
    {
        return DB::transaction(function () use ($v, $lines, $vendorInvoice, $billDate, $dueDate, $notes) {
            $b = VendorBill::create(['number' => Sequence::next('BILL-', VendorBill::class), 'vendor_id' => $v->id, 'vendor_invoice' => $vendorInvoice,
                'bill_date' => $billDate, 'due_date' => $dueDate ?: CarbonImmutable::parse($billDate)->addDays(30)->toDateString(), 'notes' => $notes]);
            foreach ($lines as $l) {
                $qty = $l['qty'] ?? 1;
                $amount = isset($l['amount']) && $l['amount'] !== '' ? $l['amount'] : Money::mul($qty, $l['unit_cost'] ?? 0);
                $b->lines()->create(['account_code' => $l['account_code'] ?? null, 'description' => $l['description'] ?? null, 'qty' => Money::s($qty),
                    'unit_cost' => Money::s($l['unit_cost'] ?? $amount), 'amount' => Money::s($amount),
                    'purchase_order_line_id' => $l['purchase_order_line_id'] ?? null, 'product_id' => $l['product_id'] ?? null]);
            }

            return $this->recalc($b);
        });
    }

    /** Recompute line amounts, total and the 3-way match result. */
    public function recalc(VendorBill $b): VendorBill
    {
        $b->load('lines.poLine');
        $total = BigDecimal::zero();
        $notes = [];
        $hasPo = false;
        $tol = Money::of(self::tolerancePct());
        foreach ($b->lines as $l) {
            if ($l->purchase_order_line_id) {
                $hasPo = true;
                $l->amount = Money::mul($l->qty, $l->unit_cost);
                $l->save();
                $po = $l->poLine;
                if (! $po) {
                    $notes[] = "Line {$l->description}: PO line no longer exists.";
                } else {
                    $open = Money::of($po->qty_received)->minus(Money::of($po->qty_billed));
                    if (Money::of($l->qty)->isGreaterThan($open)) {
                        $notes[] = "{$l->description}: billed {$l->qty} but only {$open} received and unbilled.";
                    }
                    $poCost = Money::of($po->unit_cost);
                    $diff = Money::of($l->unit_cost)->minus($poCost)->abs();
                    $limit = $poCost->multipliedBy($tol)->dividedBy(100, 4, \Brick\Math\RoundingMode::HALF_UP);
                    if ($diff->isGreaterThan($limit)) {
                        $notes[] = "{$l->description}: billed cost {$l->unit_cost} vs PO cost {$po->unit_cost} (tolerance {$tol}%).";
                    }
                }
            } elseif (! $l->account_code || ! Account::where('code', $l->account_code)->exists()) {
                $notes[] = "Line {$l->description}: choose a valid GL account.";
            }
            $total = $total->plus(Money::of($l->amount));
        }
        $b->total = Money::s($total);
        $b->match_status = $hasPo ? ($notes ? 'variance' : 'matched') : ($notes ? 'variance' : 'none');
        $b->match_notes = $notes ?: null;
        $b->save();

        return $b;
    }

    public function post(VendorBill $b, ?User $by = null, bool $approveVariance = false): VendorBill
    {
        if ($b->status !== 'draft') {
            throw new WorkflowException("Bill {$b->number} is {$b->status}; only drafts can be posted.");
        }
        $b = $this->recalc($b);
        foreach ($b->lines as $l) {
            if (! $l->purchase_order_line_id && (! $l->account_code || ! Account::where('code', $l->account_code)->exists())) {
                throw new WorkflowException("Bill {$b->number}: line '{$l->description}' needs a valid GL account.");
            }
        }
        if ($b->match_status === 'variance') {
            if (! $approveVariance) {
                throw new WorkflowException("Bill {$b->number} does not match the PO/receipt: ".implode(' ', $b->match_notes ?? []).' An admin must approve the variance.');
            }
            if (! $by?->isAdmin()) {
                throw new WorkflowException('Only an owner or admin can approve a bill variance.');
            }
        }
        if (! Money::of($b->total)->isPositive()) {
            throw new WorkflowException("Bill {$b->number} total must be positive.");
        }

        return DB::transaction(function () use ($b, $by, $approveVariance) {
            $dr = []; // account => BigDecimal (signed: + debit, - credit)
            $add = function (string $acct, BigDecimal $v) use (&$dr) {
                $dr[$acct] = ($dr[$acct] ?? BigDecimal::zero())->plus($v);
            };
            foreach ($b->lines as $l) {
                if ($l->purchase_order_line_id && ($po = PurchaseOrderLine::lockForUpdate()->find($l->purchase_order_line_id))) {
                    $qty = Money::of($l->qty);
                    $poCost = Money::of($po->unit_cost);
                    $openRecv = Money::of($po->qty_received)->minus(Money::of($po->qty_billed));
                    $matchedQty = $qty->isGreaterThan($openRecv) ? BigDecimal::max($openRecv, BigDecimal::zero()) : $qty;
                    $excessQty = $qty->minus($matchedQty);
                    $legacyOpen = BigDecimal::max(Money::of($po->qty_received_legacy)->minus(Money::of($po->qty_billed)), BigDecimal::zero());
                    $legacyQty = BigDecimal::min($matchedQty, $legacyOpen);
                    $grniQty = $matchedQty->minus($legacyQty);
                    $add(P::GRNI, $grniQty->multipliedBy($poCost));
                    $add(P::AP, $legacyQty->multipliedBy($poCost));       // pre-phase-8 receipt already credited AP: nets out
                    $add(P::PURCHASE_PRICE_VARIANCE, $excessQty->multipliedBy($poCost)); // billed beyond receipt (approved variance)
                    $add(P::PURCHASE_PRICE_VARIANCE, Money::of($l->unit_cost)->minus($poCost)->multipliedBy($qty));
                    $po->update(['qty_billed' => Money::add($po->qty_billed, $qty)]);
                } else {
                    $add($l->account_code, Money::of($l->amount));
                }
            }
            $add(P::AP, Money::of($b->total)->negated());
            $lines = [];
            foreach ($dr as $acct => $v) {
                $v = $v->toScale(4, \Brick\Math\RoundingMode::HALF_UP);
                if ($v->isZero()) {
                    continue;
                }
                $lines[] = $v->isPositive() ? [(string) $acct, (string) $v, 0] : [(string) $acct, 0, (string) $v->abs()];
            }
            // A bill that only covers pre-phase-8 receipts (already in AP) at PO cost nets to nothing: no journal needed.
            $j = $lines ? $this->posting->post($b->bill_date->toDateString(), "Vendor bill {$b->number} {$b->vendor->name} {$b->vendor_invoice}", $lines, $b) : null;
            if (! $j) {
                PeriodService::assertOpen($b->bill_date->toDateString());
            }
            $b->update(['status' => 'posted', 'journal_id' => $j?->id, 'posted_at' => now(),
                'variance_approved_by' => $b->match_status === 'variance' && $approveVariance ? $by?->id : null]);

            return $b->fresh();
        });
    }

    public function void(VendorBill $b, string $reason): VendorBill
    {
        if (Money::of($b->amount_paid)->isPositive()) {
            throw new WorkflowException("Bill {$b->number} has payments; void the payments first.");
        }
        if ($b->status === 'void') {
            throw new WorkflowException("Bill {$b->number} is already void.");
        }

        return DB::transaction(function () use ($b, $reason) {
            if ($b->status === 'posted' && $b->journal) {
                $this->posting->reverse($b->journal, "void bill: $reason");
                foreach ($b->lines as $l) {
                    if ($l->purchase_order_line_id && ($po = PurchaseOrderLine::find($l->purchase_order_line_id))) {
                        $po->update(['qty_billed' => Money::s(BigDecimal::max(Money::of($po->qty_billed)->minus(Money::of($l->qty)), BigDecimal::zero()))]);
                    }
                }
            }
            $b->update(['status' => 'void', 'notes' => trim(($b->notes ?? '')."\nVoid: $reason")]);

            return $b;
        });
    }

    /** @param array<int,mixed> $allocations bill_id => amount */
    public function pay(Vendor $v, BankAccount $bank, string $date, array $allocations, string $method = 'check', ?string $reference = null): VendorPayment
    {
        $allocations = array_filter($allocations, fn ($a) => $a !== null && $a !== '' && Money::of($a)->isPositive());
        if (! $allocations) {
            throw new WorkflowException('Allocate the payment to at least one bill.');
        }

        return DB::transaction(function () use ($v, $bank, $date, $allocations, $method, $reference) {
            $total = BigDecimal::zero();
            $bills = [];
            foreach ($allocations as $billId => $amt) {
                $b = VendorBill::lockForUpdate()->findOrFail($billId);
                if ((int) $b->vendor_id !== (int) $v->id) {
                    throw new WorkflowException("Bill {$b->number} belongs to another vendor.");
                }
                if (! in_array($b->status, ['posted', 'partial'], true)) {
                    throw new WorkflowException("Bill {$b->number} is {$b->status}; only posted bills can be paid.");
                }
                if (Money::of($amt)->isGreaterThan(Money::of($b->balance()))) {
                    throw new WorkflowException("Bill {$b->number}: paying {$amt} but only {$b->balance()} is open.");
                }
                $bills[] = [$b, $amt];
                $total = $total->plus(Money::of($amt));
            }
            $p = VendorPayment::create(['number' => Sequence::next('VPAY-', VendorPayment::class), 'vendor_id' => $v->id, 'bank_account_id' => $bank->id,
                'paid_on' => $date, 'method' => $method, 'reference' => $reference, 'amount' => Money::s($total)]);
            foreach ($bills as [$b, $amt]) {
                VendorPaymentAllocation::create(['vendor_payment_id' => $p->id, 'vendor_bill_id' => $b->id, 'amount' => Money::s($amt)]);
                $paid = Money::add($b->amount_paid, $amt);
                $b->update(['amount_paid' => $paid, 'status' => Money::of($paid)->isGreaterThanOrEqualTo(Money::of($b->total)) ? 'paid' : 'partial']);
            }
            $j = $this->posting->post($date, "Vendor payment {$p->number} {$v->name} {$method} {$reference}", [
                [P::AP, Money::s($total), 0], [$bank->account->code, 0, Money::s($total)],
            ], $p);
            $p->update(['journal_id' => $j->id]);

            return $p->fresh();
        });
    }

    public function voidPayment(VendorPayment $p, string $reason): VendorPayment
    {
        if ($p->status === 'void') {
            throw new WorkflowException("Payment {$p->number} is already void.");
        }

        return DB::transaction(function () use ($p, $reason) {
            if ($p->journal) {
                $this->posting->reverse($p->journal, "void payment: $reason");
            }
            foreach ($p->allocations as $a) {
                $b = VendorBill::lockForUpdate()->find($a->vendor_bill_id);
                $paid = Money::s(BigDecimal::max(Money::of($b->amount_paid)->minus(Money::of($a->amount)), BigDecimal::zero()));
                $b->update(['amount_paid' => $paid, 'status' => Money::of($paid)->isZero() ? 'posted' : 'partial']);
            }
            $p->update(['status' => 'void']);

            return $p;
        });
    }

    /** AP aging by due date as of a date (payments after the date are ignored). */
    public function aging(?string $asOf = null, ?int $vendorId = null): array
    {
        $asOf = CarbonImmutable::parse($asOf ?: now()->toDateString())->toDateString();
        $keys = ['current', '1-30', '31-60', '61-90', '90+', 'total'];
        $t = array_fill_keys($keys, BigDecimal::zero());
        $per = [];
        $bills = VendorBill::with('vendor')->whereIn('status', ['posted', 'partial', 'paid'])->whereDate('bill_date', '<=', $asOf)
            ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))->get();
        foreach ($bills as $b) {
            $paid = VendorPaymentAllocation::where('vendor_bill_id', $b->id)
                ->whereHas('payment', fn ($q) => $q->where('status', 'posted')->whereDate('paid_on', '<=', $asOf))->pluck('amount')->all();
            $open = Money::of($b->total)->minus(Money::of(Money::add(...($paid ?: [0]))));
            if ($open->isZero()) {
                continue;
            }
            $days = (int) $b->due_date->diffInDays(CarbonImmutable::parse($asOf), false);
            $k = $days <= 0 ? 'current' : ($days <= 30 ? '1-30' : ($days <= 60 ? '31-60' : ($days <= 90 ? '61-90' : '90+')));
            $per[$b->vendor_id] ??= ['name' => "{$b->vendor->code} {$b->vendor->name}"] + array_fill_keys($keys, BigDecimal::zero());
            foreach ([$k, 'total'] as $kk) {
                $per[$b->vendor_id][$kk] = $per[$b->vendor_id][$kk]->plus($open);
                $t[$kk] = $t[$kk]->plus($open);
            }
        }
        uasort($per, fn ($a, $b) => strcmp($a['name'], $b['name']));
        $d2 = fn ($v) => \App\Analytics\ReportService::d2($v);
        $gl = app(Ledger::class);

        return [
            'key' => 'ap_aging', 'title' => 'AP aging', 'period' => "As of $asOf",
            'columns' => ['Vendor', 'Current', '1-30', '31-60', '61-90', '90+', 'Total'],
            'rows' => array_values(array_map(fn ($r) => array_merge([$r['name']], array_map(fn ($k) => $d2($r[$k]), $keys)), $per)),
            'totals' => array_merge(['Total'], array_map(fn ($k) => $d2($t[$k]), $keys)),
            'notes' => ['Ages from each bill\'s due date. Only posted bills count.',
                'GL 2000 Accounts Payable balance: '.$d2($gl->balance(P::AP, $asOf, true)).'; GL 2050 Received Not Billed: '.$d2($gl->balance(P::GRNI, $asOf, true)).'.',
                'A difference between the AP total and GL 2000 is usually stock received before phase 8 that has no vendor bill yet.'],
            'raw_totals' => array_map(fn ($v) => Money::s($v), $t),
        ];
    }
}
