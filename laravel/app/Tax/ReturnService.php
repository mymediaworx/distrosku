<?php

namespace App\Tax;

use App\Exceptions\WorkflowException;
use App\Models\TaxFiling;
use App\Models\User;
use App\Reports\KyCigaretteXml;
use App\Reports\KyTobaccoXml;
use App\Reports\TaxReport;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * KY return lifecycle: draft -> approved (locks the month, XML snapshot) -> filed. Amendments are new versions
 * (v2, v3 ...) with a reason; their XML carries AmendedReturnIndicator "X" and all schedule details (EDI guides, "Amended Returns").
 * XML is always validated against the KY XSD before it can be downloaded or approved.
 */
class ReturnService
{
    public const XML = ['ky_tobacco_distributor' => KyTobaccoXml::class, 'ky_subjobber_cigarette' => KyCigaretteXml::class];

    /** @return array{0: ?string, 1: string[], 2: string[]} [xml or null when invalid, schema errors, data warnings] */
    public function xml(string $type, CarbonImmutable $start, CarbonImmutable $end, bool $amended): array
    {
        $cls = self::XML[$type] ?? null;
        if (! $cls) {
            throw new WorkflowException('This return has no XML format.');
        }
        $gen = new $cls;
        $xml = $gen->generate($start, $end, $amended);
        $errors = $cls::validate($xml);

        return [$errors ? null : $xml, $errors, array_values($gen->warnings)];
    }

    /** Latest version for the same return and period. */
    public static function latest(string $type, $periodStart): ?TaxFiling
    {
        return TaxFiling::where('report_type', $type)->whereDate('period_start', CarbonImmutable::parse($periodStart)->toDateString())
            ->orderByDesc('version')->orderByDesc('id')->first();
    }

    /** Rebuild the draft from current data (drafts only). */
    public function refresh(TaxFiling $f): TaxFiling
    {
        if ($f->status !== 'draft') {
            throw new WorkflowException("v{$f->version} is {$f->status}; only drafts can be regenerated.");
        }
        $r = TaxReport::for($f->report_type)->build($f->period_start->toImmutable(), $f->period_end->toImmutable());
        $f->update(['summary' => $r['summary'], 'data' => $r, 'tax_due' => $r['tax_due']]);

        return $f;
    }

    /** Approve: regenerate, validate XML (blocks if invalid), snapshot it and lock the month. */
    public function approve(TaxFiling $f, User $by): TaxFiling
    {
        if (! $by->isAdmin()) {
            throw new WorkflowException('Only an owner or admin can approve a return.');
        }
        if ($f->status !== 'draft') {
            throw new WorkflowException("v{$f->version} is already {$f->status}.");
        }
        $latest = self::latest($f->report_type, $f->period_start);
        if ($latest && $latest->id !== $f->id) {
            throw new WorkflowException("A newer version (v{$latest->version}) exists for this period.");
        }

        return DB::transaction(function () use ($f, $by) {
            $this->refresh($f);
            $xml = null;
            if (isset(self::XML[$f->report_type])) {
                [$xml, $errors] = $this->xml($f->report_type, $f->period_start->toImmutable(), $f->period_end->toImmutable(), $f->version > 1);
                if (! $xml) {
                    throw new WorkflowException('The XML does not validate against the KY schema, so the return cannot be approved: '.implode(' | ', array_slice($errors, 0, 5)));
                }
            }
            $f->update(['status' => 'approved', 'approved_by' => $by->id, 'approved_at' => now(), 'xml' => $xml]);
            if ($f->amends_id) {
                TaxFiling::whereKey($f->amends_id)->update(['superseded_at' => now()]);
            }

            return $f;
        });
    }

    public function markFiled(TaxFiling $f, User $by, string $confirmation): TaxFiling
    {
        if ($f->status !== 'approved') {
            throw new WorkflowException('Approve the return before marking it filed.');
        }
        $f->update(['status' => 'filed', 'filed_at' => now(), 'filed_by' => $by->id, 'confirmation' => $confirmation]);

        return $f;
    }

    /** Start an amended return (new draft version). Reopens the month for corrections until it is approved. */
    public function amend(TaxFiling $f, User $by, string $reason): TaxFiling
    {
        if (! $by->isAdmin()) {
            throw new WorkflowException('Only an owner or admin can amend a return.');
        }
        if (trim($reason) === '') {
            throw new WorkflowException('Give the reason for the amendment.');
        }
        if (! $f->isLocked()) {
            throw new WorkflowException('Only an approved or filed return can be amended.');
        }
        $latest = self::latest($f->report_type, $f->period_start);
        if ($latest->id !== $f->id) {
            throw new WorkflowException("Amend the latest version (v{$latest->version}).");
        }

        return TaxFiling::create(['report_type' => $f->report_type, 'period_start' => $f->period_start->toDateString(), 'period_end' => $f->period_end->toDateString(),
            'status' => 'draft', 'version' => $f->version + 1, 'amends_id' => $f->id, 'amendment_reason' => mb_substr(trim($reason), 0, 500),
            'summary' => $f->summary, 'data' => $f->data, 'tax_due' => $f->tax_due]);
    }
}
