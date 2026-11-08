<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class RiderDirectoryService
{
    /** Apply search and vehicle filters only within the rider role. */
    public function paginate(string $search, string $vehicle, int $perPage): LengthAwarePaginator
    {
        $query = User::query()
            ->select(['id', 'name', 'email', 'phone', 'vehicle', 'created_at'])
            ->where('role', UserRole::Rider);

        $search = trim($search);
        if ($search !== '') {
            $pattern = '%'.strtr(Str::lower($search), ['!' => '!!', '%' => '!%', '_' => '!_']).'%';

            /** Group the three fixed fields so an OR can never bypass the rider constraint. */
            $query->where(function (Builder $fields) use ($pattern): void {
                $fields->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(email) LIKE ? ESCAPE '!'", [$pattern])
                    ->orWhereRaw("LOWER(phone) LIKE ? ESCAPE '!'", [$pattern]);
            });
        }

        if ($vehicle === 'unset') {
            $query->whereNull('vehicle');
        } elseif ($vehicle !== '') {
            $query->where('vehicle', $vehicle);
        }

        return $query->orderBy('name')->orderBy('id')->paginate($perPage)->appends([
            'q' => $search === '' ? null : $search,
            'vehicle' => $vehicle === '' ? null : $vehicle,
            'per_page' => $perPage,
        ]);
    }
}
