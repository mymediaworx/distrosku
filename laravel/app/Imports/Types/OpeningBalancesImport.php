<?php

namespace App\Imports\Types;

use App\Imports\RowResult;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\User;
use App\Services\CustomerBalanceService;
use App\Services\Money;

/** Customer beginning (opening) balances through the existing beginning-balance service (GL Dr 1100 / Cr 3900, audited). */
class OpeningBalancesImport extends ImportType
{
    public function key(): string
    {
        return 'opening_balances';
    }

    public function label(): string
    {
        return 'Customer opening balances';
    }

    public function fields(): array
    {
        return [
            'customer_code' => ['label' => 'Customer number', 'required' => true, 'example' => 'C1001'],
            'balance' => ['label' => 'Opening balance (negative = credit)', 'required' => true, 'example' => '1250.00'],
            'as_of' => ['label' => 'As-of date', 'example' => '2026-10-31'],
        ];
    }

    public function evaluate(array $v, ImportBatch $b): RowResult
    {
        $e = [];
        static::required($v, ['customer_code' => 'Customer number', 'balance' => 'Opening balance'], $e);
        $c = ($cc = static::s($v, 'customer_code')) ? Customer::where('code', $cc)->first() : null;
        if ($cc && ! $c) {
            $e[] = "Customer \"{$cc}\" does not exist.";
        }
        $bal = static::num($v, 'balance', $e, 'Opening balance', true);
        $date = static::date($v, 'as_of', $e);
        if ($c && $bal !== null && Money::of($bal)->isPositive() && Money::of($c->beginning_balance_applied ?? 0)->isGreaterThan(Money::of($bal))) {
            $e[] = "Payments of {$c->beginning_balance_applied} are already applied; the balance cannot go below that.";
        }
        $diff = [];
        if ($c && $bal !== null && ! Money::of($c->beginning_balance ?? 0)->isEqualTo(Money::of($bal))) {
            $diff['beginning_balance'] = [Money::s($c->beginning_balance ?? 0), $bal];
        }
        if ($c && $date && $date !== $c->beginning_balance_date?->toDateString()) {
            $diff['beginning_balance_date'] = [$c->beginning_balance_date?->toDateString(), $date];
        }

        return new RowResult($e, $diff ? 'update' : 'unchanged', $diff, $cc, ['customer' => $c, 'balance' => $bal, 'date' => $date]);
    }

    public function apply(array $v, ImportBatch $b, RowResult $r): string
    {
        app(CustomerBalanceService::class)->setBeginningBalance($r->context['customer'], $r->context['balance'], $r->context['date'],
            User::find($b->user_id) ?? auth()->user(), "Import #{$b->id}");

        return $r->action;
    }
}
