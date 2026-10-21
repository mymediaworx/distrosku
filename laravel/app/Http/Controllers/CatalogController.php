<?php

namespace App\Http\Controllers;

use App\Catalog\Barcode;
use App\Catalog\ImageService;
use App\Models\Company;
use App\Models\Product;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

/** Catalog exports and barcode labels (signed-in users with catalog access, or a valid public token). */
class CatalogController extends Controller
{
    private function products(Request $r)
    {
        $ids = array_filter(array_map('intval', explode(',', (string) $r->query('ids'))));

        return Product::with('department')->when($ids, fn ($q) => $q->whereIn('id', $ids), fn ($q) => $q->notArchived())->orderBy('sku')->get();
    }

    public static function allowed(Request $r): bool
    {
        if ($t = $r->route('token')) {
            $c = Company::current();

            return $c->public_token && hash_equals($c->public_token, (string) $t) && ! $c->suspended;
        }

        return (bool) $r->user()?->canModule('catalog', 'view');
    }

    public function labels(Request $r)
    {
        abort_unless(self::allowed($r), 403);
        $products = $this->products($r);
        abort_if($products->isEmpty() || ! $r->query('ids'), 404);
        $copies = max(1, min(50, (int) $r->query('copies', 1)));
        if ($r->query('format') === 'pdf') {
            $pdf = Pdf::loadView('catalog.labels-pdf', ['products' => $products, 'copies' => $copies])->setPaper([0, 0, 162, 90]); // 2.25" x 1.25"

            return $pdf->download('labels.pdf');
        }

        return view('catalog.labels', ['products' => $products, 'copies' => $copies, 'pdfUrl' => $r->fullUrlWithQuery(['format' => 'pdf'])]);
    }

    public function csv(Request $r)
    {
        abort_unless(self::allowed($r) && ! $r->route('token'), 403);
        $rows = $this->products($r);

        return response()->streamDownload(function () use ($rows) {
            $h = fopen('php://output', 'w');
            fputcsv($h, ['sku', 'barcode', 'name', 'description', 'department_code', 'department', 'price', 'upc', 'tax_class', 'active', 'image_url']);
            foreach ($rows as $p) {
                fputcsv($h, [$p->sku, $p->barcodeValue(), $p->name, $p->description, $p->department?->code, $p->department?->name, $p->price, $p->upc,
                    $p->tax_class?->value, $p->active ? 1 : 0, $p->imageUrl()]);
            }
            fclose($h);
        }, 'catalog-'.now()->format('Ymd').'.csv', ['Content-Type' => 'text/csv']);
    }

    public function pdf(Request $r)
    {
        abort_unless(self::allowed($r) && ! $r->route('token'), 403);
        $rows = $this->products($r)->map(fn ($p) => ['p' => $p, 'img' => ImageService::dataUri($p->image_path)]);

        return Pdf::loadView('catalog.catalog-pdf', ['rows' => $rows, 'company' => Company::current()])->setPaper('letter')->download('catalog-'.now()->format('Ymd').'.pdf');
    }

    public static function barcodeSvg(Product $p): string
    {
        return Barcode::svg($p->barcodeValue());
    }
}
