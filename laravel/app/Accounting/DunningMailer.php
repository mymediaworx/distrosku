<?php

namespace App\Accounting;

use App\Audit\Audit;
use App\Mail\DunningNoticeMail;
use App\Mailing\MailConfig;
use App\Models\Company;
use App\Models\Customer;
use App\Models\DunningNotice;
use App\Models\Setting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;

/**
 * 0.20.18: automatic dunning email. Runs from the daily `dunning:send` command (07:00) or "Send now" on the Dunning page.
 * Rules:
 *  - Off by default (Company Settings > Email > Auto-email dunning). Turning it on records a start time; drafts made before then are never sent.
 *  - Uses the existing dunning stages and rules (DunningService::run makes the notices; this class only delivers them).
 *  - Recipients: the customer's active contacts flagged Billing contact (with an email); else the customer email; else the first active contact with an email.
 *  - One email per customer per stage within STAGE_QUIET_DAYS. A repeat notice for the same stage is marked "duplicate", never emailed.
 *  - No email on file, or customer opted out -> the print list. A failed send is logged and retried on the next run (MAX_ATTEMPTS), then goes to the print list.
 *  - Every result is written to the customer's History (who / when / to / stage / result). Passwords are never logged.
 */
class DunningMailer
{
    public const MAX_ATTEMPTS = 5;

    public const STAGE_QUIET_DAYS = 90;

    public function __construct(private DunningService $service) {}

    public static function enabled(): bool
    {
        return (bool) Setting::get('dunning_auto_email');
    }

    public static function setEnabled(bool $on): void
    {
        $was = self::enabled();
        Setting::put('dunning_auto_email', $on ? '1' : '0');
        if ($on && ! $was) {
            Setting::put('dunning_auto_email_since', now()->toDateTimeString());
        }
    }

    public static function since(): ?CarbonImmutable
    {
        $v = Setting::get('dunning_auto_email_since');

        return $v ? CarbonImmutable::parse($v) : null;
    }

    /** True when the server has a mailer that actually delivers. */
    public static function canSend(): bool
    {
        MailConfig::apply();

        return ! \App\Filament\Pages\Dunning::emailNotConfigured();
    }

    /** @return list<string> */
    public static function recipients(Customer $c): array
    {
        $contacts = $c->contacts()->where('active', true)->whereNotNull('email')->where('email', '!=', '')->orderBy('id')->get();
        $emails = $contacts->where('is_billing_contact', true)->pluck('email')->all();
        if (! $emails && filled($c->email)) {
            $emails = [$c->email];
        }
        if (! $emails && $contacts->isNotEmpty()) {
            $emails = [$contacts->first()->email];
        }
        $out = [];
        foreach ($emails as $e) {
            $e = mb_strtolower(trim((string) $e));
            if (filter_var($e, FILTER_VALIDATE_EMAIL) && ! in_array($e, $out, true)) {
                $out[] = $e;
            }
        }

        return $out;
    }

    /**
     * One scheduled run: make today's notices, then deliver every pending one.
     *
     * @return array{skipped: ?string, created: int, sent: int, failed: int, print: int, duplicate: int, not_due: int}
     */
    public function runDaily(?string $asOf = null, bool $force = false): array
    {
        $r = ['skipped' => null, 'created' => 0, 'sent' => 0, 'failed' => 0, 'print' => 0, 'duplicate' => 0, 'not_due' => 0];
        if (! $force && ! self::enabled()) {
            $r['skipped'] = 'Auto-email dunning is off.';

            return $r;
        }
        if (! self::canSend()) {
            $r['skipped'] = 'Email is not configured (mailer is log/array or no SMTP host). Nothing was sent.';

            return $r;
        }
        $asOf = CarbonImmutable::parse($asOf ?: now()->toDateString())->toDateString();
        $batch = $this->service->run($asOf, null);
        $r['created'] = (int) $batch->notice_count;
        if ($batch->notice_count === 0) {
            $batch->delete(); // do not leave an empty batch behind every day
        }

        return $this->deliverPending($asOf, $r);
    }

