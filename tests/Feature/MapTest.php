<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Parcel;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Request as IncomingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\Feature\Auth\AuthTestCase;

class MapTest extends AuthTestCase
{
    /** Fake only the external provider; real HTTP routes, ownership, SQL and budgets remain active. */
    protected function setUp(): void
    {
        parent::setUp();
        config(['maps.api_key' => 'test-provider-secret', 'maps.credit_limit' => 2500, 'cache.stores.maps' => ['driver' => 'array']]);
        Http::preventStrayRequests();
        $this->freezeTime();
    }

    /** Shortlisting groups original-week parcels and compares only five owned, ready, pinned stops. */
    public function test_owned_shortlist_ranks_road_time_then_distance_and_keeps_unreachable_honest(): void
    {
        $rider = $this->vehicleRider();
        $parcels = [];
        for ($index = 0; $index < 7; $index++) {
            $parcels[] = $this->pinned($rider, 14.601 + $index * 0.001);
        }
        Parcel::factory()->for($rider)->create(['customer_id' => $parcels[0]->customer_id, 'customer_address_id' => $parcels[0]->customer_address_id]);
        $this->pinned($this->account(), 14.6);
        $this->pinned($rider, 14.6001)->forceFill(['status' => 'failed'])->save();
        $this->pinned($rider, 14.6002)->forceFill(['status' => 'held', 'retry_at' => now()->addDay()])->save();
        $this->pinned($rider, 14.6003)->forceFill(['status' => 'delivered'])->save();
        Parcel::factory()->for($rider)->create();
        Http::fake(['api.geoapify.com/*' => Http::response($this->matrix(), 200)]);
        $response = $this->api('POST', '/api/v1/map/recommendations', $this->origin(), $this->token($rider))
            ->assertOk()->assertJsonPath('data.compared_count', 5)->assertJsonCount(5, 'data.candidates')
            ->assertJsonPath('data.vehicle', 'motorcycle')->assertJsonPath('data.candidates.0.parcels.0.id', $parcels[2]->id)
            ->assertJsonPath('data.candidates.0.duration_seconds', 200)->assertJsonPath('data.candidates.0.distance_meters', 2000)
            ->assertJsonPath('data.candidates.1.parcels.0.id', $parcels[1]->id)
            ->assertJsonPath('data.candidates.4.duration_seconds', null)->assertJsonPath('data.quota.used', 5);
        $first = collect($response->json('data.candidates'))->firstWhere('parcels.0.id', $parcels[0]->id);
        $this->assertCount(2, $first['parcels']);
        $this->assertStringNotContainsString('test-provider-secret', $response->getContent());
        Http::assertSent(
            /** Provider receives coordinates/profile only, never customer names, phones, payments or owner IDs. */
            fn (Request $request): bool => count($request['sources']) === 1 && count($request['targets']) === 5
                && $request['mode'] === 'motorcycle' && $request['sources'][0]['location'] === [120.98, 14.6]
                && ! str_contains($request->body(), $rider->name),
        );
    }

    /** The validated account field selects the provider mode; arbitrary client profiles are rejected. */
    public function test_vehicle_profiles_and_legacy_profile_completion(): void
    {
        $rider = $this->account();
        $token = $this->token($rider);
        $this->pinned($rider, 14.601);
        Http::fake(['api.geoapify.com/*' => Http::response($this->matrix(1), 200)]);
        $this->api('POST', '/api/v1/map/recommendations', $this->origin(), $token)->assertUnprocessable()->assertJsonValidationErrors('vehicle');
        Http::assertNothingSent();
        foreach (['motorcycle' => 'motorcycle', 'bicycle' => 'bicycle', 'car' => 'drive'] as $vehicle => $mode) {
            $rider->forceFill(['vehicle' => $vehicle])->save();
            $this->api('POST', '/api/v1/map/recommendations', $this->origin(), $token)->assertOk()->assertJsonPath('data.vehicle', $vehicle);
            Http::assertSent(/** An independently specified expected mode catches car/provider naming mistakes. */ fn (Request $request): bool => $request['mode'] === $mode);
        }
        $this->api('POST', '/api/v1/map/recommendations', [...$this->origin(), 'mode' => 'truck'], $token)
            ->assertUnprocessable()->assertJsonValidationErrors('mode');
    }

    /** No eligible coordinates means no billable request and a normal manual-delivery fallback. */
    public function test_unknown_locations_and_empty_ready_work_use_no_provider_credits(): void
    {
        $rider = $this->vehicleRider();
        Parcel::factory()->for($rider)->create();
        $this->api('POST', '/api/v1/map/recommendations', $this->origin(), $this->token($rider))
            ->assertOk()->assertJsonCount(0, 'data.candidates')->assertJsonPath('data.quota.used', 0);
        Http::assertNothingSent();
    }

