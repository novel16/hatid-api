<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Batch;
use App\Models\Parcel;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Auth\AuthTestCase;

class DeliveryTest extends AuthTestCase
{
    /** Both intake weeks appear once at a physical address, with native stop pagination. */
    #[DataProvider('entrySizes')]
    public function test_cross_week_stop_pagination_and_private_search(int $size): void
    {
        $rider = $this->account();
        $first = Parcel::factory()->for($rider)->create();
        $older = Batch::factory()->for($rider)->create(['start_date' => '2026-10-26', 'end_date' => '2026-11-01']);
        $second = Parcel::factory()->for($rider)->create([
            'customer_id' => $first->customer_id, 'customer_address_id' => $first->customer_address_id, 'batch_id' => $older->id,
        ]);
        Parcel::factory()->for($rider)->count(11)->create();
        $foreign = Parcel::factory()->create();
        $token = $this->token($rider);
        $response = $this->api('GET', '/api/v1/deliveries?per_page='.$size, token: $token)->assertOk()
            ->assertJsonPath('meta.total', 12)->assertJsonPath('meta.per_page', $size)
            ->assertJsonCount(min($size, 12), 'data');
        $this->assertStringContainsString('per_page='.$size, $response->json('links.first'));
        $stop = collect($response->json('data'))->firstWhere('address.id', $first->customer_address_id);
        $this->assertCount(2, $stop['parcels']);
        $this->api('GET', '/api/v1/deliveries?batch_id='.$older->id, token: $token)->assertOk()
            ->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'data.0.parcels');
        $this->api('GET', '/api/v1/deliveries/'.$first->id, token: $token)->assertOk()->assertJsonCount(2, 'data.parcels');
        $this->api('GET', '/api/v1/deliveries?q='.urlencode($foreign->customer->name), token: $token)
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->api('GET', '/api/v1/deliveries?batch_id='.$foreign->batch_id, token: $token)->assertNotFound();
        $this->api('GET', '/api/v1/deliveries?page=2&per_page=10', token: $token)->assertOk()->assertJsonCount(2, 'data');
    }

    /** Entry sizes never change the number of owned matching stops. */
    public static function entrySizes(): array
    {
        return [[10], [50], [100]];
    }

    /** Status and method must match the same parcel; scheduled holds stay visibly open but not ready. */
    public function test_filters_and_due_holds(): void
    {
        $rider = $this->account();
        $parcel = Parcel::factory()->for($rider)->create(['status' => 'delivered', 'receipt_method' => 'cash']);
        Parcel::factory()->for($rider)->create([
            'customer_id' => $parcel->customer_id, 'customer_address_id' => $parcel->customer_address_id, 'expected_method' => 'gcash',
        ]);
        Parcel::factory()->for($rider)->create(['status' => 'held', 'retry_at' => now()->addDay()]);
        Parcel::factory()->for($rider)->create(['status' => 'held', 'retry_at' => now()->subMinute()]);
        $token = $this->token($rider);
        $this->api('GET', '/api/v1/deliveries?status=delivered&method=gcash', token: $token)->assertOk()->assertJsonPath('meta.total', 0);
        $this->api('GET', '/api/v1/deliveries?status=ready', token: $token)->assertOk()->assertJsonPath('meta.total', 2);
        $this->api('GET', '/api/v1/deliveries', token: $token)->assertOk()->assertJsonPath('meta.total', 3);
        $this->api('GET', '/api/v1/deliveries?q=HTD-'.str_pad((string) $parcel->id, 6, '0', STR_PAD_LEFT).'&status=all', token: $token)
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonCount(1, 'data.0.parcels');
    }

    /** A partial stop handover records exactly the selected parcel's full receipt and history. */
    public function test_partial_delivery_and_identical_replay_do_not_duplicate_money(): void
    {
        $rider = $this->account();
        $first = Parcel::factory()->for($rider)->create();
        $second = Parcel::factory()->for($rider)->create([
            'customer_id' => $first->customer_id, 'customer_address_id' => $first->customer_address_id,
        ]);
        $token = $this->token($rider);
        $values = $this->outcome([$first], 'delivered');
        $values['receipts'][0]['method'] = 'gcash';
        $values['receipts'][0]['reference'] = 'External receipt 123';
        $url = '/api/v1/deliveries/'.$first->id.'/outcomes';
        $this->api('POST', $url, $values, $token)->assertOk()->assertJsonPath('data.0.receipt.amount', 11500)
            ->assertJsonPath('data.0.receipt.method', 'gcash')->assertJsonCount(1, 'data.0.attempts')
            ->assertJsonMissingPath('data.0.attempts.0.request_hash')->assertJsonMissingPath('data.0.operation_id');
        $this->api('POST', $url, $values, $token)->assertOk()->assertJsonCount(1, 'data.0.attempts');
        $this->assertSame('pending', $second->fresh()->status);
        $this->assertNull($second->fresh()->received_at);
        $this->assertSame(11500, (int) $rider->parcels()->sum('received_amount'));
        $this->assertDatabaseCount('delivery_attempts', 1);
        $this->api('POST', $url, [...$values, 'note' => 'Different evidence'], $token)->assertUnprocessable()->assertJsonValidationErrors('operation_id');
        $this->api('POST', $url, [...$values, 'operation_id' => (string) Str::uuid()], $token)->assertUnprocessable();
        $this->assertDatabaseCount('delivery_attempts', 1);
        $this->assertSame($first->batch_id, $first->fresh()->batch_id);
    }

    /** Held/failed work must be explicitly resumed; a failed visit does not return the parcel. */
    public function test_hold_failure_retry_and_confirmed_return_history(): void
    {
        $rider = $this->account();
        $parcel = Parcel::factory()->for($rider)->create();
        $token = $this->token($rider);
        $url = '/api/v1/deliveries/'.$parcel->id.'/outcomes';
        $this->api('POST', $url, $this->outcome([$parcel], 'held'), $token)->assertOk()->assertJsonPath('data.0.status', 'held');
        $this->api('POST', $url, $this->outcome([$parcel], 'delivered'), $token)->assertUnprocessable();
        $this->api('POST', $url, $this->outcome([$parcel], 'pending'), $token)->assertOk()->assertJsonPath('data.0.retry_at', null);
        $this->api('POST', $url, $this->outcome([$parcel], 'failed'), $token)->assertOk()->assertJsonPath('data.0.status', 'failed');
        $this->api('POST', $url, $this->outcome([$parcel], 'delivered'), $token)->assertUnprocessable();
        $this->api('POST', $url, [...$this->outcome([$parcel], 'returned'), 'handover_confirmed' => false], $token)->assertUnprocessable();
        $this->api('POST', $url, $this->outcome([$parcel], 'returned'), $token)->assertOk()->assertJsonPath('data.0.status', 'returned')
            ->assertJsonPath('data.0.receipt', null)->assertJsonCount(4, 'data.0.attempts');
        $this->api('POST', $url, $this->outcome([$parcel], 'pending'), $token)->assertUnprocessable();
    }

    /** Due holds are eligible without rewriting the intake date or old attempt evidence. */
    public function test_due_hold_and_archived_customer_can_be_delivered_manually(): void
    {
        $rider = $this->account();
        $parcel = Parcel::factory()->for($rider)->create(['status' => 'held', 'retry_at' => now()->subMinute()]);
        $parcel->customer->delete();
        $parcel->address->delete();
        $parcel->seller->delete();
        $this->api('POST', '/api/v1/deliveries/'.$parcel->id.'/outcomes', $this->outcome([$parcel], 'delivered'), $this->token($rider))
            ->assertOk()->assertJsonPath('data.0.status', 'delivered');
        $this->assertNull($parcel->address()->first()->latitude);
    }

    /** List eligibility and handover agree at the exact scheduled retry instant. */
    public function test_hold_is_ready_at_the_exact_retry_boundary(): void
    {
        $this->freezeTime();
        $rider = $this->account();
        $parcel = Parcel::factory()->for($rider)->create(['status' => 'held', 'retry_at' => now()]);
        $token = $this->token($rider);
        $this->api('GET', '/api/v1/deliveries?status=ready', token: $token)->assertOk()->assertJsonPath('meta.total', 1);
        $this->api('POST', '/api/v1/deliveries/'.$parcel->id.'/outcomes', $this->outcome([$parcel], 'delivered'), $token)->assertOk();
    }

    /** Lost hold responses remain replayable after the originally scheduled time has passed. */
    public function test_saved_hold_can_replay_after_its_retry_time_has_elapsed(): void
    {
        $this->freezeTime();
        $rider = $this->account();
        $parcel = Parcel::factory()->for($rider)->create();
        $token = $this->token($rider);
        $values = $this->outcome([$parcel], 'held');
        $values['retry_at'] = now()->addMinutes(10)->toIso8601String();
        $url = '/api/v1/deliveries/'.$parcel->id.'/outcomes';
        $this->api('POST', $url, $values, $token)->assertOk();
        $this->travel(20)->minutes();
        $this->api('POST', $url, $values, $token)->assertOk()->assertJsonCount(1, 'data.0.attempts');
        $this->api('POST', $url, [...$values, 'operation_id' => (string) Str::uuid()], $token)
            ->assertUnprocessable()->assertJsonValidationErrors('retry_at');
        $this->assertDatabaseCount('delivery_attempts', 1);
    }

    /** An invalid member aborts the entire handover rather than partially collecting money. */
    public function test_atomic_ownership_and_stop_membership(): void
    {
        $rider = $this->account();
        $own = Parcel::factory()->for($rider)->create();
        $otherStop = Parcel::factory()->for($rider)->create();
        $foreign = Parcel::factory()->create();
        $token = $this->token($rider);
        $this->api('GET', '/api/v1/deliveries/'.$foreign->id, token: $token)->assertNotFound();
        $this->api('POST', '/api/v1/deliveries/'.$foreign->id.'/outcomes', $this->outcome([$foreign], 'failed'), $token)->assertNotFound();
        $this->api('POST', '/api/v1/deliveries/'.$own->id.'/outcomes', $this->outcome([$own, $foreign], 'delivered'), $token)->assertNotFound();
        $this->api('POST', '/api/v1/deliveries/'.$own->id.'/outcomes', $this->outcome([$own, $otherStop], 'delivered'), $token)->assertUnprocessable();
        $this->assertSame('pending', $own->fresh()->status);
        $this->assertDatabaseCount('delivery_attempts', 0);
    }

    /** Full receipt confirmation is mandatory and malformed receipts never produce money. */
    #[DataProvider('invalidOutcomes')]
    public function test_outcome_validation(array $changes, string $field): void
    {
        $rider = $this->account();
        $parcel = Parcel::factory()->for($rider)->create();
        $values = array_replace($this->outcome([$parcel], 'delivered'), $changes);
        if (isset($changes['amount'])) {
            $values['receipts'][0]['amount'] = $changes['amount'];
            unset($values['amount']);
        }
        $this->api('POST', '/api/v1/deliveries/'.$parcel->id.'/outcomes', $values, $this->token($rider))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertNull($parcel->fresh()->received_at);
        $this->assertDatabaseCount('delivery_attempts', 0);
    }

    /** Literal invalid inputs exercise the receipt boundary and action-specific allowlist. */
    public static function invalidOutcomes(): array
    {
        return [
            'not confirmed' => [['receipt_confirmed' => false], 'receipt_confirmed'],
            'partial payment' => [['amount' => 11499], 'receipts'],
            'too much' => [['amount' => 12000], 'receipts'],
            'no receipts' => [['receipts' => []], 'receipts'],
            'no selection' => [['parcel_ids' => []], 'parcel_ids'],
            'no reason' => [['note' => ' '], 'note'],
            'fake timestamp' => [['received_at' => '2026-01-01'], 'received_at'],
            'GPS blocked' => [['latitude' => 12.0], 'latitude'],
        ];
    }

    /** The existing Bearer role, ability and expiry boundaries also protect every new operation. */
    public function test_authentication_and_query_validation(): void
    {
        $rider = $this->account();
        $parcel = Parcel::factory()->for($rider)->create();
        $url = '/api/v1/deliveries/'.$parcel->id.'/outcomes';
        $this->api('GET', '/api/v1/deliveries')->assertUnauthorized();
        $this->api('POST', $url, $this->outcome([$parcel], 'failed'))->assertUnauthorized();
        $this->api('GET', '/api/v1/deliveries', token: $this->token($this->account(UserRole::Admin)))->assertForbidden();
        $limited = $rider->createToken('Limited', ['other'])->plainTextToken;
        $this->api('POST', $url, $this->outcome([$parcel], 'failed'), $limited)->assertForbidden();
        $expired = $rider->createToken('Expired', ['rider:access'], now()->subMinute())->plainTextToken;
        $this->api('GET', '/api/v1/deliveries', token: $expired)->assertUnauthorized();
        $token = $this->token($rider);
        foreach (['per_page=0', 'per_page=101', 'page=0', 'q[]=bad', 'status=unknown', 'method=prepaid', 'batch_id=-1'] as $query) {
            $this->api('GET', '/api/v1/deliveries?'.$query, token: $token)->assertUnprocessable();
        }
    }

    /** Builds an independently stated full receipt for only the parcels explicitly selected. */
    private function outcome(array $parcels, string $outcome): array
    {
        $values = [
            'operation_id' => (string) Str::uuid(), 'parcel_ids' => array_column($parcels, 'id'),
            'outcome' => $outcome, 'note' => 'Rider reviewed this handover',
        ];
        if ($outcome === 'held') {
            $values['retry_at'] = now()->addDay()->toIso8601String();
        }
        if ($outcome === 'returned') {
            $values['handover_confirmed'] = true;
        }
        if ($outcome === 'delivered') {
            $values['receipt_confirmed'] = true;
            /** Expected full amount comes from the hand-checked fixture, not production pricing code. */
            $values['receipts'] = array_map(fn (Parcel $parcel): array => ['parcel_id' => $parcel->id, 'method' => 'cash', 'amount' => 11500], $parcels);
        }

        return $values;
    }
}
