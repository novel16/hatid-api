<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Batch;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Parcel;
use App\Models\Seller;
use App\Models\User;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Auth\AuthTestCase;

class ParcelTest extends AuthTestCase
{
    /** Seller preference edits affect future parcels while existing included prices stay frozen. */
    public function test_creates_a_private_parcel_with_frozen_pricing(): void
    {
        $rider = $this->account();
        $values = $this->entry($rider);
        $token = $this->token($rider);
        $created = $this->api('POST', '/api/v1/parcels', $values, $token)->assertCreated()
            ->assertJsonPath('data.pricing.total', 11500)->assertJsonPath('data.pricing.sellerShare', 10000)
            ->assertJsonPath('data.pricing.fee', 1500)->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.receipt', null)->assertJsonMissingPath('data.user_id')
            ->assertJsonMissingPath('data.operation_id')->assertJsonMissingPath('data.submission_hash');
        $this->api('PATCH', '/api/v1/sellers/'.$values['seller_id'], ['fee' => 2500, 'mode' => 'excluded'], $token)
            ->assertOk()->assertJsonPath('data.fee', 2500)->assertJsonPath('data.mode', 'excluded');
        $this->api('GET', '/api/v1/parcels/'.$created->json('data.id'), token: $token)->assertOk()
            ->assertJsonPath('data.pricing.total', 11500)->assertJsonPath('data.pricing.mode', 'included')
            ->assertJsonPath('data.pricing.fee', 1500)->assertJsonPath('data.pricing.sellerShare', 10000);
        $next = array_replace($values, ['operation_id' => (string) Str::uuid(), 'fee' => 2500, 'mode' => 'excluded']);
        $this->api('POST', '/api/v1/parcels', $next, $token)->assertCreated()
            ->assertJsonPath('data.pricing.total', 14000)->assertJsonPath('data.pricing.sellerShare', 11500)
            ->assertJsonPath('data.pricing.fee', 2500)->assertJsonPath('data.pricing.mode', 'excluded');
    }

    /** The agreed excluded examples add the fee exactly once. */
    #[DataProvider('excludedQuotes')]
    public function test_excluded_quote_totals(int $quote, int $total): void
    {
        $rider = $this->account();
        $values = $this->entry($rider, ['mode' => 'excluded', 'quote' => $quote]);
        Seller::findOrFail($values['seller_id'])->update(['mode' => 'excluded']);
        $this->api('POST', '/api/v1/parcels', $values, $this->token($rider))->assertCreated()
            ->assertJsonPath('data.pricing.total', $total)->assertJsonPath('data.pricing.sellerShare', $quote);
    }

    /** Literal expectations catch double-added fees. */
    public static function excludedQuotes(): array
    {
        return [[10000, 11500], [11500, 13000]];
    }

    /** Optional human text may be omitted without crashing the normalized save. */
    public function test_optional_text_defaults(): void
    {
        $rider = $this->account();
        $values = $this->entry($rider);
        unset($values['description'], $values['note']);
        $this->api('POST', '/api/v1/parcels', $values, $this->token($rider))->assertCreated()
            ->assertJsonPath('data.description', 'Parcel')->assertJsonPath('data.note', null);
    }

    /** Retried saves return the original parcel, even after directory records are archived. */
    public function test_replayed_entry_is_safe_and_changed_payload_is_rejected(): void
    {
        $rider = $this->account();
        $values = $this->entry($rider);
        $token = $this->token($rider);
        $first = $this->api('POST', '/api/v1/parcels', $values, $token)->assertCreated()->json('data.id');
        Seller::findOrFail($values['seller_id'])->delete();
        $this->api('POST', '/api/v1/parcels', $values, $token)->assertCreated()->assertJsonPath('data.id', $first);
        $this->api('POST', '/api/v1/parcels', array_replace($values, ['quote' => 12000]), $token)
            ->assertUnprocessable()->assertJsonValidationErrors('operation_id');
        $this->assertDatabaseCount('parcels', 1);
    }

    /** Foreign IDs and addresses under a different customer never create a parcel. */
    public function test_directory_and_batch_ownership_and_address_parent(): void
    {
        $rider = $this->account();
        $own = $this->entry($rider);
        $foreign = $this->entry($this->account());
        $token = $this->token($rider);
        foreach (['batch_id', 'customer_id', 'address_id', 'seller_id'] as $field) {
            $this->api('POST', '/api/v1/parcels', array_replace($own, [$field => $foreign[$field]]), $token)->assertNotFound();
        }
        $other = Customer::factory()->for($rider)->create();
        $address = CustomerAddress::factory()->for($other)->create();
        $this->api('POST', '/api/v1/parcels', array_replace($own, ['address_id' => $address->id]), $token)->assertNotFound();
        $this->assertDatabaseCount('parcels', 0);
    }

