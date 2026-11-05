<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Adds local customer fixtures only; accounts still come from registration or app:create-admin. */
    public function run(): void
    {
        if (app()->environment('local', 'testing')) {
            $this->call(CustomerSeeder::class);
        }
    }
}
