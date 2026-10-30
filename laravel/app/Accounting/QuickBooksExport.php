<?php

namespace App\Accounting;

use App\Models\Journal;
use App\Services\Money;
use Carbon\Carbon;

/**
 * #40: the general ledger as QuickBooks journal entries, for an accountant who keeps the books in QuickBooks.
 * - QuickBooks Online: CSV in the "Journal entries" import layout (JournalNo, JournalDate, AccountName, Debits, Credits, Description).
 * - QuickBooks Desktop: IIF GENERAL JOURNAL transactions (debits positive, credits negative).
 * Account names: Accounting > Chart of accounts > "QuickBooks name" (blank = the DistroSKU name). Read-only: nothing is changed.
 */
class QuickBooksExport
{
    public const FORMATS = ['qbo' => 'QuickBooks Online (CSV)', 'iif' => 'QuickBooks Desktop (IIF)'];

    /** @return iterable<Journal> */
    private function journals(string $from, string $to): iterable
    {
        return Journal::with('lines.account')->whereBetween('entry_date', [$from, $to])->orderBy('entry_date')->orderBy('id')->cursor();
    }

    private static function account($a): string
    {
        return trim((string) ($a?->qb_name ?: $a?->name ?: 'Unknown'));
    }

    private static function clean(?string $s): string
    {
        return trim(preg_replace('/[\t\r\n]+/', ' ', (string) $s));
    }

    /** @return array{name: string, mime: string, body: string, journals: int} */
    public function build(string $from, string $to, string $format): array
    {
        $from = Carbon::parse($from)->toDateString();
        $to = Carbon::parse($to)->toDateString();
        $n = 0;
        if ($format === 'iif') {
            $out = "!TRNS\tTRNSID\tTRNSTYPE\tDATE\tACCNT\tAMOUNT\tDOCNUM\tMEMO\r\n!SPL\tSPLID\tTRNSTYPE\tDATE\tACCNT\tAMOUNT\tDOCNUM\tMEMO\r\n!ENDTRNS\r\n";
            foreach ($this->journals($from, $to) as $j) {
                $lines = $j->lines->values();
                if ($lines->isEmpty()) {
                    continue;
                }
                $n++;
                $date = Carbon::parse($j->entry_date)->format('m/d/Y');
                foreach ($lines as $i => $l) {
                    $amt = Money::s(Money::of($l->debit)->minus(Money::of($l->credit)));
                    $out .= implode("\t", [$i === 0 ? 'TRNS' : 'SPL', '', 'GENERAL JOURNAL', $date, self::account($l->account), number_format((float) $amt, 2, '.', ''),
                        'DS'.$j->id, self::clean($l->memo ?: $j->memo)])."\r\n";
                }
                $out .= "ENDTRNS\r\n";
            }

            return ['name' => "distrosku-gl-{$from}-to-{$to}.iif", 'mime' => 'application/octet-stream', 'body' => $out, 'journals' => $n];
        }

        $fh = fopen('php://temp', 'w+');
        fputcsv($fh, ['JournalNo', 'JournalDate', 'AccountName', 'Debits', 'Credits', 'Description']);
        foreach ($this->journals($from, $to) as $j) {
            if ($j->lines->isEmpty()) {
                continue;
            }
            $n++;
            foreach ($j->lines as $l) {
                $d = (float) $l->debit;
                $c = (float) $l->credit;
                fputcsv($fh, ['DS'.$j->id, Carbon::parse($j->entry_date)->format('m/d/Y'), self::account($l->account),
                    $d > 0 ? number_format($d, 2, '.', '') : '', $c > 0 ? number_format($c, 2, '.', '') : '', self::clean($l->memo ?: $j->memo)]);
            }
        }
        rewind($fh);
        $body = stream_get_contents($fh);
        fclose($fh);

        return ['name' => "distrosku-gl-{$from}-to-{$to}.csv", 'mime' => 'text/csv', 'body' => $body, 'journals' => $n];
    }
}