    /** An archived parent or child cannot be used for new intake. */
    public function test_archived_directory_records_are_rejected_for_new_parcels(): void
    {
        $rider = $this->account();
        $values = $this->entry($rider);
        foreach ([Customer::find($values['customer_id']), CustomerAddress::find($values['address_id']), Seller::find($values['seller_id'])] as $record) {
            $record->delete();
            $this->api('POST', '/api/v1/parcels', $values, $this->token($rider))->assertNotFound();
            $record->restore();
        }
    }

    /** The rider must explain any departure from the seller's currently saved defaults. */
    public function test_price_override_requires_a_reason(): void
    {
        $rider = $this->account();
        $values = $this->entry($rider, ['fee' => 2000]);
        $token = $this->token($rider);
        $this->api('POST', '/api/v1/parcels', $values, $token)->assertUnprocessable()->assertJsonValidationErrors('override_reason');
        $this->api('POST', '/api/v1/parcels', [...$values, 'override_reason' => 'Seller agreed to the longer trip'], $token)
            ->assertCreated()->assertJsonPath('data.pricing.fee', 2000)->assertJsonPath('data.pricing.sellerShare', 9500);
    }

    /** Malformed money, fake receipts/ownership and unsupported payment methods cannot cross intake. */
    #[DataProvider('invalidEntries')]
    public function test_entry_validation(array $changes, string $field): void
    {
        $rider = $this->account();
        $this->api('POST', '/api/v1/parcels', $this->entry($rider, $changes), $this->token($rider))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertDatabaseCount('parcels', 0);
    }

    /** Bounds prevent overflow and floating-point money from becoming saved prices. */
    public static function invalidEntries(): array
    {
        return [
            'zero quote' => [['quote' => 0], 'quote'], 'decimal cents' => [['quote' => 11.5], 'quote'],
            'negative fee' => [['fee' => -1], 'fee'], 'fee exceeds quote' => [['fee' => 11501], 'fee'],
            'overflow' => [['quote' => PHP_INT_MAX], 'quote'], 'no operation' => [['operation_id' => ''], 'operation_id'],
            'fake owner' => [['user_id' => 999], 'user_id'], 'fake total' => [['customer_total' => 1], 'customer_total'],
            'fake receipt' => [['receipt' => ['amount' => 11500]], 'receipt'], 'fake outcome' => [['status' => 'delivered'], 'status'],
            'prepaid' => [['expected_method' => 'prepaid'], 'expected_method'], 'array text' => [['note' => []], 'note'],
        ];
    }

    /** Reading is private and intake exposes no edit/delete path for frozen prices. */
    public function test_foreign_reads_and_unsupported_mutations(): void
    {
        $rider = $this->account();
        $token = $this->token($rider);
        $id = $this->api('POST', '/api/v1/parcels', $this->entry($rider), $token)->assertCreated()->json('data.id');
        $this->api('GET', '/api/v1/parcels/'.$id, token: $this->token($this->account()))->assertNotFound();
        foreach (['PATCH', 'DELETE'] as $method) {
            $this->api($method, '/api/v1/parcels/'.$id, token: $token)->assertStatus(405);
        }
    }

    /** Shared fixtures keep ownership explicit while supporting intentionally unknown addresses. */
    protected function entry(User $rider, array $changes = []): array
    {
        $customer = Customer::factory()->for($rider)->create();
        $address = CustomerAddress::factory()->for($customer)->create(['text' => null]);
        $seller = Seller::factory()->for($rider)->create(['mode' => 'included', 'fee' => 1500]);
        $batch = $rider->batches()->first() ?? Batch::factory()->for($rider)->create();

        return array_replace([
            'operation_id' => (string) Str::uuid(), 'batch_id' => $batch->id,
            'customer_id' => $customer->id, 'address_id' => $address->id, 'seller_id' => $seller->id,
            'description' => 'Skin care', 'quote' => 11500, 'fee' => 1500, 'mode' => 'included',
            'expected_method' => 'cash', 'note' => '',
        ], $changes);
    }
}
