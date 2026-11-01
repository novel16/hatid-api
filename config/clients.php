<?php

declare(strict_types=1);

return [
    'rider_url' => rtrim(env('FRONTEND_URL', 'http://localhost:3000'), '/'),
    'admin_url' => rtrim(env('ADMIN_URL', 'http://localhost:3001'), '/'),
];
