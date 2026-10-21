<?php

namespace App\Tax;

use App\Exceptions\WorkflowException;
use App\Models\TaxFiling;
use Carbon\CarbonImmutable;

/**
 * A KY return that is approved or filed locks its month: invoices (deliveries), their lines, credit memos and stock movements
 * dated in that month cannot change. An amendment (new draft version) reopens the month until the amendment is approved.
 */
class ReturnLock
{
    public const TYPES = ['ky_tobacco_distributor', 'ky_subjobber_cigarette'];

    /** The approved/filed latest version covering this date, if any. */
    public static function lockedBy($date): ?TaxFiling
    {
        if (! $date) {
            return null;
        }
        $d = CarbonImmutable::parse($date)->toDateString();
        $hit = null;
        $rows = TaxFiling::whereIn('report_type', self::TYPES)->whereDate('period_start', '<=', $d)->whereDate('period_end', '>=', $d)
            ->orderByDesc('version')->orderByDesc('id')->get()->groupBy('report_type');
        foreach ($rows as $versions) {
            $latest = $versions->first();
            if ($latest->isLocked()) {
                $hit = $latest;
                break;
            }
        }

        return $hit;
    }

    public static function assertOpen($date, string $what): void
    {
        if ($f = self::lockedBy($date)) {
            throw new WorkflowException("{$what}: the KY return for ".$f->period_start->format('Y-m')." is {$f->status} (v{$f->version}) and locks that month. Start an amended return to change it.");
        }
    }

    /** Model guards: invoices (delivered orders) and their lines, credit memos and their lines, stock movements. */
    public static function register(): void
    {
        $so = \App\Models\SalesOrder::class;
        $so::updating(function ($m) {
            if ($m->isDirty(['customer_id', 'ship_to_id', 'delivered_at', 'warehouse_id', 'status'])) {
                $orig = $m->getOriginal('delivered_at');
                $newStatus = $m->status instanceof \BackedEnum ? $m->status->value : $m->status;
                $harmless = $m->isDirty('status') && ! $m->isDirty(['customer_id', 'ship_to_id', 'delivered_at', 'warehouse_id']) && in_array($newStatus, ['invoiced', 'paid'], true);
                if ($orig && ! $harmless) {
                    self::assertOpen($orig, "Order {$m->number}");
                }
                if ($m->isDirty('delivered_at') && $m->delivered_at) {
                    self::assertOpen($m->delivered_at, "Order {$m->number}");
                }
            }
        });
        $so::deleting(fn ($m) => $m->delivered_at ? self::assertOpen($m->delivered_at, "Order {$m->number}") : null);
        $line = fn ($l) => ($d = \App\Models\SalesOrder::whereKey($l->sales_order_id)->value('delivered_at')) ? self::assertOpen($d, 'Invoice line') : null;
        \App\Models\SalesOrderLine::saving($line);
        \App\Models\SalesOrderLine::deleting($line);

        $cm = function ($m) {
            $posted = $m->status === 'posted' || $m->getOriginal('status') === 'posted';
            if ($posted) {
                self::assertOpen($m->getOriginal('memo_date') ?? $m->memo_date, "Credit memo {$m->number}");
                self::assertOpen($m->memo_date, "Credit memo {$m->number}");
            }
        };
        \App\Models\CreditMemo::saving($cm);
        \App\Models\CreditMemo::deleting($cm);
        $cml = function ($l) {
            $m = \App\Models\CreditMemo::find($l->credit_memo_id);
            if ($m && $m->status === 'posted') {
                self::assertOpen($m->memo_date, "Credit memo {$m->number}");
            }
        };
        \App\Models\CreditMemoLine::saving($cml);
        \App\Models\CreditMemoLine::deleting($cml);

        $mv = fn ($m) => self::assertOpen($m->created_at ?? now(), 'Stock movement');
        \App\Models\StockMovement::creating($mv);
        \App\Models\StockMovement::updating(fn ($m) => self::assertOpen($m->getOriginal('created_at'), 'Stock movement'));
        \App\Models\StockMovement::deleting($mv);
    }
}