    /** Identical coordinates/profile are cached, but readiness is rebuilt and vehicle/pin changes invalidate geometry. */
    public function test_cache_reuses_geometry_without_returning_closed_or_stale_stops(): void
    {
        $rider = $this->vehicleRider();
        $parcel = $this->pinned($rider, 14.601);
        $token = $this->token($rider);
        Http::fake(['api.geoapify.com/*' => Http::response($this->matrix(1), 200)]);
        $this->api('POST', '/api/v1/map/recommendations', $this->origin(), $token)->assertOk();
        $this->api('POST', '/api/v1/map/recommendations', $this->origin(), $token)->assertOk()->assertJsonPath('data.quota.used', 1);
        Http::assertSentCount(1);
        $parcel->forceFill(['status' => 'delivered'])->save();
        $this->api('POST', '/api/v1/map/recommendations', $this->origin(), $token)->assertOk()->assertJsonCount(0, 'data.candidates');
        Http::assertSentCount(1);
        $parcel->forceFill(['status' => 'pending'])->save();
        $parcel->address->forceFill(['latitude' => 14.602])->save();
        $this->api('POST', '/api/v1/map/recommendations', $this->origin(), $token)->assertOk()->assertJsonPath('data.quota.used', 2);
        Http::assertSentCount(2);
    }

