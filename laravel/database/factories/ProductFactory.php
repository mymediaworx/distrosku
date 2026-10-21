<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    public function definition(): array
    {
        return [
            'sku' => 'TST-'.fake()->unique()->numerify('#####'),
            'upc' => fake()->numerify('0000########'),
            'name' => 'Test '.fake()->words(2, true),
            'category' => 'General',
            'tax_class' => 'general',
            'cost' => '5.0000',
            'price' => '8.0000',
        ];
    }
}
