<?php

namespace App\Http\Controllers;

use App\Models\TillSession;
use App\Till\TillService;

/** #44: printable Z-report (closed till) or X-report (open till, so far). */
class TillReportController extends Controller
{
    public function show(TillSession $till)
    {
        $u = auth()->user();
        abort_unless($u && ($u->canModule('pos', 'manage') || $u->canModule('payments', 'view')), 403);

        return view('pos.till-report', ['s' => $till->load('openedBy', 'closedBy'), 'r' => TillService::report($till)]);
    }
}
