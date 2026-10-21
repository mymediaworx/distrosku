<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Pricing\RebateLedger;
use Illuminate\Database\Seeder;

/** Idempotent (0.14.1): rebate GL accounts; posts GL for any 0.14.0 accruals made before rebate posting existed. */
class Phase14Seeder extends Seeder
{
    public function run(): void
    {
        Account::firstOrCreate(['code' => RebateLedger::RECEIVABLE], ['name' => 'Manufacturer Rebates Receivable', 'type' => 'asset']);
        Account::firstOrCreate(['code' => RebateLedger::CONTRA_COGS], ['name' => 'Manufacturer Rebates & Buydowns (contra-COGS)', 'type' => 'expense']);
        if (\Illuminate\Support\Facades\Schema::hasColumn('buydown_accruals', 'journal_id')) {
            app(RebateLedger::class)->postMissing();
        }
    }
}
