<?php

declare(strict_types=1);

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MapResource extends JsonResource
{
    /** Allowlist route figures while reusing controlled stop/parcel/address serialization. */
    public function toArray(Request $request): array
    {
        $values = $this->resource;

        return [
            'vehicle' => $values['vehicle'], 'configured' => $values['configured'],
            'quota' => $values['quota'], 'estimated_at' => $values['estimated_at'],
            'compared_count' => count($values['candidates']),
            /** No provider bodies, keys, owner IDs or request hashes reach the client. */
            'candidates' => array_map(fn (array $candidate): array => [
                ...(new DeliveryResource($candidate['parcels']))->resolve($request),
                'duration_seconds' => $candidate['duration_seconds'], 'distance_meters' => $candidate['distance_meters'],
            ], $values['candidates']),
        ];
    }
}
