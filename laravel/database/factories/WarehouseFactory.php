<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class WarehouseFactory extends Factory
{
    public function definition(): array
    {
        return [
            'code' => 'WH'.fake()->unique()->numberBetween(10, 999),
            'name' => 'Test Warehouse '.fake()->word(),
            'state' => 'KY',
        ];
    }
}
