<?php

namespace App\Services;

use App\Enums\SalesOrderStatus as S;
use App\Exceptions\WorkflowException;
use App\Models\Customer;
use App\Models\CustomerBalanceAudit;
use App\Models\Payment;
use App\Models\User;
use Brick\Math\RoundingMode;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

/**
 * Customer beginning balances (migrated from another system) and the customer's total AR balance.
 * Only admin-level roles (config erp.full_roles) may set the beginning balance. Each change posts the DELTA:
 *   increase: Dr 1100 AR / Cr 3900 Opening Balance Equity;  decrease (incl. credit balances): Dr 3900 / Cr 1100.
 */
class CustomerBalanceService
{
    public const OPENING_EQUITY = '3900';

    public function __construct(private PostingService $posting) {}

    public static function canManage(?User $u): bool
    {
        return (bool) $u?->isAdmin();
    }

    public function setBeginningBalance(Customer $c, mixed $amount, ?string $date, ?User $by, ?string $note = null): ?CustomerBalanceAudit
    {
        if (! self::canManage($by)) {
            throw new AuthorizationException('Only owner/admin/brandworx_admin may change a beginning balance.');
        }
        $new = Money::of($amount ?? 0)->toScale(2, RoundingMode::HALF_UP);
        $old = Money::of($c->beginning_balance ?? 0);
        $oldDate = $c->beginning_balance_date?->toDateString();
        $date = $date ?: ($oldDate ?? now()->toDateString());
        $delta = $new->minus($old);
        if ($delta->isZero() && $date === $oldDate) {
            return null;
        }
        $applied = Money::of($c->beginning_balance_applied ?? 0);
        if ($new->isPositive() && $applied->isGreaterThan($new)) {
            throw new WorkflowException("Payments of {$applied} already applied against the beginning balance; it cannot go below that.");
        }

        return DB::transaction(function () use ($c, $new, $old, $delta, $date, $oldDate, $by, $note) {
            $j = null;
            if (! $delta->isZero()) {
                $amt = Money::s($delta->abs());
                $lines = $delta->isPositive()
                    ? [[PostingService::AR, $amt, 0], [self::OPENING_EQUITY, 0, $amt]]
                    : [[self::OPENING_EQUITY, $amt, 0], [PostingService::AR, 0, $amt]];
                $j = $this->posting->post($date, "Beginning balance {$c->code}: {$old} -> {$new}", $lines, $c);
            }
            DB::table('customers')->where('id', $c->id)->update(['beginning_balance' => Money::s($new), 'beginning_balance_date' => $date, 'updated_at' => now()]);
            $c->refresh();

            return CustomerBalanceAudit::create([
                'customer_id' => $c->id, 'user_id' => $by?->id, 'old_balance' => Money::s($old), 'new_balance' => Money::s($new),
                'old_date' => $oldDate, 'new_date' => $date, 'journal_id' => $j?->id, 'note' => $note,
            ]);
        });
    }

    /** Remaining (unpaid) beginning balance; negative = customer credit still available. */
    public function openingRemaining(Customer $c): string
    {
        return Money::s(Money::of($c->beginning_balance ?? 0)->minus(Money::of($c->beginning_balance_applied ?? 0)));
    }

    /** Record a payment against the beginning balance (Dr Cash / Cr AR via PostingService::payment). */
    public function payOpening(Customer $c, mixed $amount, string $method = 'cash', ?string $reference = null, ?string $date = null): Payment
    {
        $amt = Money::of($amount);
        if ($amt->isNegativeOrZero() || $amt->isGreaterThan(Money::of($this->openingRemaining($c)))) {
            throw new WorkflowException("Payment must be between 0 and the open beginning balance {$this->openingRemaining($c)}.");
        }

        return DB::transaction(function () use ($c, $amt, $method, $reference, $date) {
            $p = Payment::create(['customer_id' => $c->id, 'sales_order_id' => null, 'applies_to_opening' => true,
                'amount' => Money::s($amt), 'method' => $method, 'reference' => $reference, 'paid_on' => $date ?? now()->toDateString()]);
            $this->posting->payment($p);
            DB::table('customers')->where('id', $c->id)->update(['beginning_balance_applied' => Money::add($c->beginning_balance_applied ?? 0, $amt)]);
            $c->refresh();

            return $p;
        });
    }

    /** Open invoices (invoiced SOs with balance) plus the remaining beginning balance. */
    public function balance(Customer $c): string
    {
        $inv = $c->salesOrders()->where('status', S::Invoiced)->get()->map(fn ($so) => $so->balance())->all();

        return Money::add($this->openingRemaining($c), ...($inv ?: [0]));
    }

    /** AR aging buckets by days past invoice/opening date as of $asOf: current(0-30), 31-60, 61-90, 90+. */
    public function aging(Customer $c, ?string $asOf = null): array
    {
        $asOf = CarbonImmutable::parse($asOf ?? now()->toDateString());
        $b = ['0-30' => Money::of(0), '31-60' => Money::of(0), '61-90' => Money::of(0), '90+' => Money::of(0)];
        $add = function ($date, $amt) use (&$b, $asOf) {
            $d = $date ? CarbonImmutable::parse($date)->diffInDays($asOf, false) : 0;
            $k = $d <= 30 ? '0-30' : ($d <= 60 ? '31-60' : ($d <= 90 ? '61-90' : '90+'));
            $b[$k] = $b[$k]->plus(Money::of($amt));
        };
        $open = $this->openingRemaining($c);
        if (! Money::of($open)->isZero()) {
            $add($c->beginning_balance_date, $open);
        }
        foreach ($c->salesOrders()->where('status', S::Invoiced)->get() as $so) {
            $add($so->invoiced_at ?? $so->delivered_at ?? $so->created_at, $so->balance());
        }

        return array_map(fn ($v) => Money::s($v), $b) + ['total' => $this->balance($c)];
    }
}
