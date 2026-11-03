<?php

declare(strict_types=1);

namespace App\Services\Directory;

use App\Models\Customer;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerService
{
    /** Searches only this rider's active or archived customers, with separate address records. */
    public function paginate(User $rider, string $search, bool $archived, int $perPage): LengthAwarePaginator
    {
        $query = $this->records($rider);
        if ($archived) {
            $query->onlyTrashed();
        }
        if ($search !== '') {
            $pattern = '%'.$search.'%';
            $phonePattern = '%'.(Customer::normalizePhone($search) ?? $search).'%';
            // Group every OR under the rider constraint to prevent cross-account search leaks.
            $query->where(function (Builder $group) use ($pattern, $phonePattern): void {
                $group->where('name', 'like', $pattern)->orWhere('phone', 'like', $phonePattern)
                    // Search address text through the constrained customer relation.
                    ->orWhereHas('addresses', fn (Builder $addresses) => $addresses->where('text', 'like', $pattern));
            });
        }

        return $query->orderBy('name')->orderBy('id')->paginate($perPage)->withQueryString();
    }

    /** Finds an active customer without revealing records owned by another rider. */
    public function find(User $rider, int $customerId): Customer
    {
        return $this->records($rider)->findOrFail($customerId);
    }

    /**
     * Saves customer identity and creates an initial unknown address atomically.
     *
     * @param  array<string, mixed>  $values
     */
    public function save(User $rider, array $values, ?int $customerId = null): Customer
    {
        try {
            // Keep customer creation and its initial address in the same transaction.
            return DB::transaction(function () use ($rider, $values, $customerId): Customer {
                $identity = Arr::only($values, ['name', 'phone']);
                if ($customerId === null) {
                    $customer = $rider->customers()->create($identity);
                    $customer->addresses()->create($values['address'] ?? ['label' => 'Home', 'text' => null]);
                } else {
                    $customer = $this->records($rider)->lockForUpdate()->findOrFail($customerId);
                    $customer->update($identity);
                }

                return $this->find($rider, $customer->id);
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'phone' => 'This phone already belongs to a customer in your directory. Search or restore that customer instead.',
            ]);
        }
    }

    /** Archives or restores a customer without deleting its addresses/history. */
    public function setArchived(User $rider, int $customerId, bool $archived): Customer
    {
        // Serialize archive changes with customer/address edits.
        return DB::transaction(function () use ($rider, $customerId, $archived): Customer {
            $customer = $this->records($rider)->withTrashed()->lockForUpdate()->findOrFail($customerId);
            if ($customer->trashed() !== $archived) {
                if ($archived) {
                    $customer->delete();
                } else {
                    $customer->restore();
                }
            }

            return $customer;
        });
    }

    /**
     * Creates or edits address metadata under its owned, active parent.
     *
     * @param  array<string, mixed>  $values
     */
    public function saveAddress(User $rider, int $customerId, array $values, ?int $addressId = null): Customer
    {
        // Lock the parent so it cannot be archived midway through an address change.
        return DB::transaction(function () use ($rider, $customerId, $values, $addressId): Customer {
            $customer = $this->records($rider)->lockForUpdate()->findOrFail($customerId);
            $metadata = Arr::only($values, ['label', 'text']);
            if ($addressId === null) {
                $customer->addresses()->create($metadata);
            } else {
                $customer->addresses()->findOrFail($addressId)->update($metadata);
            }

            return $this->find($rider, $customerId);
        });
    }

    /** Archives/restores one address without reassigning it or changing its GPS evidence. */
    public function setAddressArchived(User $rider, int $customerId, int $addressId, bool $archived): Customer
    {
        // Resolve both IDs through the owner/parent relation before changing the child.
        return DB::transaction(function () use ($rider, $customerId, $addressId, $archived): Customer {
            $customer = $this->records($rider)->lockForUpdate()->findOrFail($customerId);
            $address = $customer->addresses()->withTrashed()->findOrFail($addressId);
            if ($address->trashed() !== $archived) {
                if ($archived) {
                    $address->delete();
                } else {
                    $address->restore();
                }
            }

            return $this->find($rider, $customerId);
        });
    }

    /** Builds the owner scope and eager-loads both current/archived addresses for controlled output. */
    private function records(User $rider): Builder
    {
        return $rider->customers()->getQuery()->with([
            // Address IDs retain their identity when restored.
            'addresses' => fn (HasMany $addresses): HasMany => $addresses->withTrashed()->orderBy('id'),
        ]);
    }
}
