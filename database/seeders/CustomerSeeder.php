<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\User;
use Database\Factories\CustomerFactory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class CustomerSeeder extends Seeder
{
    /** Tops up the sole local rider's marked fixtures to 2,000 without altering real records. */
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new LogicException('Dummy customer seeding is only available locally or in tests.');
        }

        /** Keep addresses atomic with their customers and serialize repeat seeding for this rider. */
        DB::transaction(function (): void {
            $rider = User::query()->where('role', UserRole::Rider)->lockForUpdate()->sole();
            $existing = $rider->customers()->withTrashed()
                ->where('name', 'like', CustomerFactory::DUMMY_PREFIX.'%')->count();

            for ($remaining = 2000 - $existing; $remaining > 0; $remaining -= 100) {
                Customer::factory()->for($rider)->dummy()->count(min(100, $remaining))
                    ->has(CustomerAddress::factory(), 'addresses')->create();
            }
        });
    }
}
