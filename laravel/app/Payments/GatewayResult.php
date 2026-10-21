<?php

namespace App\Payments;

final class GatewayResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $transactionId = null,
        public readonly ?string $code = null,
        public readonly ?string $message = null,
        public readonly array $extra = [],
    ) {}
}
