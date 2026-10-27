<?php

namespace App\Navigation;

/**
 * 0.20.1: one order for the Modules launcher and the sidebar (Michael's list). Visual groups:
 *   POS … Inventory, Counter Pickup (0.20.5) | Delivery … Accounting | Reports … Compliance | Users, Settings, Imports.
 * Groups not in the list (Marketing, Admin, Platform) stay at the end.
 */
class NavOrder
{
    public const MODULES = [
        ['POS', 'Sales Orders', 'Customers', 'Payments', 'Pricing', 'Promotions', 'Products', 'Vendors', 'Purchasing', 'Warehouse', 'Inventory', 'Counter Pickup'],
        ['Delivery', 'Driver', 'Van Sales', 'Accounting'],
        ['Reports', 'Tax Filings', 'MSA Weekly', 'PACT Reports', 'Compliance'],
        ['Users', 'Company Settings', 'Imports'],
    ];

    /** Sidebar groups in the same order (POS + Sales Orders live in Sales; Vendors + POs in Purchasing; Driver + Van Sales in Delivery;
     *  Tax Filings/MSA Weekly/PACT Reports in Tax Filings; Users/Company Settings/Imports in Settings; vendor bills & payments live in Purchasing since 0.20.17). */
    public const GROUPS = ['Sales', 'Customers', 'Payments', 'Pricing', 'Promotions', 'Products', 'Purchasing', 'Warehouse', 'Inventory', 'Counter Pickup',
        'Delivery', 'Accounting', 'Reports', 'Tax Filings', 'Compliance', 'Settings', 'Marketing', 'Admin', 'Platform'];

    /** @return list<string> */
    public static function flat(): array
    {
        return array_merge(...self::MODULES);
    }
}
