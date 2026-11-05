<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Batch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Batch>
 */
class BatchFactory extends Factory
{
    /** Supplies a private weekly intake fixture; tests choose distinct ranges when creating several. */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'November 2–8',
            'start_date' => '2026-11-02',
            'end_date' => '2026-11-08',
        ];
    }
}
