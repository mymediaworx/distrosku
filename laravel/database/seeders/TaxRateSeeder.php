<?php

namespace Database\Seeders;

use App\Models\Product;
use App\Models\TaxRate;
use App\Services\KyExcise as K;
use Illuminate\Database\Seeder;

/**
 * Kentucky excise rates (KRS 138.140) as of Oct 2026. Idempotent: keyed by jurisdiction+type+effective_from,
 * and never overwrites a row a user has edited/verified. EVERY ROW IS MARKED needs_verification.
 */
class TaxRateSeeder extends Seeder
{
    public function run(): void
    {
        $krs = 'KRS 138.140 / revenue.ky.gov Tobacco & Vapor Products Tax page (read 2026-10-20)';
        $rows = [
            // [type, rate, basis, increment_oz, effective_from, notes]
            [K::CIGARETTE_PER_20, '1.100000', 'per_unit', null, '2018-07-01', 'Stamp tax $0.03 + surtax $1.06 + cancer-research surtax $0.01 per 20 sticks. Vega buys stamped: REPORT only, no tax due.'],
            [K::SNUFF, '0.190000', 'per_increment', '1.5000', '2009-04-01', '$0.19 per 1.5 oz or portion thereof, net weight. Start date is a guess (verify).'],
            [K::CHEW_SINGLE, '0.190000', 'per_unit', null, '2009-04-01', 'Single unit: net weight under 4 oz. Start date is a guess.'],
            [K::CHEW_HALF_POUND, '0.400000', 'per_unit', null, '2009-04-01', 'Half-pound unit: 4 to 8 oz. Start date is a guess.'],
            [K::CHEW_POUND, '0.650000', 'per_unit', null, '2009-04-01', 'Pound unit: over 8 to 16 oz. Start date is a guess.'],
            [K::CHEW_OVER_16, '0.190000', 'per_increment', '4.0000', '2009-04-01', 'Over 16 oz: $0.65 + $0.19 per 4 oz (or portion) above 16 oz.'],
            [K::OTP_PCT, '0.150000', 'percent', null, '2009-04-01', '15% of distributor actual selling price (excl. snuff, chew; premium cigars from 2026-07-01). Start date is a guess.'],
            [K::PREMIUM_CIGAR_PCT, '0.060000', 'percent', null, '2026-07-01', 'HB 757 (2026): 6% of distributor actual selling price for premium cigars (KRS 138.130(13) definition).'],
            [K::VAPOR_CLOSED, '1.500000', 'per_unit', null, '2020-07-01', '$1.50 per closed vapor cartridge. Start date is a guess (verify).'],
            [K::VAPOR_OPEN_PCT, '0.150000', 'percent', null, '2020-07-01', '15% of actual price of open vaping system / liquid sold separately. Start date is a guess.'],
            [K::DISTRIBUTOR_DISCOUNT_PCT, '0.000000', 'percent', null, '2000-01-01', 'Form 73A422 lines 6/13 allow a distributor discount. Rate NOT confirmed from public docs: set to 0 until Vega/CPA confirms.'],
        ];
        foreach ($rows as [$type, $rate, $basis, $inc, $from, $notes]) {
            if (TaxRate::where('jurisdiction', 'KY')->where('tax_type', $type)->whereDate('effective_from', $from)->exists()) {
                continue; // never overwrite an existing (possibly verified/edited) row
            }
            TaxRate::create(
                ['jurisdiction' => 'KY', 'tax_type' => $type, 'effective_from' => $from,
                    'rate' => $rate, 'basis' => $basis, 'increment_oz' => $inc, 'needs_verification' => true, 'source' => $krs, 'notes' => 'VERIFY. '.$notes],
            );
        }

        // TEST demo products only: fill the new tax fields so reports have data. Never touches real SKUs.
        Product::where('sku', 'TST-SNF-001')->whereNull('net_weight_oz')->update(['smokeless_type' => 'snuff', 'net_weight_oz' => '1.2', 'units_per_case' => 5]);
        Product::where('sku', 'TST-CIG-001')->whereNull('sticks_per_unit')->update(['sticks_per_unit' => 200]);
    }
}
