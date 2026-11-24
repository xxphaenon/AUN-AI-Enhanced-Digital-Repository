<?php

return [

    'paths' => ['api/*'],

    // PATCH/PUT/DELETE are used by the admin dashboard (approve, edit, delete).
    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    // The Next.js app (Member 1). Comma-separate multiple origins in .env.
    'allowed_origins' => array_filter(explode(',', env('FRONTEND_URL', 'http://localhost:3000'))),

    'allowed_origins_patterns' => [],

    // Includes Authorization (the bearer token).
    'allowed_headers' => ['*'],

    // Lets the frontend read the file name of a download made with fetch(),
    // and the rate-limit headers.
    'exposed_headers' => ['Content-Disposition', 'Retry-After', 'X-RateLimit-Remaining'],

    'max_age' => 3600,

    'supports_credentials' => false,

];
