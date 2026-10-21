<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ComplianceRuleVersionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'jurisdiction' => 'KY',
            'rule' => 'ban',
            'banned' => true,
            'effective_from' => '2020-01-01',
            'version' => 'test',
        ];
    }
}
