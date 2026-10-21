<?php

namespace App\Imports;

class RowResult
{
    /** @param list<string> $errors */
    public function __construct(
        public array $errors = [],
        public ?string $action = null,     // create|update|unchanged|schedule
        public array $diff = [],
        public ?string $key = null,        // natural key label (duplicate detection)
        public mixed $context = null,      // resolved models for apply()
        public bool $unmatched = false,    // vendor price file: no product found (flag, not an error)
    ) {}

    public function ok(): bool
    {
        return ! $this->errors && ! $this->unmatched;
    }
}
