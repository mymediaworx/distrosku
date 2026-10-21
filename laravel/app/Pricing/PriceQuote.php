<?php

namespace App\Pricing;

final class PriceQuote
{
    public function __construct(
        public readonly string $price,
        public readonly string $listPrice,
        public readonly string $source,       // list | dated | qty_break | group | contract (optionally +promo) | override | override_all
        public readonly ?int $priceRuleId = null,
        public readonly ?int $promotionId = null,
        public readonly ?int $overrideId = null,  // 0.19 customer price override
    ) {}

    public function label(): string
    {
        return PricingEngine::sourceLabel($this->source);
    }
}
