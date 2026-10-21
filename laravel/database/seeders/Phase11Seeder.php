<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

/** Idempotent additions for phase 11 (safe to re-run). */
class Phase11Seeder extends Seeder
{
    public function run(): void
    {
        Account::firstOrCreate(['code' => '5200'], ['name' => 'Inventory Shrinkage / Count Variance', 'type' => 'expense']);
    }
}
