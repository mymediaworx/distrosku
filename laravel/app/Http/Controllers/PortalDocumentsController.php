<?php

namespace App\Http\Controllers;

use App\Enums\SalesOrderStatus as S;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\SalesOrder;
use App\Services\CustomerBalanceService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Invoice and statement PDFs, for portal contacts (own customer only) and staff (customers module). */
class PortalDocumentsController extends Controller
{
    public static function invoicePdf(SalesOrder $so)
    {
        $so->load('lines.product', 'customer', 'shipTo', 'payments', 'signatures.user');

        return Pdf::loadView('docs.invoice', ['so' => $so, 'company' => Company::current(), 'info' => config('erp.company')])
            ->setPaper('letter')->download("invoice-{$so->number}.pdf");
    }

    public static function statementPdf(Customer $c)
    {
        $svc = app(CustomerBalanceService::class);
        $invoices = $c->salesOrders()->where('status', S::Invoiced)->orderBy('invoiced_at')->get()->filter(fn ($so) => (float) $so->balance() != 0.0);
        $payments = Payment::where('customer_id', $c->id)->where('paid_on', '>=', now()->subDays(90)->toDateString())->orderBy('paid_on')->get();

        return Pdf::loadView('docs.statement', ['c' => $c, 'company' => Company::current(), 'info' => config('erp.company'), 'invoices' => $invoices,
            'payments' => $payments, 'aging' => $svc->aging($c), 'opening' => $svc->openingRemaining($c)])
            ->setPaper('letter')->download("statement-{$c->code}-".now()->format('Ymd').'.pdf');
    }

    public function portalInvoice(SalesOrder $so)
    {
        abort_unless((int) $so->customer_id === (int) Auth::guard('portal')->user()->customer_id && $so->status === S::Invoiced, 404);

        return self::invoicePdf($so);
    }

    public function portalStatement()
    {
        return self::statementPdf(Auth::guard('portal')->user()->customer);
    }

    public function adminInvoice(Request $r, SalesOrder $so)
    {
        abort_unless($r->user()?->canModule('sales_orders', 'view') || $r->user()?->canModule('customers', 'view'), 403);

        return self::invoicePdf($so);
    }

    public function adminStatement(Request $r, Customer $customer)
    {
        abort_unless($r->user()?->canModule('customers', 'view'), 403);

        return self::statementPdf($customer);
    }
}
