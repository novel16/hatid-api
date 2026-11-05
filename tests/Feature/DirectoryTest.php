<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\CustomerAddress;
use App\Models\Seller;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Auth\AuthTestCase;

class DirectoryTest extends AuthTestCase
{
    /** Supplies both private directory endpoints without duplicating their security tests. */
    public static function directories(): array
    {
        return ['customers' => ['/api/v1/customers'], 'sellers' => ['/api/v1/sellers']];
    }

    /** Guests cannot read or create directory records. */
    #[DataProvider('directories')]
    public function test_directory_requires_a_bearer_token(string $path): void
    {
        $this->api('GET', $path)->assertUnauthorized();
        $this->api('POST', $path, $this->values($path))->assertUnauthorized();
    }

    /** Database role and token ability must both grant rider access. */
    #[DataProvider('directories')]
    public function test_directory_rejects_admins_and_wrong_scope_tokens(string $path): void
    {
        $admin = $this->account(UserRole::Admin);
        $adminToken = $admin->createToken('Wrong role', ['rider:access'])->plainTextToken;
        $this->api('GET', $path, token: $adminToken)->assertForbidden();

        $rider = $this->account();
        $wrongScope = $rider->createToken('Wrong scope', ['admin:access'])->plainTextToken;
        $this->api('GET', $path, token: $wrongScope)->assertForbidden();
    }

    /** Responses expose controlled fields and never owner/authentication data. */
    #[DataProvider('directories')]
    public function test_create_and_show_use_controlled_resources(string $path): void
    {
        $token = $this->token($this->account());
        $response = $this->api('POST', $path, $this->values($path, ['name' => '  Maria Store  ']), $token)
            ->assertCreated()->assertJsonPath('data.name', 'Maria Store')
            ->assertJsonPath('data.archived_at', null)->assertHeaderMissing('Set-Cookie');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $record = $response->json('data');
        $keys = $this->isCustomer($path)
            ? ['id', 'name', 'phone', 'addresses', 'archived_at']
            : ['id', 'name', 'contact', 'mode', 'fee', 'archived_at'];
        $this->assertEqualsCanonicalizing($keys, array_keys($record));
        $this->api('GET', $path.'/'.$record['id'], token: $token)->assertOk()->assertJsonPath('data', $record);

        if ($this->isCustomer($path)) {
            $address = $record['addresses'][0];
            $this->assertEqualsCanonicalizing(
                ['id', 'label', 'text', 'latitude', 'longitude', 'verified_at', 'gps_accuracy', 'gps_captured_at', 'location_corrected', 'location_version', 'archived_at'],
                array_keys($address),
            );
            $this->assertSame('Home', $address['label']);
            $this->assertNull($address['latitude']);
            $this->assertNull($address['longitude']);
            $this->assertNull($address['gps_accuracy']);
            $this->assertNull($address['gps_captured_at']);
            $this->assertFalse($address['location_corrected']);
            $this->assertSame(0, $address['location_version']);
        } else {
            $this->assertSame(1500, $record['fee']);
        }
    }

