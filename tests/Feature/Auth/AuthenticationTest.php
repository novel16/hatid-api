<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;

class AuthenticationTest extends AuthTestCase
{
    /** @return array<string, array{string, string, array<string>}> */
    public static function requiredAuthFields(): array
    {
        return [
            'registration' => ['POST', '/api/v1/auth/register', ['name', 'email', 'password', 'device_name']],
            'rider login' => ['POST', '/api/v1/auth/login', ['email', 'password', 'device_name']],
            'admin login' => ['POST', '/api/v1/admin/auth/login', ['email', 'password', 'device_name']],
            'password recovery' => ['POST', '/api/v1/auth/forgot-password', ['email']],
            'password reset' => ['POST', '/api/v1/auth/reset-password', ['email', 'token', 'password']],
            'rider password change' => ['PUT', '/api/v1/auth/password', ['current_password', 'password']],
            'admin password change' => ['PUT', '/api/v1/admin/auth/password', ['current_password', 'password']],
        ];
    }

    /** @param array<string> $fields */
    #[DataProvider('requiredAuthFields')]
    public function test_each_auth_action_keeps_its_required_fields(string $method, string $path, array $fields): void
    {
        $token = null;

        if ($method === 'PUT') {
            $role = str_contains($path, '/admin/') ? UserRole::Admin : UserRole::Rider;
            $token = $this->token($this->account($role));
        }

        $this->api($method, $path, token: $token)
            ->assertUnprocessable()->assertJsonValidationErrors($fields);
    }

    public function test_registration_returns_a_rider_and_a_hashed_expiring_bearer_token(): void
    {
        $this->travelTo(now()->startOfSecond());

        $response = $this->api('POST', '/api/v1/auth/register', $this->registration())
            ->assertCreated()
            ->assertJsonPath('data.user.role', 'rider')
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.expires_at', now()->addDay()->toISOString())
            ->assertJsonMissingPath('data.user.password')
            ->assertJsonMissingPath('data.user.remember_token')
            ->assertHeaderMissing('Set-Cookie');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $user = User::query()->sole();
        $this->assertTrue(Hash::check(self::PASSWORD, $user->password));
        $stored = PersonalAccessToken::findToken($response->json('data.access_token'));
        $this->assertSame(['rider:access'], $stored->abilities);
        $this->assertNotSame($response->json('data.access_token'), $stored->token);
        $this->assertSame(64, strlen($stored->token));

        $this->api('GET', '/api/v1/auth/me', token: $response->json('data.access_token'))
            ->assertOk()->assertJsonPath('data.id', $user->id);
    }

    /** @return array<string, array{string, mixed}> */
    public static function privilegeInputs(): array
    {
        return [
            'role' => ['role', 'admin'],
            'admin flag' => ['is_admin', true],
            'abilities' => ['abilities', ['admin:access']],
        ];
    }

