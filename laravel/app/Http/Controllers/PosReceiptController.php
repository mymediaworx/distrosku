<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\SalesOrder;
use App\Services\Money;
use Illuminate\Http\Request;

/**
 * Issue #27: printable counter receipt for a POS sale, printed through Chrome.
 * 80 mm thermal roll (default) or letter. Behind PosAccess (password or PIN session with POS rights).
 */
class PosReceiptController extends Controller
{
    public function show(Request $request, SalesOrder $so)
    {
        abort_unless($so->channel === 'pos', 404);

        $format = $request->query('format') === 'letter' ? 'letter' : '80mm';
        $so->load(['lines.product', 'customer', 'warehouse', 'payments' => fn ($q) => $q->orderBy('id')]);

        // Change due is display-only: the cash tendered isn't stored, so the POS passes it on the link.
        $tendered = $request->query('tendered');
        $cashPaid = Money::add(...($so->payments->where('method', 'cash')->pluck('amount')->all() ?: [0]));
        $change = is_numeric($tendered) && Money::of($cashPaid)->isPositive() && Money::of($tendered)->isGreaterThanOrEqualTo(Money::of($cashPaid))
            ? Money::s(Money::of($tendered)->minus(Money::of($cashPaid))) : null;

        return response()->view('pos.receipt', [
            'so' => $so,
            'company' => Company::current(),
            'format' => $format,
            'tendered' => $change !== null ? Money::s($tendered) : null,
            'change' => $change,
            'autoPrint' => $request->boolean('print'),
        ])->header('Cache-Control', 'no-store');
    }
}
