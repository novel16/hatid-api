<?php

declare(strict_types=1);

namespace App\Services\Directory;

use App\Models\Seller;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SellerService
{
    /** Searches only the rider's selected active/archived seller directory. */
    public function paginate(User $rider, string $search, bool $archived, int $perPage): LengthAwarePaginator
    {
        $query = $rider->sellers()->getQuery();
        if ($archived) {
            $query->onlyTrashed();
        }
        if ($search !== '') {
            $pattern = '%'.$search.'%';
            // Both search fields stay inside the rider constraint.
            $query->where(function (Builder $group) use ($pattern): void {
                $group->where('name', 'like', $pattern)->orWhere('contact', 'like', $pattern);
            });
        }

        return $query->orderBy('name')->orderBy('id')->paginate($perPage)->withQueryString();
    }

    /** Finds one active seller without exposing another rider's records. */
    public function find(User $rider, int $sellerId): Seller
    {
        return $rider->sellers()->findOrFail($sellerId);
    }

    /**
     * Saves fee defaults only; existing parcel snapshots are never recalculated.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(User $rider, array $values, ?int $sellerId = null): Seller
    {
        // Serialize preference changes with archive/restore operations.
        return DB::transaction(function () use ($rider, $values, $sellerId): Seller {
            $preferences = Arr::only($values, ['name', 'contact', 'mode', 'fee']);
            if ($sellerId === null) {
                return $rider->sellers()->create($preferences);
            }
            $seller = $rider->sellers()->lockForUpdate()->findOrFail($sellerId);
            $seller->update($preferences);

            return $seller;
        });
    }

    /** Archives/restores preferences while retaining the seller's identity for future references. */
    public function setArchived(User $rider, int $sellerId, bool $archived): Seller
    {
        // Repeated archive/restore requests preserve the same record.
        return DB::transaction(function () use ($rider, $sellerId, $archived): Seller {
            $seller = $rider->sellers()->withTrashed()->lockForUpdate()->findOrFail($sellerId);
            if ($seller->trashed() !== $archived) {
                if ($archived) {
                    $seller->delete();
                } else {
                    $seller->restore();
                }
            }

            return $seller;
        });
    }
}
