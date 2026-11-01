<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use PHPUnit\Framework\Attributes\DataProvider;

class PasswordManagementTest extends AuthTestCase
{
    #[DataProvider('authClients')]
    public function test_recovery_is_generic_and_sends_a_link_to_the_correct_client(UserRole $role, string $prefix): void
    {
        Notification::fake();
        $user = $this->account($role);

        $known = $this->api('POST', '/api/v1/auth/forgot-password', ['email' => strtoupper($user->email)])->assertOk();
        $unknown = $this->api('POST', '/api/v1/auth/forgot-password', ['email' => 'absent@example.com'])->assertOk();

        $this->assertSame($known->json(), $unknown->json());
        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, $role): bool {
            $url = $notification->toMail($user)->actionUrl;
            $client = $role === UserRole::Admin ? 'admin_url' : 'rider_url';
            $this->assertStringStartsWith(config('clients.'.$client).'/reset-password?', $url);
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $this->assertSame($user->email, $query['email']);
            $this->assertSame($notification->token, $query['token']);

            return true;
        });
        Notification::assertCount(1);
        $this->assertArrayNotHasKey('token', $known->json());
    }

    #[DataProvider('authClients')]
    public function test_password_reset_changes_the_password_and_revokes_all_owned_tokens(UserRole $role, string $prefix): void
    {
        $user = $this->account($role);
        $first = $this->token($user);
        $this->token($user, 'Other device');
        $otherPerson = $this->token($this->account($role));
        $resetToken = Password::createToken($user);

        $this->assertNotSame($resetToken, DB::table('password_reset_tokens')->where('email', $user->email)->value('token'));
        $this->api('POST', '/api/v1/auth/reset-password', [
            'email' => $user->email,
            'token' => $resetToken,
            'password' => 'NewHatidPassword123',
            'password_confirmation' => 'NewHatidPassword123',
        ])->assertOk();

        $this->assertTrue(Hash::check('NewHatidPassword123', $user->fresh()->password));
        $this->assertSame($role, $user->fresh()->role);
        $this->assertDatabaseCount('personal_access_tokens', 1);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->api('GET', $prefix.'/me', token: $first)->assertUnauthorized();
        $this->api('GET', $prefix.'/me', token: $otherPerson)->assertOk();
        $this->api('POST', $prefix.'/login', [
            'email' => $user->email, 'password' => 'NewHatidPassword123', 'device_name' => 'Browser',
        ])->assertOk();
    }

    public function test_invalid_reset_tokens_do_not_change_passwords_or_revoke_tokens(): void
    {
        $user = $this->account();
        $current = $this->token($user);
        $payload = [
            'email' => $user->email, 'token' => 'invalid',
            'password' => 'NewHatidPassword123', 'password_confirmation' => 'NewHatidPassword123',
        ];

        $known = $this->api('POST', '/api/v1/auth/reset-password', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('token');
        $unknown = $this->api('POST', '/api/v1/auth/reset-password', [...$payload, 'email' => 'absent@example.com'])
            ->assertUnprocessable()->assertJsonValidationErrors('token');

        $this->assertSame($known->json(), $unknown->json());
        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
        $this->api('GET', '/api/v1/auth/me', token: $current)->assertOk();
    }

    public function test_expired_password_reset_tokens_are_rejected(): void
    {
        $user = $this->account();
        $token = Password::createToken($user);
        $this->travel(61)->minutes();

        $this->api('POST', '/api/v1/auth/reset-password', [
            'email' => $user->email, 'token' => $token,
            'password' => 'NewHatidPassword123', 'password_confirmation' => 'NewHatidPassword123',
        ])->assertUnprocessable()->assertJsonValidationErrors('token');

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
    }

    public function test_password_reset_tokens_cannot_be_reused(): void
    {
        $user = $this->account();
        $payload = [
            'email' => $user->email, 'token' => Password::createToken($user),
            'password' => 'NewHatidPassword123', 'password_confirmation' => 'NewHatidPassword123',
        ];
        $this->api('POST', '/api/v1/auth/reset-password', $payload)->assertOk();
        $this->api('POST', '/api/v1/auth/reset-password', $payload)
            ->assertUnprocessable()->assertJsonValidationErrors('token');
    }

    #[DataProvider('authClients')]
    public function test_changing_password_requires_reauthentication_on_all_devices(UserRole $role, string $prefix): void
    {
        $user = $this->account($role);
        $current = $this->token($user);
        $second = $this->token($user, 'Second device');

        $this->api('PUT', $prefix.'/password', [
            'current_password' => self::PASSWORD,
            'password' => 'NewHatidPassword123',
            'password_confirmation' => 'NewHatidPassword123',
        ], $current)->assertNoContent();

        $this->assertTrue(Hash::check('NewHatidPassword123', $user->fresh()->password));
        $this->assertDatabaseCount('personal_access_tokens', 0);
        $this->api('GET', $prefix.'/me', token: $current)->assertUnauthorized();
        $this->api('GET', $prefix.'/me', token: $second)->assertUnauthorized();
    }

    public function test_an_incorrect_current_password_preserves_the_account_and_tokens(): void
    {
        $user = $this->account();
        $current = $this->token($user);

        $this->api('PUT', '/api/v1/auth/password', [
            'current_password' => 'IncorrectPassword123',
            'password' => 'NewHatidPassword123',
            'password_confirmation' => 'NewHatidPassword123',
        ], $current)->assertUnprocessable()->assertJsonValidationErrors('current_password');

        $this->assertTrue(Hash::check(self::PASSWORD, $user->fresh()->password));
        $this->api('GET', '/api/v1/auth/me', token: $current)->assertOk();
    }
}
