<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Parcel;
use App\Models\User;
use App\Services\CollectionService;
use App\Services\DeliveryService;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Feature\Auth\AuthTestCase;

class NotificationTest extends AuthTestCase
{
    /** A verified new account must receive an empty real inbox, never sample activity. */
    public function test_owned_notification_feed_starts_empty(): void
    {
        $rider = $this->account();
        $this->api('GET', '/api/v1/notifications', token: $this->token($rider))
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('unread_count', 0)
            ->assertJsonPath('meta.total', 0)->assertJsonPath('meta.per_page', 10);
    }

    /** Pagination and the global unread count never include foreign or unrelated framework alerts. */
    #[DataProvider('pageSizes')]
    public function test_private_server_pagination_has_full_unread_count(int $size, int $rows): void
    {
        $rider = $this->account();
        for ($index = 0; $index < 105; $index++) {
            $this->notice($rider);
        }
        $this->notice($rider, ['type' => 'other.framework.notification']);
        $this->notice($this->account());
        $differentType = $this->notice($rider);
        $differentType->forceFill(['notifiable_type' => Parcel::class])->save();
        $this->api('GET', '/api/v1/notifications?page=2&per_page='.$size, token: $this->token($rider))
            ->assertOk()->assertJsonCount($rows, 'data')->assertJsonPath('meta.total', 105)
            ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.per_page', $size)->assertJsonPath('unread_count', 105);
    }

    /** Exercise each supported UI size on a second page, including the short final page. */
    public static function pageSizes(): array
    {
        return [[10, 10], [50, 50], [100, 5]];
    }

