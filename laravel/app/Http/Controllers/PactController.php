<?php

namespace App\Http\Controllers;

use App\Compliance\PactReportService;
use App\Models\Company;
use App\Models\PactRegistration;
use App\Models\PactReport;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/** PACT Act printables (no footer): monthly state report (HTML / PDF / CSV) and the registration statement. */
class PactController extends Controller
{
    private function out(Request $r, string $view, array $data, string $file)
    {
        $data += ['company' => Company::current(), 'pdf' => $r->query('format') === 'pdf'];

        return $data['pdf'] ? Pdf::loadView($view, $data)->setPaper('letter', 'landscape')->download($file) : response()->view($view, $data);
    }

    public function report(Request $r, PactReport $report, PactReportService $svc)
    {
        abort_unless($r->user()?->canModule('pact'), 403);
        $name = "pact-{$report->state}-{$report->period}";
        if ($r->query('format') === 'csv') {
            return response($svc->csv($report), 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => "attachment; filename=\"$name.csv\""]);
        }

        return $this->out($r, 'compliance.pact-report', ['report' => $report, 'lines' => $svc->lines($report),
            'us' => PactReportService::registration('US'), 'reg' => PactReportService::registration($report->state)], "$name.pdf");
    }

    public function registration(Request $r, PactRegistration $registration)
    {
        abort_unless($r->user()?->canModule('pact'), 403);

        return $this->out($r, 'compliance.pact-registration', ['reg' => $registration], "pact-registration-{$registration->jurisdiction}.pdf");
    }
}
