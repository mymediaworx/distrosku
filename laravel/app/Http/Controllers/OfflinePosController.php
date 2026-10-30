<?php

namespace App\Http\Controllers;

use App\Compliance\WalkInRule;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Warehouse;
use App\Pos\OfflineSaleSync;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Schema;

/**
 * #26 device side: the offline POS page (works with no signal once loaded) and the catalog it keeps on the device.
 * Sales made offline are queued in the browser and sent to pos.offline.sync, which runs the same checkout as the live POS
 * and holds anything doubtful for review (prices, compliance, credit). The catalog never contains costs or balances.
 */
class OfflinePosController extends Controller
{
    public function page()
    {
        $wh = Warehouse::where('code', 'MAIN')->value('id') ?? Warehouse::value('id');

        return response()->view('pos.offline', [
            'warehouseId' => $wh,
            'maxAgeHours' => OfflineSaleSync::MAX_CATALOG_AGE_HOURS,
            'tenders' => OfflineSaleSync::OFFLINE_TENDERS,
        ])->header('Cache-Control', 'no-cache');
    }

    public function catalog(): JsonResponse
    {
        $pending = Schema::hasColumn('products', 'approval_status');
        $products = Product::query()->where('active', true)->whereNull('archived_at')
            ->when($pending, fn ($q) => $q->whereNull('approval_status')) // #45: pending quick-tools SKUs can't be sold
            ->with(['barcodes.unit:id,code,factor'])
            ->orderBy('name')
            ->get(['id', 'sku', 'name', 'upc', 'price', 'tax_class', 'base_unit_name'])
            ->map(fn (Product $p) => [
                'id' => $p->id, 'sku' => $p->sku, 'name' => $p->name, 'upc' => $p->upc, 'price' => (string) $p->price,
                'unit' => $p->base_unit_name ?: 'Each', 'regulated' => $p->isRegulated(),
                'codes' => $p->barcodes->map(fn ($b) => ['code' => $b->code, 'factor' => $b->unit ? (string) $b->unit->factor : '1', 'unit' => $b->unit?->code])->values(),
            ]);
        $customers = Customer::query()->where('active', true)->orderBy('name')->get(['id', 'code', 'name', 'dba', 'walk_in'])
            ->map(fn (Customer $c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name, 'dba' => $c->dba, 'walk_in' => WalkInRule::isWalkIn($c)]);

        return response()->json([
            'at' => now()->toIso8601String(),
            'cashier' => ['id' => auth()->id(), 'name' => auth()->user()?->name],
            'products' => $products,
            'customers' => $customers,
        ])->header('Cache-Control', 'no-store');
    }
}
