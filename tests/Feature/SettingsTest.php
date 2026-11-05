<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Auth\AuthTestCase;

class SettingsTest extends AuthTestCase
{
    /** Legacy riders can keep working, then choose or change their routing vehicle in Settings. */
    public function test_vehicle_completion_is_owned_and_does_not_change_other_preferences(): void
    {
        $user = $this->account();
        $other = $this->account();
        $token = $this->token($user);
        $this->api('GET', '/api/v1/auth/me', token: $token)->assertOk()->assertJsonPath('data.vehicle', null);
        foreach (['bicycle', 'car', 'motorcycle'] as $vehicle) {
            $this->api('PATCH', '/api/v1/settings', ['vehicle' => $vehicle], $token)->assertOk()->assertJsonPath('data.vehicle', $vehicle);
        }
        foreach ([null, '', 'truck', ['car']] as $invalid) {
            $this->api('PATCH', '/api/v1/settings', ['vehicle' => $invalid], $token)
                ->assertUnprocessable()->assertJsonValidationErrors('vehicle');
        }
        $this->api('GET', '/api/v1/auth/me', token: $token)->assertOk()->assertJsonPath('data.vehicle', 'motorcycle');
        $this->assertNull($other->fresh()->vehicle);
        $this->assertTrue($user->fresh()->settingPreferences()['deliveryAlerts']);
    }

    /** Existing accounts receive safe defaults without exposing stored internals. */
    public function test_account_settings_have_defaults_and_controlled_serialization(): void
    {
        $user = $this->account();

        $this->api('GET', '/api/v1/auth/me', token: $this->token($user))
            ->assertOk()->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.preferences', [
                'deliveryAlerts' => true, 'collectionAlerts' => true, 'compactView' => false,
            ])
            ->assertJsonMissingPath('data.password')->assertJsonMissingPath('data.remember_token');
    }

    /** Profile ownership comes from the Bearer token and credentials stay untouched. */
    public function test_profile_update_is_owned_normalized_and_survives_another_request(): void
    {
        $user = $this->account();
        $other = $this->account();
        $token = $this->token($user);

        $this->api('PATCH', '/api/v1/settings', ['name' => '  Ana Rider  ', 'phone' => '  +63 917 123 4567  '], $token)
            ->assertOk()->assertJsonPath('data.name', 'Ana Rider')
            ->assertJsonPath('data.phone', '+63 917 123 4567')
            ->assertJsonPath('data.email', $user->email)->assertJsonPath('data.role', 'rider');

        $this->api('GET', '/api/v1/auth/me', token: $token)
            ->assertOk()->assertJsonPath('data.name', 'Ana Rider')->assertJsonPath('data.phone', '+63 917 123 4567');
        $this->assertSame($other->name, $other->fresh()->name);
        $this->assertSame($user->password, $user->fresh()->password);
    }

    /** False preferences and a cleared phone persist without resetting unrelated values. */
    public function test_partial_preferences_merge_and_phone_can_be_cleared(): void
    {
        $token = $this->token($this->account());
        $this->api('PATCH', '/api/v1/settings', [
            'phone' => '09171234567', 'preferences' => ['deliveryAlerts' => false, 'compactView' => true],
        ], $token)->assertOk();
        $this->api('PATCH', '/api/v1/settings', [
            'phone' => '', 'preferences' => ['collectionAlerts' => false],
        ], $token)->assertOk()->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.preferences', [
                'deliveryAlerts' => false, 'collectionAlerts' => false, 'compactView' => true,
            ]);
        $this->api('GET', '/api/v1/auth/me', token: $token)
            ->assertOk()->assertJsonPath('data.preferences.deliveryAlerts', false)
            ->assertJsonPath('data.preferences.compactView', true);
    }

    /** Invalid input and protected account fields cannot silently change the account. */
    #[DataProvider('invalidSettings')]
    public function test_settings_validation(array $values, string $field): void
    {
        $user = $this->account();
        $this->api('PATCH', '/api/v1/settings', $values, $this->token($user))
            ->assertUnprocessable()->assertJsonValidationErrors($field);
        $this->assertSame($user->name, $user->fresh()->name);
        $this->assertSame($user->email, $user->fresh()->email);
    }

    /** Includes ordinary validation and attempted account/ownership changes. */
    public static function invalidSettings(): array
    {
        return [
            'blank name' => [['name' => '   '], 'name'],
            'long name' => [['name' => str_repeat('a', 256)], 'name'],
            'phone type' => [['phone' => []], 'phone'],
            'long phone' => [['phone' => str_repeat('1', 31)], 'phone'],
            'preferences type' => [['preferences' => 'all'], 'preferences'],
            'unknown preference' => [['preferences' => ['admin' => true]], 'preferences'],
            'invalid switch' => [['preferences' => ['compactView' => 'yes']], 'preferences.compactView'],
            'email' => [['email' => 'another@example.test'], 'email'],
            'role' => [['role' => 'admin'], 'role'],
            'owner' => [['user_id' => 999], 'user_id'],
            'password' => [['password' => 'UnexpectedPassword123'], 'password'],
        ];
    }

    /** Settings use the same authentication, role and token-ability checks as directories. */
    public function test_settings_require_a_valid_rider_token_with_access(): void
    {
        $values = ['name' => 'Updated Rider'];
        $this->api('PATCH', '/api/v1/settings', $values)->assertUnauthorized();
        $this->api('PATCH', '/api/v1/settings', $values, 'invalid')->assertUnauthorized();
        $this->api('PATCH', '/api/v1/settings', $values, $this->token($this->account(UserRole::Admin)))
            ->assertForbidden();
        $user = $this->account();
        $restricted = $user->createToken('Restricted', ['other'], now()->addHour());
        $this->api('PATCH', '/api/v1/settings', $values, $restricted->plainTextToken)->assertForbidden();
        $expired = $user->createToken('Expired', ['rider:access'], now()->subMinute());
        $this->api('PATCH', '/api/v1/settings', $values, $expired->plainTextToken)->assertUnauthorized();
    }
}
