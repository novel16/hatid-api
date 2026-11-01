<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\User;
use Laravel\Sanctum\NewAccessToken;

final readonly class AuthSession
{
    public function __construct(
        public User $user,
        public NewAccessToken $token,
    ) {}
}
