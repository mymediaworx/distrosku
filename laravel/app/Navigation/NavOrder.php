<?php

namespace App\Navigation;

/**
 * 0.20.1: one order for the Modules launcher and the sidebar (Michael's list). Visual groups:
 *   POS … Inventory | Delivery … Bills (AP) | Reports … Compliance | Users, Settings, Imports.
 * Groups not in the list (Marketing, Admin, Platform) stay at the end.
 */
class NavOrder
{
    public const MODULES = [
        ['POS', 'Sales Orders', 'Customers', 'Payments', 'Pricing', 'Promotions', 'Products', 'Vendors', 'Purchasing', 'Warehouse', 'Inventory'],
        ['Delivery', 'Driver', 'Van Sales', 'Accounting', 'Bills (AP)'],
        ['Reports', 'Tax Filings', 'MSA Weekly', 'PACT Reports', 'Compliance'],
        ['Users', 'Settings', 'Imports'],
    ];

    /** Sidebar groups in the same order (POS + Sales Orders live in Sales; Vendors + POs in Purchasing; Driver + Van Sales in Delivery;
     *  Tax Filings/MSA Weekly/PACT Reports in Tax Filings; Users/Settings/Imports in Settings). */
    public const GROUPS = ['Sales', 'Customers', 'Payments', 'Pricing', 'Promotions', 'Products', 'Purchasing', 'Warehouse', 'Inventory',
        'Delivery', 'Accounting', 'Bills (AP)', 'Reports', 'Tax Filings', 'Compliance', 'Settings', 'Marketing', 'Admin', 'Platform'];

    /** @return list<string> */
    public static function flat(): array
    {
        return array_merge(...self::MODULES);
    }
}
