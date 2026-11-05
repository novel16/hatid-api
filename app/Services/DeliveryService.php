<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CustomerAddress;
use App\Models\DeliveryAttempt;
use App\Models\Parcel;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class DeliveryService
{
    /** Persists only a reviewed, fresh location backed by this rider's real successful attempt. */
    public function saveLocation(User $rider, int $parcelId, array $values): void
    {
        /** Use the same owner-first lock order as money outcomes; this transaction never changes receipts. */
        DB::transaction(function () use ($rider, $parcelId, $values): void {
            User::whereKey($rider->id)->lockForUpdate()->firstOrFail();
            $parcel = $rider->parcels()->findOrFail($parcelId);
            $evidence = $parcel->attempts()->where('outcome', 'delivered')->first();
            if ($parcel->status !== 'delivered' || ! $parcel->received_at || $parcel->received_amount !== $parcel->customer_total || ! $evidence) {
                throw ValidationException::withMessages(['delivery' => 'Confirm a successful delivery and full receipt before saving this address location.']);
            }
            $captured = CarbonImmutable::parse($values['captured_at'])->utc();
            if ($captured->lt($parcel->received_at->subSeconds(5))) {
                throw ValidationException::withMessages(['captured_at' => 'Take a fresh location reading after the successful handover.']);
            }
            $address = CustomerAddress::withTrashed()->whereKey($parcel->customer_address_id)
                ->where('customer_id', $parcel->customer_id)
                /** Even a corrupted parcel relation cannot authorize another rider's address. */
                ->whereHas('customer', fn (Builder $customer) => $customer->withTrashed()->where('user_id', $rider->id))
                ->lockForUpdate()->firstOrFail();
            abort_unless((int) $address->location_version === (int) $values['expected_version'], 409, 'This pin changed on another device. Refresh the stop and review its current pin.');
            if ($address->latitude !== null && $address->longitude !== null && ! ($values['replace_confirmed'] ?? false)) {
                throw ValidationException::withMessages(['replace_confirmed' => 'Explicitly confirm replacing this address’s saved pin.']);
            }
            $address->forceFill([
                'latitude' => $values['latitude'], 'longitude' => $values['longitude'],
                'gps_accuracy' => $values['accuracy'], 'gps_captured_at' => $captured,
                'location_corrected' => abs((float) $values['latitude'] - (float) $values['gps_latitude']) > 0.00000005
                    || abs((float) $values['longitude'] - (float) $values['gps_longitude']) > 0.00000005,
                'verified_at' => now(), 'verified_delivery_attempt_id' => $evidence->id,
                'location_version' => $address->location_version + 1,
            ])->save();
        }, 3);
    }

    /** Paginates physical stops, applying every filter to the same owned parcel. */
    public function paginate(User $rider, array $filters): LengthAwarePaginator
    {
        $query = $rider->parcels()->getQuery();
        if (isset($filters['batch_id'])) {
            $rider->batches()->findOrFail($filters['batch_id']);
            $query->where('batch_id', $filters['batch_id']);
        }
        $status = $filters['status'] ?? 'open';
        if ($status === 'open') {
            $query->whereIn('status', ['pending', 'held', 'failed']);
        } elseif ($status === 'ready') {
            $query->ready();
        } elseif ($status !== 'all') {
            $query->where('status', $status);
        }
        $method = $filters['method'] ?? 'all';
        if ($method !== 'all') {
            /** Filter by actual receipt method when one exists; otherwise use the expected method. */
            $query->where(function (Builder $payments) use ($method): void {
                $payments->where('receipt_method', $method)->orWhere(
                    /** Pending collections still have an expected, unconfirmed payment method. */
                    fn (Builder $expected) => $expected->whereNull('receipt_method')->where('expected_method', $method),
                );
            });
        }
        $search = $filters['q'] ?? '';
        if ($search !== '') {
            /** Keep text/reference alternatives grouped underneath the owner/status/payment scope. */
            $query->where(function (Builder $matches) use ($search): void {
                $pattern = '%'.$search.'%';
                $matches->where('description', 'like', $pattern)
                    /** Includes archived directory identity for an existing parcel. */
                    ->orWhereHas('customer', fn (Builder $customer) => $customer->where('name', 'like', $pattern)->orWhere('phone', 'like', $pattern))
                    /** Each address remains independent rather than searching other customer stops. */
                    ->orWhereHas('address', fn (Builder $address) => $address->where('text', 'like', $pattern))
                    /** Seller searches cannot escape the enclosing private parcel query. */
                    ->orWhereHas('seller', fn (Builder $seller) => $seller->where('name', 'like', $pattern));
                if (preg_match('/^(?:HTD-)?0*([1-9][0-9]*)$/i', $search, $reference)) {
                    $matches->orWhereKey((int) $reference[1]);
                }
            });
        }
        $stops = (clone $query)->select(['customer_id', 'customer_address_id'])
            ->groupBy('customer_id', 'customer_address_id')->orderBy('customer_id')->orderBy('customer_address_id')
            ->paginate((int) ($filters['per_page'] ?? 10))->withQueryString();
        $parcels = $query->with(['customer', 'address', 'seller', 'batch'])
            ->whereIn('customer_address_id', $stops->getCollection()->pluck('customer_address_id'))
            ->orderBy('id')->get()->groupBy('customer_address_id');

        /** Each paginated item becomes a complete matching stop, never a client-side page slice. */
        return $stops->through(fn (Parcel $key): Collection => $parcels->get($key->customer_address_id));
    }

    /** Shows this stop across original intake weeks, including closed parcels and their history. */
    public function find(User $rider, int $parcelId): Collection
    {
        $anchor = $rider->parcels()->findOrFail($parcelId);

        return $rider->parcels()->where('customer_id', $anchor->customer_id)
            ->where('customer_address_id', $anchor->customer_address_id)
            ->with(['customer', 'address', 'seller', 'batch', 'attempts'])->orderBy('id')->get();
    }

    /** Atomically records only selected parcels, one full receipt each, with replay-safe evidence. */
    public function outcome(User $rider, int $parcelId, array $values): Collection
    {
        $ids = array_map(intval(...), $values['parcel_ids']);
        sort($ids);
        $receipts = collect($values['receipts'] ?? [])->keyBy('parcel_id')->sortKeys()->map(
            /** Normalize numeric/reference representations before comparing a replay. */
            fn (array $receipt): array => [
                'parcel_id' => (int) $receipt['parcel_id'], 'method' => $receipt['method'],
                'amount' => (int) $receipt['amount'], 'reference' => trim($receipt['reference'] ?? '') ?: null,
            ],
        );
        $retry = isset($values['retry_at']) ? CarbonImmutable::parse($values['retry_at'])->utc() : null;
        $hash = hash('sha256', json_encode([
            'ids' => $ids, 'outcome' => $values['outcome'], 'note' => $values['note'],
            'retry' => $retry?->toIso8601String(), 'receipts' => $receipts->values()->all(),
        ], JSON_THROW_ON_ERROR));

        /** One owned transaction prevents simultaneous devices collecting the same parcel twice. */
        return DB::transaction(function () use ($rider, $parcelId, $values, $ids, $receipts, $retry, $hash): Collection {
            User::whereKey($rider->id)->lockForUpdate()->firstOrFail();
            $anchor = $rider->parcels()->findOrFail($parcelId);
            $selected = $rider->parcels()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
            abort_unless($selected->count() === count($ids), 404);
            foreach ($selected as $parcel) {
                if ($parcel->customer_id !== $anchor->customer_id || $parcel->customer_address_id !== $anchor->customer_address_id) {
                    throw ValidationException::withMessages(['parcel_ids' => 'Choose parcels at this customer and address only.']);
                }
            }
            $previous = DeliveryAttempt::where('operation_id', $values['operation_id'])
                /** Resolve replay ownership through its parcel, never a global operation lookup. */
                ->whereHas('parcel', fn (Builder $parcel) => $parcel->where('user_id', $rider->id))->get();
            if ($previous->isNotEmpty()) {
                if ($previous->count() !== count($ids) || $previous->contains(
                    /** A changed selection or receipt is not an identical retry. */
                    fn (DeliveryAttempt $attempt): bool => ! hash_equals($attempt->request_hash, $hash),
                )) {
                    throw ValidationException::withMessages(['operation_id' => 'This action already saved different evidence. Review the delivery before trying another action.']);
                }

                return $selected->load(['customer', 'address', 'seller', 'batch', 'attempts']);
            }
            $outcome = $values['outcome'];
            if ($outcome === 'held' && ! $retry->isFuture()) {
                throw ValidationException::withMessages(['retry_at' => 'Choose a future retry time for a new hold.']);
            }
            if ($outcome === 'delivered' && ($receipts->count() !== count($ids) || $receipts->keys()->map(intval(...))->values()->all() !== $ids)) {
                throw ValidationException::withMessages(['receipts' => 'Confirm one full receipt for every selected parcel, without extra parcels.']);
            }
            foreach ($selected as $parcel) {
                if (in_array($parcel->status, ['delivered', 'returned'], true) || $parcel->received_at) {
                    throw ValidationException::withMessages(['parcel_ids' => 'A selected parcel is already closed. Refresh this stop; saved money and history cannot be overwritten.']);
                }
                if ($outcome === 'delivered') {
                    $ready = $parcel->status === 'pending' || ($parcel->status === 'held' && $parcel->retry_at?->isPast());
                    if (! $ready) {
                        throw ValidationException::withMessages(['parcel_ids' => 'Resume failed or future-held parcels before confirming delivery.']);
                    }
                    $receipt = $receipts->get($parcel->id);
                    if ($receipt['amount'] !== $parcel->customer_total) {
                        throw ValidationException::withMessages(['receipts' => 'The confirmed receipt must equal the selected parcel’s full customer total. Partial payment is not supported.']);
                    }
                }
                if ($outcome === 'pending' && ! in_array($parcel->status, ['held', 'failed'], true)) {
                    throw ValidationException::withMessages(['parcel_ids' => 'Only held or failed parcels need to be resumed.']);
                }
            }
            foreach ($selected as $parcel) {
                $changes = ['status' => $outcome, 'retry_at' => $retry];
                if ($outcome === 'delivered') {
                    $receipt = $receipts->get($parcel->id);
                    $changes += [
                        'receipt_method' => $receipt['method'], 'received_amount' => $receipt['amount'],
                        'receipt_reference' => $receipt['reference'], 'received_at' => now(),
                    ];
                }
                $parcel->forceFill($changes)->save();
                $parcel->attempts()->create([
                    'operation_id' => $values['operation_id'], 'request_hash' => $hash,
                    'outcome' => $outcome, 'note' => $values['note'], 'retry_at' => $retry,
                ]);
            }

            return $selected->load(['customer', 'address', 'seller', 'batch', 'attempts']);
        }, 3);
    }
}
