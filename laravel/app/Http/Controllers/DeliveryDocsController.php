<?php

namespace App\Http\Controllers;

use App\Models\Company;
use App\Models\DeliveryStop;
use App\Models\SalesOrder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/** Phase 15: delivery receipt / proof PDF (signature, acknowledgments, photo) and stop photo viewer. */
class DeliveryDocsController extends Controller
{
    private static function canSee(Request $r): bool
    {
        $u = $r->user();

        return $u && ($u->canModule('sales_orders', 'view') || $u->canModule('delivery_manifests', 'view') || $u->canModule('pickup_signature', 'view'));
    }

    public function proof(Request $r, SalesOrder $so)
    {
        abort_unless(self::canSee($r), 403);
        $so->load('lines.product', 'customer', 'shipTo', 'signatures.user', 'deliveryStops.manifest.driver');
        $photos = $so->deliveryStops->filter(fn ($s) => $s->photo_path && Storage::disk('local')->exists($s->photo_path))
            ->map(fn ($s) => 'data:'.(Storage::disk('local')->mimeType($s->photo_path) ?: 'image/jpeg').';base64,'.base64_encode(Storage::disk('local')->get($s->photo_path)))->values();

        return Pdf::loadView('docs.delivery-proof', ['so' => $so, 'photos' => $photos, 'company' => Company::current(), 'info' => config('erp.company')])
            ->setPaper('letter')->download("delivery-{$so->number}.pdf");
    }

    public function photo(Request $r, DeliveryStop $stop)
    {
        abort_unless(self::canSee($r), 403);
        abort_unless($stop->photo_path && Storage::disk('local')->exists($stop->photo_path), 404);

        return Storage::disk('local')->response($stop->photo_path);
    }
}
