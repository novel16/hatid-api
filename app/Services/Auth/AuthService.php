<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\NewAccessToken;
use SensitiveParameter;

final class AuthService
{
    public function __construct(
        private readonly TokenService $tokens,
        private readonly Timebox $timebox,
    ) {}

    /** Creates a rider with their explicit primary vehicle and an expiring Bearer token. */
    public function register(
        string $name,
        string $email,
        #[SensitiveParameter] string $password,
        string $deviceName,
        string $vehicle,
    ): NewAccessToken {
        /** Save the profile and issued token as one transaction. */
        return DB::transaction(function () use ($name, $email, $password, $deviceName, $vehicle): NewAccessToken {
            $user = $this->createUser($name, $email, $password, UserRole::Rider);
            $user->forceFill(['vehicle' => $vehicle])->save();

            return $this->tokens->issue($user, $deviceName);
        });
    }

    public function login(
        string $email,
        #[SensitiveParameter] string $password,
        string $deviceName,
        UserRole $role,
    ): NewAccessToken {
        return $this->timebox->call(function () use ($email, $password, $deviceName, $role): NewAccessToken {
            return DB::transaction(function () use ($email, $password, $deviceName, $role): NewAccessToken {
                $user = User::query()
                    ->where('email', User::normalizeEmail($email))
                    ->lockForUpdate()
                    ->first();

                if (! $user || ! Hash::check($password, $user->password) || $user->role !== $role) {
                    throw ValidationException::withMessages([
                        'email' => ['The provided credentials are incorrect.'],
                    ]);
                }

                if (Hash::needsRehash($user->password)) {
                    $user->forceFill(['password' => $password])->save();
                }

                return $this->tokens->issue($user, $deviceName);
            });
        }, 200_000);
    }

    public function createAdmin(
        string $name,
        string $email,
        #[SensitiveParameter] string $password,
    ): User {
        return $this->createUser($name, $email, $password, UserRole::Admin);
    }

    private function createUser(
        string $name,
        string $email,
        #[SensitiveParameter] string $password,
        UserRole $role,
    ): User {
        $user = new User([
            'name' => $name,
            'email' => User::normalizeEmail($email),
            'password' => $password,
        ]);
        $user->role = $role;

        try {
            $user->save();
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'email' => ['The email has already been taken.'],
            ]);
        }

        return $user;
    }
}
