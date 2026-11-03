<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomerAddress> */
class CustomerAddressFactory extends Factory
{
    /** Creates an unverified address for one customer. */
    public function definition(): array
    {
        return ['customer_id' => Customer::factory(), 'label' => 'Home', 'text' => fake()->streetAddress()];
    }
}