    /** Tiles cost quarter-credits, cache across riders, and never expose provider keys or require cookie auth. */
    public function test_authenticated_tile_cache_and_quarter_credit_accounting(): void
    {
        Http::fake(['maps.geoapify.com/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);
        $first = $this->token($this->account());
        $second = $this->token($this->account());
        $url = '/api/v1/map/tiles/12/3424/1879';
        $this->api('GET', $url)->assertUnauthorized();
        $this->api('GET', $url, token: $first)->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeaderMissing('Set-Cookie');
        $this->api('GET', $url, token: $second)->assertOk();
        Http::assertSentCount(1);
        $this->api('GET', '/api/v1/map', token: $first)->assertOk()->assertJsonPath('data.quota.used', 0.25);
        foreach (['19/0/0', '2/4/1', '2/1/4', '2/-1/0', '2/no/0'] as $bad) {
            $this->api('GET', '/api/v1/map/tiles/'.$bad, token: $first)->assertStatus(in_array($bad, ['2/-1/0', '2/no/0'], true) ? 404 : 422);
        }
        Http::assertSentCount(1);
    }

    /** A small test burst proves bucket isolation without sending 120 requests on the user's device. */
    public function test_tile_traffic_cannot_exhaust_manual_delivery_rate_limits(): void
    {
        RateLimiter::for('authenticated', /** Exercise the same named bucket at a small limit. */ static fn (IncomingRequest $request): Limit => Limit::perMinute(2)->by((string) $request->user()->id));
        $rider = $this->account();
        $parcel = Parcel::factory()->for($rider)->create();
        $token = $this->token($rider);
        Http::fake(['maps.geoapify.com/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);
        for ($index = 0; $index < 2; $index++) {
            $this->api('GET', '/api/v1/map/tiles/12/3424/1879', token: $token)->assertOk();
        }
        $this->api('GET', '/api/v1/deliveries', token: $token)->assertOk();
        $this->api('POST', '/api/v1/deliveries/'.$parcel->id.'/outcomes', [
            'operation_id' => (string) Str::uuid(), 'outcome' => 'delivered', 'parcel_ids' => [$parcel->id],
            'note' => 'Rider confirmed manual handover', 'receipt_confirmed' => true,
            'receipts' => [['parcel_id' => $parcel->id, 'method' => 'cash', 'amount' => 11500]],
        ], $token)->assertOk()->assertJsonPath('data.0.status', 'delivered');
        Http::assertSentCount(1);
    }

    /** All riders share a persisted rolling allowance; cache flush or midnight cannot reset spent credits. */
    public function test_budget_is_shared_persistent_and_expires_conservatively(): void
    {
        $this->travelTo(now()->utc()->startOfDay()->addHours(23)->addMinutes(59));
        DB::table('map_usage')->insert(['bucket' => now()->utc()->startOfHour()->format('Y-m-d H:i:s'), 'units' => 10000]);
        Cache::flush();
        Cache::store('maps')->flush();
        $token = $this->token($this->vehicleRider());
        $this->api('GET', '/api/v1/map/tiles/12/3424/1879', token: $token)->assertStatus(429);
        Http::assertNothingSent();
        $this->travel(2)->minutes();
        $other = $this->token($this->account());
        $this->api('GET', '/api/v1/map/tiles/12/3424/1879', token: $other)->assertStatus(429);
        $this->travel(25)->hours();
        Http::fake(['maps.geoapify.com/*' => Http::response($this->png(), 200, ['Content-Type' => 'image/png'])]);
        $this->api('GET', '/api/v1/map/tiles/12/3424/1879', token: $other)->assertUnauthorized();
        $this->api('GET', '/api/v1/map/tiles/12/3424/1879', token: $this->token($this->account()))->assertOk();
    }

    /** Distance surcharges are charged from returned road distance, with spare capacity reserved before the request. */
    public function test_matrix_distance_surcharge_and_conservative_reservation(): void
    {
        $rider = $this->vehicleRider();
        $this->pinned($rider, 14.601);
        $matrix = $this->matrix(1);
        $matrix['sources_to_targets'][0][0]['distance'] = 1200000;
        Http::fake(['api.geoapify.com/*' => Http::response($matrix, 200)]);
        $token = $this->token($rider);
        $this->api('POST', '/api/v1/map/recommendations', $this->origin(), $token)->assertOk()->assertJsonPath('data.quota.used', 3);
        Cache::flush();
        Cache::store('maps')->flush();
        DB::table('map_usage')->update(['units' => 10000]);
        $this->api('POST', '/api/v1/map/recommendations', $this->origin(), $token)->assertStatus(429);
        Http::assertSentCount(1);
    }

    /** Provider errors and malformed/null routes never become fake zero-minute recommendations or secret leaks. */
    public function test_provider_failures_and_missing_configuration_fail_safely(): void
    {
        $rider = $this->vehicleRider();
        $this->pinned($rider, 14.601);
        $token = $this->token($rider);
        foreach ([Http::response(['message' => 'test-provider-secret'], 403), Http::response(['sources_to_targets' => []], 200), Http::failedConnection()] as $failure) {
            Cache::flush();
            Cache::store('maps')->flush();
            Http::fake(['api.geoapify.com/*' => $failure]);
            $response = $this->api('POST', '/api/v1/map/recommendations', $this->origin(), $token)->assertStatus(503);
            $this->assertStringNotContainsString('test-provider-secret', $response->getContent());
        }
        config(['maps.api_key' => '']);
        $this->api('GET', '/api/v1/map', token: $token)->assertOk()->assertJsonPath('data.configured', false);
        $this->api('POST', '/api/v1/map/recommendations', $this->origin(), $token)->assertStatus(503);
    }

    /** Fresh GPS, Bearer ownership, role and abilities protect all new provider operations. */
    public function test_map_authentication_and_origin_validation(): void
    {
        $this->api('GET', '/api/v1/map')->assertUnauthorized();
        $this->api('POST', '/api/v1/map/recommendations', $this->origin())->assertUnauthorized();
        $this->api('GET', '/api/v1/map', token: $this->token($this->account(UserRole::Admin)))->assertForbidden();
        $rider = $this->vehicleRider();
        $limited = $rider->createToken('Restricted', ['other'])->plainTextToken;
        $this->api('GET', '/api/v1/map', token: $limited)->assertForbidden();
        foreach ([['latitude' => 91], ['longitude' => 181], ['accuracy' => -1], ['captured_at' => now()->subMinutes(6)->toISOString()], ['targets' => [['location' => [1, 2]]]], ['vehicle' => 'car']] as $changes) {
            $this->api('POST', '/api/v1/map/recommendations', [...$this->origin(), ...$changes], $this->token($rider))->assertUnprocessable();
        }
        Http::assertNothingSent();
    }

    /** Creates one explicit account vehicle without changing legacy-account factory defaults. */
    private function vehicleRider(): User
    {
        $rider = $this->account();
        $rider->forceFill(['vehicle' => 'motorcycle'])->save();

        return $rider;
    }

    /** Supplies independently positioned owned pinned stops for eligibility/ranking tests. */
    private function pinned(User $rider, float $latitude): Parcel
    {
        $parcel = Parcel::factory()->for($rider)->create();
        $parcel->address->forceFill(['latitude' => $latitude, 'longitude' => 120.98, 'verified_at' => now()])->save();

        return $parcel;
    }

    /** Models a fresh Manila rider reading rather than a selected customer pin. */
    private function origin(): array
    {
        return ['latitude' => 14.6, 'longitude' => 120.98, 'accuracy' => 12, 'captured_at' => now()->toISOString()];
    }

    /** Literal times/distances make the third route fastest after the distance tie-break. */
    private function matrix(int $count = 5): array
    {
        $cells = [
            ['distance' => 5000, 'time' => 500, 'source_index' => 0, 'target_index' => 0],
            ['distance' => 2500, 'time' => 200, 'source_index' => 0, 'target_index' => 1],
            ['distance' => 2000, 'time' => 200, 'source_index' => 0, 'target_index' => 2],
            ['distance' => 8000, 'time' => 800, 'source_index' => 0, 'target_index' => 3],
            ['distance' => null, 'time' => null, 'source_index' => 0, 'target_index' => 4],
        ];

        return ['sources_to_targets' => [array_slice($cells, 0, $count)]];
    }

    /** One actual PNG lets binary proxy tests exercise content validation and byte-preserving caching. */
    private function png(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aRfkAAAAASUVORK5CYII=');
    }
}
