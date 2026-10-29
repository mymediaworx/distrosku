<?php

namespace App\Imports\Types;

use App\Delivery\DeliveryDays;
use App\Imports\RowResult;
use App\Models\Customer;
use App\Models\CustomerGroup;
use App\Models\ImportBatch;
use App\Support\CustomerType;

class CustomersImport extends ImportType
{
    public function key(): string
    {
        return 'customers';
    }

    public function label(): string
    {
        return 'Customers';
    }

    public function fields(): array
    {
        return [
            'code' => ['label' => 'Account Number', 'required' => true, 'example' => 'C1001'],
            'name' => ['label' => 'Customer Name', 'required' => true, 'example' => 'Corner Market'],
            'dba' => ['label' => 'DBA', 'example' => 'Corner Mkt'],
            'type' => ['label' => 'Type (C-store, Liquor store, Smoke shop, Retailer, Restaurant, Food truck, Wholesale distributor, Other)', 'example' => 'retailer'],
            'email' => ['label' => 'Email'], 'phone' => ['label' => 'Phone'],
            'terms' => ['label' => 'Terms', 'example' => 'Net 15'],
            'credit_limit' => ['label' => 'Credit limit', 'example' => '5000'],
            'fein' => ['label' => 'FEIN'],
            'price_group' => ['label' => 'Price group code'],
            'delivery_days' => ['label' => 'Delivery days', 'example' => 'mon,thu'],
            'active' => ['label' => 'Active (yes/no)'],
        ];
    }

    public function evaluate(array $v, ImportBatch $b): RowResult
    {
        $e = [];
        static::required($v, ['code' => 'Account Number'], $e);
        $code = static::s($v, 'code');
        $c = $code ? Customer::where('code', $code)->first() : null;
        if (! $c && static::s($v, 'name') === null) {
            $e[] = 'Name is required for a new customer.';
        }
        $type = static::s($v, 'type');
        if ($type && CustomerType::map($type) === 'other' && strtolower(trim($type)) !== 'other') {
            $e[] = "Type \"{$type}\" must be one of: ".implode(', ', CustomerType::OPTIONS).'.';
        }
        $email = static::s($v, 'email');
        if ($email && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $e[] = "Email \"{$email}\" is not valid.";
        }
        $groupId = null;
        if ($g = static::s($v, 'price_group')) {
            $groupId = CustomerGroup::where('code', $g)->value('id');
            if (! $groupId) {
                $e[] = "Price group \"{$g}\" does not exist.";
            }
        }
        $days = static::s($v, 'delivery_days');
        $attrs = [
            'name' => static::s($v, 'name'), 'dba' => static::s($v, 'dba'), 'type' => $type ? CustomerType::map($type) : null, 'email' => $email, 'phone' => static::s($v, 'phone'),
            'terms' => static::s($v, 'terms'), 'credit_limit' => (($cl = static::num($v, 'credit_limit', $e, 'Credit limit')) === null ? null : number_format((float) $cl, 2, '.', '')), 'fein' => static::s($v, 'fein'),
            'customer_group_id' => $groupId, 'delivery_days' => $days ? DeliveryDays::toCsv($days) : null, 'active' => static::bool($v, 'active', $e),
        ];
        if ($days && ! $attrs['delivery_days']) {
            $e[] = "Delivery days \"{$days}\" not understood (use mon,tue,...).";
        }
        $diff = static::diff($c, $attrs);

        return new RowResult($e, $c ? ($diff ? 'update' : 'unchanged') : 'create', $diff, $code, ['model' => $c, 'attrs' => $attrs]);
    }

    public function apply(array $v, ImportBatch $b, RowResult $r): string
    {
        $c = $r->context['model'] ?? null;
        $attrs = static::filled($r->context['attrs']);
        if ($c) {
            $c->update($attrs);
        } else {
            Customer::create(['code' => $r->key] + $attrs);
        }

        return $r->action;
    }
}
