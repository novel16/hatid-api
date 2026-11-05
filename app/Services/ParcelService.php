<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Parcel;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ParcelService
{
    /** Eager-loads controlled history and allows archived directory data for existing work. */
    public function find(User $rider, int $parcelId): Parcel
    {
        return $rider->parcels()->with(['customer', 'address', 'seller', 'batch', 'attempts'])->findOrFail($parcelId);
    }

    /** Saves one reviewed price snapshot; a lost response may safely retry the same operation. */
    public function create(User $rider, array $values): Parcel
    {
        $normalized = [
            'batch_id' => (int) $values['batch_id'], 'customer_id' => (int) $values['customer_id'],
            'customer_address_id' => (int) $values['address_id'], 'seller_id' => (int) $values['seller_id'],
            'description' => ($values['description'] ?? null) ?: 'Parcel', 'note' => ($values['note'] ?? null) ?: null,
            'expected_method' => $values['expected_method'], 'fee_mode' => $values['mode'],
            'quote' => (int) $values['quote'], 'fee' => (int) $values['fee'],
            'override_reason' => $values['override_reason'] ?? null,
        ];
        $hash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));

        /** Serialize only this rider's writes, including a duplicate request arriving before its first response. */
        return DB::transaction(function () use ($rider, $values, $normalized, $hash): Parcel {
            User::whereKey($rider->id)->lockForUpdate()->firstOrFail();
            $existing = $rider->parcels()->where('operation_id', $values['operation_id'])->first();
            if ($existing) {
                if (! hash_equals($existing->submission_hash, $hash)) {
                    throw ValidationException::withMessages(['operation_id' => 'This save was already used with different details. Open the saved parcel before encoding another.']);
                }

                return $this->find($rider, $existing->id);
            }
            $rider->batches()->findOrFail($normalized['batch_id']);
            $customer = $rider->customers()->lockForUpdate()->findOrFail($normalized['customer_id']);
            $customer->addresses()->lockForUpdate()->findOrFail($normalized['customer_address_id']);
            $seller = $rider->sellers()->lockForUpdate()->findOrFail($normalized['seller_id']);
            if (($seller->fee !== $normalized['fee'] || $seller->mode !== $normalized['fee_mode']) && ! $normalized['override_reason']) {
                throw ValidationException::withMessages(['override_reason' => 'Seller defaults differ from this quote. Review the fee and explain the override.']);
            }
            $total = $normalized['fee_mode'] === 'included' ? $normalized['quote'] : $normalized['quote'] + $normalized['fee'];
            $parcel = $rider->parcels()->make([
                ...$normalized, 'customer_total' => $total, 'seller_share' => $total - $normalized['fee'],
            ]);
            $parcel->forceFill(['operation_id' => $values['operation_id'], 'submission_hash' => $hash])->save();

            return $this->find($rider, $parcel->id);
        }, 3);
    }
}
