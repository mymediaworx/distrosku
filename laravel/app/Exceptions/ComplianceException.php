<?php

namespace App\Exceptions;

use RuntimeException;

class ComplianceException extends RuntimeException
{
    /** @param array<string, array<int, string>> $reasons keyed by SKU */
    public function __construct(public readonly array $reasons)
    {
        $flat = [];
        foreach ($reasons as $sku => $list) {
            foreach ($list as $r) {
                $flat[] = "$sku: $r";
            }
        }
        parent::__construct('Compliance blocked: '.implode('; ', $flat));
    }
}
