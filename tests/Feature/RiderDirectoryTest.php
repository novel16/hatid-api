<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;

class RiderDirectoryTest extends AuthTestCase
{
    private const PATH = '/api/v1/admin/riders';

    /** Protect profile data with a real, unexpired token, admin role and admin ability. */
    public function test_directory_requires_admin_role_and_ability(): void
    {
        $admin = $this->account(UserRole::Admin);
        $rider = $this->account();

        $this->api('GET', self::PATH)->assertUnauthorized();
        $this->listRiders($this->token($rider))->assertForbidden();
        $this->listRiders($rider->createToken('Wrong role', ['admin:access'], now()->addHour())->plainTextToken)
            ->assertForbidden();
        $this->listRiders($admin->createToken('Wrong ability', ['rider:access'], now()->addHour())->plainTextToken)
            ->assertForbidden();
        $this->listRiders($admin->createToken('Expired admin', ['admin:access'], now()->subMinute())->plainTextToken)
            ->assertUnauthorized();
    }

    /** List only riders and allowlist the same fields used by the table and profile dialog. */
    public function test_directory_exposes_only_approved_rider_profile_fields(): void
    {
        $admin = $this->account(UserRole::Admin);
        $rider = User::factory()->create(['vehicle' => null, 'phone' => null]);

        $response = $this->listRiders($this->token($admin))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $rider->id)
            ->assertJsonPath('data.0.name', $rider->name)
            ->assertJsonPath('data.0.email', $rider->email)
            ->assertJsonPath('data.0.phone', null)
            ->assertJsonPath('data.0.vehicle', null)
            ->assertJsonPath('data.0.created_at', $rider->created_at->toISOString())
            ->assertJsonPath('meta.per_page', 10)
            ->assertJsonPath('meta.total', 1)
            ->assertHeaderMissing('Set-Cookie');

        $this->assertEqualsCanonicalizing(
            ['id', 'name', 'email', 'phone', 'vehicle', 'created_at', 'status', 'status_revision', 'suspended_at'],
            array_keys($response->json('data.0')),
        );
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    /** Group name, email and phone matches under the rider-only constraint. */
    public function test_search_matches_name_email_and_phone_without_including_admins(): void
    {
        $admin = User::factory()->admin()->create([
            'name' => 'Santos Administrator', 'email' => 'admin-match@example.test', 'phone' => '09170001234',
        ]);
        $rider = User::factory()->create([
            'name' => 'Inez Santos', 'email' => 'inez-match@example.test', 'phone' => '09170001234',
        ]);
        User::factory()->create(['name' => 'Other Rider', 'email' => 'different@example.test', 'phone' => '09220004567']);
        $token = $this->token($admin);

        foreach (['  sAnToS  ', 'MATCH@EXAMPLE.TEST', '0001234'] as $term) {
            $this->listRiders($token, 'q='.rawurlencode($term))
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $rider->id);
        }
    }

    /** Treat user-entered SQL wildcard characters as ordinary search text. */
    public function test_search_treats_wildcards_literally(): void
    {
        $token = $this->token($this->account(UserRole::Admin));
        $rider = User::factory()->create(['name' => 'Gio 10%_!']);
        User::factory()->create(['name' => 'Gio 100AB!']);

        $this->listRiders($token, 'q='.rawurlencode('10%_!'))
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $rider->id);
    }

    /** Combine search with the vehicle filter and include older profiles with no vehicle. */
    public function test_vehicle_filter_supports_all_registered_modes_and_unset(): void
    {
        $token = $this->token($this->account(UserRole::Admin));

        foreach (['motorcycle', 'bicycle', 'car', 'unset'] as $filter) {
            $rider = User::factory()->create(['name' => 'Shared Rider', 'vehicle' => $filter === 'unset' ? null : $filter]);
            User::factory()->create(['name' => 'Different Name', 'vehicle' => $rider->vehicle]);

            $this->listRiders($token, 'vehicle='.$filter.'&q=shared')
                ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $rider->id);
        }

        $this->listRiders($token, 'vehicle=bicycle&q=absent')
            ->assertOk()->assertJsonPath('data', [])->assertJsonPath('meta.total', 0);
    }

    /** Keep stable page boundaries for tied names and retain supported filters in links. */
    public function test_native_pagination_supports_each_entry_choice(): void
    {
        $token = $this->token($this->account(UserRole::Admin));
        $ids = User::factory()->count(105)->create(['name' => 'Same Rider', 'vehicle' => 'motorcycle'])->modelKeys();

        foreach ([10, 50, 100] as $perPage) {
            $response = $this->listRiders($token, 'page=2&per_page='.$perPage.'&vehicle=motorcycle&q=Same%20Rider')
                ->assertOk()->assertJsonPath('meta.total', 105)->assertJsonPath('meta.current_page', 2)
                ->assertJsonPath('meta.per_page', $perPage);

            $this->assertSame(array_slice($ids, $perPage, $perPage), array_column($response->json('data'), 'id'));
            parse_str((string) parse_url($response->json('links.prev'), PHP_URL_QUERY), $filters);
            $this->assertSame('motorcycle', $filters['vehicle']);
            $this->assertSame('Same Rider', $filters['q']);
            $this->assertSame((string) $perPage, $filters['per_page']);
        }
    }

    /** Reject malformed search, unsupported vehicles and unbounded paging inputs. */
    public function test_directory_validates_filters_and_paging(): void
    {
        $token = $this->token($this->account(UserRole::Admin));
        $invalid = [
            ['q%5B%5D=match', 'q'], ['q='.str_repeat('a', 101), 'q'], ['vehicle=truck', 'vehicle'],
            ['per_page=20', 'per_page'], ['per_page=101', 'per_page'], ['page=0', 'page'], ['page=2147483648', 'page'],
        ];

        foreach ($invalid as [$query, $field]) {
            $this->listRiders($token, $query)->assertUnprocessable()->assertJsonValidationErrors($field);
        }
    }

    /** Exercise the real request guard and query string instead of an in-memory page. */
    private function listRiders(string $token, string $query = ''): TestResponse
    {
        return $this->api('GET', self::PATH.($query === '' ? '' : '?'.$query), token: $token);
    }
}
