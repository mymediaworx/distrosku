<?php

namespace App\Accounting;

use App\Analytics\ReportService;
use App\Exceptions\WorkflowException;
use App\Models\BankAccount;
use App\Models\BankStatement;
use App\Models\BankStatementLine;
use App\Models\JournalLine;
use App\Models\User;
use App\Services\Money;
use App\Services\PostingService;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Bank reconciliation: import a CSV statement, auto-match lines to GL lines on the bank's account
 * (same signed amount, within MATCH_DAYS, reference used as a tie-breaker), match by hand, post bank-only items
 * (fees, interest) as adjustments, and reconcile when nothing is unmatched and the difference is zero.
 */
class BankReconService
{
    public const MATCH_DAYS = 7;

    public function __construct(private PostingService $posting, private Ledger $gl) {}

    /** Parse a bank CSV. Header names are matched loosely: date; description/memo/payee; amount or debit+credit (withdrawal/deposit); reference/check. */
    public static function parseCsv(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $rows = array_values(array_filter(array_map('str_getcsv', preg_split('/\r\n|\n|\r/', trim($csv))), fn ($r) => count(array_filter($r, fn ($c) => trim((string) $c) !== '')) > 0));
        if (count($rows) < 2) {
            throw new WorkflowException('The CSV needs a header row and at least one transaction.');
        }
        $h = array_map(fn ($c) => strtolower(trim((string) $c)), array_shift($rows));
        $find = function (array $names) use ($h) {
            foreach ($h as $i => $c) {
                foreach ($names as $n) {
                    if ($c === $n || str_contains($c, $n)) {
                        return $i;
                    }
                }
            }

            return null;
        };
        $iDate = $find(['date', 'posted']);
        $iDesc = $find(['description', 'memo', 'payee', 'details', 'name']);
        $iRef = $find(['reference', 'check', 'ref', 'number']);
        $iAmt = $find(['amount']);
        $iDr = $find(['debit', 'withdrawal', 'payment']);
        $iCr = $find(['credit', 'deposit']);
        if ($iDate === null || ($iAmt === null && ($iDr === null || $iCr === null))) {
            throw new WorkflowException('CSV must have a Date column and either Amount or Debit + Credit (Withdrawal + Deposit) columns.');
        }
        $num = function ($v) {
            $v = trim((string) $v);
            if ($v === '') {
                return BigDecimal::zero();
            }
            $neg = str_starts_with($v, '(') && str_ends_with($v, ')') || str_starts_with($v, '-');
            $v = preg_replace('/[^0-9.]/', '', $v);

            return $v === '' ? BigDecimal::zero() : ($neg ? Money::of($v)->negated() : Money::of($v));
        };
        $out = [];
        foreach ($rows as $n => $r) {
            $raw = trim((string) ($r[$iDate] ?? ''));
            $date = null;
            foreach (['Y-m-d', 'm/d/Y', 'n/j/Y', 'm/d/y', 'n/j/y', 'm-d-Y'] as $fmt) {
                $d = \DateTime::createFromFormat('!'.$fmt, $raw);
                if ($d && $d->format($fmt) === $raw) {
                    $date = $d->format('Y-m-d');
                    break;
                }
            }
            if (! $date) {
                throw new WorkflowException('Row '.($n + 2).": can't read date '$raw'.");
            }
            $amt = $iAmt !== null ? $num($r[$iAmt] ?? '') : $num($r[$iCr] ?? '')->abs()->minus($num($r[$iDr] ?? '')->abs());
            $out[] = ['txn_date' => $date, 'description' => $iDesc !== null ? mb_substr(trim((string) ($r[$iDesc] ?? '')), 0, 250) : null,
                'reference' => $iRef !== null && $iRef !== $iAmt ? mb_substr(trim((string) ($r[$iRef] ?? '')), 0, 120) : null, 'amount' => Money::s($amt)];
        }

        return $out;
    }

    public function import(BankAccount $bank, string $csv, string $statementDate, mixed $opening, mixed $closing, ?string $filename = null): BankStatement
    {
        $lines = self::parseCsv($csv);

        return DB::transaction(function () use ($bank, $lines, $statementDate, $opening, $closing, $filename) {
            $s = BankStatement::create(['bank_account_id' => $bank->id, 'statement_date' => $statementDate, 'opening_balance' => Money::s($opening),
                'closing_balance' => Money::s($closing), 'filename' => $filename]);
            $s->lines()->createMany($lines);
            $this->autoMatch($s);

            return $s->fresh();
        });
    }

