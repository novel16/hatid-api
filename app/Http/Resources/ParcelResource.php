<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Directory\CustomerResource;
use App\Models\DeliveryAttempt;
use App\Models\Parcel;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Parcel */
class ParcelResource extends JsonResource
{
    /** No owner/hash fields, raw models or mutable seller defaults escape the saved snapshot. */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'reference' => 'HTD-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT),
            'description' => $this->description, 'note' => $this->note,
            'customer' => ['id' => $this->customer->id, 'name' => $this->customer->name, 'phone' => $this->customer->phone],
            'address' => CustomerResource::addressData($this->address),
            'seller' => ['id' => $this->seller->id, 'name' => $this->seller->name],
            'batch' => ['id' => $this->batch->id, 'name' => $this->batch->name, 'start' => $this->batch->start_date->toDateString(), 'end' => $this->batch->end_date->toDateString()],
            'pricing' => ['mode' => $this->fee_mode, 'quote' => $this->quote, 'fee' => $this->fee, 'total' => $this->customer_total, 'sellerShare' => $this->seller_share],
            'expected_method' => $this->expected_method, 'override_reason' => $this->override_reason,
            'status' => $this->status, 'retry_at' => $this->retry_at?->toISOString(),
            'receipt' => $this->received_at ? [
                'method' => $this->receipt_method, 'amount' => $this->received_amount,
                'reference' => $this->receipt_reference, 'confirmed_at' => $this->received_at->toISOString(),
            ] : null,
            /** History is loaded only for detail; list responses stay compact. */
            'attempts' => $this->whenLoaded('attempts', fn () => $this->attempts->map(
                /** Never expose replay keys or request hashes from evidence. */
                fn (DeliveryAttempt $attempt): array => [
                    'id' => $attempt->id, 'outcome' => $attempt->outcome, 'note' => $attempt->note,
                    'retry_at' => $attempt->retry_at?->toISOString(), 'at' => $attempt->created_at->toISOString(),
                ],
            )),
            'created_at' => $this->created_at->toISOString(),
            /** Collection queries add settlement totals without changing the immutable receipt. */
            'settlement' => $this->whenHas('remitted_amount', fn (): array => [
                'remitted' => (int) $this->remitted_amount,
                'outstanding' => $this->seller_share - (int) $this->remitted_amount,
            ]),
            /** Detail pages expose only this handover's original allocation, not a pivot model. */
            'allocated_amount' => $this->whenPivotLoaded('remittance_allocations', fn (): int => (int) $this->pivot->amount),
        ];
    }
}
