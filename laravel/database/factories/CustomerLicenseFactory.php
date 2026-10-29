<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerLicenseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'type' => 'tobacco_retail',
            'site_id' => null,
            'number' => 'TEST-'.fake()->numerify('######'),
            'state' => 'KY',
            'expires_at' => now()->addYear()->toDateString(),
        ];
    }

    /** Kentucky Site ID only (no License #). */
    public function kySiteId(?string $siteId = null): static
    {
        return $this->state(fn () => [
            'state' => 'KY',
            'site_id' => $siteId ?? fake()->numerify('########'),
            'number' => null,
        ]);
    }
}
