<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

class ShipToFactory extends Factory
{
    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'name' => 'Main store',
            'address' => fake()->streetAddress(),
            'city' => 'Lexington',
            'state' => 'KY',
            'zip' => '40505',
        ];
    }
}
