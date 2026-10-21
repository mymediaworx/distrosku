<?php

namespace App\Catalog;

use App\Models\Department;
use App\Models\PriceSubmission;
use App\Models\Product;
use App\Models\SharedProduct;
use App\Services\Money;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class CatalogService
{
    public function __construct(private ImageService $images) {}

    /** Next {DEPT}-{0001} code. Locked per-department counter in `sequences`; continues after existing codes; never collides. */
    public function nextSku(Department $d): string
    {
        $code = strtoupper($d->code);
        $name = 'SKU:'.$code;

        return DB::transaction(function () use ($code, $name) {
            $row = DB::table('sequences')->where('name', $name)->lockForUpdate()->first();
            if (! $row) {
                $max = 0;
                foreach (Product::where('sku', 'like', $code.'-%')->pluck('sku') as $s) {
                    if (preg_match('/^'.preg_quote($code, '/').'-(\d+)$/', $s, $m)) {
                        $max = max($max, (int) $m[1]);
                    }
                }
                DB::table('sequences')->insertOrIgnore(['name' => $name, 'next_value' => $max + 1, 'created_at' => now(), 'updated_at' => now()]);
                $row = DB::table('sequences')->where('name', $name)->lockForUpdate()->first();
            }
            $n = (int) $row->next_value;
            do {
                $sku = $code.'-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT);
                $n++;
            } while (Product::where('sku', $sku)->exists());
            DB::table('sequences')->where('name', $name)->update(['next_value' => $n, 'updated_at' => now()]);

            return $sku;
        });
    }

    /**
     * Create a catalog SKU on the existing products table. $data: department_id, name, price, upc?, description?
     * $image: UploadedFile or already-stored public path.
     */
    public function create(array $data, UploadedFile|string|null $image = null, string $source = 'admin'): Product
    {
        $d = Department::where('active', true)->findOrFail($data['department_id']);
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '') {
            throw new RuntimeException('Name is required.');
        }
        $path = null;
        if ($image instanceof UploadedFile) {
            $path = $this->images->store($image, 'catalog');
        } elseif (is_string($image) && $image !== '') {
            $path = $this->images->normalizeStored($image, 'catalog');
        }

        return DB::transaction(function () use ($d, $data, $name, $path, $source) {
            $p = Product::create([
                'sku' => $this->nextSku($d), 'name' => mb_substr($name, 0, 255), 'description' => $data['description'] ?? null,
                'price' => Money::s($data['price'] ?? 0), 'upc' => self::cleanUpc($data['upc'] ?? null), 'department_id' => $d->id,
                'image_path' => $path, 'tax_class' => $data['tax_class'] ?? 'general', 'cost' => $data['cost'] ?? 0, 'active' => true,
            ]);
            $this->link($p, $source);

            return $p->fresh();
        });
    }

    /** Link a SKU to the shared product master by normalized name (create if missing) and record a price submission. */
    public function link(Product $p, string $source = 'admin'): SharedProduct
    {
        $norm = SharedProduct::normalize($p->name);
        $sp = SharedProduct::firstOrCreate(['normalized_name' => $norm], ['name' => $p->name, 'description' => $p->description, 'image_path' => $p->image_path]);
        if (! $sp->image_path && $p->image_path) {
            $sp->update(['image_path' => $p->image_path]);
        }
        if (! $sp->description && $p->description) {
            $sp->update(['description' => $p->description]);
        }
        if ((int) $p->shared_product_id !== $sp->id) {
            $p->forceFill(['shared_product_id' => $sp->id])->saveQuietly();
        }
        PriceSubmission::create(['shared_product_id' => $sp->id, 'product_id' => $p->id, 'price' => Money::s($p->price ?? 0), 'source' => $source]);

        return $sp;
    }

    public static function cleanUpc(?string $upc): ?string
    {
        $u = preg_replace('/[^0-9A-Za-z]/', '', (string) $upc);

        return $u === '' ? null : substr($u, 0, 20);
    }

    /** Lookup by exact SKU code / UPC, or name contains. */
    public function lookup(string $q, int $limit = 25)
    {
        $q = trim($q);
        if ($q === '') {
            return collect();
        }
        $clean = self::cleanUpc($q);

        return Product::with('department')->where('active', true)->notArchived()
            ->where(fn ($w) => $w->where('sku', strtoupper($q))->orWhere('upc', $clean)->orWhere('name', 'like', '%'.str_replace(['%', '_'], ['\%', '\_'], $q).'%'))
            ->orderByRaw('CASE WHEN sku = ? THEN 0 WHEN upc = ? THEN 1 ELSE 2 END', [strtoupper($q), $clean])->limit($limit)->get();
    }

    /** Research rows: shared products with avg/min/max of submitted prices. */
    public static function researchQuery()
    {
        return SharedProduct::query()
            ->withCount('priceSubmissions')
            ->withAvg('priceSubmissions as avg_price', 'price')
            ->withMin('priceSubmissions as min_price', 'price')
            ->withMax('priceSubmissions as max_price', 'price');
    }
}
