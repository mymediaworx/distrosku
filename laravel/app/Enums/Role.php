<?php

namespace App\Enums;

enum Role: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case BrandworxAdmin = 'brandworx_admin';
    case Warehouse = 'warehouse';
    case Sales = 'sales';
    case Driver = 'driver';

    public static function options(): array
    {
        $o = [];
        foreach (self::cases() as $c) {
            $o[$c->value] = ucwords(str_replace('_', ' ', $c->value));
        }

        return $o;
    }
}
