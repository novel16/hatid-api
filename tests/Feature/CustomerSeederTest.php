<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\User;
use Database\Seeders\CustomerSeeder;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Database\MultipleRecordsFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class CustomerSeederTest extends TestCase
{
    use RefreshDatabase;

    /** Seeds private, unverified fixtures once without changing existing customers or accounts. */
    public function test_seeds_two_thousand_customers_with_addresses_without_duplicates(): void
    {
        $rider = User::factory()->create();
        User::factory()->admin()->create();
        $existing = Customer::factory()->for($rider)->has(CustomerAddress::factory(), 'addresses')->create();
        $original = $existing->fresh()->getAttributes();

        $this->seed(DatabaseSeeder::class);

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('customers', 2001);
        $this->assertDatabaseCount('customer_addresses', 2001);
        $dummy = Customer::query()->where('name', 'like', '[Dummy] %');
        $this->assertSame(2000, $dummy->count());
        $this->assertSame([$rider->id], (clone $dummy)->distinct()->pluck('user_id')->all());
        $this->assertSame(0, (clone $dummy)->whereNotNull('phone')->count());
        $this->assertSame(0, (clone $dummy)->whereDoesntHave('addresses')->count());
        $this->assertSame(0, CustomerAddress::query()->whereNotNull('latitude')->count());
        $this->assertSame(0, CustomerAddress::query()->whereNotNull('longitude')->count());
        $this->assertSame(0, CustomerAddress::query()->whereNotNull('verified_at')->count());
        $this->assertSame($original, $existing->fresh()->getAttributes());

        $this->seed(CustomerSeeder::class);

        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseCount('customers', 2001);
        $this->assertDatabaseCount('customer_addresses', 2001);
    }

    /** Ambiguous ownership must stop seeding instead of choosing an arbitrary rider. */
    public function test_does_not_choose_between_multiple_riders(): void
    {
        User::factory()->count(2)->create();
        $this->expectException(MultipleRecordsFoundException::class);

        $this->seed(CustomerSeeder::class);
    }

    /** Explicit class invocation cannot add dummy customers in production. */
    public function test_dummy_seeding_is_restricted_to_local_and_testing(): void
    {
        $this->app->instance('env', 'production');
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Dummy customer seeding is only available locally or in tests.');

        $this->app->make(CustomerSeeder::class)->run();
    }
}
