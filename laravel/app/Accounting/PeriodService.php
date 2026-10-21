<?php

namespace App\Accounting;

use App\Exceptions\PeriodClosedException;
use App\Exceptions\WorkflowException;
use App\Models\FiscalPeriod;
use App\Models\PeriodAudit;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Monthly fiscal periods. Nothing posts into a closed period; an admin can reopen with a reason (audited). */
class PeriodService
{
    private static ?bool $tableExists = null;

    public static function key(string $date): string
    {
        return CarbonImmutable::parse($date)->format('Y-m');
    }

    public static function isClosed(string $date): bool
    {
        if (self::$tableExists === null || self::$tableExists === false) {
            self::$tableExists = Schema::hasTable('fiscal_periods'); // pre-migration installs (upgrade runs before migrate)
        }
        if (! self::$tableExists) {
            return false;
        }

        return FiscalPeriod::where('period', self::key($date))->where('status', 'closed')->exists();
    }

    public static function assertOpen(string $date): void
    {
        if (self::isClosed($date)) {
            throw new PeriodClosedException('Period '.self::key($date).' is closed. Post in an open period, or ask an admin to reopen it.');
        }
    }

    public static function ensure(string $period): FiscalPeriod
    {
        $s = CarbonImmutable::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();

        return FiscalPeriod::firstOrCreate(['period' => $s->format('Y-m')], ['starts_on' => $s->toDateString(), 'ends_on' => $s->endOfMonth()->toDateString()]);
    }

    /** Close a month. Warns (returns) unreconciled bank statements and draft bills; refuses only if already closed. */
    public function close(string $period, User $by, ?string $reason = null): FiscalPeriod
    {
        if (! $by->isAdmin()) {
            throw new WorkflowException('Only an owner or admin can close a period.');
        }

        return DB::transaction(function () use ($period, $by, $reason) {
            $p = self::ensure($period);
            if ($p->status === 'closed') {
                throw new WorkflowException("Period {$p->period} is already closed.");
            }
            $p->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $by->id]);
            PeriodAudit::create(['fiscal_period_id' => $p->id, 'action' => 'close', 'user_id' => $by->id, 'reason' => $reason]);

            return $p;
        });
    }

    public function reopen(string $period, User $by, string $reason): FiscalPeriod
    {
        if (! $by->isAdmin()) {
            throw new WorkflowException('Only an owner or admin can reopen a period.');
        }
        if (trim($reason) === '') {
            throw new WorkflowException('A reason is required to reopen a period.');
        }

        return DB::transaction(function () use ($period, $by, $reason) {
            $p = self::ensure($period);
            if ($p->status !== 'closed') {
                throw new WorkflowException("Period {$p->period} is not closed.");
            }
            $p->update(['status' => 'open', 'closed_at' => null, 'closed_by' => null]);
            PeriodAudit::create(['fiscal_period_id' => $p->id, 'action' => 'reopen', 'user_id' => $by->id, 'reason' => $reason]);

            return $p;
        });
    }

    /** Pre-close checklist for a month (informational). */
    public function checklist(string $period): array
    {
        $s = CarbonImmutable::createFromFormat('Y-m-d', $period.'-01')->startOfMonth();
        $e = $s->endOfMonth();

        return [
            'Draft vendor bills dated in the month' => \App\Models\VendorBill::where('status', 'draft')->whereBetween('bill_date', [$s->toDateString(), $e->toDateString()])->count(),
            'Bank statements in the month not reconciled' => \App\Models\BankStatement::where('status', 'open')->whereBetween('statement_date', [$s->toDateString(), $e->toDateString()])->count(),
            'Sales orders delivered but not invoiced' => \App\Models\SalesOrder::where('status', 'delivered')->count(),
            'Purchase orders received but not fully billed' => \App\Models\PurchaseOrderLine::whereColumn('qty_billed', '<', 'qty_received')->distinct('purchase_order_id')->count('purchase_order_id'),
        ];
    }
}
