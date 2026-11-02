<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SensitiveParameter;

final class PasswordService
{
    public function __construct(private readonly TokenService $tokens) {}

    public function sendResetLink(string $email): void
    {
        Password::sendResetLink(['email' => User::normalizeEmail($email)]);
    }

    public function reset(
        string $email,
        #[SensitiveParameter] string $token,
        #[SensitiveParameter] string $password,
    ): void {
        $status = DB::transaction(fn (): string => Password::reset(
            ['email' => User::normalizeEmail($email), 'token' => $token, 'password' => $password],
            function (User $user, string $password): void {
                $account = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();
                $this->updatePassword($account, $password);

                DB::afterCommit(fn () => event(new PasswordReset($account)));
            },
        ));

        if ($status !== Password::PasswordReset) {
            throw ValidationException::withMessages([
                'token' => ['This password reset link is invalid or has expired.'],
            ]);
        }
    }

    public function change(
        User $user,
        #[SensitiveParameter] string $currentPassword,
        #[SensitiveParameter] string $newPassword,
    ): void {
        DB::transaction(function () use ($user, $currentPassword, $newPassword): void {
            $account = User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            if (! Hash::check($currentPassword, $account->password)) {
                throw ValidationException::withMessages([
                    'current_password' => ['The current password is incorrect.'],
                ]);
            }

            $this->updatePassword($account, $newPassword);
        });
    }

    private function updatePassword(User $user, #[SensitiveParameter] string $password): void
    {
        $user->forceFill([
            'password' => $password,
            'remember_token' => Str::random(60),
        ])->save();

        $this->tokens->revokeAll($user);
    }
}
