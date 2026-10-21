<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class VendorFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'V'.fake()->unique()->numerify('####'),
            'name' => fake()->company().' (TEST)',
            'is_manufacturer' => false,
        ];
    }
}
