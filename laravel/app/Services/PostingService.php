<?php

namespace App\Services;

use App\Exceptions\UnbalancedJournalException;
use App\Models\Account;
use App\Models\Journal;
use App\Models\Payment;
use App\Models\PurchaseOrder;
use App\Models\SalesOrder;
use App\Models\TaxFiling;
use Brick\Math\BigDecimal;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** Double-entry posting. Every journal must balance; posted journals are never edited. */
class PostingService
{
    public const CASH = '1000';

    public const AR = '1100';

    public const INVENTORY = '1200';

    public const AP = '2000';

    public const SALES = '4000';

    public const COGS = '5000';

    public const KY_EXCISE_PAYABLE = '2100';

    /** 0.20.20: taxes added to an invoice (product taxes). */
    public const SALES_TAX_PAYABLE = '2200';

    /** Goods received, vendor bill not yet entered (cleared by the bill: 3-way match). */
    public const GRNI = '2050';

    public const PURCHASE_PRICE_VARIANCE = '5100';

    public const RETAINED_EARNINGS = '3100';

    /** Inventory count variances (shrinkage / found stock). */
    public const SHRINKAGE = '5200';

    /**
     * @param  array<int, array{0:string,1:mixed,2:mixed,3?:string}>  $lines  [account code, debit, credit, memo]
     */
    public function post(string $date, string $memo, array $lines, ?Model $source = null): Journal
    {
        $dr = BigDecimal::zero();
        $cr = BigDecimal::zero();
        $rows = [];
        foreach ($lines as $l) {
            $d = Money::of($l[1] ?? 0);
            $c = Money::of($l[2] ?? 0);
            if ($d->isNegative() || $c->isNegative()) {
                throw new UnbalancedJournalException('Journal amounts cannot be negative.');
            }
            if ($d->isZero() && $c->isZero()) {
                continue;
            }
            $account = Account::where('code', $l[0])->first();
            if (! $account) {
                throw new UnbalancedJournalException("Unknown account {$l[0]}.");
            }
            $dr = $dr->plus($d);
            $cr = $cr->plus($c);
            $rows[] = ['account_id' => $account->id, 'debit' => Money::s($d), 'credit' => Money::s($c), 'memo' => $l[3] ?? null];
        }
        if (! $dr->isEqualTo($cr)) {
            throw new UnbalancedJournalException("Journal does not balance: debits {$dr} vs credits {$cr}.");
        }
        if ($dr->isZero()) {
            throw new UnbalancedJournalException('Journal has no amounts.');
        }
        \App\Accounting\PeriodService::assertOpen($date);

        return DB::transaction(function () use ($date, $memo, $rows, $source, $dr) {
            $j = Journal::create([
                'entry_date' => $date,
                'memo' => $memo,
                'source_type' => $source?->getMorphClass(),
                'source_id' => $source?->getKey(),
                'total' => Money::s($dr),
            ]);
            $j->lines()->createMany($rows);

            return $j;
        });
    }

    public function purchaseReceipt(PurchaseOrder $po, mixed $amount): Journal
    {
        // Phase 8: receipts credit 2050 Received Not Billed; the vendor bill moves it to AP (3-way match).
        return $this->post(now()->toDateString(), "PO {$po->number} received", [
            [self::INVENTORY, $amount, 0],
            [self::GRNI, 0, $amount],
        ], $po);
    }

    /** KY tobacco distributor tax owed by Vega on untaxed snuff/chew received. Capitalised into inventory cost. */
    public function exciseAccrual(PurchaseOrder $po, mixed $amount, string $memo = ''): Journal
    {
        return $this->post(now()->toDateString(), trim("KY tobacco excise accrual PO {$po->number} {$memo}"), [
            [self::INVENTORY, $amount, 0, 'KY excise capitalised'],
            [self::KY_EXCISE_PAYABLE, 0, $amount],
        ], $po);
    }

