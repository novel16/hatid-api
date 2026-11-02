<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Laravel\Sanctum\NewAccessToken;
use Laravel\Sanctum\PersonalAccessToken;

final class TokenService
{
    public function issue(User $user, string $deviceName): NewAccessToken
    {
        $lifetime = max(1, (int) config('auth_tokens.lifetime_minutes.'.$user->role->value));

        $token = $user->createToken(
            $deviceName,
            [$user->role->tokenAbility()],
            now()->addMinutes($lifetime),
        );

        $token->accessToken->setRelation('tokenable', $user);

        return $token;
    }

    /** @return LengthAwarePaginator<int, PersonalAccessToken> */
    public function paginate(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return $user->tokens()
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->when(config('sanctum.expiration'), function (Builder $query, int $minutes): void {
                $query->where('created_at', '>', now()->subMinutes($minutes));
            })
            ->latest('id')
            ->paginate($perPage);
    }

    public function revokeCurrent(User $user): void
    {
        $token = $user->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            throw new AuthenticationException;
        }

        $token->delete();
    }

    public function revokeAll(User $user): void
    {
        $user->tokens()->delete();
    }

    public function revokeOwned(User $user, int $tokenId): void
    {
        $user->tokens()->whereKey($tokenId)->firstOrFail()->delete();
    }
}
