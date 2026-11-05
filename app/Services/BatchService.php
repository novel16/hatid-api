<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Batch;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;

final class BatchService
{
    /** Searches only owned batches and retains older ranges in the same stable pagination. */
    public function paginate(User $rider, string $search, int $perPage): LengthAwarePaginator
    {
        $query = $rider->batches();
        if ($search !== '') {
            $query->where('name', 'like', '%'.$search.'%');
        }

        return $query->orderByDesc('start_date')->orderByDesc('id')->paginate($perPage)->withQueryString();
    }

    /** Does not turn an end date into an access or delivery deadline. */
    public function find(User $rider, int $batchId): Batch
    {
        return $rider->batches()->findOrFail($batchId);
    }

    /**
     * The database serializes duplicate range submissions without a pre-check race.
     *
     * @param  array{name: string, start: string, end: string}  $values
     */
    public function create(User $rider, array $values): Batch
    {
        try {
            return $rider->batches()->create([
                'name' => $values['name'], 'start_date' => $values['start'], 'end_date' => $values['end'],
            ]);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'start' => ['You already have a batch for this date range. Open it from Weekly batches.'],
            ]);
        }
    }

    /** Supplies Monday–Sunday defaults using the same local calendar as the rider UI. */
    public function currentWeek(): array
    {
        $start = now('Asia/Manila')->startOfWeek(CarbonInterface::MONDAY);

        return [
            'name' => 'Week of '.$start->format('M j, Y'),
            'start' => $start->toDateString(),
            'end' => $start->copy()->addDays(6)->toDateString(),
        ];
    }
}