    /** GL lines on the bank's account that are not yet cleared. */
    public function unclearedQuery(BankAccount $bank)
    {
        return JournalLine::query()->with('journal')->where('account_id', $bank->account_id)
            ->whereNotIn('id', BankStatementLine::whereNotNull('journal_line_id')->select('journal_line_id'))
            ->whereHas('journal', fn ($q) => $q->when($bank->cleared_through, fn ($w, $d) => $w->whereDate('entry_date', '>', $d->toDateString())));
    }

    private static function signed(JournalLine $l): BigDecimal
    {
        return Money::of($l->debit)->minus(Money::of($l->credit));
    }

    public function autoMatch(BankStatement $s): int
    {
        $s->load('bankAccount', 'lines');
        $pool = $this->unclearedQuery($s->bankAccount)->get();
        $n = 0;
        foreach ($s->lines->where('status', 'unmatched') as $line) {
            $d = CarbonImmutable::parse($line->txn_date);
            $cands = $pool->filter(fn (JournalLine $l) => self::signed($l)->isEqualTo(Money::of($line->amount))
                && abs(CarbonImmutable::parse($l->journal->entry_date)->diffInDays($d, false)) <= self::MATCH_DAYS);
            if ($cands->count() > 1 && $line->reference) {
                $ref = $cands->filter(fn ($l) => str_contains(strtolower($l->journal->memo.' '.$l->memo), strtolower($line->reference)));
                $cands = $ref->count() ? $ref : $cands;
            }
            if ($cands->count() > 1) { // nearest date, but only if unambiguous
                $byDist = $cands->groupBy(fn ($l) => abs(CarbonImmutable::parse($l->journal->entry_date)->diffInDays($d, false)))->sortKeys();
                $cands = $byDist->first();
            }
            if ($cands->count() === 1) {
                $jl = $cands->first();
                $line->update(['journal_line_id' => $jl->id, 'status' => 'matched']);
                $pool = $pool->reject(fn ($l) => $l->id === $jl->id);
                $n++;
            }
        }

        return $n;
    }

    public function match(BankStatementLine $line, int $journalLineId): BankStatementLine
    {
        $this->assertOpen($line->statement);
        $jl = JournalLine::findOrFail($journalLineId);
        if ((int) $jl->account_id !== (int) $line->statement->bankAccount->account_id) {
            throw new WorkflowException('That GL line is not on this bank account.');
        }
        if (BankStatementLine::where('journal_line_id', $jl->id)->where('id', '!=', $line->id)->exists()) {
            throw new WorkflowException('That GL line is already matched.');
        }
        if (! self::signed($jl)->isEqualTo(Money::of($line->amount))) {
            throw new WorkflowException('Amounts differ: bank '.ReportService::d2($line->amount).' vs GL '.ReportService::d2(self::signed($jl)).'.');
        }
        $line->update(['journal_line_id' => $jl->id, 'status' => 'matched']);

        return $line;
    }

    public function unmatch(BankStatementLine $line): BankStatementLine
    {
        $this->assertOpen($line->statement);
        if ($line->status === 'adjusted') {
            throw new WorkflowException('This line was posted as an adjustment; reverse the journal in Accounting > Journals instead.');
        }
        $line->update(['journal_line_id' => null, 'status' => 'unmatched']);

        return $line;
    }

    /** Bank-only item (fee, interest, NSF): post it against $accountCode and match it. */
    public function adjust(BankStatementLine $line, string $accountCode, ?string $memo = null): BankStatementLine
    {
        $this->assertOpen($line->statement);
        if ($line->status !== 'unmatched') {
            throw new WorkflowException('Only unmatched lines can be posted as adjustments.');
        }
        $bankCode = $line->statement->bankAccount->account->code;
        if ($accountCode === $bankCode) {
            throw new WorkflowException('Choose the other side of the entry (for example 6200 card fees), not the bank account itself.');
        }
        $amt = Money::of($line->amount);

        return DB::transaction(function () use ($line, $accountCode, $memo, $amt, $bankCode) {
            $lines = $amt->isPositive() ? [[$bankCode, (string) $amt, 0], [$accountCode, 0, (string) $amt]] : [[$accountCode, (string) $amt->abs(), 0], [$bankCode, 0, (string) $amt->abs()]];
            $j = $this->posting->post($line->txn_date->toDateString(), 'Bank adjustment: '.($memo ?: $line->description), $lines, $line->statement);
            $jl = $j->lines()->where('account_id', $line->statement->bankAccount->account_id)->first();
            $line->update(['journal_line_id' => $jl->id, 'status' => 'adjusted']);

            return $line;
        });
    }

