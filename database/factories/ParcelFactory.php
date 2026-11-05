<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Batch;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Parcel;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Parcel> */
class ParcelFactory extends Factory
{
    /** Creates a consistent owned stop with a hand-checked included-fee snapshot. */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            /** Several fixtures can share an intake week without violating the date-range constraint. */
            'batch_id' => fn (array $values): int => Batch::where('user_id', $values['user_id'])->orderBy('id')->first()?->id
                ?? Batch::factory()->create(['user_id' => $values['user_id']])->id,
            /** Every relationship derives its owner from this parcel's rider. */
            'customer_id' => fn (array $values): int => Customer::factory()->create(['user_id' => $values['user_id']])->id,
            /** An overridden customer also becomes the generated address's actual parent. */
            'customer_address_id' => fn (array $values): int => CustomerAddress::factory()->create(['customer_id' => $values['customer_id']])->id,
            /** Keep seller defaults consistent with the frozen sample unless a test changes them. */
            'seller_id' => fn (array $values): int => Seller::factory()->create(['user_id' => $values['user_id']])->id,
            'operation_id' => (string) Str::uuid(), 'submission_hash' => hash('sha256', 'fixture'),
            'description' => 'Parcel', 'note' => null, 'expected_method' => 'cash',
            'fee_mode' => 'included', 'quote' => 11500, 'fee' => 1500, 'customer_total' => 11500, 'seller_share' => 10000,
            'status' => 'pending',
        ];
    }
}
