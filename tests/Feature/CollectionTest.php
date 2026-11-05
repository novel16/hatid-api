<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Parcel;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Auth\AuthTestCase;

class CollectionTest extends AuthTestCase
{
    /** Money routes resolve private records only after real rider Bearer/role/ability checks. */
    public function test_money_routes_require_rider_bearer_and_scope(): void
    {
        $admin = $this->token($this->account(UserRole::Admin));
        $wrong = $this->account()->createToken('Wrong scope', ['admin:access'])->plainTextToken;
        foreach (['/collections', '/collections/sellers', '/remittances', '/remittances/1'] as $path) {
            $this->api('GET', '/api/v1'.$path)->assertUnauthorized();
            $this->api('GET', '/api/v1'.$path, token: $admin)->assertForbidden();
            $this->api('GET', '/api/v1'.$path, token: $wrong)->assertForbidden();
        }
        $this->api('POST', '/api/v1/remittances')->assertUnauthorized();
    }

    /** Only full successful receipts accrue money; actual method and lifetime totals remain distinct. */
    public function test_receipts_and_summary_use_actual_confirmed_money(): void
    {
        $rider = $this->account();
        $token = $this->token($rider);
        $cash = $this->receipt($rider, changes: ['expected_method' => 'gcash']);
        $this->receipt($rider, total: 23000, method: 'gcash');
        Parcel::factory()->for($rider)->create();
        $this->receipt($rider, changes: ['status' => 'failed']);
        $this->receipt($rider, changes: ['received_amount' => 100]);
        $this->receipt($rider, changes: ['received_at' => null]);
        $this->receipt($this->account(), total: 99900);
        $response = $this->api('GET', '/api/v1/collections?method=cash&per_page=10', token: $token)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $cash->id)
            ->assertJsonPath('summary.cash', 11500)->assertJsonPath('summary.gcash', 23000)
            ->assertJsonPath('summary.fees', 3000)->assertJsonPath('summary.seller_share', 31500)
            ->assertJsonPath('summary.remitted', 0)->assertJsonPath('summary.outstanding', 31500)
            ->assertJsonPath('summary.receipts', 2)->assertJsonPath('data.0.settlement.outstanding', 10000)
            ->assertJsonMissingPath('data.0.user_id')->assertJsonMissingPath('data.0.operation_id');
        $this->assertFalse($response->headers->has('Set-Cookie'));
    }

    /** Partial handovers pay the oldest seller share, including archived sellers across intake weeks. */
    public function test_partial_and_full_handover_allocate_oldest_receipts_first(): void
    {
        $rider = $this->account();
        $token = $this->token($rider);
        $old = $this->receipt($rider, changes: ['received_at' => now()->subDays(9)]);
        $recent = $this->receipt($rider, $old->seller, 23000, method: 'gcash');
        $old->seller->delete();
        $first = $this->api('POST', '/api/v1/remittances', $this->handover($old->seller_id, 15000, 'gcash'), $token)
            ->assertCreated()->assertJsonPath('data.amount', 15000)->assertJsonPath('data.method', 'gcash')
            ->assertJsonPath('data.seller.id', $old->seller_id)->assertJsonMissingPath('data.user_id')
            ->assertJsonMissingPath('data.operation_id')->assertJsonMissingPath('data.request_hash');
        $this->assertDatabaseHas('remittance_allocations', ['remittance_id' => $first->json('data.id'), 'parcel_id' => $old->id, 'amount' => 10000]);
        $this->assertDatabaseHas('remittance_allocations', ['remittance_id' => $first->json('data.id'), 'parcel_id' => $recent->id, 'amount' => 5000]);
        $this->api('GET', '/api/v1/collections/sellers', token: $token)->assertOk()
            ->assertJsonPath('data.0.id', $old->seller_id)->assertJsonPath('data.0.balance.outstanding', 16500)
            ->assertJsonPath('data.0.balance.remitted', 15000)->assertJsonPath('data.0.balance.fees', 3000);
        $this->api('POST', '/api/v1/remittances', $this->handover($old->seller_id, 16500), $token)->assertCreated();
        $this->api('GET', '/api/v1/collections', token: $token)->assertOk()
            ->assertJsonPath('summary.outstanding', 0)->assertJsonPath('summary.remitted', 31500)
            ->assertJsonPath('summary.fees', 3000)->assertJsonPath('summary.cash', 11500)->assertJsonPath('summary.gcash', 23000);
        $this->assertSame(1500, $old->fresh()->fee);
        $this->assertSame(11500, $old->fresh()->received_amount);
    }

    /** Identical retries survive exhausted balance; changed operations and competing overpayments fail. */
    public function test_replays_and_serial_competing_handover_cannot_over_remit(): void
    {
        $rider = $this->account();
        $token = $this->token($rider);
        $parcel = $this->receipt($rider);
        $values = $this->handover($parcel->seller_id, 10000);
        $first = $this->api('POST', '/api/v1/remittances', $values, $token)->assertCreated();
        $this->api('POST', '/api/v1/remittances', $values, $token)->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));
        foreach (['amount' => 9999, 'method' => 'gcash', 'reference' => 'Changed'] as $field => $change) {
            $this->api('POST', '/api/v1/remittances', array_replace($values, [$field => $change]), $token)->assertConflict();
        }
        $this->api('POST', '/api/v1/remittances', $this->handover($parcel->seller_id, 1), $token)->assertUnprocessable();
        $this->assertDatabaseCount('remittances', 1);
        $this->assertDatabaseCount('remittance_allocations', 1);
    }

    /** Private parent/child records and grouped search cannot escape the authenticated rider. */
    public function test_search_and_money_records_remain_private(): void
    {
        $rider = $this->account();
        $token = $this->token($rider);
        $own = $this->receipt($rider);
        $foreign = $this->receipt($this->account());
        $foreign->seller->update(['name' => 'Foreign Shop Unique']);
        $saved = $this->api('POST', '/api/v1/remittances', $this->handover($foreign->seller_id, 10000), $this->token($foreign->user))->assertCreated();
        $this->api('POST', '/api/v1/remittances', $this->handover($foreign->seller_id, 1), $token)->assertNotFound();
        $this->api('GET', '/api/v1/remittances/'.$saved->json('data.id'), token: $token)->assertNotFound();
        $this->api('GET', '/api/v1/collections?q=Foreign%20Shop%20Unique', token: $token)->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', '/api/v1/collections/sellers?q=Foreign%20Shop%20Unique', token: $token)->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', '/api/v1/remittances?q=handover', token: $token)->assertOk()->assertJsonCount(0, 'data');
        $this->api('POST', '/api/v1/remittances', array_replace($this->handover($own->seller_id, 1), ['user_id' => $foreign->user_id]), $token)
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
    }

    /** Rejected money writes leave both headers and allocations untouched. */
    #[DataProvider('invalidHandovers')]
    public function test_handover_validation_is_atomic(array $changes, string $field): void
    {
        $rider = $this->account();
        $parcel = $this->receipt($rider);
        $this->api('POST', '/api/v1/remittances', array_replace($this->handover($parcel->seller_id, 6000), $changes), $this->token($rider))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('remittances', 0);
        $this->assertDatabaseCount('remittance_allocations', 0);
    }

    /** Literal boundaries check strict centavo inputs, confirmation and server-owned fields. */
    public static function invalidHandovers(): array
    {
        return [
            'zero' => [['amount' => 0], 'amount'], 'negative' => [['amount' => -1], 'amount'],
            'fraction' => [['amount' => 12.5], 'amount'], 'numeric string' => [['amount' => '6000'], 'amount'],
            'over balance' => [['amount' => 10001], 'amount'], 'unsafe' => [['amount' => 9007199254740992], 'amount'],
            'prepaid' => [['method' => 'prepaid'], 'method'], 'unconfirmed' => [['handover_confirmed' => false], 'handover_confirmed'],
            'operation' => [['operation_id' => 'bad'], 'operation_id'], 'allocations' => [['allocations' => [['amount' => 1]]], 'allocations'],
            'backdating' => [['created_at' => '2026-01-01'], 'created_at'], 'long reference' => [['reference' => str_repeat('x', 201)], 'reference'],
        ];
    }

    /** Full lists preserve server totals; allocations use ten-row pages and immutable history routes. */
    public function test_server_pagination_filters_and_compact_allocations(): void
    {
        $rider = $this->account();
        $token = $this->token($rider);
        $seller = Seller::factory()->for($rider)->create(['name' => 'Local Shop']);
        for ($index = 0; $index < 12; $index++) {
            $this->receipt($rider, $seller, changes: ['received_at' => now()->subMinutes(12 - $index)]);
        }
        $this->api('GET', '/api/v1/collections?page=2', token: $token)->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 12)->assertJsonPath('summary.cash', 138000);
        foreach ([10, 50, 100] as $size) {
            foreach (['/collections', '/collections/sellers', '/remittances'] as $path) {
                $this->api('GET', '/api/v1'.$path.'?per_page='.$size, token: $token)->assertOk()->assertJsonPath('meta.per_page', $size);
            }
        }
        $saved = $this->api('POST', '/api/v1/remittances', $this->handover($seller->id, 120000), $token)->assertCreated();
        $this->api('GET', '/api/v1/remittances/'.$saved->json('data.id').'?page=2', token: $token)->assertOk()
            ->assertJsonCount(2, 'data')->assertJsonPath('meta.total', 12)->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('remittance.amount', 120000)->assertJsonPath('data.0.allocated_amount', 10000);
        $this->api('GET', '/api/v1/remittances?method=gcash', token: $token)->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', '/api/v1/remittances?q=Local', token: $token)->assertOk()->assertJsonCount(1, 'data');
        $this->api('GET', '/api/v1/collections/sellers?balance=outstanding', token: $token)->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', '/api/v1/collections/sellers?balance=settled', token: $token)->assertOk()->assertJsonCount(1, 'data');
        $this->api('PATCH', '/api/v1/remittances/'.$saved->json('data.id'), ['amount' => 1], $token)->assertMethodNotAllowed();
        $this->api('DELETE', '/api/v1/remittances/'.$saved->json('data.id'), token: $token)->assertMethodNotAllowed();
    }

    /** Builds confirmed-receipt fixtures only in the isolated test database. */
    private function receipt(User $rider, ?Seller $seller = null, int $total = 11500, int $fee = 1500, string $method = 'cash', array $changes = []): Parcel
    {
        return Parcel::factory()->for($rider)->create(array_replace([
            'seller_id' => ($seller ?? Seller::factory()->for($rider)->create())->id,
            'status' => 'delivered', 'quote' => $total, 'customer_total' => $total, 'fee' => $fee, 'seller_share' => $total - $fee,
            'received_at' => now(), 'received_amount' => $total, 'receipt_method' => $method,
        ], $changes));
    }

    /** Stable operation input records an already completed external handover. */
    private function handover(int $sellerId, int $amount, string $method = 'cash'): array
    {
        return ['seller_id' => $sellerId, 'amount' => $amount, 'method' => $method, 'operation_id' => (string) Str::uuid(), 'reference' => 'handover', 'handover_confirmed' => true];
    }
}
