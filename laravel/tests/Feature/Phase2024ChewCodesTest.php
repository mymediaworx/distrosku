<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Reports\KyTobaccoXml;
use Database\Seeders\CoreSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** #64 (0.20.24): chew StateDesc codes on the KY tobacco filing follow Table 3 (loose leaf / plug / twist by unit size). */
class Phase2024ChewCodesTest extends TestCase
{
    use RefreshDatabase;

    private function code(string $type, string $oz): string
    {
        $p = Product::factory()->make(['tax_class' => 'snuff_chew', 'smokeless_type' => $type, 'net_weight_oz' => $oz, 'ky_state_desc' => null]);

        return app(KyTobaccoXml::class)->codes($p)[1];
    }

    public function test_codes_by_type_and_unit_size(): void
    {
        $this->seed(CoreSeeder::class);
        $this->assertSame(['KY-LL1', 'KY-LL2', 'KY-LL3', 'KY-LL4'], [$this->code('chew', '3'), $this->code('chew', '6'), $this->code('chew', '12'), $this->code('chew', '20')]);
        $this->assertSame(['KY-PL1', 'KY-PL3'], [$this->code('plug', '3'), $this->code('plug', '16')]);
        $this->assertSame(['KY-TW2', 'KY-TW4'], [$this->code('twist', '8'), $this->code('twist', '17')]);
        $this->assertSame('KY-MS1', $this->code('snuff', '1.2'));
        $p = Product::factory()->make(['tax_class' => 'snuff_chew', 'smokeless_type' => 'plug', 'net_weight_oz' => '3', 'ky_state_desc' => 'KY-PL1-MRT1']);
        $this->assertSame('KY-PL1-MRT1', app(KyTobaccoXml::class)->codes($p)[1]);   // product override still wins
    }
}
