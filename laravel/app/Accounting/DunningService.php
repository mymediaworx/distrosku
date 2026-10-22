<?php

namespace App\Accounting;

use App\Analytics\ReportService;
use App\Enums\SalesOrderStatus as S;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DunningBatch;
use App\Models\DunningNotice;
use App\Models\Setting;
use App\Models\User;
use App\Services\CustomerBalanceService;
use App\Services\Money;
use Barryvdh\DomPDF\Facade\Pdf;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Overdue reminders. A run creates a batch of DRAFT notices (subject + body + statement PDF) for review.
 * Nothing is emailed by the ERP: there is no send path here by design.
 */
class DunningService
{
    public const DEFAULT_LEVELS = [
        ['level' => 1, 'days' => 1, 'name' => 'Friendly reminder'],
        ['level' => 2, 'days' => 30, 'name' => 'Second notice'],
        ['level' => 3, 'days' => 60, 'name' => 'Final notice'],
    ];

    /** Do not repeat the same level for a customer within this many days. */
    public const REPEAT_DAYS = 14;

    public function __construct(private CustomerBalanceService $balances, private ReportService $reports) {}

    public static function levels(): array
    {
        $v = json_decode((string) Setting::get('dunning_levels', ''), true);
        $levels = is_array($v) && $v ? $v : self::DEFAULT_LEVELS;
        usort($levels, fn ($a, $b) => $a['days'] <=> $b['days']);

        return $levels;
    }

    public static function saveLevels(array $levels): void
    {
        $clean = [];
        foreach (array_values($levels) as $i => $l) {
            if (($l['days'] ?? '') === '' || ($l['name'] ?? '') === '') {
                continue;
            }
            $clean[] = ['level' => $i + 1, 'days' => max(1, (int) $l['days']), 'name' => mb_substr((string) $l['name'], 0, 60)];
        }
        Setting::put('dunning_levels', json_encode($clean ?: self::DEFAULT_LEVELS));
    }

    public static function termsDays(?string $terms): int
    {
        if ($terms && preg_match('/net\s*(\d+)/i', $terms, $m)) { // 0.20.6: "2/10 Net 30" = 30
            return (int) $m[1];
        }
        if ($terms && preg_match('/(\d+)/', $terms, $m)) {
            return (int) $m[1];
        }

        return in_array(strtolower((string) $terms), ['cod', 'cash', 'due on receipt'], true) ? 0 : 15;
    }

    /** Overdue items for one customer as of a date: [[label, date, due, days, open]] */
    public function overdue(Customer $c, string $asOf): array
    {
        $days = self::termsDays($c->terms);
        $as = CarbonImmutable::parse($asOf);
        $items = [];
        foreach ($c->salesOrders()->where('status', S::Invoiced)->whereNotNull('invoiced_at')->get() as $so) {
            $open = Money::of($so->balance());
            $due = CarbonImmutable::parse($so->invoiced_at)->startOfDay()->addDays($days);
            $late = (int) $due->diffInDays($as, false);
            if ($open->isPositive() && $late > 0) {
                $items[] = ['label' => "Invoice {$so->number}", 'date' => CarbonImmutable::parse($so->invoiced_at)->toDateString(), 'due' => $due->toDateString(), 'days' => $late, 'open' => Money::s($open)];
            }
        }
        $opening = Money::of($this->balances->openingRemaining($c));
        if ($opening->isPositive() && $c->beginning_balance_date) {
            $due = CarbonImmutable::parse($c->beginning_balance_date)->addDays($days);
            $late = (int) $due->diffInDays($as, false);
            if ($late > 0) {
                $items[] = ['label' => 'Beginning balance', 'date' => CarbonImmutable::parse($c->beginning_balance_date)->toDateString(), 'due' => $due->toDateString(), 'days' => $late, 'open' => Money::s($opening)];
            }
        }

        return $items;
    }

