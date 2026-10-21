<?php

namespace Database\Seeders;

use App\Catalog\CatalogService;
use App\Models\Company;
use App\Models\Product;
use Illuminate\Database\Seeder;

/** Idempotent phase 4 setup: company record; link existing products to the shared product master (one price each, once). */
class Phase4Seeder extends Seeder
{
    public function run(): void
    {
        Company::current();
        $svc = app(CatalogService::class);
        Product::whereNull('shared_product_id')->orderBy('id')->each(fn (Product $p) => $svc->link($p, 'import'));
    }
}
