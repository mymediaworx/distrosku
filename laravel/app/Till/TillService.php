<?php

namespace App\Till;

use App\Audit\Audit;
use App\Exceptions\WorkflowException;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Models\TillMovement;
use App\Models\TillSession;
use App\Models\User;
use App\Services\Money;
use App\Services\PostingService;
use Illuminate\Support\Facades\DB;

/**
 * #44: counter cash drawer. Open a till on a register with a float, record paid-ins / paid-outs / safe drops, close it with a
 * count. Expected cash = float + cash taken on POS sales linked to the till + paid-in - paid-out - drops.
 * GL: the drawer and the safe are both account 1000 Cash, so paid-ins and drops only move cash between them (no entry).
 * A paid-out is an expense: Dr 6500 Paid-outs / Cr 1000 Cash. The variance at close posts to 6550 Cash Over/Short
 * (short: Dr 6550 / Cr 1000; over: Dr 1000 / Cr 6550). Existing sale and payment postings are unchanged.
 */
class TillService
{
    public const PAID_OUTS = '6500';

    public const OVER_SHORT = '6550';

    public function __construct(private PostingService $gl) {}

    public static function current(?string $register): ?TillSession
    {
        return $register ? TillSession::where('register', $register)->where('status', 'open')->latest('id')->first() : null;
    }

    public function open(string $register, ?int $warehouseId, mixed $float, User $by): TillSession
    {
        if (! is_numeric($float) || (float) $float < 0) {
            throw new WorkflowException('Enter the starting cash (0 or more).');
        }

        return DB::transaction(function () use ($register, $warehouseId, $float, $by) {
            if (TillSession::where('register', $register)->where('status', 'open')->lockForUpdate()->exists()) {
                throw new WorkflowException('A till is already open on this register. Close it first.');
            }
            $s = TillSession::create(['register' => mb_substr($register, 0, 80), 'warehouse_id' => $warehouseId, 'opened_by' => $by->id,
                'opened_at' => now(), 'opening_float' => Money::s($float), 'status' => 'open']);
            Audit::action('till_opened', $s, "Till opened on {$s->register} by {$by->name} with \$".number_format((float) $float, 2), ['float' => Money::s($float)]);

            return $s;
        });
    }

    public function move(TillSession $s, string $type, mixed $amount, string $reason, User $by): TillMovement
    {
        if (! $s->isOpen()) {
            throw new WorkflowException('This till is closed.');
        }
        if (! array_key_exists($type, TillMovement::TYPES)) {
            throw new WorkflowException('Unknown cash movement.');
        }
        if (! is_numeric($amount) || (float) $amount <= 0) {
            throw new WorkflowException('Enter an amount above 0.');
        }
        if (trim($reason) === '') {
            throw new WorkflowException('Say what the cash is for.');
        }

        return DB::transaction(function () use ($s, $type, $amount, $reason, $by) {
            $m = $s->movements()->create(['type' => $type, 'amount' => Money::s($amount), 'reason' => mb_substr(trim($reason), 0, 255), 'user_id' => $by->id]);
            if ($type === 'paid_out') {
                $j = $this->gl->post(now()->toDateString(), "Till paid-out ({$s->register}): {$m->reason}", [
                    [self::PAID_OUTS, $m->amount, 0],
                    [PostingService::CASH, 0, $m->amount],
                ], $m);
                $m->update(['journal_id' => $j->id]);
            }
            Audit::action('till_'.$type, $m, TillMovement::TYPES[$type]." \$".number_format((float) $amount, 2)." on {$s->register}: {$m->reason}", ['amount' => $m->amount]);

            return $m;
        });
    }

    /** Cash taken on POS sales linked to this till (payments with method cash). */
    public static function cashSales(TillSession $s): string
    {
        return Money::s(Payment::where('method', 'cash')->whereIn('sales_order_id', SalesOrder::where('till_session_id', $s->id)->select('id'))->sum('amount'));
    }

