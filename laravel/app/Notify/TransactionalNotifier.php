<?php

namespace App\Notify;

use App\Models\Customer;

/** 0.19: transactional email / SMS driver. Default binding = NullNotifier (no sends). */
interface TransactionalNotifier
{
    /** Queue the template for the customer on every channel allowed. Returns what was queued / skipped (for logs/tests). */
    public function notify(string $template, Customer $customer, array $vars = []): array;
}
