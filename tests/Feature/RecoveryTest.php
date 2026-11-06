<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Parcel;
use App\Models\Remittance;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Auth\AuthTestCase;

class RecoveryTest extends AuthTestCase
{
    /** Checking an unsaved operation must never create a parcel or claim a receipt. */
    public function test_unsaved_parcel_recovery_check_is_read_only(): void
    {
        $rider = $this->account();
        $parcel = Parcel::factory()->for($rider)->create();
        $values = [
            'operation_id' => (string) Str::uuid(), 'batch_id' => $parcel->batch_id,
            'customer_id' => $parcel->customer_id, 'address_id' => $parcel->customer_address_id,
            'seller_id' => $parcel->seller_id, 'quote' => 11500, 'fee' => 1500,
            'mode' => 'included', 'expected_method' => 'cash',
        ];
        $this->api('POST', '/api/v1/parcels/recovery', $values, $this->token($rider))
            ->assertOk()->assertExactJson(['data' => ['state' => 'not_recorded', 'target' => null, 'confirmed_at' => null]]);
        $this->assertDatabaseCount('parcels', 1);
        $this->assertDatabaseCount('delivery_attempts', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    /** Saved UUIDs are confirmed only for the owner and matching normalized inputs, even after archives. */
    public function test_parcel_probe_matches_original_inputs_without_new_writes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-06T01:00:00Z'));
        $rider = $this->account();
        $fixture = Parcel::factory()->for($rider)->create();
        $values = $this->entry($fixture);
        $token = $this->token($rider);
        $saved = $this->api('POST', '/api/v1/parcels', $values, $token)->assertCreated()->json('data.id');
        $fixture->customer->delete();
        $fixture->seller->delete();
        $same = [...$values, 'quote' => '11500', 'fee' => '1500', 'description' => '', 'note' => null];
        $this->api('POST', '/api/v1/parcels/recovery', $same, $token)->assertOk()
            ->assertExactJson(['data' => ['state' => 'confirmed', 'target' => ['kind' => 'parcel', 'id' => $saved], 'confirmed_at' => '2026-11-06T01:00:00.000000Z']]);
        $this->api('POST', '/api/v1/parcels/recovery', [...$values, 'fee' => 1600], $token)->assertConflict();
        $this->api('POST', '/api/v1/parcels/recovery', $values, $this->token($this->account()))->assertOk()
            ->assertJsonPath('data.state', 'not_recorded')->assertJsonPath('data.target', null);
        $this->assertDatabaseCount('parcels', 2);
        $this->assertDatabaseCount('delivery_attempts', 0);
        $this->assertDatabaseCount('notifications', 0);
    }

    /** Recovery recognizes earlier holds after retry time and later resume, without returning current status as proof. */
    public function test_delivery_probe_confirms_original_evidence_after_later_outcomes(): void
    {
        $this->freezeTime();
        $rider = $this->account();
        $parcel = Parcel::factory()->for($rider)->create();
        $token = $this->token($rider);
        $held = ['operation_id' => (string) Str::uuid(), 'parcel_ids' => [$parcel->id], 'outcome' => 'held', 'note' => 'Customer away', 'retry_at' => now()->addDay()->toIso8601String()];
        $this->api('POST', '/api/v1/deliveries/'.$parcel->id.'/outcomes', $held, $token)->assertOk();
        $at = $parcel->attempts()->first()->created_at->toISOString();
        $this->travel(2)->days();
        $token = $this->token($rider);
        $this->api('POST', '/api/v1/deliveries/'.$parcel->id.'/outcomes', ['operation_id' => (string) Str::uuid(), 'parcel_ids' => [$parcel->id], 'outcome' => 'pending', 'note' => 'Explicitly resumed'], $token)->assertOk();
        $url = '/api/v1/deliveries/'.$parcel->id.'/recovery';
        $this->api('POST', $url, $held, $token)->assertOk()->assertJsonPath('data.state', 'confirmed')
            ->assertJsonPath('data.target.kind', 'delivery')->assertJsonPath('data.target.id', $parcel->id)->assertJsonPath('data.confirmed_at', $at);
        $this->api('POST', $url, [...$held, 'note' => 'Changed evidence'], $token)->assertConflict();
        $this->assertSame('pending', $parcel->fresh()->status);
        $this->assertNull($parcel->fresh()->received_at);
        $this->assertDatabaseCount('delivery_attempts', 2);
        $this->assertDatabaseCount('notifications', 2);
    }

    /** Selection/receipt order uses the same canonical representation as the original atomic handover. */
    public function test_delivery_probe_normalizes_selection_and_receipt_order(): void
    {
        $rider = $this->account();
        $first = Parcel::factory()->for($rider)->create();
        $second = Parcel::factory()->for($rider)->create(['customer_id' => $first->customer_id, 'customer_address_id' => $first->customer_address_id]);
        $values = $this->outcome([$first, $second]);
        $token = $this->token($rider);
        $this->api('POST', '/api/v1/deliveries/'.$first->id.'/outcomes', $values, $token)->assertOk();
        $same = [...$values, 'parcel_ids' => array_reverse($values['parcel_ids']), 'receipts' => array_reverse($values['receipts'])];
        $url = '/api/v1/deliveries/'.$first->id.'/recovery';
        $this->api('POST', $url, $same, $token)->assertOk()->assertJsonPath('data.state', 'confirmed');
        $changed = $values;
        $changed['receipts'][0]['method'] = 'gcash';
        $this->api('POST', $url, $changed, $token)->assertConflict();
        $foreign = Parcel::factory()->create();
        $this->api('POST', '/api/v1/deliveries/'.$foreign->id.'/recovery', $values, $token)->assertNotFound();
        $this->assertDatabaseCount('delivery_attempts', 2);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame(11500, $first->fresh()->received_amount);
        $this->assertSame(11500, $second->fresh()->received_amount);
    }

    /** A settled balance cannot hide an earlier matching handover or create another allocation. */
    public function test_remittance_probe_preserves_completed_partial_handover_evidence(): void
    {
        $rider = $this->account();
        $parcel = Parcel::factory()->for($rider)->create(['status' => 'delivered', 'received_at' => now(), 'received_amount' => 11500, 'receipt_method' => 'cash']);
        $values = ['operation_id' => (string) Str::uuid(), 'seller_id' => $parcel->seller_id, 'amount' => 6000, 'method' => 'cash', 'reference' => ' EXT-01 ', 'handover_confirmed' => true];
        $token = $this->token($rider);
        $saved = $this->api('POST', '/api/v1/remittances', $values, $token)->assertCreated()->json('data.id');
        $at = Remittance::findOrFail($saved)->created_at->toISOString();
        $this->api('POST', '/api/v1/remittances', [...$values, 'operation_id' => (string) Str::uuid(), 'amount' => 4000], $token)->assertCreated();
        $this->api('POST', '/api/v1/remittances/recovery', [...$values, 'reference' => 'EXT-01'], $token)->assertOk()
            ->assertExactJson(['data' => ['state' => 'confirmed', 'target' => ['kind' => 'remittance', 'id' => $saved], 'confirmed_at' => $at]]);
        $this->api('POST', '/api/v1/remittances/recovery', [...$values, 'amount' => 6100], $token)->assertConflict();
        $this->api('POST', '/api/v1/remittances/recovery', $values, $this->token($this->account()))->assertOk()->assertJsonPath('data.state', 'not_recorded');
        $this->assertDatabaseCount('remittances', 2);
        $this->assertDatabaseCount('remittance_allocations', 2);
        $this->assertDatabaseCount('notifications', 2);
        $this->assertSame(1500, $parcel->fresh()->fee);
    }

    /** All recovery checks require real rider Bearer/ability and preserve the existing expiry boundary. */
    public function test_recovery_routes_require_current_rider_permission(): void
    {
        $rider = $this->account();
        $parcel = Parcel::factory()->for($rider)->create();
        $admin = $this->token($this->account(UserRole::Admin));
        $limited = $rider->createToken('Wrong ability', ['admin:access'])->plainTextToken;
        $expired = $rider->createToken('Expired', ['rider:access'], now()->subMinute())->plainTextToken;
        foreach (['/parcels/recovery', '/deliveries/'.$parcel->id.'/recovery', '/remittances/recovery'] as $path) {
            $this->api('POST', '/api/v1'.$path)->assertUnauthorized();
            $this->api('POST', '/api/v1'.$path, token: $admin)->assertForbidden();
            $this->api('POST', '/api/v1'.$path, token: $limited)->assertForbidden();
            $this->api('POST', '/api/v1'.$path, token: $expired)->assertUnauthorized();
        }
    }

    /** Existing field validation is shared with probes, including strict money and owner overrides. */
    #[DataProvider('invalidInputs')]
    public function test_recovery_probe_validates_original_payload(string $kind, array $change, string $field): void
    {
        $rider = $this->account();
        $parcel = Parcel::factory()->for($rider)->create();
        $path = match ($kind) {
            'parcel' => '/parcels/recovery', 'delivery' => '/deliveries/'.$parcel->id.'/recovery', 'remittance' => '/remittances/recovery'
        };
        $values = match ($kind) {
            'parcel' => $this->entry($parcel), 'delivery' => $this->outcome([$parcel]),
            'remittance' => ['operation_id' => (string) Str::uuid(), 'seller_id' => $parcel->seller_id, 'amount' => 1000, 'method' => 'cash', 'handover_confirmed' => true],
        };
        $this->api('POST', '/api/v1'.$path, [...$values, ...$change], $this->token($rider))->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('delivery_attempts', 0);
        $this->assertDatabaseCount('remittances', 0);
    }

    /** Catch unbounded/tampered operations before any recovery lookup or mutation can run. */
    public static function invalidInputs(): array
    {
        return [['parcel', ['operation_id' => 'bad'], 'operation_id'], ['parcel', ['user_id' => 12], 'user_id'], ['parcel', ['fee' => -1], 'fee'], ['delivery', ['parcel_ids' => []], 'parcel_ids'], ['delivery', ['receipt_confirmed' => false], 'receipt_confirmed'], ['remittance', ['amount' => 10.5], 'amount'], ['remittance', ['handover_confirmed' => false], 'handover_confirmed']];
    }

    /** Independent intake fixture values exercise canonical normalization without copying its hasher. */
    private function entry(Parcel $parcel): array
    {
        return ['operation_id' => (string) Str::uuid(), 'batch_id' => $parcel->batch_id, 'customer_id' => $parcel->customer_id, 'address_id' => $parcel->customer_address_id, 'seller_id' => $parcel->seller_id, 'quote' => 11500, 'fee' => 1500, 'mode' => 'included', 'expected_method' => 'cash'];
    }

    /** Full hand-checked receipts use PHP115 per fixture parcel and explicit rider confirmation. */
    private function outcome(array $parcels): array
    {
        return ['operation_id' => (string) Str::uuid(), 'parcel_ids' => array_column($parcels, 'id'), 'outcome' => 'delivered', 'note' => 'Confirmed handover', 'receipt_confirmed' => true,
            'receipts' => array_map(/** Preserve each selected parcel's own receipt identity. */ fn (Parcel $parcel): array => ['parcel_id' => $parcel->id, 'method' => 'cash', 'amount' => 11500], $parcels)];
    }
}
