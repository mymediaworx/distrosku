<?php

namespace App\Accounting;

use App\Models\Account;
use App\Models\BankAccount;
use App\Services\Money;
use Brick\Math\BigDecimal;
use Illuminate\Support\Facades\DB;

/** GL balance queries. SQL selects rows; sums are exact decimals in PHP. */
class Ledger
{
    /** Net (debit - credit) per account code for journals dated within [from, to] (null from = since the beginning). */
    public function net(?string $from, string $to): array
    {
        $rows = DB::table('journal_lines as l')->join('journals as j', 'j.id', '=', 'l.journal_id')->join('accounts as a', 'a.id', '=', 'l.account_id')
            ->when($from, fn ($q) => $q->whereDate('j.entry_date', '>=', $from))->whereDate('j.entry_date', '<=', $to)
            ->get(['a.code', 'l.debit', 'l.credit']);
        $out = [];
        foreach ($rows as $r) {
            $out[$r->code] = ($out[$r->code] ?? BigDecimal::zero())->plus(Money::of($r->debit))->minus(Money::of($r->credit));
        }

        return $out;
    }

    /** Balance of one account as of a date. $creditNatural flips the sign (liabilities, equity, revenue). */
    public function balance(string $code, string $asOf, bool $creditNatural = false): BigDecimal
    {
        $v = $this->net(null, $asOf)[$code] ?? BigDecimal::zero();

        return $creditNatural ? $v->negated() : $v;
    }

    public static function creditNatural(string $type): bool
    {
        return in_array($type, ['liability', 'equity', 'revenue'], true);
    }

    /** GL codes that are cash: 1000/1010 plus every bank account's GL account. */
    public static function cashCodes(): array
    {
        $codes = ['1000', '1010'];
        try {
            $codes = array_merge($codes, BankAccount::with('account')->get()->pluck('account.code')->filter()->all());
        } catch (\Throwable) {
        }

        return array_values(array_unique($codes));
    }

    public function accounts()
    {
        return Account::orderBy('code')->get();
    }
}
