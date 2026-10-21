<?php

namespace Database\Seeders;

use App\Models\Account;
use App\Models\BankAccount;
use Illuminate\Database\Seeder;

/** Idempotent additions for phase 8 accounting (safe to re-run). */
class Phase8Seeder extends Seeder
{
    public function run(): void
    {
        Account::firstOrCreate(['code' => '2050'], ['name' => 'Received Not Billed (GRNI)', 'type' => 'liability']);
        Account::firstOrCreate(['code' => '5100'], ['name' => 'Purchase Price Variance', 'type' => 'expense']);
        Account::firstOrCreate(['code' => '6400'], ['name' => 'Bank Fees', 'type' => 'expense']);
        Account::firstOrCreate(['code' => '7000'], ['name' => 'Interest Income', 'type' => 'revenue']);
        $cash = Account::where('code', '1000')->first();
        if ($cash && ! BankAccount::exists()) {
            BankAccount::create(['name' => 'Operating account', 'account_id' => $cash->id, 'is_default' => true]);
        }
    }
}
