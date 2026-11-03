<?php

declare(strict_types=1);

namespace App\Http\Resources\Directory;

use App\Models\Seller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Seller */
class SellerResource extends JsonResource
{
    /** Allows seller preferences without owner IDs or unrelated collection records. */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'contact' => $this->contact,
            'mode' => $this->mode,
            'fee' => $this->fee,
            'archived_at' => $this->deleted_at?->toISOString(),
        ];
    }
}
