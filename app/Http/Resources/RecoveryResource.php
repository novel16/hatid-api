<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\DeliveryAttempt;
use App\Models\Parcel;
use App\Models\Remittance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class RecoveryResource extends JsonResource
{
    /** Expose only confirmation metadata, never operation hashes, owners or raw financial models. */
    public function toArray(Request $request): array
    {
        $record = $this->resource;
        if ($record === null) {
            return ['state' => 'not_recorded', 'target' => null, 'confirmed_at' => null];
        }
        $kind = match (true) {
            $record instanceof Parcel => 'parcel', $record instanceof DeliveryAttempt => 'delivery',
            $record instanceof Remittance => 'remittance',
        };

        return [
            'state' => 'confirmed',
            'target' => ['kind' => $kind, 'id' => $record instanceof DeliveryAttempt ? $record->parcel_id : $record->id],
            'confirmed_at' => $record->created_at->toISOString(),
        ];
    }
}
