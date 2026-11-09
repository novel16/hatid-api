<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RiderDirectoryService
{
    /** Apply search, vehicle and status filters within the rider role. */
    public function paginate(string $search, string $vehicle, string $status, int $perPage): LengthAwarePaginator
    {
        $query = $this->riders();

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

        if ($status === 'suspended') {
            $query->whereNotNull('suspended_at');
        } elseif ($status === 'active') {
            $query->whereNull('suspended_at');
        }

        if ($vehicle === 'unset') {
            $query->whereNull('vehicle');
        } elseif ($vehicle !== '') {
            $query->where('vehicle', $vehicle);
        }

        return $query->orderBy('name')->orderBy('id')->paginate($perPage)->appends([
            'q' => $search === '' ? null : $search,
            'vehicle' => $vehicle === '' ? null : $vehicle,
            'status' => $status === '' ? null : $status,
            'per_page' => $perPage,
        ]);
    }

    /**
     * Read the current profile and a fixed ten-row page of original decisions.
     *
     * @return array{rider: User, history: LengthAwarePaginator}
     */
    public function activity(int $riderId): array
    {
        /** Hold the rider row while reading its profile and history count and rows. */
        return DB::transaction(function () use ($riderId): array {
            $rider = $this->riders()->sharedLock()->findOrFail($riderId);
            $history = DB::table('rider_status_activities')
                ->where('rider_id', $rider->id)
                ->orderByDesc('id')
                ->paginate(10, ['id', 'status', 'reason', 'admin_id', 'admin_name', 'created_at']);

            return ['rider' => $rider, 'history' => $history];
        });
    }

    /** Commit the reviewed transition, token revocation and its history together. */
    public function changeStatus(User $admin, int $riderId, string $status, string $reason, int $revision): User
    {
        /** Lock the same user row that protects login before comparing the revision. */
        return DB::transaction(function () use ($admin, $riderId, $status, $reason, $revision): User {
            $rider = $this->riders()->lockForUpdate()->findOrFail($riderId);
            $suspending = $status === 'suspended';
            abort_if(
                $rider->rider_status_revision !== $revision || $rider->isSuspendedRider() === $suspending,
                409,
                'The rider status has changed or is already current. Refresh the profile.',
            );

            $changedAt = now();
            $rider->forceFill([
                'suspended_at' => $suspending ? $changedAt : null,
                'rider_status_revision' => $revision + 1,
            ])->save();
            $rider->tokens()->delete();

            DB::table('rider_status_activities')->insert([
                'rider_id' => $rider->id,
                'admin_id' => $admin->id,
                'admin_name' => $admin->name,
                'status' => $status,
                'reason' => $reason,
                'created_at' => $changedAt,
            ]);

            return $rider;
        });
    }

    /** Share the role constraint and profile fields between reads and status actions. */
    private function riders(): Builder
    {
        return User::query()
            ->select(['id', 'name', 'email', 'phone', 'vehicle', 'created_at', 'role', 'suspended_at', 'rider_status_revision'])
            ->where('role', UserRole::Rider);
    }
}
