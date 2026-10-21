<?php

namespace App\Portal;

use App\Models\Customer;
use App\Models\Product;
use App\Services\Money;

/** Customer-specific portal price: delegates to the shared PricingEngine (contract, group, qty break, dated, promotions). */
class PortalPricing
{
    public function price(Product $product, Customer $customer, mixed $qty = 1): string
    {
        return app(\App\Pricing\PricingEngine::class)->quote($product, $customer, $qty)->price;
    }
}
