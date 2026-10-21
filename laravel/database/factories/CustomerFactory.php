<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'C'.fake()->unique()->numerify('#####'),
            'name' => fake()->company().' (TEST)',
            'type' => 'retailer',
            'email' => fake()->safeEmail(),
        ];
    }
}
