<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use MongoDB\Laravel\Schema\Blueprint;

/**
 * Sanctum login tokens (App\Models\PersonalAccessToken). Replaces Sanctum's
 * own SQL migration, which is not used with MongoDB.
 */
return new class extends Migration
{
    protected $connection = 'mongodb';

    public function up(): void
    {
        Schema::connection($this->connection)->create('personal_access_tokens', function (Blueprint $collection) {
            $collection->unique('token');
            $collection->index(['tokenable_type' => 1, 'tokenable_id' => 1]);
            $collection->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('personal_access_tokens');
    }
};
