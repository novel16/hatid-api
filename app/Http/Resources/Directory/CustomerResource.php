<?php

declare(strict_types=1);

namespace App\Http\Resources\Directory;

use App\Models\Customer;
use App\Models\CustomerAddress;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Customer */
class CustomerResource extends JsonResource
{
    /** Allows only customer identity and the explicitly loaded address collection. */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'phone' => $this->phone,
            // Apply the address allowlist only to the relation loaded by the scoped service.
            'addresses' => $this->whenLoaded('addresses', fn () => $this->addresses->map(self::addressData(...))),
            'archived_at' => $this->deleted_at?->toISOString(),
        ];
    }

    /** Exposes address metadata/read-only GPS without its parent or owner identifiers. */
    public static function addressData(CustomerAddress $address): array
    {
        return [
            'id' => $address->id,
            'label' => $address->label,
            'text' => $address->text,
            'latitude' => $address->latitude,
            'longitude' => $address->longitude,
            'verified_at' => $address->verified_at?->toISOString(),
            'gps_accuracy' => $address->gps_accuracy,
            'gps_captured_at' => $address->gps_captured_at?->toISOString(),
            'location_corrected' => (bool) $address->location_corrected,
            'location_version' => (int) $address->location_version,
            'archived_at' => $address->deleted_at?->toISOString(),
        ];
    }
}
