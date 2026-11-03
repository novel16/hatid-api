<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    /** Creates a private customer fixture with an optional phone. */
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'name' => fake()->name(), 'phone' => null];
    }
}