    #[DataProvider('privilegeInputs')]
    public function test_public_registration_cannot_assign_privileges(string $field, mixed $value): void
    {
        $this->api('POST', '/api/v1/auth/register', [...$this->registration(), $field => $value])
            ->assertUnprocessable()->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_email_normalization_prevents_duplicate_accounts(): void
    {
        $this->account()->forceFill(['email' => 'ana@example.com'])->save();

        $this->api('POST', '/api/v1/auth/register', [...$this->registration(), 'email' => 'ANA@EXAMPLE.COM'])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('users', 1);
    }

    public function test_registration_requires_password_confirmation_and_a_strong_password(): void
    {
        $this->api('POST', '/api/v1/auth/register', [
            ...$this->registration(),
            'password' => 'weak',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors('password');

        $this->assertDatabaseCount('users', 0);
    }

    public function test_password_length_is_checked_in_bytes_before_bcrypt_hashing(): void
    {
        $password = 'A1'.str_repeat('é', 36);

        $this->api('POST', '/api/v1/auth/register', [
            ...$this->registration(),
            'password' => $password,
            'password_confirmation' => $password,
        ])->assertUnprocessable()->assertJsonValidationErrors('password');
    }

    #[DataProvider('authClients')]
    public function test_each_client_can_login_and_receive_its_scoped_expiring_token(UserRole $role, string $prefix): void
    {
        $this->travelTo(now()->startOfSecond());
        $user = $this->account($role);

        $response = $this->api('POST', $prefix.'/login', [
            'email' => strtoupper($user->email),
            'password' => self::PASSWORD,
            'device_name' => 'Browser',
        ])->assertOk()->assertJsonPath('data.user.role', $role->value)->assertHeaderMissing('Set-Cookie');

        $token = PersonalAccessToken::findToken($response->json('data.access_token'));
        $this->assertSame([$role->tokenAbility()], $token->abilities);
        $this->assertTrue($token->expires_at->equalTo(now()->addMinutes(config('auth_tokens.lifetime_minutes.'.$role->value))));

        $this->api('GET', $prefix.'/me', token: $response->json('data.access_token'))
            ->assertOk()->assertExactJsonStructure([
                'data' => ['id', 'name', 'email', 'role', 'email_verified_at', 'created_at'],
            ]);
    }

    #[DataProvider('authClients')]
    public function test_credentials_cannot_be_used_to_login_to_the_other_client(UserRole $role, string $prefix): void
    {
        $otherRole = $role === UserRole::Rider ? UserRole::Admin : UserRole::Rider;
        $user = $this->account($otherRole);

        $this->api('POST', $prefix.'/login', [
            'email' => $user->email,
            'password' => self::PASSWORD,
            'device_name' => 'Browser',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_invalid_and_unknown_credentials_return_the_same_error(): void
    {
        $user = $this->account();
        $payload = ['email' => $user->email, 'password' => 'WrongPassword123', 'device_name' => 'Phone'];
        $known = $this->api('POST', '/api/v1/auth/login', $payload)->assertUnprocessable();
        $unknown = $this->api('POST', '/api/v1/auth/login', [...$payload, 'email' => 'absent@example.com'])->assertUnprocessable();

        $this->assertSame($known->json(), $unknown->json());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[DataProvider('authClients')]
    public function test_an_authenticated_web_session_does_not_authenticate_the_api(UserRole $role, string $prefix): void
    {
        $this->actingAs($this->account($role), 'web');
        $this->withCookie('laravel_session', 'session-cookie')->getJson($prefix.'/me')->assertUnauthorized();
    }

    public function test_tokens_in_query_parameters_are_not_accepted(): void
    {
        $token = $this->token($this->account());

        $this->api('GET', '/api/v1/auth/me?access_token='.urlencode($token))->assertUnauthorized();
    }

    public function test_auth_errors_are_json_without_an_accept_header(): void
    {
        $this->get('/api/v1/auth/me')->assertUnauthorized()->assertHeader('Content-Type', 'application/json');
    }

    #[DataProvider('authClients')]
    public function test_roles_and_token_abilities_are_both_enforced(UserRole $role, string $prefix): void
    {
        $other = $role === UserRole::Rider ? UserRole::Admin : UserRole::Rider;
        $wrongRole = $this->account($other);
        $forgedScope = $wrongRole->createToken('Forged scope', [$role->tokenAbility()], now()->addHour())->plainTextToken;
        $this->api('GET', $prefix.'/me', token: $forgedScope)->assertForbidden();

        $correctRole = $this->account($role);
        $wrongScope = $correctRole->createToken('Wrong scope', [$other->tokenAbility()], now()->addHour())->plainTextToken;
        $this->api('GET', $prefix.'/me', token: $wrongScope)->assertForbidden();
    }

    public function test_a_role_change_does_not_upgrade_existing_token_permissions(): void
    {
        $user = $this->account();
        $token = $this->token($user);
        $user->forceFill(['role' => UserRole::Admin])->save();

        $this->api('GET', '/api/v1/admin/auth/me', token: $token)->assertForbidden();
        $this->api('GET', '/api/v1/auth/me', token: $token)->assertForbidden();
    }

    public function test_login_rate_limiting_handles_normalized_and_malformed_identities(): void
    {
        $this->api('POST', '/api/v1/auth/login', ['email' => ['invalid']])
            ->assertUnprocessable()->assertJsonValidationErrors('email');

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->api('POST', '/api/v1/auth/login', [
                'email' => $attempt % 2 === 0 ? 'missing@example.com' : 'MISSING@EXAMPLE.COM',
                'password' => self::PASSWORD,
                'device_name' => 'Phone',
            ])->assertUnprocessable();
        }

        $this->api('POST', '/api/v1/admin/auth/login', [
            'email' => 'missing@example.com', 'password' => self::PASSWORD, 'device_name' => 'Browser',
        ])->assertTooManyRequests()->assertHeader('Retry-After');
    }

    /**
     * Lists the local clients allowed to send Bearer requests.
     *
     * @return array<string, array{string}>
     */
    public static function frontendOrigins(): array
    {
        return ['rider' => ['http://localhost:3011'], 'admin' => ['http://localhost:3012']];
    }

    #[DataProvider('frontendOrigins')]
    public function test_both_frontend_origins_can_preflight_bearer_requests_without_cookies(string $origin): void
    {
        $this->options('/api/v1/auth/me', [], [
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'GET',
            'Access-Control-Request-Headers' => 'Authorization,Content-Type',
        ])->assertNoContent()->assertHeader('Access-Control-Allow-Origin', $origin)
            ->assertHeaderMissing('Access-Control-Allow-Credentials');
    }

    public function test_untrusted_origins_are_not_allowed_and_csrf_cookie_route_is_disabled(): void
    {
        $this->options('/api/v1/auth/me', [], [
            'Origin' => 'https://untrusted.example', 'Access-Control-Request-Method' => 'GET',
        ])->assertHeaderMissing('Access-Control-Allow-Origin');

        $this->getJson('/sanctum/csrf-cookie')->assertNotFound();
        $this->api('POST', '/api/v1/admin/auth/register', $this->registration())->assertNotFound();
    }
}