    public static function expected(TillSession $s): string
    {
        $mv = $s->movements()->get()->groupBy('type')->map(fn ($g) => Money::add(...$g->pluck('amount')->all()));

        return Money::s(Money::of($s->opening_float)->plus(Money::of(self::cashSales($s)))->plus(Money::of($mv['paid_in'] ?? 0))
            ->minus(Money::of($mv['paid_out'] ?? 0))->minus(Money::of($mv['drop'] ?? 0)));
    }

    /** @param  array<string, int|string>  $detail  optional count by denomination, e.g. ['20' => 5, '1' => 12, 'coins' => '3.40'] */
    public function close(TillSession $s, mixed $counted, User $by, array $detail = [], ?string $notes = null): TillSession
    {
        if (! is_numeric($counted) || (float) $counted < 0) {
            throw new WorkflowException('Enter the counted cash.');
        }

        return DB::transaction(function () use ($s, $counted, $by, $detail, $notes) {
            $s = TillSession::lockForUpdate()->findOrFail($s->id);
            if (! $s->isOpen()) {
                throw new WorkflowException('This till is already closed.');
            }
            $expected = self::expected($s);
            $variance = Money::of($counted)->minus(Money::of($expected));
            $s->fill(['closed_by' => $by->id, 'closed_at' => now(), 'expected_cash' => $expected, 'counted_cash' => Money::s($counted),
                'variance' => Money::s($variance), 'count_detail' => $detail ?: null, 'notes' => $notes ? mb_substr($notes, 0, 255) : null, 'status' => 'closed']);
            if (! $variance->isZero()) {
                $amt = Money::s($variance->abs());
                $lines = $variance->isNegative()
                    ? [[self::OVER_SHORT, $amt, 0], [PostingService::CASH, 0, $amt]]   // short
                    : [[PostingService::CASH, $amt, 0], [self::OVER_SHORT, 0, $amt]];  // over
                $s->journal_id = $this->gl->post(now()->toDateString(), "Till {$s->register} ".($variance->isNegative() ? 'short' : 'over'), $lines, $s)->id;
            }
            $s->save();
            Audit::action('till_closed', $s, "Till closed on {$s->register} by {$by->name}: expected \$".number_format((float) $expected, 2)
                .', counted $'.number_format((float) $counted, 2).', '.($variance->isZero() ? 'balanced' : ($variance->isNegative() ? 'short' : 'over').' $'.number_format(abs((float) Money::s($variance)), 2)),
                ['expected' => $expected, 'counted' => Money::s($counted), 'variance' => Money::s($variance)]);

            return $s;
        });
    }

    /** Z-report figures for a session (open or closed). */
    public static function report(TillSession $s): array
    {
        $orders = SalesOrder::where('till_session_id', $s->id)->get(['id', 'number', 'total', 'amount_paid']);
        $pays = Payment::whereIn('sales_order_id', $orders->pluck('id'))->get()->groupBy('method')
            ->map(fn ($g) => ['count' => $g->count(), 'amount' => Money::add(...$g->pluck('amount')->all())]);
        $onAccount = $orders->filter(fn ($o) => Money::of($o->amount_paid ?? 0)->isZero());
        $mv = $s->movements()->with('user')->orderBy('id')->get();

        return [
            'sales_count' => $orders->count(),
            'sales_total' => Money::add(0, ...$orders->pluck('total')->all()),
            'by_tender' => $pays->all() + ($onAccount->isNotEmpty() ? ['account' => ['count' => $onAccount->count(), 'amount' => Money::add(...$onAccount->pluck('total')->all())]] : []),
            'movements' => $mv,
            'totals' => collect(TillMovement::TYPES)->map(fn ($l, $t) => Money::add(0, ...$mv->where('type', $t)->pluck('amount')->all()))->all(),
            'float' => Money::s($s->opening_float),
            'cash_sales' => self::cashSales($s),
            'expected' => $s->isOpen() ? self::expected($s) : Money::s($s->expected_cash),
        ];
    }
}
