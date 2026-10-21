<?php

namespace App\Services;

use App\Exceptions\WorkflowException;
use App\Models\PurchaseOrder;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

class PurchaseOrderService
{
    public function __construct(private InventoryService $inventory, private PostingService $posting, private KyExcise $excise) {}

    public function recalc(PurchaseOrder $po): PurchaseOrder
    {
        $po->load('lines');
        $po->total = Money::add(...($po->lines->map(fn ($l) => Money::mul($l->qty, $l->unit_cost))->all() ?: [0]));
        $po->save();

        return $po;
    }

    /**
     * Receive stock. $quantities = [line_id => qty]; null receives everything still open.
     * Stock in + Dr Inventory / Cr AP, and for untaxed snuff/chew from a non-KY-licensed source
     * Dr Inventory / Cr 2100 KY excise payable.
     */
    /** Over-receipt allowance %: the PO's own setting, else the global setting (default 0 = refuse over-receipts). */
    public static function allowancePct(PurchaseOrder $po): string
    {
        return Money::s($po->over_receipt_pct ?? \App\Models\Setting::get('over_receipt_pct', 0));
    }

    /** Max that can still be received on a line, allowance included. */
    public static function receivable(PurchaseOrder $po, $line): BigDecimal
    {
        $max = Money::of($line->qty)->plus(Money::of($line->qty)->multipliedBy(Money::of(self::allowancePct($po)))->dividedBy(100, 4, \Brick\Math\RoundingMode::DOWN));

        return BigDecimal::max($max->minus(Money::of($line->qty_received)), BigDecimal::zero());
    }

    public function receive(PurchaseOrder $po, ?array $quantities = null): PurchaseOrder
    {
        if (! in_array($po->status, ['draft', 'ordered', 'partial'], true)) {
            throw new WorkflowException("PO {$po->number} is {$po->status}; cannot receive.");
        }

        return DB::transaction(function () use ($po, $quantities) {
            $po->load('lines.product', 'warehouse', 'vendor');
            $amount = '0';
            $excise = '0';
            foreach ($po->lines as $l) {
                $open = Money::of($l->qty)->minus(Money::of($l->qty_received));
                $take = $quantities === null ? BigDecimal::max($open, BigDecimal::zero()) : Money::of($quantities[$l->id] ?? 0);
                if ($take->isNegativeOrZero()) {
                    continue;
                }
                $room = self::receivable($po, $l);
                if ($take->isGreaterThan($room)) {
                    throw new WorkflowException("Line {$l->product->sku}: receiving {$take} but only ".Money::s($room).' can be received'
                        .(Money::of(self::allowancePct($po))->isPositive() ? ' (incl. '.self::allowancePct($po).'% over-receipt allowance).' : ' (over-receipts not allowed).'));
                }
                $over = $take->minus(BigDecimal::max($open, BigDecimal::zero()));
                if ($over->isPositive()) {
                    \App\Models\WarehouseEvent::create(['type' => 'over_receipt', 'user_id' => auth()->id(), 'purchase_order_id' => $po->id,
                        'product_id' => $l->product_id, 'qty' => Money::s($over), 'reason' => 'Within '.self::allowancePct($po).'% allowance',
                        'details' => ['ordered' => (string) $l->qty, 'previously_received' => (string) $l->qty_received, 'received_now' => Money::s($take)]]);
                }
                $this->inventory->move($l->product, $po->warehouse, $take, 'receipt', $po, $l->unit_cost);
                $tax = KyExcise::vegaOwesOnPurchase($l->product, $po->vendor) ? $this->excise->smokelessTax($l->product, $take) : '0';
                $l->update(['qty_received' => Money::add($l->qty_received, $take), 'excise_accrued' => Money::add($l->excise_accrued, $tax)]);
                $l->product->update(['cost' => $l->unit_cost]); // last-cost for now
                $amount = Money::add($amount, Money::mul($take, $l->unit_cost));
                $excise = Money::add($excise, $tax);
            }
            if (Money::of($amount)->isZero()) {
                throw new WorkflowException('Nothing to receive.');
            }
            $this->posting->purchaseReceipt($po, $amount);
            if (Money::of($excise)->isPositive()) {
                $this->posting->exciseAccrual($po, $excise);
            }
            $this->recalc($po);
            $fully = $po->lines->every(fn ($l) => Money::of($l->fresh()->qty_received)->isGreaterThanOrEqualTo(Money::of($l->qty)));
            $po->update(['status' => $fully ? 'received' : 'partial', 'received_at' => now()]);

            return $po;
        });
    }
}