    /** A stable timeline and explicit allowlist prevent raw notification data/owner fields escaping. */
    public function test_feed_filters_unread_and_allowlists_related_record_targets(): void
    {
        $this->freezeTime();
        $rider = $this->account();
        $this->notice($rider, ['read_at' => now()]);
        $first = $this->notice($rider, ['id' => '00000000-0000-4000-8000-000000000001']);
        $last = $this->notice($rider, ['id' => '00000000-0000-4000-8000-000000000002']);
        $token = $this->token($rider);
        $response = $this->api('GET', '/api/v1/notifications?state=unread', token: $token)
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 2)->assertJsonPath('unread_count', 2)
            ->assertJsonPath('data.0.id', $last->id)->assertJsonPath('data.1.id', $first->id)
            ->assertJsonPath('data.0.target.kind', 'delivery')->assertJsonPath('data.0.target.id', 15);
        $this->assertEqualsCanonicalizing(['id', 'category', 'title', 'message', 'target', 'read_at', 'created_at'], array_keys($response->json('data.0')));
        $this->assertEqualsCanonicalizing(['kind', 'id'], array_keys($response->json('data.0.target')));
        $this->api('GET', '/api/v1/notifications/unread-count', token: $token)
            ->assertOk()->assertExactJson(['data' => ['unread_count' => 2]]);
    }

    /** Read actions require owner scope, preserve the first read time and cannot rewrite event data. */
    public function test_single_read_is_owned_and_idempotent(): void
    {
        $this->freezeTime();
        $rider = $this->account();
        $notice = $this->notice($rider);
        $foreign = $this->notice($this->account());
        $other = $this->notice($rider, ['type' => 'other.framework.notification']);
        $token = $this->token($rider);
        foreach ([$foreign, $other] as $hidden) {
            $this->api('PATCH', '/api/v1/notifications/'.$hidden->id.'/read', token: $token)->assertNotFound();
            $this->assertNull($hidden->fresh()->read_at);
        }
        $url = '/api/v1/notifications/'.$notice->id.'/read';
        $this->api('PATCH', $url, ['read_at' => now()->toISOString()], $token)->assertUnprocessable()->assertJsonValidationErrors('read_at');
        $this->assertNull($notice->fresh()->read_at);
        $first = $this->api('PATCH', $url, token: $token)->assertOk()->json('data.read_at');
        $this->assertNotNull($first);
        $this->travel(5)->minutes();
        $this->api('PATCH', $url, token: $token)->assertOk()->assertJsonPath('data.read_at', $first);
        $this->assertSame($notice->data, $notice->fresh()->data);
        $this->api('GET', '/api/v1/notifications/unread-count', token: $token)->assertJsonPath('data.unread_count', 0);
    }

    /** Bulk read crosses pages without touching another rider, prior read times or history rows. */
    public function test_mark_all_read_updates_only_current_owned_unread_alerts(): void
    {
        $this->freezeTime();
        $rider = $this->account();
        $older = $this->notice($rider, ['read_at' => now()->subDay()]);
        for ($index = 0; $index < 25; $index++) {
            $this->notice($rider);
        }
        $foreign = $this->notice($this->account());
        $token = $this->token($rider);
        $this->api('PATCH', '/api/v1/notifications/read-all', token: $token)->assertNoContent();
        $this->api('PATCH', '/api/v1/notifications/read-all', token: $token)->assertNoContent();
        $this->api('GET', '/api/v1/notifications?state=unread', token: $token)
            ->assertOk()->assertJsonCount(0, 'data')->assertJsonPath('unread_count', 0);
        $this->assertSame($older->read_at->toISOString(), $older->fresh()->read_at->toISOString());
        $this->assertNull($foreign->fresh()->read_at);
        $this->assertDatabaseCount('notifications', 27);
    }

    /** All new read/write routes retain the real Bearer, role, ability and expiry boundary. */
    public function test_notification_routes_require_rider_bearer_permission(): void
    {
        $rider = $this->account();
        $notice = $this->notice($rider);
        $admin = $this->token($this->account(UserRole::Admin));
        $limited = $rider->createToken('Wrong permission', ['admin:access'])->plainTextToken;
        $expired = $rider->createToken('Expired', ['rider:access'], now()->subMinute())->plainTextToken;
        foreach ([['GET', '/notifications'], ['GET', '/notifications/unread-count'], ['PATCH', '/notifications/read-all'], ['PATCH', '/notifications/'.$notice->id.'/read']] as [$method, $path]) {
            $this->api($method, '/api/v1'.$path)->assertUnauthorized();
            $this->api($method, '/api/v1'.$path, token: $admin)->assertForbidden();
            $this->api($method, '/api/v1'.$path, token: $limited)->assertForbidden();
            $this->api($method, '/api/v1'.$path, token: $expired)->assertUnauthorized();
        }
    }

    /** Client state, page size and ownership inputs are validated before private queries. */
    #[DataProvider('invalidQueries')]
    public function test_notification_query_validation(string $query, string $field): void
    {
        $this->api('GET', '/api/v1/notifications?'.$query, token: $this->token($this->account()))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
    }

    /** These cases catch accidental unbounded queries and accepting owner/data overrides. */
    public static function invalidQueries(): array
    {
        return [['per_page=0', 'per_page'], ['per_page=101', 'per_page'], ['page=0', 'page'], ['state=other', 'state'], ['user_id=12', 'user_id'], ['notifiable_id=12', 'notifiable_id'], ['read_at=2026-11-01', 'read_at']];
    }

    /** A grouped handover emits one alert, and an identical replay never emits it again. */
    public function test_delivery_notification_is_grouped_and_replay_safe(): void
    {
        $rider = $this->account();
        $first = Parcel::factory()->for($rider)->create();
        $second = Parcel::factory()->for($rider)->create(['customer_id' => $first->customer_id, 'customer_address_id' => $first->customer_address_id]);
        $values = $this->outcome([$first, $second]);
        $token = $this->token($rider);
        $url = '/api/v1/deliveries/'.$first->id.'/outcomes';
        $this->api('POST', $url, $values, $token)->assertOk();
        $this->api('POST', $url, $values, $token)->assertOk();
        $this->api('POST', $url, [...$values, 'note' => 'Changed evidence'], $token)->assertUnprocessable();
        $this->assertDatabaseCount('notifications', 1);
        $this->assertDatabaseCount('delivery_attempts', 2);
        $this->api('GET', '/api/v1/notifications', token: $token)->assertOk()
            ->assertJsonPath('data.0.category', 'collection')->assertJsonPath('data.0.target.kind', 'delivery')
            ->assertJsonPath('data.0.target.id', $first->id)->assertJsonPath('data.0.read_at', null);
    }

    /** Every supported manual outcome produces activity, including an explicit resume and return. */
    #[DataProvider('outcomes')]
    public function test_manual_outcomes_emit_delivery_activity(string $outcome): void
    {
        $rider = $this->account();
        $parcel = Parcel::factory()->for($rider)->create(['status' => $outcome === 'pending' ? 'failed' : 'pending']);
        $this->api('POST', '/api/v1/deliveries/'.$parcel->id.'/outcomes', $this->outcome([$parcel], $outcome), $this->token($rider))->assertOk();
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame($outcome, $parcel->fresh()->status);
    }

    /** These are actual existing delivery transitions, not invented scheduled reminders. */
    public static function outcomes(): array
    {
        return [['delivered'], ['held'], ['failed'], ['pending'], ['returned']];
    }

    /** Collection preferences also cover customer receipts, without duplicating a grouped handover alert. */
    #[DataProvider('alertPreferences')]
    public function test_receipts_honor_either_relevant_preference_without_duplicate_alerts(bool $delivery, bool $collection, string $outcome, ?string $category): void
    {
        $rider = $this->account();
        $rider->forceFill(['preferences' => ['deliveryAlerts' => $delivery, 'collectionAlerts' => $collection]])->save();
        $parcel = Parcel::factory()->for($rider)->create();
        $this->api('POST', '/api/v1/deliveries/'.$parcel->id.'/outcomes', $this->outcome([$parcel], $outcome), $this->token($rider))->assertOk();
        $this->assertDatabaseCount('notifications', $category === null ? 0 : 1);
        if ($category !== null) {
            $this->assertSame($category, $rider->notifications()->first()->type);
        }
    }

    /** A combined delivery/payment alert satisfies both toggles while producing at most one timeline row. */
    public static function alertPreferences(): array
    {
        return [[true, true, 'delivered', 'collection'], [false, true, 'delivered', 'collection'], [true, false, 'delivered', 'delivery'], [false, false, 'delivered', null], [false, true, 'held', null], [true, false, 'failed', 'delivery']];
    }

    /** Preferences suppress new alerts only; re-enabling and replay cannot recreate suppressed history. */
    public function test_preferences_and_replays_preserve_history_without_retroactive_alerts(): void
    {
        $rider = $this->account();
        $token = $this->token($rider);
        $parcel = Parcel::factory()->for($rider)->create();
        $outcome = $this->outcome([$parcel]);
        $handover = ['operation_id' => (string) Str::uuid(), 'seller_id' => $parcel->seller_id, 'amount' => 6000, 'method' => 'cash', 'handover_confirmed' => true];
        $this->api('PATCH', '/api/v1/settings', ['preferences' => ['deliveryAlerts' => false, 'collectionAlerts' => false]], $token)->assertOk();
        $this->api('POST', '/api/v1/deliveries/'.$parcel->id.'/outcomes', $outcome, $token)->assertOk();
        $saved = $this->api('POST', '/api/v1/remittances', $handover, $token)->assertCreated()->json('data.id');
        $this->assertDatabaseCount('notifications', 0);
        $this->api('PATCH', '/api/v1/settings', ['preferences' => ['deliveryAlerts' => true, 'collectionAlerts' => true]], $token)->assertOk();
        $this->api('POST', '/api/v1/deliveries/'.$parcel->id.'/outcomes', $outcome, $token)->assertOk();
        $this->api('POST', '/api/v1/remittances', $handover, $token)->assertCreated()->assertJsonPath('data.id', $saved);
        $this->assertDatabaseCount('notifications', 0);
        $new = [...$handover, 'operation_id' => (string) Str::uuid(), 'amount' => 4000];
        $record = $this->api('POST', '/api/v1/remittances', $new, $token)->assertCreated()->json('data.id');
        $this->api('POST', '/api/v1/remittances', $new, $token)->assertCreated()->assertJsonPath('data.id', $record);
        $this->api('POST', '/api/v1/remittances', [...$new, 'amount' => 3000], $token)->assertConflict();
        $this->api('GET', '/api/v1/notifications', token: $token)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.category', 'collection')->assertJsonPath('data.0.target.kind', 'remittance')->assertJsonPath('data.0.target.id', $record);
        $this->api('PATCH', '/api/v1/settings', ['preferences' => ['collectionAlerts' => false]], $token)->assertOk();
        $this->api('GET', '/api/v1/notifications', token: $token)->assertOk()->assertJsonCount(1, 'data');
        $this->assertSame(11500, $parcel->fresh()->received_amount);
        $this->assertDatabaseCount('delivery_attempts', 1);
        $this->assertDatabaseCount('remittances', 2);
    }

    /** A caller's old profile cannot override preferences saved before the owner lock is acquired. */
    public function test_alert_creation_uses_the_current_locked_rider_preferences(): void
    {
        $rider = $this->account();
        $parcel = Parcel::factory()->for($rider)->create();
        DB::table('users')->where('id', $rider->id)->update(['preferences' => json_encode(['deliveryAlerts' => false, 'collectionAlerts' => false])]);
        app(DeliveryService::class)->outcome($rider, $parcel->id, $this->outcome([$parcel]));
        app(CollectionService::class)->record($rider, ['operation_id' => (string) Str::uuid(), 'seller_id' => $parcel->seller_id, 'amount' => 6000, 'method' => 'cash']);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('delivery_attempts', 1);
        $this->assertDatabaseCount('remittances', 1);
    }

    /** Failed notification storage must leave no partially committed receipt, attempt or allocation. */
    #[DataProvider('businessWrites')]
    public function test_notification_insert_failure_rolls_back_the_business_write(string $kind): void
    {
        $rider = $this->account();
        $parcel = Parcel::factory()->for($rider)->create($kind === 'remittance' ? ['status' => 'delivered', 'received_amount' => 11500, 'received_at' => now(), 'receipt_method' => 'cash'] : []);
        $event = 'eloquent.creating: '.DatabaseNotification::class;
        Event::listen($event, /** Simulate a real database-notification insertion failure at its model boundary. */ static function (): void {
            throw new RuntimeException('Test notification storage failure');
        });
        try {
            $path = $kind === 'remittance' ? '/api/v1/remittances' : '/api/v1/deliveries/'.$parcel->id.'/outcomes';
            $payload = $kind === 'remittance'
                ? ['operation_id' => (string) Str::uuid(), 'seller_id' => $parcel->seller_id, 'amount' => 6000, 'method' => 'cash', 'handover_confirmed' => true]
                : $this->outcome([$parcel]);
            $this->api('POST', $path, $payload, $this->token($rider))->assertStatus(500);
        } finally {
            Event::forget($event);
        }
        $this->assertSame($kind === 'remittance' ? 'delivered' : 'pending', $parcel->fresh()->status);
        $this->assertSame($kind === 'remittance' ? 11500 : null, $parcel->fresh()->received_amount);
        $this->assertDatabaseCount('notifications', 0);
        $this->assertDatabaseCount('delivery_attempts', 0);
        $this->assertDatabaseCount('remittances', 0);
        $this->assertDatabaseCount('remittance_allocations', 0);
    }

    /** Both existing transaction owners must share notification commit/rollback behavior. */
    public static function businessWrites(): array
    {
        return [['delivery'], ['remittance']];
    }

    /** Creates isolated native-model inbox fixtures with extra fields the resource must not expose. */
    private function notice(User $rider, array $attributes = []): DatabaseNotification
    {
        return $rider->notifications()->create([
            'id' => (string) Str::uuid(), 'type' => 'delivery',
            'data' => ['title' => 'Delivery update', 'message' => 'Rider confirmed an outcome.', 'target' => ['kind' => 'delivery', 'id' => 15, 'url' => 'https://untrusted.invalid'], 'secret' => 'must stay private'],
            'read_at' => null, ...$attributes,
        ]);
    }

    /** Supplies independently checked full amounts and explicit confirmations for existing outcome rules. */
    private function outcome(array $parcels, string $outcome = 'delivered'): array
    {
        $values = ['operation_id' => (string) Str::uuid(), 'parcel_ids' => array_column($parcels, 'id'), 'outcome' => $outcome, 'note' => 'Rider confirmed this action'];
        if ($outcome === 'delivered') {
            $values += ['receipt_confirmed' => true, 'receipts' => array_map(/** Hand-checked fixture customer total is PHP115. */ fn (Parcel $parcel): array => ['parcel_id' => $parcel->id, 'method' => 'cash', 'amount' => 11500], $parcels)];
        } elseif ($outcome === 'held') {
            $values['retry_at'] = now()->addDay()->toIso8601String();
        } elseif ($outcome === 'returned') {
            $values['handover_confirmed'] = true;
        }

        return $values;
    }
}
