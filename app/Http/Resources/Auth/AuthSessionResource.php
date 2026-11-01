<?php

declare(strict_types=1);

namespace App\Http\Resources\Auth;

use App\Data\AuthSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuthSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var AuthSession $session */
        $session = $this->resource;

        return [
            'user' => new UserResource($session->user),
            'access_token' => $session->token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $session->token->accessToken->expires_at->toISOString(),
        ];
    }
}
