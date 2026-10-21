<?php

namespace App\Imports\Types;

use App\Delivery\DeliveryDays;
use App\Imports\RowResult;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\ShipTo;

class ShipTosImport extends ImportType
{
    public function key(): string
    {
        return 'ship_tos';
    }

    public function label(): string
    {
        return 'Ship-to locations';
    }

    public function fields(): array
    {
        return [
            'customer_code' => ['label' => 'Customer number', 'required' => true, 'example' => 'C1001'],
            'name' => ['label' => 'Ship-to name', 'required' => true, 'example' => 'Main store'],
            'address' => ['label' => 'Address'], 'city' => ['label' => 'City'], 'county' => ['label' => 'County'],
            'state' => ['label' => 'State (2 letters)', 'required' => true, 'example' => 'KY'], 'zip' => ['label' => 'ZIP'], 'phone' => ['label' => 'Phone'],
            'route_name' => ['label' => 'Route name'], 'route_sequence' => ['label' => 'Route sequence'],
            'delivery_days' => ['label' => 'Delivery days', 'example' => 'tue'],
        ];
    }

    public function evaluate(array $v, ImportBatch $b): RowResult
    {
        $e = [];
        static::required($v, ['customer_code' => 'Customer number', 'name' => 'Ship-to name'], $e);
        $c = ($cc = static::s($v, 'customer_code')) ? Customer::where('code', $cc)->first() : null;
        if ($cc && ! $c) {
            $e[] = "Customer \"{$cc}\" does not exist (import customers first).";
        }
        $name = static::s($v, 'name');
        $st = $c && $name ? ShipTo::where('customer_id', $c->id)->where('name', $name)->first() : null;
        $state = static::s($v, 'state');
        if ($state !== null && ! preg_match('/^[A-Za-z]{2}$/', $state)) {
            $e[] = "State \"{$state}\" must be a 2-letter code.";
        } elseif (! $st && $state === null) {
            $e[] = 'State is required for a new ship-to.';
        }
        $seq = static::num($v, 'route_sequence', $e, 'Route sequence');
        $days = static::s($v, 'delivery_days');
        $attrs = ['address' => static::s($v, 'address'), 'city' => static::s($v, 'city'), 'county' => static::s($v, 'county'),
            'state' => $state ? strtoupper($state) : null, 'zip' => static::s($v, 'zip'), 'phone' => static::s($v, 'phone'),
            'route_name' => static::s($v, 'route_name'), 'route_sequence' => $seq !== null ? (int) $seq : null,
            'delivery_days' => $days ? DeliveryDays::toCsv($days) : null];
        $diff = static::diff($st, $attrs);

        return new RowResult($e, $st ? ($diff ? 'update' : 'unchanged') : 'create', $diff, "{$cc} / {$name}", ['model' => $st, 'customer' => $c, 'name' => $name, 'attrs' => $attrs]);
    }

    public function apply(array $v, ImportBatch $b, RowResult $r): string
    {
        $attrs = static::filled($r->context['attrs']);
        $r->context['model'] ? $r->context['model']->update($attrs) : ShipTo::create(['customer_id' => $r->context['customer']->id, 'name' => $r->context['name']] + $attrs);

        return $r->action;
    }
}
