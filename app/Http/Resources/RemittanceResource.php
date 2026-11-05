<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Remittance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Remittance */
class RemittanceResource extends JsonResource
{
    /** Exposes handover evidence without ownership, operation keys or payload hashes. */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => 'RMT-'.str_pad((string) $this->id, 6, '0', STR_PAD_LEFT),
            'seller' => ['id' => $this->seller->id, 'name' => $this->seller->name, 'archived_at' => $this->seller->deleted_at?->toISOString()],
            'amount' => $this->amount, 'method' => $this->method, 'reference' => $this->reference,
            'recorded_at' => $this->created_at->toISOString(),
            'allocation_count' => $this->whenCounted('parcels'),
        ];
    }
}
