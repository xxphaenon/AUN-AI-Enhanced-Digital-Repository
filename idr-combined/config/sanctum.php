<?php

use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Laravel\Sanctum\Http\Middleware\AuthenticateSession;

/*
| Laravel Sanctum, used here purely for API tokens:
|
|   Authorization: Bearer <token from /api/auth/login>
|
| The Next.js app is on a different origin and does not use cookies, so the
| stateful/SPA-cookie features are switched off.
*/

return [

    'stateful' => [],

    // No session guards are tried first: a request is authenticated by its
    // bearer token only.
    'guard' => [],

    // Lifetime is set per token (expires_at) from idr.auth.token_ttl_minutes,
    // so the global created_at-based expiry is not needed.
    'expiration' => null,

    // Makes leaked tokens recognisable to secret scanners (e.g. GitHub's).
    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', 'idr_'),

    'middleware' => [
        'authenticate_session' => AuthenticateSession::class,
        'encrypt_cookies' => EncryptCookies::class,
        'validate_csrf_token' => ValidateCsrfToken::class,
    ],

];