    /** Search OR clauses and pagination cannot escape the authenticated rider's scope. */
    #[DataProvider('directories')]
    public function test_search_and_pagination_are_private(string $path): void
    {
        $token = $this->token($this->account());
        $otherToken = $this->token($this->account());
        $first = $this->record($path, $token, ['name' => 'First customer']);
        $this->record($path, $token, ['name' => 'Second customer']);
        $this->record($path, $otherToken, ['name' => 'Secret customer']);

        $this->api('GET', $path.'?per_page=1', token: $token)->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)->assertJsonPath('data.0.id', $first['id']);
        $this->api('GET', $path.'?q=Secret', token: $token)->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0);
        $this->api('GET', $path.'?q=Second', token: $token)->assertOk()->assertJsonCount(1, 'data');
    }

    /** UI page sizes return real scoped totals, bounded slices and preserved search links. */
    #[DataProvider('directories')]
    public function test_directory_supports_ten_fifty_and_one_hundred_entries(string $path): void
    {
        $rider = $this->account();
        $token = $this->token($rider);
        $model = $this->isCustomer($path) ? Customer::class : Seller::class;
        $model::factory()->for($rider)->count(105)->create(['name' => 'Matching record']);
        $model::factory()->for($rider)->create(['name' => 'Unrelated record']);
        $model::factory()->for($rider)->create(['name' => 'Matching archived', 'deleted_at' => now()]);
        $model::factory()->count(2)->create(['name' => 'Matching foreign']);

        $this->api('GET', $path.'?q=Matching', token: $token)->assertOk()
            ->assertJsonCount(10, 'data')->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 105)->assertJsonPath('meta.last_page', 11);

        foreach ([10 => 10, 50 => 50, 100 => 5] as $perPage => $count) {
            $response = $this->api('GET', $path.'?q=Matching&per_page='.$perPage.'&page=2', token: $token)
                ->assertOk()->assertJsonCount($count, 'data')
                ->assertJsonPath('meta.current_page', 2)->assertJsonPath('meta.per_page', $perPage)
                ->assertJsonPath('meta.total', 105)->assertJsonPath('meta.from', $perPage + 1)
                ->assertJsonPath('meta.to', min($perPage * 2, 105));
            parse_str(parse_url($response->json('links.prev'), PHP_URL_QUERY), $query);
            $this->assertSame('Matching', $query['q']);
            $this->assertSame((string) $perPage, $query['per_page']);
            $this->assertSame('1', $query['page']);
        }

        $this->api('GET', $path.'?q=NoSuchRecord&per_page=100', token: $token)->assertOk()
            ->assertJsonCount(0, 'data')->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.from', null)->assertJsonPath('meta.to', null);
    }

    /** Foreign IDs cannot be read, changed, archived or restored. */
    #[DataProvider('directories')]
    public function test_foreign_records_return_not_found(string $path): void
    {
        $ownerToken = $this->token($this->account());
        $otherToken = $this->token($this->account());
        $record = $this->record($path, $ownerToken);
        $url = $path.'/'.$record['id'];

        $this->api('GET', $url, token: $otherToken)->assertNotFound();
        $this->api('PATCH', $url, ['name' => 'Changed'], $otherToken)->assertNotFound();
        $this->api('DELETE', $url, token: $otherToken)->assertNotFound();
        $this->api('POST', $url.'/restore', token: $otherToken)->assertNotFound();
        $this->api('GET', $url, token: $ownerToken)->assertOk()->assertJsonPath('data.name', $record['name']);
    }

    /** Partial edits preserve other fields; archive/restore is reversible and repeatable. */
    #[DataProvider('directories')]
    public function test_updates_and_archive_restore_preserve_records(string $path): void
    {
        $token = $this->token($this->account());
        $record = $this->record($path, $token);
        $url = $path.'/'.$record['id'];

        $this->api('PATCH', $url, ['name' => 'Updated name'], $token)->assertOk()
            ->assertJsonPath('data.name', 'Updated name');
        $this->api('DELETE', $url, token: $token)->assertNoContent();
        $this->api('DELETE', $url, token: $token)->assertNoContent();
        $this->api('GET', $path, token: $token)->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', $path.'?archived=1', token: $token)->assertOk()->assertJsonPath('data.0.id', $record['id']);
        $this->api('GET', $url, token: $token)->assertNotFound();
        $this->api('POST', $url.'/restore', token: $token)->assertOk()->assertJsonPath('data.archived_at', null);
        $this->api('POST', $url.'/restore', token: $token)->assertOk();
        $restored = $this->api('GET', $url, token: $token)->assertOk()->json('data');
        $field = $this->isCustomer($path) ? 'addresses' : 'fee';
        $this->assertSame($record[$field], $restored[$field]);
    }

    /** Client-supplied owner IDs and invalid list parameters fail validation. */
    #[DataProvider('directories')]
    public function test_directory_input_is_allowlisted_and_bounded(string $path): void
    {
        $token = $this->token($this->account());
        $this->api('POST', $path, $this->values($path, ['user_id' => 999]), $token)
            ->assertUnprocessable()->assertJsonValidationErrors('user_id');
        $this->api('POST', $path, $this->values($path, ['name' => '   ']), $token)
            ->assertUnprocessable()->assertJsonValidationErrors('name');
        $this->api('GET', $path.'?q[]=bad&page=0&per_page=101', token: $token)
            ->assertUnprocessable()->assertJsonValidationErrors(['q', 'page', 'per_page']);
    }

    /** Customer creation without address text still supports an unknown/manual location. */
    public function test_customer_can_have_an_unknown_address_and_nullable_phone(): void
    {
        $token = $this->token($this->account());
        $first = $this->record('/api/v1/customers', $token);
        $this->record('/api/v1/customers', $token);
        $this->assertNull($first['phone']);
        $this->assertNull($first['addresses'][0]['text']);
        $this->assertNull($first['addresses'][0]['verified_at']);
    }

    /** A supplied initial address must have a label instead of failing during its database insert. */
    public function test_supplied_initial_address_requires_a_label(): void
    {
        $token = $this->token($this->account());
        foreach (['address' => [], 'address.label' => ['text' => 'Rizal Street']] as $field => $address) {
            $this->api('POST', '/api/v1/customers', [
                'name' => 'Maria Santos', 'address' => $address,
            ], $token)->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->assertDatabaseCount('customers', 0);
            $this->assertDatabaseCount('customer_addresses', 0);
        }
    }

    /** The same phone may belong to different riders, but not duplicate one rider's directory. */
    public function test_phone_uniqueness_includes_archived_records_and_is_scoped_to_rider(): void
    {
        $path = '/api/v1/customers';
        $token = $this->token($this->account());
        $otherToken = $this->token($this->account());
        $record = $this->record($path, $token, ['phone' => ' (0917) 123-4567 ']);
        $this->assertSame('09171234567', $record['phone']);
        $this->record($path, $otherToken, ['phone' => '09171234567']);
        $this->api('POST', $path, $this->values($path, ['phone' => '09171234567']), $token)
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->api('PATCH', $path.'/'.$record['id'], ['phone' => '09171234567'], $token)->assertOk();
        $this->api('DELETE', $path.'/'.$record['id'], token: $token)->assertNoContent();
        $this->api('POST', $path, $this->values($path, ['phone' => '09171234567']), $token)
            ->assertUnprocessable()->assertJsonValidationErrors('phone');
    }

    /** Address mutations remain attached to their parent and preserve independent address IDs. */
    public function test_customer_addresses_can_be_added_edited_archived_and_restored(): void
    {
        $token = $this->token($this->account());
        $customer = $this->record('/api/v1/customers', $token);
        $url = '/api/v1/customers/'.$customer['id'];
        $response = $this->api('POST', $url.'/addresses', ['label' => 'Work', 'text' => 'Rizal Street'], $token)
            ->assertCreated()->assertJsonCount(2, 'data.addresses');
        $address = $response->json('data.addresses.1');
        $addressUrl = $url.'/addresses/'.$address['id'];
        $this->api('PATCH', $addressUrl, ['label' => 'Office', 'text' => null], $token)->assertOk()
            ->assertJsonPath('data.addresses.1.label', 'Office')->assertJsonPath('data.addresses.1.text', null);
        $this->api('DELETE', $addressUrl, token: $token)->assertNoContent();
        $archived = $this->api('GET', $url, token: $token)->assertOk()->json('data.addresses.1.archived_at');
        $this->assertNotNull($archived);
        $this->api('DELETE', $url, token: $token)->assertNoContent();
        $this->api('POST', $url.'/restore', token: $token)->assertOk()
            ->assertJsonPath('data.addresses.1.archived_at', $archived);
        $this->api('POST', $addressUrl.'/restore', token: $token)->assertOk()
            ->assertJsonPath('data.addresses.1.archived_at', null)
            ->assertJsonPath('data.addresses.0.id', $customer['addresses'][0]['id']);
    }

    /** A valid address ID is still inaccessible through another customer or rider. */
    public function test_addresses_cannot_be_reassigned_or_accessed_through_another_parent(): void
    {
        $token = $this->token($this->account());
        $otherToken = $this->token($this->account());
        $first = $this->record('/api/v1/customers', $token);
        $second = $this->record('/api/v1/customers', $token);
        $url = '/api/v1/customers/'.$second['id'].'/addresses/'.$first['addresses'][0]['id'];
        $this->api('PATCH', $url, ['label' => 'Stolen'], $token)->assertNotFound();
        $this->api('DELETE', $url, token: $token)->assertNotFound();
        $this->api('POST', $url.'/restore', token: $token)->assertNotFound();
        $ownAddress = '/api/v1/customers/'.$first['id'].'/addresses/'.$first['addresses'][0]['id'];
        $this->api('PATCH', $ownAddress, ['label' => 'Stolen'], $otherToken)->assertNotFound();
        $this->api('POST', '/api/v1/customers/'.$first['id'].'/addresses', [
            'label' => 'Work', 'customer_id' => $second['id'],
        ], $token)->assertUnprocessable()->assertJsonValidationErrors('customer_id');
    }

    /** Unverified client coordinates cannot bypass the future successful-delivery requirement. */
    public function test_directory_cannot_write_gps_coordinates(): void
    {
        $token = $this->token($this->account());
        $this->api('POST', '/api/v1/customers', $this->values('/api/v1/customers', [
            'address' => ['label' => 'Home', 'latitude' => 7.4, 'longitude' => 125.8],
        ]), $token)->assertUnprocessable();
        $customer = $this->record('/api/v1/customers', $token);
        $url = '/api/v1/customers/'.$customer['id'].'/addresses/'.$customer['addresses'][0]['id'];
        $this->api('PATCH', $url, ['latitude' => 7.4, 'longitude' => 125.8, 'verified_at' => now()->toISOString()], $token)
            ->assertUnprocessable()->assertJsonValidationErrors(['latitude', 'longitude', 'verified_at']);
        $this->api('GET', '/api/v1/customers/'.$customer['id'], token: $token)->assertOk()
            ->assertJsonPath('data.addresses.0.latitude', null);
    }

    /** Nested address text participates in search without exposing another rider's customers. */
    public function test_customer_search_can_match_address_and_phone(): void
    {
        $token = $this->token($this->account());
        $otherToken = $this->token($this->account());
        $this->record('/api/v1/customers', $token, [
            'phone' => '09171234567', 'address' => ['label' => 'Home', 'text' => 'Mabini Street'],
        ]);
        $this->api('GET', '/api/v1/customers?q=Mabini', token: $token)->assertOk()->assertJsonCount(1, 'data');
        $this->api('GET', '/api/v1/customers?q=1234567', token: $token)->assertOk()->assertJsonCount(1, 'data');
        $this->api('GET', '/api/v1/customers?q=123-4567', token: $token)->assertOk()->assertJsonCount(1, 'data');
        $this->record('/api/v1/customers', $otherToken, [
            'phone' => '09179876543', 'address' => ['label' => 'Work', 'text' => 'Private Road'],
        ]);
        $this->api('GET', '/api/v1/customers?q=Private', token: $token)->assertOk()->assertJsonCount(0, 'data');
        $this->api('GET', '/api/v1/customers?q=9876543', token: $token)->assertOk()->assertJsonCount(0, 'data');
    }

    /** Seller defaults are explicit, integer centavos and safely bounded. */
    public function test_seller_fee_validation_and_partial_updates(): void
    {
        $path = '/api/v1/sellers';
        $token = $this->token($this->account());
        foreach ([-1, 1.5, true, 9007199254740992] as $fee) {
            $this->api('POST', $path, $this->values($path, ['fee' => $fee]), $token)
                ->assertUnprocessable()->assertJsonValidationErrors('fee');
        }
        $this->api('POST', $path, $this->values($path, ['mode' => 'automatic']), $token)
            ->assertUnprocessable()->assertJsonValidationErrors('mode');
        $missing = $this->values($path);
        unset($missing['mode']);
        $this->api('POST', $path, $missing, $token)->assertUnprocessable()->assertJsonValidationErrors('mode');
        $seller = $this->record($path, $token);
        $this->api('PATCH', $path.'/'.$seller['id'], ['mode' => 'excluded', 'fee' => 2000], $token)->assertOk()
            ->assertJsonPath('data.mode', 'excluded')->assertJsonPath('data.fee', 2000)
            ->assertJsonPath('data.name', $seller['name']);
        $this->api('PATCH', $path.'/'.$seller['id'], ['fee' => 0], $token)->assertOk()->assertJsonPath('data.fee', 0);
    }

    /** Default pagination remains bounded while factory-created records keep their owner/parent links. */
    public function test_default_pagination_preserves_owner_and_address_boundaries(): void
    {
        $rider = $this->account();
        $token = $this->token($rider);
        Seller::factory()->for($rider)->count(26)->create();
        Seller::factory()->count(2)->create();
        $customer = Customer::factory()->for($rider)->has(CustomerAddress::factory()->count(2), 'addresses')->create();
        $this->api('GET', '/api/v1/sellers', token: $token)->assertOk()
            ->assertJsonCount(10, 'data')->assertJsonPath('meta.total', 26)->assertJsonPath('meta.last_page', 3);
        $this->api('GET', '/api/v1/sellers?page=3', token: $token)->assertOk()->assertJsonCount(6, 'data');
        $this->api('GET', '/api/v1/customers/'.$customer->id, token: $token)->assertOk()
            ->assertJsonCount(2, 'data.addresses');
    }

    /** Builds a small valid payload shared by the customer/seller HTTP contracts. */
    private function values(string $path, array $overrides = []): array
    {
        $values = $this->isCustomer($path)
            ? ['name' => 'Maria Santos', 'phone' => null]
            : ['name' => 'Nena Shop', 'contact' => 'Nena', 'mode' => 'included', 'fee' => 1500];

        return [...$values, ...$overrides];
    }

    /** Creates fixtures through the public feature contract rather than bypassing its invariants. */
    private function record(string $path, string $token, array $overrides = []): array
    {
        return $this->api('POST', $path, $this->values($path, $overrides), $token)->assertCreated()->json('data');
    }

    /** Distinguishes the two literal endpoints for their feature-specific payloads. */
    private function isCustomer(string $path): bool
    {
        return $path === '/api/v1/customers';
    }
}
