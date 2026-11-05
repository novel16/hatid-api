<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Http\Resources\Directory\CustomerResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DeliveryResource extends JsonResource
{
    /** Exposes one physical stop and its controlled parcel resources, with no route or wallet claims. */
    public function toArray(Request $request): array
    {
        $parcels = $this->resource;
        $first = $parcels->first();

        return [
            'key' => $first->customer_id.':'.$first->customer_address_id,
            'customer' => ['id' => $first->customer->id, 'name' => $first->customer->name, 'phone' => $first->customer->phone],
            'address' => CustomerResource::addressData($first->address),
            'parcels' => ParcelResource::collection($parcels),
        ];
    }
}
