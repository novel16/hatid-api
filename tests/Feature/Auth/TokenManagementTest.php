<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;

class TokenManagementTest extends AuthTestCase
{
    #[DataProvider('authClients')]
    public function test_token_collection_is_paginated_owned_and_never_exposes_hashes(UserRole $role, string $prefix): void
    {
        $user = $this->account($role);
        $this->token($user, 'Other device');
        $this->token($this->account($role), 'Another person');
        $user->createToken('Expired', [$role->tokenAbility()], now()->subMinute());
        $current = $this->token($user, 'Current device');

        $response = $this->api('GET', $prefix.'/tokens?per_page=1', token: $current)
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.per_page', 1)->assertJsonPath('data.0.name', 'Current device')
            ->assertJsonPath('data.0.is_current', true)
            ->assertJsonMissingPath('data.0.token')->assertJsonMissingPath('data.0.tokenable_id')
            ->assertJsonStructure(['data', 'links', 'meta']);

        $this->assertSame([
            'id', 'name', 'abilities', 'is_current', 'last_used_at', 'expires_at', 'created_at',
        ], array_keys($response->json('data.0')));
    }

    public function test_pagination_is_bounded(): void
    {
        $this->api('GET', '/api/v1/auth/tokens?per_page=101', token: $this->token($this->account()))
            ->assertUnprocessable()->assertJsonValidationErrors('per_page');
    }

    #[DataProvider('authClients')]
    public function test_logout_revokes_only_the_current_device(UserRole $role, string $prefix): void
    {
        $user = $this->account($role);
        $current = $this->token($user);
        $otherDevice = $this->token($user, 'Other phone');
        $this->token($this->account($role));

        $this->api('POST', $prefix.'/logout', token: $current)->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 2);
        $this->api('GET', $prefix.'/me', token: $current)->assertUnauthorized();
        $this->api('GET', $prefix.'/me', token: $otherDevice)->assertOk();
    }

    #[DataProvider('authClients')]
    public function test_logout_all_revokes_all_owned_devices_and_no_other_users(UserRole $role, string $prefix): void
    {
        $user = $this->account($role);
        $current = $this->token($user);
        $second = $this->token($user, 'Second phone');
        $someoneElse = $this->token($this->account($role));

        $this->api('POST', $prefix.'/logout-all', token: $current)->assertNoContent();
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->api('GET', $prefix.'/me', token: $current)->assertUnauthorized();
        $this->api('GET', $prefix.'/me', token: $second)->assertUnauthorized();
        $this->api('GET', $prefix.'/me', token: $someoneElse)->assertOk();
    }

    #[DataProvider('authClients')]
    public function test_only_an_owned_token_can_be_revoked(UserRole $role, string $prefix): void
    {
        $user = $this->account($role);
        $current = $this->token($user);
        $otherDevice = $this->token($user, 'Other phone');
        $foreign = $this->token($this->account($role));
        $foreignId = PersonalAccessToken::findToken($foreign)->id;

        $this->api('DELETE', $prefix.'/tokens/'.$foreignId, token: $current)->assertNotFound();
        $this->assertNotNull(PersonalAccessToken::findToken($foreign));

        $ownedId = PersonalAccessToken::findToken($otherDevice)->id;
        $this->api('DELETE', $prefix.'/tokens/'.$ownedId, token: $current)->assertNoContent();
        $this->api('GET', $prefix.'/me', token: $otherDevice)->assertUnauthorized();
        $this->api('GET', $prefix.'/me', token: $current)->assertOk();
    }

    #[DataProvider('authClients')]
    public function test_expired_and_invalid_tokens_are_rejected(UserRole $role, string $prefix): void
    {
        $user = $this->account($role);
        $expired = $user->createToken('Expired', [$role->tokenAbility()], now()->subMinute())->plainTextToken;
        $this->api('GET', $prefix.'/me', token: $expired)->assertUnauthorized();
        $this->api('GET', $prefix.'/me', token: 'invalid-token')->assertUnauthorized();
        $this->api('GET', $prefix.'/me', token: '999999|wrong-secret')->assertUnauthorized();
    }
}