    /** @param array<string, mixed> $r */
    public function deliverPending(string $asOf, array $r = []): array
    {
        $r += ['skipped' => null, 'created' => 0, 'sent' => 0, 'failed' => 0, 'print' => 0, 'duplicate' => 0, 'not_due' => 0];
        $since = self::since();
        $q = DunningNotice::query()->with(['customer', 'batch'])
            ->where('status', '!=', 'dismissed')
            ->where(fn ($w) => $w->whereNull('email_status')->orWhere(fn ($x) => $x->where('email_status', 'failed')->where('email_attempts', '<', self::MAX_ATTEMPTS)))
            ->where('created_at', '>=', now()->subDays(DunningService::REPEAT_DAYS))
            ->orderBy('id');
        if ($since) {
            $q->where('created_at', '>=', $since);
        }
        foreach ($q->get() as $n) {
            $res = $this->deliver($n, $asOf);
            $r[$res] = ($r[$res] ?? 0) + 1;
        }

        return $r;
    }

    /** Deliver one notice. Returns sent | failed | print | duplicate | not_due. */
    public function deliver(DunningNotice $n, string $asOf): string
    {
        $c = $n->customer;
        if (! $c) {
            return $this->mark($n, 'not_due', null, 'Customer no longer exists');
        }
        if ($c->dunning_email_optout) {
            return $this->mark($n, 'opted_out', null, 'Customer is opted out of dunning email', 'print');
        }
        if (! $this->service->overdue($c, $asOf)) {
            return $this->mark($n, 'not_due', null, 'Nothing is past due any more');
        }
        $already = DunningNotice::where('customer_id', $c->id)->where('level', $n->level)->where('id', '!=', $n->id)
            ->where('email_status', 'sent')->where('emailed_at', '>=', now()->subDays(self::STAGE_QUIET_DAYS))->exists();
        if ($already) {
            return $this->mark($n, 'duplicate', null, "Stage {$n->level} was already emailed in the last ".self::STAGE_QUIET_DAYS.' days');
        }
        $to = self::recipients($c);
        if (! $to) {
            return $this->mark($n, 'no_email', null, 'No email address on file', 'print');
        }
        try {
            $pdf = $this->service->noticesPdf([$n], $n->batch?->as_of?->toDateString() ?? $asOf);
            Mail::to($to)->send(new DunningNoticeMail($n, $pdf, Company::current()->email));
        } catch (\Throwable $e) {
            $n->email_attempts = $n->email_attempts + 1;
            $err = MailConfig::safeError($e);

            return $this->mark($n, 'failed', $to, $err, $n->email_attempts >= self::MAX_ATTEMPTS ? 'print' : 'failed');
        }
        $n->email_attempts = $n->email_attempts + 1;

        return $this->mark($n, 'sent', $to, null);
    }

    /**
     * Record the result on the notice and on the customer's History.
     * $ret is what deliver() returns (defaults to the status).
     */
    private function mark(DunningNotice $n, string $status, ?array $to, ?string $note, ?string $ret = null): string
    {
        $n->email_status = $status;
        $n->email_to = $to ? implode(', ', $to) : $n->email_to;
        $n->email_error = in_array($status, ['sent'], true) ? null : ($note ? mb_substr($note, 0, 500) : null);
        if ($status === 'sent') {
            $n->emailed_at = now();
        }
        $n->save();
        $by = auth()->user()?->name ?: 'System (scheduled dunning email)';
        Audit::action('dunning_email', $n->customer, "Dunning email stage {$n->level}: {$status}".($to ? ' to '.implode(', ', $to) : '').($note ? " ({$note})" : ''),
            ['notice_id' => $n->id, 'stage' => $n->level, 'result' => $status, 'to' => $to ? implode(', ', $to) : null, 'attempt' => $n->email_attempts, 'by' => $by, 'detail' => $note]);

        return $ret ?? $status;
    }

    /** Notices that need to be printed and mailed by hand. */
    public static function printList()
    {
        return DunningNotice::query()->where('status', '!=', 'dismissed')->whereNull('printed_at')
            ->where(fn ($w) => $w->whereIn('email_status', ['no_email', 'opted_out'])->orWhere(fn ($x) => $x->where('email_status', 'failed')->where('email_attempts', '>=', self::MAX_ATTEMPTS)));
    }
}