    public static function levelFor(int $days): ?array
    {
        $hit = null;
        foreach (self::levels() as $l) {
            if ($days >= (int) $l['days']) {
                $hit = $l;
            }
        }

        return $hit;
    }

    public function run(?string $asOf, ?User $by): DunningBatch
    {
        $asOf = CarbonImmutable::parse($asOf ?: now()->toDateString())->toDateString();
        $company = Company::current()->name ?: config('erp.company');

        return DB::transaction(function () use ($asOf, $by, $company) {
            $batch = DunningBatch::create(['as_of' => $asOf, 'created_by' => $by?->id]);
            $total = BigDecimal::zero();
            $n = 0;
            foreach (Customer::where('active', true)->orderBy('name')->get() as $c) {
                $items = $this->overdue($c, $asOf);
                if (! $items) {
                    continue;
                }
                $max = max(array_column($items, 'days'));
                $lvl = self::levelFor($max);
                if (! $lvl) {
                    continue;
                }
                $recent = DunningNotice::where('customer_id', $c->id)->where('level', $lvl['level'])->where('status', '!=', 'dismissed')
                    ->where('created_at', '>=', now()->subDays(self::REPEAT_DAYS))->exists();
                if ($recent) {
                    continue;
                }
                $over = BigDecimal::zero();
                foreach ($items as $i) {
                    $over = $over->plus(Money::of($i['open']));
                }
                $contact = $c->email ?: (method_exists($c, 'contacts') ? $c->contacts()->where('active', true)->value('email') : null);
                [$subject, $body] = $this->compose($c, $lvl, $items, $over, $asOf, $company);
                DunningNotice::create(['dunning_batch_id' => $batch->id, 'customer_id' => $c->id, 'level' => $lvl['level'], 'max_days_overdue' => $max,
                    'overdue_amount' => Money::s($over), 'balance' => $this->balances->balance($c), 'to_email' => $contact,
                    'subject' => $subject, 'body' => $body, 'invoices' => $items]);
                $total = $total->plus($over);
                $n++;
            }
            $batch->update(['notice_count' => $n, 'total_overdue' => Money::s($total)]);

            return $batch;
        });
    }

    private function compose(Customer $c, array $lvl, array $items, BigDecimal $over, string $asOf, string $company): array
    {
        $amt = '$'.number_format((float) (string) $over->toScale(2, \Brick\Math\RoundingMode::HALF_UP), 2);
        $subject = "{$lvl['name']}: {$amt} past due ({$company})";
        $list = implode("\n", array_map(fn ($i) => "  - {$i['label']} dated {$i['date']}, due {$i['due']}, {$i['days']} days late: $".number_format((float) $i['open'], 2), $items));
        $tone = match ((int) $lvl['level']) {
            1 => 'This is a friendly reminder that the following items are past due:',
            2 => 'Our records show the following items are still past due. Please arrange payment this week:',
            default => 'This is a final notice. The following items are seriously past due and your account may be placed on credit hold:',
        };
        $body = "Hello {$c->name},\n\n{$tone}\n\n{$list}\n\nTotal past due as of {$asOf}: {$amt}\n\n"
            ."You can pay or download your statement in the customer portal. If you've already paid, please disregard this notice.\n\nThank you,\n{$company}";

        return [$subject, $body];
    }

    /** One PDF with a statement per notice in the batch (last 90 days of AR activity). */
    public function statementsPdf(DunningBatch $batch): string
    {
        $reports = [];
        $from = CarbonImmutable::parse($batch->as_of)->subDays(90)->toDateString();
        foreach ($batch->notices()->where('status', '!=', 'dismissed')->with('customer')->get() as $n) {
            $reports[] = ['notice' => $n, 'r' => $this->reports->statement($n->customer_id, $from, $batch->as_of->toDateString())];
        }

        return Pdf::loadView('reports.dunning-statements', ['batch' => $batch, 'items' => $reports, 'company' => Company::current()->name ?: config('erp.company')])
            ->setPaper('letter')->output();
    }
}
