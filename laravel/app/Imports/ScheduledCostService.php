<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\ScheduledCost;
use Illuminate\Support\Facades\DB;

/** Applies vendor cost changes whose effective date has arrived (daily via cron, and on demand from the Imports page). */
class ScheduledCostService
{
    public function applyDue(?string $asOf = null): int
    {
        $n = 0;
        ScheduledCost::whereNull('applied_at')->whereDate('effective_on', '<=', $asOf ?? today()->toDateString())->orderBy('effective_on')->orderBy('id')
            ->chunkById(200, function ($rows) use (&$n) {
                foreach ($rows as $sc) {
                    DB::transaction(function () use ($sc) {
                        if ($p = Product::find($sc->product_id)) {
                            $p->update(['cost' => $sc->cost]);
                        }
                        $sc->update(['applied_at' => now()]);
                    });
                    $n++;
                }
            });

        return $n;
    }
}
