<?php

namespace App\Reports;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;

/**
 * A generated tax report: summary lines (form lines) + detail sections (schedules).
 * Shape: ['title','form','period_start','period_end','notes'=>[], 'summary'=>[[line,label,value]], 'tax_due'=>string,
 *         'sections'=>[key=>['title','columns'=>[],'rows'=>[[]]]]]
 */
abstract class TaxReport
{
    abstract public function key(): string;

    abstract public function title(): string;

    abstract public function build(CarbonImmutable $start, CarbonImmutable $end): array;

    public static function for(string $key): self
    {
        return match ($key) {
            'ky_subjobber_cigarette' => new KySubjobberCigaretteReport,
            'ky_tobacco_distributor' => new KyTobaccoDistributorReport,
        };
    }

    public function toCsv(array $r): string
    {
        $h = fopen('php://temp', 'r+');
        fputcsv($h, [$r['title']]);
        fputcsv($h, ['Period', $r['period_start'].' to '.$r['period_end']]);
        fputcsv($h, []);
        fputcsv($h, ['Line', 'Description', 'Value']);
        foreach ($r['summary'] as $line) {
            fputcsv($h, $line);
        }
        foreach ($r['sections'] as $s) {
            fputcsv($h, []);
            fputcsv($h, [$s['title']]);
            fputcsv($h, $s['columns']);
            foreach ($s['rows'] as $row) {
                fputcsv($h, $row);
            }
        }
        if ($r['notes']) {
            fputcsv($h, []);
            fputcsv($h, ['Notes']);
            foreach ($r['notes'] as $n) {
                fputcsv($h, [$n]);
            }
        }
        rewind($h);

        return stream_get_contents($h);
    }

    public function toPdf(array $r): string
    {
        return Pdf::loadView('reports.pdf', ['r' => $r, 'company' => config('erp.company')])
            ->setPaper('letter', 'landscape')->output();
    }

    /** Drill-down map: summary line code => source documents (sales orders / credit memos / purchase orders). */
    protected static function drill(array $map, array $refs): array
    {
        $out = [];
        foreach ($map as $code => $buckets) {
            $out[$code] = array_values(array_merge(...array_map(fn ($b) => $refs[$b] ?? [], $buckets)));
        }

        return $out;
    }
}
