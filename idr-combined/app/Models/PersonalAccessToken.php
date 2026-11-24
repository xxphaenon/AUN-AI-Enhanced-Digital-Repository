<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;
use MongoDB\Laravel\Eloquent\DocumentModel;

/**
 * Collection: personal_access_tokens
 *
 * Sanctum's token model, stored in MongoDB instead of SQL. Only the SHA-256
 * hash of each token is saved; the plain token is shown once, at login.
 * Registered in AppServiceProvider with Sanctum::usePersonalAccessTokenModel().
 */
class PersonalAccessToken extends SanctumPersonalAccessToken
{
    use DocumentModel;

    protected $connection = 'mongodb';

    protected $table = 'personal_access_tokens';

    protected $keyType = 'string';
}
