<?php

namespace App\Credit;

use App\Audit\Audit;
use App\Enums\SalesOrderStatus as S;
use App\Exceptions\WorkflowException;
use App\Models\Customer;
use App\Models\SalesOrder;
use App\Models\User;
use App\Services\Money;

/**
 * 0.17.1 customer credit limit.
 * Exposure = AR balance (unpaid invoices + remaining beginning balance; a negative beginning balance is an unapplied credit and reduces it)
 *          + open orders not yet invoiced (confirmed / picked / delivered), net of anything already paid or credited on them.
 * A limit of 0 or blank means "no limit" (the column always defaulted to 0 and was never enforced, so 0 cannot mean "no credit").
 * Only charges to the account are checked; sales paid at the time of sale are never blocked.
 */
class CreditLimit
{
    public const OPEN = [S::Confirmed, S::Picked, S::Delivered];

    public function limit(Customer $c): ?string
    {
        $l = Money::of($c->credit_limit ?? 0);

        return $l->isPositive() ? Money::s($l) : null;
    }

    public function exposure(Customer $c, ?int $excludeOrderId = null): string
    {
        $open = SalesOrder::where('customer_id', $c->id)->whereIn('status', self::OPEN)
            ->when($excludeOrderId, fn ($q) => $q->whereKeyNot($excludeOrderId))->get()->map(fn ($so) => $so->balance())->all();

        return Money::s(Money::of(Money::add($c->balance(), ...($open ?: [0]))));
    }

    /** null = no limit. */
    public function available(Customer $c): ?string
    {
        $l = $this->limit($c);

        return $l === null ? null : Money::s(Money::of($l)->minus(Money::of($this->exposure($c))));
    }

    /** Throws when charging $amount to the account would push exposure over the limit (unless the order carries an owner/admin override). */
    public function check(Customer $c, mixed $amount, ?SalesOrder $so = null): void
    {
        $l = $this->limit($c);
        if ($l === null || ($so && $so->credit_override_at)) {
            return;
        }
        $exp = $this->exposure($c, $so?->id);
        $after = Money::of($exp)->plus(Money::of($amount));
        if ($after->isGreaterThan(Money::of($l))) {
            $availD = Money::of($l)->minus(Money::of($exp));
            $avail = $availD->isNegative() ? 'none (already $'.self::fmt($availD->negated()).' over)' : '$'.self::fmt($availD);
            throw new CreditLimitException(sprintf('Credit limit exceeded for %s: limit $%s, open balance and orders $%s, this charge $%s, available %s. Collect payment, or an owner/admin can confirm it as a sales order in admin with a credit override (Sales Orders, row menu, Confirm with credit override).',
                $c->name, self::fmt($l), self::fmt($exp), self::fmt($amount), $avail), $l, $exp, Money::s($amount));
        }
    }

    /** Owner/admin override on one order. Reason required; recorded in the Activity log. */
    public function override(SalesOrder $so, User $by, string $reason): SalesOrder
    {
        if (! $by->isAdmin()) {
            throw new WorkflowException('Only an owner or admin can override a credit limit.');
        }
        $reason = trim($reason);
        if (mb_strlen($reason) < 3) {
            throw new WorkflowException('A reason is required to override the credit limit.');
        }
        $c = $so->customer;
        $so->forceFill(['credit_override_by' => $by->id, 'credit_override_at' => now(), 'credit_override_reason' => mb_substr($reason, 0, 500)])->save();
        Audit::action('credit_override', $so, "Credit limit override on {$so->number} for {$c->name}: {$reason}",
            ['reason' => $reason, 'limit' => $this->limit($c), 'exposure' => $this->exposure($c, $so->id), 'order_total' => (string) $so->total], [], $by->id);

        return $so;
    }

    /** One-line text for screens: "No limit" or "$x of $limit (open $y)". */
    public static function summary(Customer $c): string
    {
        $svc = app(self::class);
        $l = $svc->limit($c);
        $exp = $svc->exposure($c);
        if ($l === null) {
            return 'No limit (open balance and orders $'.self::fmt($exp).')';
        }

        $a = Money::of($l)->minus(Money::of($exp));

        return ($a->isNegative() ? 'None available: $'.self::fmt($a->negated()).' over the $'.self::fmt($l).' limit' : '$'.self::fmt($a).' available of $'.self::fmt($l).' limit')
            .' (open balance and orders $'.self::fmt($exp).')';
    }

    public static function fmt(mixed $v): string
    {
        return number_format((float) Money::s($v), 2);
    }
}
