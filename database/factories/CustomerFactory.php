<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Customer> */
class CustomerFactory extends Factory
{
    public const DUMMY_PREFIX = '[Dummy] ';

    /** Creates a private customer fixture with an optional phone. */
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'name' => fake()->name(), 'phone' => null];
    }

    /** Labels synthetic customers clearly without generating callable phone numbers. */
    public function dummy(): static
    {
        return $this->state(
            /** Reuse the normal Faker identity with a recognizable dummy marker. */
            fn (array $attributes): array => ['name' => self::DUMMY_PREFIX.$attributes['name'], 'phone' => null],
        );
    }
}
