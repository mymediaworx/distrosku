<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * Test fixtures are created as "the system", not as whoever the test is
     * signed in as, so the 0.17.3 brandworx_admin role guard does not block fixture setup.
     */
    public function create($attributes = [], ?\Illuminate\Database\Eloquent\Model $parent = null)
    {
        $guard = auth()->guard();
        $user = $guard->user();
        if (! $user || ! method_exists($guard, 'forgetUser')) {
            return parent::create($attributes, $parent);
        }
        $guard->forgetUser();
        try {
            return parent::create($attributes, $parent);
        } finally {
            $guard->setUser($user);
        }
    }

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => 'sales',
            'active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn () => ['email_verified_at' => null]);
    }
}
