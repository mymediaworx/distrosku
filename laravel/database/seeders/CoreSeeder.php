<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\ComplianceRuleVersion;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

/** Safe to run on every install: chart of accounts, 2 warehouses, starter rules. Idempotent. */
class CoreSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['1000', 'Cash', 'asset'], ['1010', 'Undeposited Funds', 'asset'], ['1100', 'Accounts Receivable', 'asset'],
            ['1200', 'Inventory', 'asset'], ['1250', 'Manufacturer Rebates Receivable', 'asset'], ['1300', 'Prepaid Excise / Stamps', 'asset'],
            ['2000', 'Accounts Payable', 'liability'], ['2100', 'KY Tobacco Excise Payable', 'liability'],
            ['2110', 'IL Tobacco Tax Payable', 'liability'], ['2200', 'Sales Tax Payable', 'liability'],
            ['3000', "Owner's Equity", 'equity'], ['3100', 'Retained Earnings', 'equity'],
            ['4000', 'Sales', 'revenue'], ['4100', 'Buydown / Manufacturer Credits', 'revenue'],
            ['5000', 'Cost of Goods Sold', 'expense'], ['5050', 'Manufacturer Rebates & Buydowns (contra-COGS)', 'expense'], ['6000', 'Operating Expenses', 'expense'],
            ['6100', 'Freight', 'expense'], ['6200', 'Card Processing Fees', 'expense'],
        ] as [$code, $name, $type]) {
            Account::updateOrCreate(['code' => $code], ['name' => $name, 'type' => $type]);
        }

        $this->call(Phase3Seeder::class);
        $this->call(Phase4Seeder::class);
        $this->call(Phase8Seeder::class);
        $this->call(Phase11Seeder::class);
        $this->call(Phase12Seeder::class);

        Warehouse::updateOrCreate(['code' => 'MAIN'], ['name' => 'Main Lexington', 'city' => 'Lexington', 'state' => 'KY', 'is_licensed_tobacco' => true]);
        Warehouse::updateOrCreate(['code' => 'BEV'], ['name' => 'Beverage', 'city' => 'Lexington', 'state' => 'KY', 'is_licensed_tobacco' => false]);

        // Starter rules. PROTOTYPE ONLY: verify every rule before production; the Rules Hub replaces these.
        $rules = [
            ['KY', 'cigarette_stamped', null, 'license_required', false, null, 'tobacco_retail', '2020-01-01'],
            ['KY', 'snuff_chew', null, 'license_required', false, null, 'tobacco_retail', '2020-01-01'],
            ['KY', 'cigar', null, 'license_required', false, null, 'tobacco_retail', '2020-01-01'],
            ['KY', 'vapor', null, 'license_required', false, null, 'tobacco_retail', '2020-01-01'],
            ['IL', 'cigar', null, 'license_required', false, null, 'tobacco_wholesale', '2020-01-01'],
            ['IL', 'pipe', null, 'license_required', false, null, 'tobacco_wholesale', '2020-01-01'],
            ['IN', 'kratom', null, 'ban', true, null, null, '2014-07-01'],
            ['TN', 'kratom', null, 'ban', true, null, null, '2026-01-01'],
            ['IL', 'hemp', null, 'license_required', false, null, 'hemp', '2020-01-01'],
        ];
        foreach ($rules as [$j, $class, $hemp, $rule, $banned, $max, $lic, $from]) {
            ComplianceRuleVersion::updateOrCreate(
                ['jurisdiction' => $j, 'tax_class' => $class, 'hemp_type' => $hemp, 'rule' => $rule, 'version' => 'local-1'],
                ['banned' => $banned, 'max_thc_mg' => $max, 'license_type' => $lic, 'effective_from' => $from,
                    'source' => 'PROTOTYPE - verify before use', 'notes' => 'Seeded starter rule; Rules Hub will supersede.'],
            );
        }
        // Federal hemp redefinition (total THC per container) - every destination state.
        foreach (['KY', 'IL', 'IN', 'TN', 'MS'] as $st) {
            ComplianceRuleVersion::updateOrCreate(
                ['jurisdiction' => $st, 'tax_class' => 'hemp', 'hemp_type' => null, 'rule' => 'thc_limit', 'version' => 'local-1'],
                ['max_thc_mg' => '0.4000', 'effective_from' => '2026-11-12', 'source' => 'PROTOTYPE - verify federal hemp definition',
                    'notes' => 'Placeholder for federal total-THC-per-container limit.'],
            );
        }
    }
}
