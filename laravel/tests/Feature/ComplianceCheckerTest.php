<?php

namespace Tests\Feature;

use App\Models\ComplianceRuleVersion;
use App\Models\Customer;
use App\Models\CustomerLicense;
use App\Models\Product;
use App\Models\ShipTo;
use App\Services\ComplianceChecker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ComplianceCheckerTest extends TestCase
{
    use RefreshDatabase;

    private function checker(): ComplianceChecker
    {
        return app(ComplianceChecker::class);
    }

    private function customerIn(string $state): array
    {
        $c = Customer::factory()->create();
        $s = ShipTo::factory()->create(['customer_id' => $c->id, 'state' => $state]);

        return [$c, $s];
    }

    public function test_pact_item_blocked_out_of_state_but_allowed_in_ky(): void
    {
        $p = Product::factory()->create(['tax_class' => 'cigarette_stamped', 'pact_covered' => true]);
        [$c, $il] = $this->customerIn('IL');
        $ky = ShipTo::factory()->create(['customer_id' => $c->id, 'state' => 'KY']);

        $this->assertNotEmpty($r = $this->checker()->checkLine($p, $c, $il));
        $this->assertStringContainsString('PACT', $r[0]);
        $this->assertSame([], $this->checker()->checkLine($p, $c, $ky));
    }

    public function test_non_pact_cigar_allowed_out_of_state(): void
    {
        $p = Product::factory()->create(['tax_class' => 'cigar', 'pact_covered' => false]);
        [$c, $s] = $this->customerIn('TN');
        $this->assertSame([], $this->checker()->checkLine($p, $c, $s));
    }

    public function test_state_ban_respects_effective_dates(): void
    {
        ComplianceRuleVersion::factory()->create(['jurisdiction' => 'IN', 'tax_class' => 'kratom', 'rule' => 'ban', 'banned' => true,
            'effective_from' => '2026-01-01', 'effective_to' => '2026-12-31']);
        $p = Product::factory()->create(['tax_class' => 'kratom']);
        [$c, $s] = $this->customerIn('IN');

        $this->assertSame([], $this->checker()->checkLine($p, $c, $s, '2025-12-31'));
        $this->assertStringContainsString('banned in IN', $this->checker()->checkLine($p, $c, $s, '2026-06-01')[0]);
        $this->assertSame([], $this->checker()->checkLine($p, $c, $s, '2027-01-01'));
    }

    public function test_hemp_type_ban_only_hits_that_type(): void
    {
        ComplianceRuleVersion::factory()->create(['jurisdiction' => 'MS', 'tax_class' => 'hemp', 'hemp_type' => 'delta8', 'rule' => 'ban', 'banned' => true]);
        [$c, $s] = $this->customerIn('MS');
        $d8 = Product::factory()->create(['tax_class' => 'hemp', 'hemp_type' => 'delta8']);
        $cbd = Product::factory()->create(['tax_class' => 'hemp', 'hemp_type' => 'cbd']);

        $this->assertNotEmpty($this->checker()->checkLine($d8, $c, $s));
        $this->assertSame([], $this->checker()->checkLine($cbd, $c, $s));
    }

    public function test_thc_limit(): void
    {
        ComplianceRuleVersion::factory()->create(['jurisdiction' => 'TN', 'tax_class' => 'hemp', 'rule' => 'thc_limit', 'banned' => false,
            'max_thc_mg' => '0.4', 'effective_from' => '2026-11-12']);
        [$c, $s] = $this->customerIn('TN');
        $hi = Product::factory()->create(['tax_class' => 'hemp', 'hemp_type' => 'delta8', 'thc_mg' => '250']);
        $lo = Product::factory()->create(['tax_class' => 'hemp', 'hemp_type' => 'cbd', 'thc_mg' => '0.1']);

        $this->assertSame([], $this->checker()->checkLine($hi, $c, $s, '2026-11-11'));
        $this->assertStringContainsString('exceeds', $this->checker()->checkLine($hi, $c, $s, '2026-11-12')[0]);
        $this->assertSame([], $this->checker()->checkLine($lo, $c, $s, '2026-11-12'));
    }

    public function test_license_required_and_expiry(): void
    {
        ComplianceRuleVersion::factory()->create(['jurisdiction' => 'KY', 'tax_class' => 'cigar', 'rule' => 'license_required',
            'banned' => false, 'license_type' => 'tobacco_retail']);
        $p = Product::factory()->create(['tax_class' => 'cigar']);
        [$c, $s] = $this->customerIn('KY');

        $this->assertStringContainsString('license', $this->checker()->checkLine($p, $c, $s)[0]);

        $lic = CustomerLicense::factory()->create(['customer_id' => $c->id, 'state' => 'KY', 'type' => 'tobacco_retail', 'expires_at' => now()->subDay()]);
        $this->assertNotEmpty($this->checker()->checkLine($p, $c, $s), 'expired license must not count');

        $lic->update(['expires_at' => now()->addMonth()]);
        $this->assertSame([], $this->checker()->checkLine($p, $c, $s));
    }

    public function test_general_merchandise_always_allowed(): void
    {
        $p = Product::factory()->create(['tax_class' => 'general']);
        [$c, $s] = $this->customerIn('IN');
        $this->assertSame([], $this->checker()->checkLine($p, $c, $s));
    }
}
