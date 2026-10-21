<?php

namespace App\Imports\Types;

use App\Imports\RowResult;
use App\Models\ImportBatch;
use App\Models\Vendor;

class VendorsImport extends ImportType
{
    public function key(): string
    {
        return 'vendors';
    }

    public function label(): string
    {
        return 'Vendors';
    }

    public function fields(): array
    {
        return [
            'code' => ['label' => 'Vendor code', 'required' => true, 'example' => 'V100'],
            'name' => ['label' => 'Name', 'required' => true, 'example' => 'Acme Tobacco'],
            'is_manufacturer' => ['label' => 'Manufacturer (yes/no)'], 'email' => ['label' => 'Email'], 'fein' => ['label' => 'FEIN'],
            'address' => ['label' => 'Address'], 'city' => ['label' => 'City'], 'state' => ['label' => 'State'], 'zip' => ['label' => 'ZIP'],
            'ky_license_number' => ['label' => 'KY license #'], 'lead_time_days' => ['label' => 'Lead time (days)'],
        ];
    }

    public function evaluate(array $v, ImportBatch $b): RowResult
    {
        $e = [];
        static::required($v, ['code' => 'Vendor code'], $e);
        $code = static::s($v, 'code');
        $m = $code ? Vendor::where('code', $code)->first() : null;
        if (! $m && static::s($v, 'name') === null) {
            $e[] = 'Name is required for a new vendor.';
        }
        $email = static::s($v, 'email');
        if ($email && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $e[] = "Email \"{$email}\" is not valid.";
        }
        $lt = static::num($v, 'lead_time_days', $e, 'Lead time');
        $attrs = ['name' => static::s($v, 'name'), 'is_manufacturer' => static::bool($v, 'is_manufacturer', $e), 'email' => $email, 'fein' => static::s($v, 'fein'),
            'address' => static::s($v, 'address'), 'city' => static::s($v, 'city'), 'state' => ($s = static::s($v, 'state')) ? strtoupper($s) : null,
            'zip' => static::s($v, 'zip'), 'ky_license_number' => static::s($v, 'ky_license_number'), 'lead_time_days' => $lt !== null ? (int) $lt : null];
        $diff = static::diff($m, $attrs);

        return new RowResult($e, $m ? ($diff ? 'update' : 'unchanged') : 'create', $diff, $code, ['model' => $m, 'attrs' => $attrs]);
    }

    public function apply(array $v, ImportBatch $b, RowResult $r): string
    {
        $attrs = static::filled($r->context['attrs']);
        $r->context['model'] ? $r->context['model']->update($attrs) : Vendor::create(['code' => $r->key] + $attrs);

        return $r->action;
    }
}
