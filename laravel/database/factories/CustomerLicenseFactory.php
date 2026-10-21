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
            'number' => 'TEST-'.fake()->numerify('######'),
            'state' => 'KY',
            'expires_at' => now()->addYear()->toDateString(),
        ];
    }
}