    private function assertOpen(BankStatement $s): void
    {
        if ($s->status === 'reconciled') {
            throw new WorkflowException('This statement is already reconciled.');
        }
    }

    public function summary(BankStatement $s): array
    {
        $s->load('bankAccount.account', 'lines');
        $asOf = $s->statement_date->toDateString();
        $book = $this->gl->balance($s->bankAccount->account->code, $asOf);
        /** @var Collection $out */
        $out = $this->unclearedQuery($s->bankAccount)->get()->filter(fn ($l) => $l->journal->entry_date->toDateString() <= $asOf);
        $deposits = $out->filter(fn ($l) => self::signed($l)->isPositive());
        $checks = $out->filter(fn ($l) => self::signed($l)->isNegative());
        $sum = fn ($c) => $c->reduce(fn ($t, $l) => $t->plus(self::signed($l)), BigDecimal::zero());
        $adjBank = Money::of($s->closing_balance)->plus($sum($deposits))->plus($sum($checks));
        $stmtSum = $s->lines->reduce(fn ($t, $l) => $t->plus(Money::of($l->amount)), BigDecimal::zero());

        return [
            'statement_closing' => Money::s($s->closing_balance),
            'deposits_in_transit' => Money::s($sum($deposits)),
            'outstanding_payments' => Money::s($sum($checks)),
            'adjusted_bank' => Money::s($adjBank),
            'book_balance' => Money::s($book),
            'difference' => Money::s($adjBank->minus($book)),
            'unmatched_lines' => $s->lines->where('status', 'unmatched')->count(),
            'statement_lines_total' => Money::s($stmtSum),
            'statement_math_ok' => Money::of($s->opening_balance)->plus($stmtSum)->isEqualTo(Money::of($s->closing_balance)),
            'outstanding' => $out->map(fn ($l) => [$l->journal->entry_date->toDateString(), $l->journal->memo, Money::s(self::signed($l))])->values()->all(),
        ];
    }

    public function reconcile(BankStatement $s, User $by): BankStatement
    {
        $this->assertOpen($s);
        $sum = $this->summary($s);
        if ($sum['unmatched_lines'] > 0) {
            throw new WorkflowException("{$sum['unmatched_lines']} statement line(s) are still unmatched.");
        }
        if (! Money::of($sum['difference'])->isZero()) {
            throw new WorkflowException('Difference is '.ReportService::d2($sum['difference']).'; it must be 0.00 to reconcile.');
        }
        if (! $sum['statement_math_ok']) {
            throw new WorkflowException('Opening balance plus statement lines does not equal the closing balance. Check the balances you entered.');
        }
        unset($sum['outstanding']);
        $s->update(['status' => 'reconciled', 'reconciled_at' => now(), 'reconciled_by' => $by->id, 'summary' => $sum]);

        return $s;
    }

    /** Printable/CSV reconciliation report in the management report shape. */
    public function report(BankStatement $s): array
    {
        $sum = $this->summary($s);
        $d2 = fn ($v) => ReportService::d2($v);
        $rows = [
            ['Statement closing balance', '', $d2($sum['statement_closing'])],
            ['Add: deposits in transit', '', $d2($sum['deposits_in_transit'])],
            ['Less: outstanding payments', '', $d2($sum['outstanding_payments'])],
            ['Adjusted bank balance', '', $d2($sum['adjusted_bank'])],
            ['Book balance (GL '.$s->bankAccount->account->code.')', '', $d2($sum['book_balance'])],
            ['Difference', '', $d2($sum['difference'])],
            ['', '', ''],
            ['OUTSTANDING ITEMS', '', ''],
        ];
        foreach ($sum['outstanding'] as [$date, $memo, $amt]) {
            $rows[] = [$date, $memo, $d2($amt)];
        }
        $rows[] = ['', '', ''];
        $rows[] = ['CLEARED STATEMENT LINES', '', ''];
        foreach ($s->lines()->orderBy('txn_date')->get() as $l) {
            $rows[] = [$l->txn_date->toDateString(), trim($l->description.' '.$l->reference).' ['.$l->status.']', $d2($l->amount)];
        }

        return ['key' => 'bank_rec', 'title' => "Bank reconciliation: {$s->bankAccount->name}", 'period' => 'Statement '.$s->statement_date->toDateString().' ('.$s->status.')',
            'columns' => ['Item', 'Detail', 'Amount'], 'rows' => $rows, 'totals' => null,
            'notes' => [$sum['unmatched_lines'] ? "{$sum['unmatched_lines']} line(s) unmatched." : 'All statement lines matched.']];
    }
}
