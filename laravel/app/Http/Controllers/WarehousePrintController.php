<?php

namespace App\Http\Controllers;

use App\Inventory\CountService;
use App\Models\Bin;
use App\Models\Company;
use App\Models\InventoryCount;
use App\Models\Package;
use App\Models\PickList;
use App\Models\SalesOrder;
use App\Warehouse\LotService;
use App\Warehouse\WarehouseService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Throwable;

/** Printable pick and packing lists (HTML for the browser's print, ?format=pdf for a PDF). No footer on printables. */
class WarehousePrintController extends Controller
{
    private function out(Request $r, string $view, array $data, string $file)
    {
        $data += ['company' => Company::current(), 'pdf' => $r->query('format') === 'pdf'];
        if ($data['pdf']) {
            return Pdf::loadView($view, $data)->setPaper('letter')->download($file);
        }

        return response()->view($view, $data);
    }

    public function pick(Request $r, SalesOrder $so, WarehouseService $wh)
    {
        abort_unless($r->user()?->canModule('warehouse_scan') || $r->user()?->canModule('sales_orders'), 403);
        try {
            $pl = $wh->pickList($so);
        } catch (Throwable $e) {
            abort(404, $e->getMessage());
        }
        $so->load('customer', 'shipTo', 'warehouse');

        return $this->out($r, 'warehouse.pick-list', ['so' => $so, 'pl' => $pl, 'lines' => $wh->orderedLines($pl)], "pick-{$pl->number}.pdf");
    }

    public function pack(Request $r, SalesOrder $so, WarehouseService $wh)
    {
        abort_unless($r->user()?->canModule('warehouse_scan') || $r->user()?->canModule('sales_orders'), 403);
        $pl = PickList::where('sales_order_id', $so->id)->whereIn('status', ['picked', 'packed'])->first();
        abort_unless($pl, 404, 'Not picked yet.');
        $so->load('customer', 'shipTo', 'warehouse');

        return $this->out($r, 'warehouse.packing-list', ['so' => $so, 'pl' => $pl, 'lines' => $wh->orderedLines($pl),
            'packages' => $pl->packages()->with('lines.pickLine.product')->orderBy('seq')->get()], "packing-{$so->number}.pdf");
    }

    /** Bin barcode labels (CODE128 of the bin code; what put-away scans). ?ids=1,2,3 or ?warehouse=ID for all active bins. */
    public function binLabels(Request $r)
    {
        abort_unless($r->user()?->canModule('bins'), 403);
        $q = Bin::with('warehouse')->orderBy('pick_sequence')->orderBy('code');
        if ($r->filled('ids')) {
            $q->whereIn('id', array_filter(array_map('intval', explode(',', (string) $r->query('ids')))));
        } else {
            $q->where('active', true)->when($r->filled('warehouse'), fn ($w) => $w->where('warehouse_id', (int) $r->query('warehouse')));
        }
        $bins = $q->limit(1000)->get();
        abort_if($bins->isEmpty(), 404, 'No bins.');

        return $this->out($r, 'warehouse.bin-labels', ['bins' => $bins], 'bin-labels.pdf');
    }

    /** Recall look-up export (CSV). */
    public function recall(Request $r)
    {
        abort_unless($r->user()?->canModule('lots'), 403);
        $rep = app(LotService::class)->recall((string) $r->query('lot'), $r->integer('product') ?: null);

        return response()->streamDownload(function () use ($rep) {
            $h = fopen('php://output', 'w');
            fputcsv($h, $rep['columns']);
            foreach ($rep['rows'] as $row) {
                fputcsv($h, array_map(fn ($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v, $row));
            }
            fclose($h);
        }, 'recall-'.preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $r->query('lot')).'.csv', ['Content-Type' => 'text/csv']);
    }

    /** Printable count sheet (blind counts hide expected qty from non-admins). No footer. */
    public function countSheet(Request $r, InventoryCount $count)
    {
        abort_unless($r->user()?->canModule('counts'), 403);
        $lines = $count->lines()->with('product.units', 'bin', 'lot')->get()->sortBy(fn ($l) => [$l->bin?->code ?? 'zzz', $l->product->sku, $l->lot?->lot_number ?? ''])->values();

        return $this->out($r, 'warehouse.count-sheet', ['count' => $count->load('warehouse'), 'lines' => $lines,
            'showExpected' => CountService::showExpected($count, $r->user())], "count-{$count->number}.pdf");
    }

    /** 4x6 carton shipping label (manual carrier + tracking). No footer. */
    public function label(Request $r, Package $package)
    {
        abort_unless($r->user()?->canModule('shipping') || $r->user()?->canModule('warehouse_scan'), 403);
        $package->load('carrier', 'pickList.salesOrder.customer', 'pickList.salesOrder.shipTo', 'pickList.salesOrder.warehouse');
        $so = $package->pickList->salesOrder;
        $package->update(['label_printed_at' => now()]);
        $data = ['pkg' => $package, 'so' => $so, 'count' => $package->pickList->packages()->count(), 'url' => $package->trackingUrl(),
            'company' => Company::current(), 'pdf' => $r->query('format') === 'pdf'];
        if ($data['pdf']) {
            return Pdf::loadView('warehouse.shipping-label', $data)->setPaper([0, 0, 288, 432])->download("label-{$so->number}-{$package->seq}.pdf");
        }

        return response()->view('warehouse.shipping-label', $data);
    }
}
