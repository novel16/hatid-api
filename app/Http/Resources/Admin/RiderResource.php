<?php

declare(strict_types=1);

namespace App\Http\Resources\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class RiderResource extends JsonResource
{
    /** Keep the directory and profile dialog on the same allowlisted fields. */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'vehicle' => $this->vehicle,
            'created_at' => $this->created_at?->toISOString(),
            'status' => $this->isSuspendedRider() ? 'suspended' : 'active',
            'status_revision' => $this->rider_status_revision,
            'suspended_at' => $this->suspended_at?->toISOString(),
        ];
    }
}
