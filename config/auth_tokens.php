<?php

declare(strict_types=1);

return [
    'lifetime_minutes' => [
        'rider' => (int) env('AUTH_RIDER_TOKEN_TTL', 1440),
        'admin' => (int) env('AUTH_ADMIN_TOKEN_TTL', 60),
    ],
];
