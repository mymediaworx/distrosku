<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\Setting;
use Illuminate\Database\Seeder;

/** Idempotent additions for phase 3 (safe to re-run on upgraded installs). */
class Phase3Seeder extends Seeder
{
    public function run(): void
    {
        $this->call(TaxRateSeeder::class);
        Account::firstOrCreate(['code' => '6300'], ['name' => 'Damaged / Returned Goods Write-off', 'type' => 'expense']);
        Account::firstOrCreate(['code' => '3900'], ['name' => 'Opening Balance Equity', 'type' => 'equity']);
        Setting::installId();
    }
}
