<?php

declare(strict_types=1);

return [
    // Authentication is exclusively via Bearer tokens; never fall back to web sessions.
    'stateful' => [],
    'guard' => [],
    'routes' => false,

    // Each token receives an explicit expires_at from TokenService.
    'expiration' => null,
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),
];
