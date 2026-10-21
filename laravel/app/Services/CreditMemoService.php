<?php

namespace App\Services;

use App\Enums\SalesOrderStatus;
use App\Exceptions\WorkflowException;
use App\Models\Buydown;
use App\Models\CreditMemo;
use App\Models\CreditMemoLine;
use App\Models\SalesOrder;
use App\Models\SalesOrderLine;
use Illuminate\Support\Facades\DB;

/** Customer returns / credit memos against invoiced sales order lines. */
class CreditMemoService
{
    public const DAMAGE_ACCOUNT = '6300';

    public function __construct(private InventoryService $inventory, private PostingService $posting) {}

    /** Qty of a sales order line already credited on posted memos. */
    public function returnedQty(SalesOrderLine $line): string
    {
        return Money::s(CreditMemoLine::where('sales_order_line_id', $line->id)
            ->whereHas('creditMemo', fn ($q) => $q->where('status', 'posted'))->sum('qty'));
    }

    /**
     * @param  array<int, array{sales_order_line_id:int, qty:mixed, disposition?:string}>  $lines
     */
    public function create(SalesOrder $so, array $lines, ?string $reason = null, ?string $date = null): CreditMemo
    {
        if ($so->status !== SalesOrderStatus::Invoiced) {
            throw new WorkflowException("Credit memos need an invoiced order; {$so->number} is {$so->status->value}.");
        }

        return DB::transaction(function () use ($so, $lines, $reason, $date) {
            $cm = CreditMemo::create(['sales_order_id' => $so->id, 'customer_id' => $so->customer_id, 'memo_date' => $date ?? now()->toDateString(), 'reason' => $reason]);
            foreach ($lines as $l) {
                if (Money::of($l['qty'] ?? 0)->isNegativeOrZero()) {
                    continue;
                }
                $sol = SalesOrderLine::where('sales_order_id', $so->id)->findOrFail($l['sales_order_line_id']);
                $disp = ($l['disposition'] ?? 'restock') === 'damage' ? 'damage' : 'restock';
                $cm->lines()->create([
                    'sales_order_line_id' => $sol->id, 'product_id' => $sol->product_id, 'qty' => Money::s($l['qty']),
                    'unit_price' => $sol->unit_price, 'unit_cost' => $sol->unit_cost,
                    'line_total' => Money::mul($l['qty'], $sol->unit_price), 'disposition' => $disp,
                ]);
            }
            if ($cm->lines()->count() === 0) {
                throw new WorkflowException('Credit memo has no lines.');
            }

            return $this->recalc($cm);
        });
    }

    public function recalc(CreditMemo $cm): CreditMemo
    {
        $cm->load('lines');
        foreach ($cm->lines as $l) {
            $l->update(['line_total' => Money::mul($l->qty, $l->unit_price)]);
        }
        $cm->update([
            'total' => Money::add(...($cm->lines->pluck('line_total')->all() ?: [0])),
            'cost_total' => Money::add(...($cm->lines->map(fn ($l) => Money::mul($l->qty, $l->unit_cost))->all() ?: [0])),
        ]);

        return $cm;
    }

    /**
     * Post: restock lines return to stock; damaged lines are written off.
     * Dr Sales / Cr AR (total); restock Dr Inventory / Cr COGS; damage Dr 6300 write-off / Cr COGS.
     */
    public function post(CreditMemo $cm): CreditMemo
    {
        if ($cm->status !== 'draft') {
            throw new WorkflowException("Credit memo {$cm->number} is {$cm->status}.");
        }

        return DB::transaction(function () use ($cm) {
            $this->recalc($cm);
            $cm->load('lines.salesOrderLine', 'lines.product', 'salesOrder.warehouse');
            $so = $cm->salesOrder;
            $restockCost = '0';
            $damageCost = '0';
            foreach ($cm->lines as $l) {
                $open = Money::of($l->salesOrderLine->qty)->minus(Money::of($this->returnedQty($l->salesOrderLine)));
                if (Money::of($l->qty)->isGreaterThan($open)) {
                    throw new WorkflowException("{$l->product->sku}: returning {$l->qty} but only {$open} left to return on {$so->number}.");
                }
                $cost = Money::mul($l->qty, $l->unit_cost);
                if ($l->disposition === 'restock') {
                    $this->inventory->move($l->product, $so->warehouse, $l->qty, 'return', $cm, $l->unit_cost, "Return {$cm->number}");
                    $restockCost = Money::add($restockCost, $cost);
                } else {
                    $damageCost = Money::add($damageCost, $cost);
                }
            }
            $this->posting->post($cm->memo_date->toDateString(), "Credit memo {$cm->number} ({$so->number})", [
                [PostingService::SALES, $cm->total, 0],
                [PostingService::AR, 0, $cm->total],
                [PostingService::INVENTORY, $restockCost, 0, 'Returned to stock'],
                [self::DAMAGE_ACCOUNT, $damageCost, 0, 'Damaged returns written off'],
                [PostingService::COGS, 0, Money::add($restockCost, $damageCost)],
            ], $cm);
            app(\App\Pricing\RebateLedger::class)->reverseForCreditMemo($cm); // 14.1: proportional rebate/buydown reversal + GL
            $so->update(['amount_credited' => Money::add($so->amount_credited, $cm->total)]);
            $cm->update(['status' => 'posted', 'posted_at' => now()]);

            return $cm;
        });
    }
}
