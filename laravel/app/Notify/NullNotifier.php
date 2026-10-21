<?php

namespace App\Notify;

use App\Models\Customer;

/** Sends nothing (vegatest1 has no mail; BrandWorx Marketing sending is off). */
class NullNotifier implements TransactionalNotifier
{
    public function notify(string $template, Customer $customer, array $vars = []): array
    {
        return [];
    }
}
