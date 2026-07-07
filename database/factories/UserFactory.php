<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'nombre'   => fake()->firstName(),
            'apellido' => fake()->lastName(),
            'email'    => fake()->unique()->safeEmail(),
            'password' => Hash::make('password'),
            'role'     => User::ROLE_AUXILIAR,
            'activo'   => true,
            'remember_token' => Str::random(10),
        ];
    }

    public function admin(): static
    {
        return $this->state(['role' => User::ROLE_ADMIN]);
    }

    public function contador(): static
    {
        return $this->state(['role' => User::ROLE_CONTADOR]);
    }

    public function inactivo(): static
    {
        return $this->state(['activo' => false]);
    }
}
