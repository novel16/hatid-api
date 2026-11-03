<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Seller> */
class SellerFactory extends Factory
{
    /** Creates explicit seller defaults with a centavo-denominated fee. */
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'name' => fake()->company(), 'contact' => null, 'mode' => 'included', 'fee' => 1500];
    }
}
