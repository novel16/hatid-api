<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class MapService
{
    /** Keep provider transport/quota separate from private stop selection and ranking. */
    public function __construct(private readonly GeoapifyService $provider) {}

    /** Metadata is free to read; opening the page never requests GPS or computes a route automatically. */
    public function status(User $rider): array
    {
        return ['vehicle' => $rider->vehicle, 'configured' => $this->provider->configured(),
            'quota' => $this->provider->usage(), 'candidates' => [], 'estimated_at' => null];
    }

    /** Compare only five current owned physical stops, then rank real returned time and road distance. */
    public function recommend(User $rider, array $origin): array
    {
        $profiles = [
            'motorcycle' => ['mode' => 'motorcycle'],
            // A motorcycle with a sidecar uses regular road routing with highway avoidance.
            'trijet' => ['mode' => 'drive', 'avoid' => [['type' => 'highways']]],
            'bicycle' => ['mode' => 'bicycle'],
            'car' => ['mode' => 'drive'],
        ];
        $profile = $profiles[$rider->vehicle ?? ''] ?? null;
        if ($profile === null) {
            throw ValidationException::withMessages(['vehicle' => 'Choose your primary vehicle in Settings before requesting road recommendations.']);
        }
        // ponytail: scan this rider's ready work; use a spatial query if measured directory volume warrants it.
        $groups = $rider->parcels()->ready()->with(['customer', 'address', 'seller', 'batch'])->orderBy('id')->get()
            ->groupBy('customer_address_id')
            /** Unknown/unverified positions remain available through the manual delivery list. */
            ->filter(fn (Collection $parcels): bool => $parcels->first()->address->latitude !== null
                && $parcels->first()->address->longitude !== null && $parcels->first()->address->verified_at !== null)
            /** Straight-line proximity is only a shortlist; it is never presented as a road estimate. */
            ->sortBy(fn (Collection $parcels): float => $this->distance($origin, $parcels->first()->address->latitude, $parcels->first()->address->longitude))
            ->take(5)->values();
        if ($groups->isEmpty()) {
            return $this->status($rider);
        }
        $cells = $this->provider->matrix([
            ...$profile, 'units' => 'metric', 'traffic' => 'free_flow',
            'sources' => [['location' => [round((float) $origin['longitude'], 6), round((float) $origin['latitude'], 6)]]],
            /** Provider payloads contain only coordinates, never customer identity or parcel finances. */
            'targets' => $groups->map(fn (Collection $parcels): array => ['location' => [
                $parcels->first()->address->longitude, $parcels->first()->address->latitude,
            ]])->all(),
        ]);
        /** Null/unreachable routes sort last and retain explicit null fields for manual override. */
        $candidates = $groups->map(fn (Collection $parcels, int $index): array => [
            'parcels' => $parcels, 'duration_seconds' => $cells[$index]['time'], 'distance_meters' => $cells[$index]['distance'],
        ])->sort(
            /** Break equal time estimates with road distance, then stable parcel identity. */
            fn (array $a, array $b): int => [
                $a['duration_seconds'] ?? PHP_FLOAT_MAX, $a['distance_meters'] ?? PHP_FLOAT_MAX, $a['parcels']->first()->id,
            ] <=> [
                $b['duration_seconds'] ?? PHP_FLOAT_MAX, $b['distance_meters'] ?? PHP_FLOAT_MAX, $b['parcels']->first()->id,
            ],
        )->values()->all();

        return [...$this->status($rider), 'candidates' => $candidates, 'estimated_at' => now()->toISOString()];
    }

    /** Haversine central angle is sufficient to order nearby candidates; no paid/geocoding dependency. */
    private function distance(array $origin, float $latitude, float $longitude): float
    {
        $a = sin(deg2rad($latitude - (float) $origin['latitude']) / 2) ** 2
            + cos(deg2rad((float) $origin['latitude'])) * cos(deg2rad($latitude))
            * sin(deg2rad($longitude - (float) $origin['longitude']) / 2) ** 2;

        return 2 * asin(sqrt(min(1, max(0, $a))));
    }
}