    /** Pay the KY excise liability for a filing: Dr 2100 / Cr Cash. */
    public function excisePayment(TaxFiling $f, mixed $amount, ?string $date = null, ?string $reference = null): Journal
    {
        if (Money::of($amount)->isNegativeOrZero()) {
            throw new UnbalancedJournalException('Payment amount must be positive.');
        }

        return DB::transaction(function () use ($f, $amount, $date, $reference) {
            $j = $this->post($date ?? now()->toDateString(), trim("KY excise payment {$f->report_type} {$f->period_start->format('Y-m')} {$reference}"), [
                [self::KY_EXCISE_PAYABLE, $amount, 0], [self::CASH, 0, $amount],
            ], $f);
            $f->update(['amount_paid' => Money::add($f->amount_paid, $amount), 'paid_on' => $date ?? now()->toDateString(), 'payment_reference' => $reference]);

            return $j;
        });
    }

    /** Posted journals are never edited: reversal swaps debits/credits in a new journal. */
    public function reverse(Journal $j, ?string $reason = null, ?string $date = null): Journal
    {
        if ($j->reversal_of_id) {
            throw new UnbalancedJournalException("Journal #{$j->id} is itself a reversal.");
        }
        if ($j->reversed_at || Journal::where('reversal_of_id', $j->id)->exists()) {
            throw new UnbalancedJournalException("Journal #{$j->id} is already reversed.");
        }

        return DB::transaction(function () use ($j, $reason, $date) {
            $j->load('lines.account');
            $r = $this->post($date ?? now()->toDateString(), "Reversal of #{$j->id}: {$j->memo}".($reason ? " ({$reason})" : ''),
                $j->lines->map(fn ($l) => [$l->account->code, $l->credit, $l->debit, $l->memo])->all(),
                $j->source_type ? $j->source : null);
            $r->update(['reversal_of_id' => $j->id]);
            $j->update(['reversed_at' => now()]);

            return $r;
        });
    }

    /**
     * Manual (adjusting) journal entry by an admin. Same rules as every other posting: balanced, open period.
     *
     * @param  array<int, array{account:string, debit?:mixed, credit?:mixed, memo?:string}>  $lines
     */
    /** Count variance: gain = Dr Inventory / Cr Shrinkage; loss = Dr Shrinkage / Cr Inventory. Amounts are positive. */
    public function countVariance(Model $count, string $date, mixed $gain, mixed $loss, string $memo): Journal
    {
        return $this->post($date, $memo, [
            [self::INVENTORY, $gain, 0, 'Count gains'],
            [self::SHRINKAGE, 0, $gain, 'Count gains'],
            [self::SHRINKAGE, $loss, 0, 'Count losses'],
            [self::INVENTORY, 0, $loss, 'Count losses'],
        ], $count);
    }

    public function manual(string $date, string $memo, array $lines, ?\App\Models\User $by = null): Journal
    {
        $who = $by ? " (by {$by->email})" : '';

        return $this->post($date, 'Manual: '.$memo.$who, array_map(fn ($l) => [$l['account'], $l['debit'] ?? 0, $l['credit'] ?? 0, $l['memo'] ?? null], $lines));
    }

    public function salesInvoice(SalesOrder $so): Journal
    {
        $so->loadMissing('lines');
        $cost = Money::add(...$so->lines->map(fn ($l) => Money::mul($l->qty, $l->unit_cost))->all() ?: [0]);

        return $this->post(now()->toDateString(), "Invoice {$so->number}", [
            [self::AR, $so->total, 0],
            [self::SALES, 0, Money::s(Money::of($so->total)->minus(Money::of($so->tax_total ?? 0)))],
            [self::SALES_TAX_PAYABLE, 0, $so->tax_total ?? 0, 'Taxes on invoice'], // 0.20.20: taxes shown on the invoice are owed, not revenue
            [self::COGS, $cost, 0],
            [self::INVENTORY, 0, $cost],
        ], $so);
    }

    public function payment(Payment $p): Journal
    {
        return $this->post($p->paid_on->toDateString(), "Payment {$p->method} {$p->reference}", [
            [self::CASH, $p->amount, 0],
            [self::AR, 0, $p->amount],
        ], $p);
    }
}
