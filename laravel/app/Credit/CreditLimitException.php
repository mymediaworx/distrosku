<?php

namespace App\Credit;

use App\Exceptions\WorkflowException;

class CreditLimitException extends WorkflowException
{
    public function __construct(string $message, public readonly string $limit = '0', public readonly string $exposure = '0', public readonly string $amount = '0')
    {
        parent::__construct($message);
    }
}
