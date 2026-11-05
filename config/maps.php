<?php

declare(strict_types=1);

return [
    'api_key' => env('GEOAPIFY_API_KEY', ''),
    // A rolling allowance leaves headroom below the provider's daily Free plan.
    'credit_limit' => min(2500, max(0, (int) env('GEOAPIFY_CREDIT_LIMIT', 2500))),
];
