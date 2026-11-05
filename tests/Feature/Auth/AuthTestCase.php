<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\Auth\TokenService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

abstract class AuthTestCase extends TestCase
{
    use RefreshDatabase;

    protected const PASSWORD = 'HatidPassword123';

    protected function account(UserRole $role = UserRole::Rider): User
    {
        $factory = $role === UserRole::Admin ? User::factory()->admin() : User::factory();

        return $factory->create(['password' => self::PASSWORD]);
    }

    protected function token(User $user, string $name = 'Test device'): string
    {
        return $this->app->make(TokenService::class)->issue($user, $name)->plainTextToken;
    }

    /**
     * Reset request guards so multiple HTTP calls cannot reuse a cached authenticated user.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     */
    protected function api(string $method, string $uri, array $data = [], ?string $token = null, array $headers = []): TestResponse
    {
        $this->app['auth']->forgetGuards();

        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $this->json($method, $uri, $data, $headers);
    }

    /** @return array<string, array{UserRole, string}> */
    public static function authClients(): array
    {
        return [
            'rider' => [UserRole::Rider, '/api/v1/auth'],
            'admin' => [UserRole::Admin, '/api/v1/admin/auth'],
        ];
    }

    /** @return array<string, string> */
    protected function registration(): array
    {
        return [
            'name' => 'Ana Rider',
            'email' => 'ana@example.com',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            'device_name' => 'Ana phone',
            'vehicle' => 'motorcycle',
        ];
    }
}
