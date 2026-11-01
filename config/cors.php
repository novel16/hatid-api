<?php

declare(strict_types=1);

return [
    'paths' => ['api/*'],
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    'allowed_origins' => array_values(array_filter(array_map('trim', explode(',', env(
        'CORS_ALLOWED_ORIGINS',
        env('FRONTEND_URL', 'http://localhost:3000').','.env('ADMIN_URL', 'http://localhost:3001'),
    ))))),
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type'],
    'exposed_headers' => [],
    'max_age' => 600,
    'supports_credentials' => false,
];
