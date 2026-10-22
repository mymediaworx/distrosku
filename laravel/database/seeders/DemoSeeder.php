<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\CustomerLicense;
use App\Models\Product;
use App\Models\ShipTo;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Warehouse;
use App\Services\InventoryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/** Clearly fake TEST data only. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $main = Warehouse::where('code', 'MAIN')->firstOrFail();
        $bev = Warehouse::where('code', 'BEV')->firstOrFail();

        foreach (['warehouse', 'sales', 'driver'] as $role) {
            User::firstOrCreate(['email' => "test-{$role}@example.test"], [
                'name' => 'Test '.ucfirst($role), 'role' => $role, 'password' => Str::password(20), 'email_verified_at' => now(),
            ]);
        }

        $products = [
            ['TST-CIG-001', '000000000011', 'TEST Cigarette Kings 20s carton', 'Cigarettes', 'cigarette_stamped', true, null, null, '55.0000', '62.5000', 'MAIN'],
            ['TST-CGR-001', '000000000028', 'TEST Cigarillo 2pk', 'Cigars', 'cigar', false, null, null, '0.6500', '0.9900', 'MAIN'],
            ['TST-PIP-001', '000000000035', 'TEST Pipe Tobacco 12oz', 'Pipe', 'pipe', false, null, null, '9.0000', '13.5000', 'MAIN'],
            ['TST-SNF-001', '000000000042', 'TEST Long Cut Snuff 5ct roll', 'Smokeless', 'snuff_chew', true, null, null, '18.0000', '24.0000', 'MAIN'],
            ['TST-VAP-001', '000000000059', 'TEST Disposable Vape', 'Vapor', 'vapor', true, null, null, '6.0000', '9.9900', 'MAIN'],
            ['TST-HMP-001', '000000000066', 'TEST Delta-8 Gummies 10ct', 'Hemp', 'hemp', false, 'delta8', '250.0000', '7.0000', '12.0000', 'MAIN'],
            ['TST-HMP-002', '000000000073', 'TEST CBD Tincture (0 THC)', 'Hemp', 'hemp', false, 'cbd', '0.0000', '10.0000', '18.0000', 'MAIN'],
            ['TST-KRT-001', '000000000080', 'TEST Kratom Capsules 20ct', 'Kratom', 'kratom', false, null, null, '5.0000', '9.0000', 'MAIN'],
            ['TST-BEV-001', '000000000097', 'TEST Energy Drink 24pk', 'Beverage', 'beverage', false, null, null, '22.0000', '28.0000', 'BEV'],
            ['TST-GEN-001', '000000000103', 'TEST Lighter Display 50ct', 'Sundries', 'general', false, null, null, '15.0000', '25.0000', 'MAIN'],
        ];
        $inv = app(InventoryService::class);
        foreach ($products as [$sku, $upc, $name, $cat, $class, $pact, $hemp, $thc, $cost, $price, $wh]) {
            $p = Product::firstOrCreate(['sku' => $sku], compact('upc', 'name') + [
                'category' => $cat, 'tax_class' => $class, 'pact_covered' => $pact, 'hemp_type' => $hemp,
                'thc_mg' => $thc, 'cost' => $cost, 'price' => $price,
            ]);
            if ($sku === 'TST-SNF-001') {
                $p->update(['smokeless_type' => 'snuff', 'net_weight_oz' => '1.2', 'units_per_case' => 5]);
            }
            if ($sku === 'TST-CIG-001') {
                $p->update(['sticks_per_unit' => 200]);
            }
            if ($p->wasRecentlyCreated) {
                $inv->move($p, $wh === 'BEV' ? $bev : $main, '100', 'adjustment', null, $cost, 'TEST opening stock');
            }
        }

        Vendor::firstOrCreate(['code' => 'VTEST1'], ['name' => 'TEST Tobacco Manufacturer Co', 'is_manufacturer' => true, 'state' => 'NC']);
        Vendor::firstOrCreate(['code' => 'VTEST2'], ['name' => 'TEST Beverage Supply', 'state' => 'KY']);

        $ky = Customer::firstOrCreate(['code' => 'CTEST-KY'], ['name' => 'TEST Corner Market (KY)', 'type' => 'c_store']);
        $kyShip = ShipTo::firstOrCreate(['customer_id' => $ky->id, 'name' => 'Main store'], ['address' => '1 Test St', 'city' => 'Lexington', 'state' => 'KY', 'zip' => '40505']);
        CustomerLicense::firstOrCreate(['customer_id' => $ky->id, 'type' => 'tobacco_retail'], ['number' => 'TEST-KY-0001', 'state' => 'KY', 'expires_at' => now()->addYear()]);

        $il = Customer::firstOrCreate(['code' => 'CTEST-IL'], ['name' => 'TEST Wholesale (IL)', 'type' => 'wholesale_distributor']);
        ShipTo::firstOrCreate(['customer_id' => $il->id, 'name' => 'IL warehouse'], ['address' => '2 Test Ave', 'city' => 'Springfield', 'state' => 'IL', 'zip' => '62701']);
        CustomerLicense::firstOrCreate(['customer_id' => $il->id, 'type' => 'tobacco_wholesale'], ['number' => 'TEST-IL-0001', 'state' => 'IL', 'expires_at' => now()->addYear()]);

        $in = Customer::firstOrCreate(['code' => 'CTEST-IN'], ['name' => 'TEST Wholesale (IN)', 'type' => 'wholesale_distributor']);
        ShipTo::firstOrCreate(['customer_id' => $in->id, 'name' => 'IN warehouse'], ['address' => '3 Test Rd', 'city' => 'Evansville', 'state' => 'IN', 'zip' => '47708']);

        $walk = Customer::firstOrCreate(['code' => 'CASH'], ['name' => 'TEST Counter / Cash Customer', 'type' => 'retailer', 'terms' => 'COD']);
        ShipTo::firstOrCreate(['customer_id' => $walk->id, 'name' => 'Counter pickup'], ['city' => 'Lexington', 'state' => 'KY']);
        CustomerLicense::firstOrCreate(['customer_id' => $walk->id, 'type' => 'tobacco_retail'], ['number' => 'TEST-KY-CASH', 'state' => 'KY', 'expires_at' => now()->addYear()]);
    }
}
