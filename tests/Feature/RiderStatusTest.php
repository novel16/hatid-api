<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Batch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Auth\AuthTestCase;

class RiderStatusTest extends AuthTestCase
{
    /** Suspension ends old sessions, and reactivation lets the rider resume their own work. */
    public function test_suspension_revokes_tokens_and_reactivation_requires_a_new_login(): void
    {
        $admin = $this->account(UserRole::Admin);
        $rider = $this->account();
        $other = $this->account();
        $batch = Batch::factory()->for($rider)->create();
        $adminToken = $this->token($admin);
        $first = $this->token($rider);
        $second = $this->token($rider);
        $otherToken = $this->token($other);

        $this->changeStatus($adminToken, $rider, 'suspended', 0)
            ->assertOk()->assertJsonPath('data.status', 'suspended')->assertJsonPath('data.status_revision', 1);
        $this->assertNotNull($rider->fresh()->suspended_at);
        $this->assertSame(0, $rider->tokens()->count());
        $this->api('GET', '/api/v1/auth/me', token: $first)->assertUnauthorized();
        $this->api('GET', '/api/v1/auth/me', token: $second)->assertUnauthorized();
        $this->api('GET', '/api/v1/auth/me', token: $otherToken)->assertOk();
        $this->api('GET', '/api/v1/admin/auth/me', token: $adminToken)->assertOk();

        $login = ['email' => $rider->email, 'password' => self::PASSWORD, 'device_name' => 'Test browser'];
        $this->api('POST', '/api/v1/auth/login', [...$login, 'password' => 'WrongPassword'])
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'The provided credentials are incorrect.');
        $this->api('POST', '/api/v1/auth/login', $login)
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'This rider account is suspended. Contact your Hatid administrator.');
        $this->assertSame(0, $rider->tokens()->count());

        $leftover = $rider->createToken('Isolated guard fixture', ['rider:access'], now()->addHour())->plainTextToken;
        $this->api('GET', '/api/v1/auth/me', token: $leftover)->assertForbidden();
        $this->api('GET', '/api/v1/batches', token: $leftover)->assertForbidden();

        $this->changeStatus($adminToken, $rider, 'active', 1, 'Access restored.')
            ->assertOk()->assertJsonPath('data.status', 'active')->assertJsonPath('data.status_revision', 2)
            ->assertJsonPath('data.suspended_at', null);
        $this->api('GET', '/api/v1/auth/me', token: $leftover)->assertUnauthorized();
        $this->api('GET', '/api/v1/auth/me', token: $first)->assertUnauthorized();
        $new = $this->api('POST', '/api/v1/auth/login', $login)->assertOk()->json('data.access_token');
        $this->api('GET', '/api/v1/batches/'.$batch->id, token: $new)->assertOk();
        $this->assertDatabaseCount('rider_status_activities', 2);
    }

    /** Only the proper admin role and ability can read or change rider status. */
    public function test_status_routes_require_admin_bearer_and_rider_targets(): void
    {
        $admin = $this->account(UserRole::Admin);
        $rider = $this->account();
        $path = $this->riderPath($rider);
        $this->api('PATCH', $path.'/status')->assertUnauthorized();
        $this->api('GET', $path.'/activity')->assertUnauthorized();
        $this->changeStatus($this->token($rider), $rider, 'suspended', 0)->assertForbidden();
        $this->api('GET', $path.'/activity', token: $this->token($rider))->assertForbidden();
        $wrongAbility = $admin->createToken('Wrong scope', ['rider:access'], now()->addHour())->plainTextToken;
        $this->changeStatus($wrongAbility, $rider, 'suspended', 0)->assertForbidden();
        $this->api('GET', $path.'/activity', token: $wrongAbility)->assertForbidden();
        $adminToken = $this->token($admin);
        $this->changeStatus($adminToken, $admin, 'suspended', 0)->assertNotFound();
        $this->api('GET', $this->riderPath($admin).'/activity', token: $adminToken)->assertNotFound();
        $this->api('GET', '/api/v1/admin/riders/999999/activity', token: $adminToken)->assertNotFound();
        $this->assertDatabaseCount('rider_status_activities', 0);
    }

    /** Reject missing reasons, malformed revisions and unsupported status values before writing. */
    public function test_status_actions_validate_reason_revision_and_status(): void
    {
        $token = $this->token($this->account(UserRole::Admin));
        $rider = $this->account();
        $path = $this->riderPath($rider);
        $this->api('PATCH', $path.'/status', ['status' => 'unknown', 'reason' => '  ', 'expected_revision' => -1], $token)
            ->assertUnprocessable()->assertJsonValidationErrors(['status', 'reason', 'expected_revision']);
        $this->api('PATCH', $path.'/status', ['status' => 'active', 'expected_revision' => 0], $token)
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->changeStatus($token, $rider, 'suspended', 0, str_repeat('a', 501))
            ->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->api('GET', $path.'/activity?page=0', token: $token)->assertUnprocessable()->assertJsonValidationErrors('page');
        $this->assertDatabaseCount('rider_status_activities', 0);
    }

    /** Replayed or stale confirmations cannot repeat a transition or erase a newer decision. */
    public function test_status_revision_rejects_replay_and_a_stale_confirmation_after_reactivation(): void
    {
        $token = $this->token($this->account(UserRole::Admin));
        $rider = $this->account();
        $this->changeStatus($token, $rider, 'suspended', 0)->assertOk();
        $this->changeStatus($token, $rider, 'suspended', 0)->assertConflict();
        $this->changeStatus($token, $rider, 'active', 1)->assertOk();
        $freshToken = $this->token($rider);
        $this->changeStatus($token, $rider, 'suspended', 0)->assertConflict();
        $this->changeStatus($token, $rider, 'active', 2)->assertConflict();
        $this->assertSame(2, $rider->fresh()->rider_status_revision);
        $this->assertDatabaseCount('rider_status_activities', 2);
        $this->api('GET', '/api/v1/auth/me', token: $freshToken)->assertOk();
    }

    /** Keep each activity's original actor name, reason and time in its rider's history. */
    public function test_history_uses_fixed_pages_and_original_actor_evidence(): void
    {
        $this->travelTo(now()->startOfSecond());
        $admin = $this->account(UserRole::Admin);
        $adminName = $admin->name;
        $token = $this->token($admin);
        $rider = $this->account();
        $other = $this->account();

        for ($revision = 0; $revision < 11; $revision++) {
            $this->changeStatus($token, $rider, $revision % 2 === 0 ? 'suspended' : 'active', $revision, 'Review '.$revision)
                ->assertOk();
        }
        $this->changeStatus($token, $other, 'suspended', 0)->assertOk();
        $admin->forceFill(['name' => 'Renamed admin'])->save();

        $path = $this->riderPath($rider).'/activity';
        $response = $this->api('GET', $path.'?per_page=100', token: $token)
            ->assertOk()->assertJsonPath('rider.id', $rider->id)->assertJsonPath('rider.status_revision', 11)
            ->assertJsonPath('meta.total', 11)->assertJsonPath('meta.per_page', 10)->assertJsonCount(10, 'data')
            ->assertJsonPath('data.0.status', 'suspended')->assertJsonPath('data.0.reason', 'Review 10')
            ->assertJsonPath('data.0.admin_id', $admin->id)->assertJsonPath('data.0.admin_name', $adminName)
            ->assertJsonPath('data.0.created_at', now()->toISOString());
        $this->assertEqualsCanonicalizing(
            ['id', 'status', 'reason', 'admin_id', 'admin_name', 'created_at'],
            array_keys($response->json('data.0')),
        );
        $this->api('GET', $path.'?page=2', token: $token)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.reason', 'Review 0');
    }

    /** A failed activity insert must roll back both the status and token deletion. */
    public function test_activity_failure_rolls_back_suspension_and_revocation(): void
    {
        $token = $this->token($this->account(UserRole::Admin));
        $rider = $this->account();
        $riderToken = $this->token($rider);

        DB::unprepared("CREATE TRIGGER reject_rider_activity BEFORE INSERT ON rider_status_activities BEGIN SELECT RAISE(ABORT, 'Isolated activity storage failure'); END");
        try {
            $this->changeStatus($token, $rider, 'suspended', 0)->assertServerError();
        } finally {
            DB::unprepared('DROP TRIGGER reject_rider_activity');
        }

        $this->assertNull($rider->fresh()->suspended_at);
        $this->assertSame(0, $rider->fresh()->rider_status_revision);
        $this->assertDatabaseCount('rider_status_activities', 0);
        $this->api('GET', '/api/v1/auth/me', token: $riderToken)->assertOk();
    }

    /** Combine status with existing directory filters and keep existing riders active by default. */
    public function test_directory_filters_by_status_and_preserves_pagination_links(): void
    {
        $token = $this->token($this->account(UserRole::Admin));
        $first = User::factory()->create(['name' => 'Shared Rider', 'vehicle' => 'bicycle']);
        $second = User::factory()->create(['name' => 'Shared Rider', 'vehicle' => 'bicycle']);
        User::factory()->create(['name' => 'Shared Rider', 'vehicle' => 'car']);
        $this->changeStatus($token, $first, 'suspended', 0)->assertOk();

        $list = '/api/v1/admin/riders?q=shared&vehicle=bicycle&per_page=50&status=';
        $suspended = $this->api('GET', $list.'suspended', token: $token)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $first->id)
            ->assertJsonPath('data.0.status', 'suspended');
        $this->assertStringContainsString('status=suspended', $suspended->json('links.first'));
        $this->api('GET', $list.'active', token: $token)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.0.status_revision', 0)->assertJsonPath('data.0.status', 'active');
        $this->api('GET', $list.'invalid', token: $token)->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    /** Target the same authorized rider for a profile read and a status change. */
    private function riderPath(User $rider): string
    {
        return '/api/v1/admin/riders/'.$rider->id;
    }

    /** Submit the exact revision that the administrator reviewed. */
    private function changeStatus(string $token, User $rider, string $status, int $revision, string $reason = 'Account review.'): TestResponse
    {
        return $this->api('PATCH', $this->riderPath($rider).'/status', [
            'status' => $status, 'expected_revision' => $revision, 'reason' => $reason,
        ], $token);
    }
}
